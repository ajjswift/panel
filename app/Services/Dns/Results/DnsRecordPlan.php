<?php

namespace Pterodactyl\Services\Dns\Results;

final readonly class DnsRecordPlan
{
    /**
     * How players/visitors reach the server once the address is set up.
     *
     * - clean:     they type just the address (no port). A DNS record is enough.
     * - with_port: they type the address plus ":port". A DNS record is enough.
     * - proxy:     a reverse proxy would be needed to give a clean address on
     *              this port (e.g. a website on a non-standard port).
     */
    public const ACCESS_CLEAN = 'clean';
    public const ACCESS_WITH_PORT = 'with_port';
    public const ACCESS_PROXY = 'proxy';

    /**
     * @param array<int, array<string, mixed>> $records
     * @param string[] $warnings
     */
    public function __construct(
        public array $records,
        public string $connectionAddress,
        public bool $portDiscoverable,
        public string $explanation,
        public array $warnings,
        public string $accessMethod = self::ACCESS_CLEAN,
        public bool $proxyRequired = false,
        public string $playerAddress = '',
        public string $friendlyNote = '',
        public ?string $proxyTargetScheme = null,
        public ?string $webDetectionSource = null,
        public bool $minecraftDetected = false,
    ) {
    }

    public function toArray(): array
    {
        $plan = [
            'records' => $this->records,
            'connection_address' => $this->connectionAddress,
            'port_discoverable' => $this->portDiscoverable,
            'explanation' => $this->explanation,
            'warnings' => $this->warnings,
            'access_method' => $this->accessMethod,
            'proxy_required' => $this->proxyRequired,
            'player_address' => $this->playerAddress ?: $this->connectionAddress,
            'friendly_note' => $this->friendlyNote,
        ];

        if ($this->proxyTargetScheme) {
            $plan['proxy_target_scheme'] = $this->proxyTargetScheme;
        }
        if ($this->webDetectionSource) {
            $plan['web_detection_source'] = $this->webDetectionSource;
        }
        if ($this->minecraftDetected) {
            $plan['minecraft_detected'] = true;
        }

        return $plan;
    }
}
