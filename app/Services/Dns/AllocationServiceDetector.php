<?php

namespace Pterodactyl\Services\Dns;

use Pterodactyl\Services\Dns\Results\DnsTarget;
use Pterodactyl\Services\Dns\Results\DetectedAllocationService;

class AllocationServiceDetector
{
    public function __construct(
        private HttpServiceDetector $http,
        private MinecraftServiceDetector $minecraft,
    ) {
    }

    public function detect(DnsTarget $target, int $port): DetectedAllocationService
    {
        if ($this->http->detect($target, $port) === 'http') {
            return new DetectedAllocationService(DetectedAllocationService::HTTP);
        }

        if ($this->minecraft->detect($target, $port)) {
            return new DetectedAllocationService(DetectedAllocationService::MINECRAFT_JAVA);
        }

        return new DetectedAllocationService(DetectedAllocationService::UNKNOWN);
    }
}
