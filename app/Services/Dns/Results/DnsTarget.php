<?php

namespace Pterodactyl\Services\Dns\Results;

final readonly class DnsTarget
{
    public function __construct(
        public string $recordType,
        public string $value,
        public string $source,
    ) {
    }
}
