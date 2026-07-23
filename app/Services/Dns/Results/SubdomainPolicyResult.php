<?php

namespace Pterodactyl\Services\Dns\Results;

use Illuminate\Support\Collection;
use Pterodactyl\Models\DnsServiceProfile;

final readonly class SubdomainPolicyResult
{
    /**
     * @param Collection<int, \Pterodactyl\Models\ManagedDomain> $eligibleDomains
     * @param string[] $warnings
     */
    public function __construct(
        public bool $enabled,
        public bool $canView,
        public bool $canCreate,
        public bool $canUpdate,
        public bool $canDelete,
        public bool $canRepair,
        public int $limit,
        public int $used,
        public string $policySource,
        public ?DnsServiceProfile $serviceProfile,
        public Collection $eligibleDomains,
        public ?string $disabledReason,
        public ?string $disabledReasonCode,
        public array $warnings = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'can_view' => $this->canView,
            'can_create' => $this->canCreate,
            'can_update' => $this->canUpdate,
            'can_delete' => $this->canDelete,
            'can_repair' => $this->canRepair,
            'limit' => $this->limit,
            'used' => $this->used,
            'remaining' => max(0, $this->limit - $this->used),
            'policy_source' => $this->policySource,
            'service_profile' => $this->serviceProfile ? [
                'uuid' => $this->serviceProfile->uuid,
                'name' => $this->serviceProfile->name,
                'protocol' => $this->serviceProfile->protocol,
                'default_port' => $this->serviceProfile->default_port,
                'supports_direct_dns' => $this->serviceProfile->supports_direct_dns,
                'supports_srv' => $this->serviceProfile->supports_srv,
            ] : null,
            'eligible_domains' => $this->eligibleDomains->map(fn ($domain) => [
                'uuid' => $domain->uuid,
                'name' => $domain->name,
                'domain' => $domain->domain,
                'description' => $domain->description,
            ])->values()->all(),
            'disabled_reason' => $this->disabledReason,
            'disabled_reason_code' => $this->disabledReasonCode,
            'warnings' => $this->warnings,
        ];
    }
}
