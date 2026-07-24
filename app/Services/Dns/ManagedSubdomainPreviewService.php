<?php

namespace Pterodactyl\Services\Dns;

use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Services\Dns\Results\DnsRecordPlan;

class ManagedSubdomainPreviewService
{
    public function __construct(
        private SubdomainPolicyResolver $policyResolver,
        private DnsServiceProfileResolver $profileResolver,
        private DnsTargetResolver $targetResolver,
        private DnsRecordPlanner $recordPlanner,
        private ManagedHostnameAvailabilityService $availabilityService,
        private AllocationServiceDetector $allocationServiceDetector,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(
        Server $server,
        User $user,
        ManagedDomain $domain,
        Allocation $allocation,
        string $label,
        bool $requireCreatePermission = true,
    ): array {
        $policy = $this->policyResolver->resolve($server, $user, !$requireCreatePermission);
        if (!$policy->enabled || ($requireCreatePermission && !$policy->canCreate)) {
            throw new DisplayException($policy->disabledReason ?? 'You do not have permission to create a managed hostname.');
        }

        if ($allocation->server_id !== $server->id) {
            throw new DisplayException('The selected allocation does not belong to this server.');
        }

        if (!$policy->eligibleDomains->contains('id', $domain->id)) {
            throw new DisplayException('The selected parent domain is not available for this server.');
        }

        $label = $this->normalizeLabel($label);
        $this->validateLabel($domain, $label);
        $fqdn = sprintf('%s.%s', $label, strtolower(rtrim($domain->domain, '.')));
        if ($requireCreatePermission) {
            // Provider and panel ownership checks happen before touching the
            // allocation port, matching the public decision order.
            $this->availabilityService->assertAvailable($domain, $fqdn);
        }

        ['profile' => $profile, 'source' => $source] = $this->profileResolver->resolve($server);
        if (!$profile) {
            throw new DisplayException('No DNS service profile is configured for the active game.');
        }

        $target = $this->targetResolver->resolve($allocation, $domain);
        $reverseProxyTarget = $this->targetResolver->resolveForReverseProxy($allocation);
        $detected = $this->allocationServiceDetector->detect($target, $allocation->port);
        if ($detected->isHttp() && !$reverseProxyTarget) {
            throw new DisplayException('A website was detected on this port, but reverse proxying is not configured for its node. Ask an administrator to enable the node reverse proxy first.');
        }
        $srvTarget = $detected->isMinecraftJava()
            ? $this->targetResolver->resolveForSrv($allocation)
            : null;
        if ($detected->isMinecraftJava() && (!$domain->supports_srv || !$srvTarget)) {
            throw new DisplayException('A Minecraft server was detected on this port, but an SRV record cannot be created for this domain and node. Ask an administrator to configure a public node hostname and enable SRV records.');
        }

        $plan = $this->recordPlanner->build(
            $fqdn,
            $allocation,
            $domain,
            $profile,
            $target,
            $reverseProxyTarget,
            $detected->isHttp() ? 'http' : null,
            $detected->isMinecraftJava(),
            $srvTarget,
        );
        $publicTarget = $plan->accessMethod === DnsRecordPlan::ACCESS_PROXY
            ? ($reverseProxyTarget ?? $target)
            : $target;

        return [
            'label' => $label,
            'fqdn' => $fqdn,
            'allocation' => [
                'id' => $allocation->id,
                'ip' => $allocation->ip,
                'port' => $allocation->port,
                'display' => sprintf('%s:%d', $allocation->ip_alias ?: $allocation->ip, $allocation->port),
            ],
            'public_target' => [
                'type' => $publicTarget->recordType,
                'value' => $publicTarget->value,
                'source' => $publicTarget->source,
            ],
            'service_profile' => [
                'id' => $profile->id,
                'name' => $profile->name,
                'detection_source' => $source,
                'supports_srv' => $profile->supports_srv,
            ],
            'detected_service' => $detected->type,
            'record_plan' => $plan->toArray(),
        ];
    }

    public function normalizeLabel(string $label): string
    {
        return strtolower(trim($label));
    }

    public function validateLabel(ManagedDomain $domain, string $label): void
    {
        if (!$label || strlen($label) > 63 || str_contains($label, '.')) {
            throw new DisplayException('Enter a single valid DNS label between 1 and 63 characters.');
        }

        if (@preg_match('~' . $domain->label_pattern . '~D', $label) !== 1) {
            throw new DisplayException('This label does not match the rules configured for the parent domain.');
        }

        $reserved = array_map('strtolower', $domain->reserved_labels ?? []);
        if (in_array($label, $reserved, true)) {
            throw new DisplayException('This hostname is reserved. Choose another name.');
        }
    }
}
