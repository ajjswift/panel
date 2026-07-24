<?php

namespace Pterodactyl\Services\Dns;

use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Models\DnsServiceProfile;
use Pterodactyl\Services\Dns\Results\DnsTarget;
use Pterodactyl\Services\Dns\Results\DnsRecordPlan;

class DnsRecordPlanner
{
    public function __construct(private ?HttpServiceDetector $httpServiceDetector = null)
    {
    }

    public function build(
        string $fqdn,
        Allocation $allocation,
        ManagedDomain $domain,
        DnsServiceProfile $profile,
        DnsTarget $target,
        ?DnsTarget $reverseProxyTarget = null,
        ?string $knownWebScheme = null,
    ): DnsRecordPlan {
        $protocol = strtolower($profile->protocol);
        $profileIsWeb = in_array($protocol, ['http', 'https'], true);
        $knownWebScheme = in_array($knownWebScheme, ['http', 'https'], true) ? $knownWebScheme : null;
        $detectedWebScheme = $profileIsWeb ? $protocol : $knownWebScheme;
        $webDetectionSource = $profileIsWeb
            ? 'service_profile'
            : ($knownWebScheme ? 'http_probe' : null);

        // A server's service profile describes its primary game, not every
        // allocation attached to it. Probe custom TCP allocations so a web UI
        // such as a map can override an inherited Minecraft/game profile.
        if (
            !$detectedWebScheme
            && $reverseProxyTarget
            && $this->httpServiceDetector
            && $protocol !== 'udp'
            && !in_array($allocation->port, self::WEB_PORTS, true)
        ) {
            $detectedWebScheme = $this->httpServiceDetector->detect($target, $allocation->port, $fqdn);
            $webDetectionSource = $detectedWebScheme ? 'http_probe' : null;
        }

        // An HTTP response is stronger evidence for this allocation than the
        // server-wide game profile, so do not publish an unrelated game SRV
        // record for the website port.
        $useSrv = !$detectedWebScheme && $profile->supports_srv && $domain->supports_srv;
        if ($useSrv) {
            $srvRecord = [
                'key' => 'srv',
                'type' => 'SRV',
                'name' => sprintf('%s.%s.%s', $profile->srv_service, $profile->srv_protocol, $fqdn),
                'content' => null,
                'ttl' => $domain->ttl,
                'proxied' => false,
                'priority' => $profile->srv_priority,
                'weight' => $profile->srv_weight,
                'port' => $allocation->port,
                'target' => $fqdn,
            ];
        }

        $portlessOnDefault = !$detectedWebScheme
            && $profile->portless_on_default_port
            && $profile->default_port
            && $profile->default_port === $allocation->port;

        // Work out how a visitor actually reaches the server, and whether a
        // reverse proxy would be needed to make the address clean.
        [$accessMethod, $proxyRequired, $playerAddress, $note] = $this->detectAccess(
            $fqdn,
            $allocation->port,
            $useSrv,
            $portlessOnDefault,
            $detectedWebScheme,
            $reverseProxyTarget !== null,
        );

        $recordTarget = $accessMethod === DnsRecordPlan::ACCESS_PROXY ? ($reverseProxyTarget ?? $target) : $target;
        $records = [[
            'key' => 'address',
            'type' => $recordTarget->recordType,
            'name' => $fqdn,
            'content' => $recordTarget->value,
            'ttl' => $domain->ttl,
            'proxied' => false,
        ]];
        if (isset($srvRecord)) {
            $records[] = $srvRecord;
        }

        $portDiscoverable = $accessMethod === DnsRecordPlan::ACCESS_CLEAN;
        $connectionAddress = $playerAddress;

        // Keep the older technical explanation around for compatibility, but the
        // client now leans on the friendly note instead.
        $explanation = $note;

        return new DnsRecordPlan(
            records: $records,
            connectionAddress: $connectionAddress,
            portDiscoverable: $portDiscoverable,
            explanation: $explanation,
            warnings: $accessMethod === DnsRecordPlan::ACCESS_WITH_PORT
                ? ['Players need to include the number after the colon when they connect.']
                : [],
            accessMethod: $accessMethod,
            proxyRequired: $proxyRequired,
            playerAddress: $playerAddress,
            friendlyNote: $note,
            proxyTargetScheme: $accessMethod === DnsRecordPlan::ACCESS_PROXY ? $detectedWebScheme : null,
            webDetectionSource: $webDetectionSource,
        );
    }

    /**
     * Ports where the game/service client automatically assumes the port, so a
     * plain address (with just a DNS record) is enough — no proxy, no SRV, no
     * ":port". This is what lets detection "just work" for a given port even
     * when an egg has not been mapped to a service profile.
     */
    private const CLIENT_ASSUMED_PORTS = [
        25565, // Minecraft: Java Edition
        19132, // Minecraft: Bedrock Edition
        80,    // HTTP (browsers default to 80)
        443,   // HTTPS (browsers default to 443)
    ];

    /**
     * Standard web ports handled cleanly by any browser.
     */
    private const WEB_PORTS = [80, 443];

    /**
     * Automatically classify how a given port is reached and whether it needs a
     * reverse proxy or a plain DNS record.
     *
     * @return array{0: string, 1: bool, 2: string, 3: string}
     */
    private function detectAccess(
        string $fqdn,
        int $port,
        bool $useSrv,
        bool $portlessOnDefault,
        ?string $detectedWebScheme,
        bool $reverseProxyAvailable,
    ): array {
        $isWeb = $detectedWebScheme !== null;

        // A well-known port the client assumes automatically, or an SRV-aware
        // game, or a service on its own default port: the plain address works.
        if (
            ($isWeb && in_array($port, self::WEB_PORTS, true))
            || (!$isWeb && (in_array($port, self::CLIENT_ASSUMED_PORTS, true) || $useSrv || $portlessOnDefault))
        ) {
            return [
                DnsRecordPlan::ACCESS_CLEAN,
                false,
                $fqdn,
                $isWeb && in_array($port, self::WEB_PORTS, true)
                    ? sprintf('Your site will be reachable at https://%s.', $fqdn)
                    : sprintf('Players just enter %s — no port needed.', $fqdn),
            ];
        }

        // A website on a custom port needs a reverse proxy to drop the ":port"
        // from the public address. Use it automatically when this node has a
        // configured agent and public proxy target.
        if ($isWeb && $reverseProxyAvailable) {
            return [
                DnsRecordPlan::ACCESS_PROXY,
                true,
                $fqdn,
                sprintf('A website was detected on port %d. It will be securely available at https://%s with no port needed.', $port, $fqdn),
            ];
        }

        if ($isWeb) {
            return [
                DnsRecordPlan::ACCESS_WITH_PORT,
                true,
                sprintf('%s:%d', $fqdn, $port),
                sprintf('This is a website on a custom port. Visitors use %s:%d because this node does not have reverse proxying available.', $fqdn, $port),
            ];
        }

        // Everything else works over plain DNS, but the port has to be typed.
        return [
            DnsRecordPlan::ACCESS_WITH_PORT,
            false,
            sprintf('%s:%d', $fqdn, $port),
            sprintf('Players connect using %s:%d (include the number after the colon; DNS cannot redirect arbitrary ports).', $fqdn, $port),
        ];
    }
}
