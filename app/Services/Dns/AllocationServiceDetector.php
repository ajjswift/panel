<?php

namespace Pterodactyl\Services\Dns;

use Pterodactyl\Models\Allocation;
use Pterodactyl\Services\Dns\Results\DetectedAllocationService;

/**
 * Decides what is actually running on an allocation by probing it, in the exact
 * order the routing decision needs:
 *   1. HTTP responds        → a website        → reverse proxy.
 *   2. Minecraft handshake  → a Minecraft game → SRV record.
 *   3. neither              → unknown          → plain DNS record.
 */
class AllocationServiceDetector
{
    public function __construct(
        private DnsTargetResolver $targetResolver,
        private HttpServiceDetector $http,
        private MinecraftServiceDetector $minecraft,
    ) {
    }

    public function detect(Allocation $allocation): DetectedAllocationService
    {
        $hosts = $this->targetResolver->resolveProbeCandidates($allocation);
        if ($hosts === []) {
            return new DetectedAllocationService(DetectedAllocationService::UNKNOWN);
        }

        if ($this->http->detect($hosts, $allocation->port) === 'http') {
            return new DetectedAllocationService(DetectedAllocationService::HTTP);
        }

        if ($this->minecraft->detect($hosts, $allocation->port)) {
            return new DetectedAllocationService(DetectedAllocationService::MINECRAFT_JAVA);
        }

        return new DetectedAllocationService(DetectedAllocationService::UNKNOWN);
    }
}
