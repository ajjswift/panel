<?php

namespace Pterodactyl\Services\Dns;

use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Models\DnsServiceProfile;
use Pterodactyl\Services\Dns\Results\DnsTarget;
use Pterodactyl\Services\Dns\Results\DnsRecordPlan;

class DnsRecordPlanner
{
    public function build(
        string $fqdn,
        Allocation $allocation,
        ManagedDomain $domain,
        DnsServiceProfile $profile,
        DnsTarget $target,
        ?DnsTarget $reverseProxyTarget = null,
        ?string $knownWebScheme = null,
        ?bool $minecraftDetected = null,
        ?string $srvTarget = null,
    ): DnsRecordPlan {
        $protocol = strtolower($profile->protocol);
        $legacyProfileDetection = $minecraftDetected === null;
        $profileIsWeb = $legacyProfileDetection && in_array($protocol, ['http', 'https'], true);
        $knownWebScheme = in_array($knownWebScheme, ['http', 'https'], true) ? $knownWebScheme : null;
        $detectedWebScheme = $profileIsWeb ? $protocol : $knownWebScheme;
        $webDetectionSource = $profileIsWeb
            ? 'service_profile'
            : ($knownWebScheme ? 'http_probe' : null);
        $minecraftDetected = $minecraftDetected
            ?? (!$detectedWebScheme && $profile->supports_srv);
        $useSrv = !$detectedWebScheme
            && $minecraftDetected
            && $domain->supports_srv
            && $srvTarget !== null;

        if ($useSrv) {
            // The node already has a resolvable hostname, so the managed label
            // only needs the SRV record. Creating an address record here would
            // hide whether SRV classification actually happened.
            $records = [[
                'key' => 'srv',
                'type' => 'SRV',
                'name' => sprintf('_minecraft._tcp.%s', $fqdn),
                'content' => null,
                'ttl' => $domain->ttl,
                'proxied' => false,
                'priority' => $profile->srv_priority,
                'weight' => $profile->srv_weight,
                'port' => $allocation->port,
                'target' => $srvTarget,
            ]];
        } else {
            $recordTarget = $detectedWebScheme && $reverseProxyTarget
                ? $reverseProxyTarget
                : $target;
            $records = [[
                'key' => 'address',
                'type' => $recordTarget->recordType,
                'name' => $fqdn,
                'content' => $recordTarget->value,
                'ttl' => $domain->ttl,
                'proxied' => false,
            ]];
        }

        $portlessOnDefault = $legacyProfileDetection
            && !$detectedWebScheme
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
            $minecraftDetected,
        );

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
            minecraftDetected: $minecraftDetected,
        );
    }

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
        bool $minecraftDetected,
    ): array {
        $isWeb = $detectedWebScheme !== null;

        if ($useSrv) {
            return [
                DnsRecordPlan::ACCESS_CLEAN,
                false,
                $fqdn,
                sprintf('A Minecraft server was detected on port %d. Players connect using %s through an SRV record.', $port, $fqdn),
            ];
        }

        if ($isWeb && $reverseProxyAvailable) {
            return [
                DnsRecordPlan::ACCESS_PROXY,
                true,
                $fqdn,
                sprintf('A website was detected on port %d. It will be securely available at https://%s with no port needed.', $port, $fqdn),
            ];
        }

        if ($minecraftDetected && ($port === 25565 || $portlessOnDefault)) {
            return [
                DnsRecordPlan::ACCESS_CLEAN,
                false,
                $fqdn,
                sprintf('A Minecraft server was detected on its default port. Players connect using %s.', $fqdn),
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
