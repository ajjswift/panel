<?php

namespace Pterodactyl\Services\Dns\Results;

final readonly class DnsRecordPlan
{
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
    ) {
    }

    public function toArray(): array
    {
        return [
            'records' => $this->records,
            'connection_address' => $this->connectionAddress,
            'port_discoverable' => $this->portDiscoverable,
            'explanation' => $this->explanation,
            'warnings' => $this->warnings,
        ];
    }
}
