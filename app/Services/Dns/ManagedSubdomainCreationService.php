<?php

namespace Pterodactyl\Services\Dns;

use Ramsey\Uuid\Uuid;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Enum\DnsRoutingMode;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Models\ManagedSubdomain;
use Pterodactyl\Enum\ManagedSubdomainStatus;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Jobs\Dns\SyncManagedSubdomainJob;
use Pterodactyl\Jobs\ReverseProxy\SyncNodeReverseProxyJob;

class ManagedSubdomainCreationService
{
    public function __construct(
        private ManagedSubdomainPreviewService $previewService,
        private SubdomainPolicyResolver $policyResolver,
    ) {
    }

    public function handle(
        Server $server,
        User $user,
        ManagedDomain $domain,
        Allocation $allocation,
        string $label,
    ): ManagedSubdomain {
        $preview = $this->previewService->handle($server, $user, $domain, $allocation, $label);
        $lock = Cache::lock('managed-dns:fqdn:' . $preview['fqdn'], 30);

        if (!$lock->get()) {
            throw new DisplayException('This hostname is currently being changed by another request. Try again shortly.');
        }

        try {
            $managed = Activity::event('server:subdomain.create')
                ->property(['fqdn' => $preview['fqdn'], 'allocation' => $preview['allocation']['display']])
                ->transaction(function ($log) use ($server, $user, $domain, $allocation, $preview) {
                    $server = Server::query()->lockForUpdate()->findOrFail($server->id);
                    $domain = ManagedDomain::query()->lockForUpdate()->findOrFail($domain->id);
                    $allocation = Allocation::query()->lockForUpdate()->findOrFail($allocation->id);
                    if ($allocation->server_id !== $server->id) {
                        throw new DisplayException('The selected allocation is no longer assigned to this server.');
                    }

                    $policy = $this->policyResolver->resolve($server, $user);
                    if (!$policy->canCreate || !$policy->eligibleDomains->contains('id', $domain->id)) {
                        throw new DisplayException($policy->disabledReason ?? 'Managed hostname creation is no longer available.');
                    }

                    if ($domain->domain_limit && $domain->managedSubdomains()->whereNull('deleted_at')->count() >= $domain->domain_limit) {
                        throw new DisplayException('The selected parent domain has reached its managed-hostname limit.');
                    }

                    if ($domain->per_server_limit && $domain->managedSubdomains()->whereNull('deleted_at')->where('server_id', $server->id)->count() >= $domain->per_server_limit) {
                        throw new DisplayException('This server has reached the limit for the selected parent domain.');
                    }

                    if ($domain->per_user_limit && $domain->managedSubdomains()->whereNull('deleted_at')->where('created_by', $user->id)->count() >= $domain->per_user_limit) {
                        throw new DisplayException('You have reached the limit for the selected parent domain.');
                    }

                    if (ManagedSubdomain::query()->where('fqdn', $preview['fqdn'])->exists()) {
                        throw new DisplayException('This hostname is already in use. Choose another name or contact an administrator.');
                    }

                    $isProxy = ($preview['record_plan']['access_method'] ?? 'clean') === 'proxy';
                    $detectedByHttpProbe = ($preview['record_plan']['web_detection_source'] ?? null) === 'http_probe';
                    $detectedMinecraft = ($preview['record_plan']['minecraft_detected'] ?? false) === true;
                    $detectedScheme = $preview['record_plan']['proxy_target_scheme'] ?? null;

                    $managed = new ManagedSubdomain();
                    $managed->forceFill([
                        'uuid' => Uuid::uuid4()->toString(),
                        'server_id' => $server->id,
                        'allocation_id' => $allocation->id,
                        'managed_domain_id' => $domain->id,
                        'created_by' => $user->id,
                        'dns_service_profile_id' => $preview['service_profile']['id'],
                        'label' => $preview['label'],
                        'fqdn' => $preview['fqdn'],
                        'routing_mode' => $isProxy ? 'reverse_proxy' : 'direct_dns',
                        'detected_service' => $detectedByHttpProbe
                            ? sprintf('%s website', strtoupper($detectedScheme ?: 'HTTP'))
                            : ($detectedMinecraft ? 'Minecraft Java' : $preview['service_profile']['name']),
                        'service_detection_source' => $detectedByHttpProbe
                            ? 'http_probe'
                            : ($detectedMinecraft ? 'minecraft_probe' : 'direct_dns_fallback'),
                        'status' => ManagedSubdomainStatus::Pending,
                        'desired_state_version' => 1,
                        'public_target_type' => $preview['public_target']['type'],
                        'public_target' => $preview['public_target']['value'],
                        'target_port' => $allocation->port,
                        'connection_address' => $preview['record_plan']['connection_address'],
                        'desired_record_plan' => $preview['record_plan'],
                    ]);
                    $managed->saveOrFail();
                    $log->subject($managed);

                    return $managed;
                });

            SyncManagedSubdomainJob::dispatch($managed->id, $managed->desired_state_version)->afterCommit();

            // Reverse-proxied addresses also need the node's agent to learn
            // about the new route (DNS + certificate + proxy happen there). The
            // job no-ops if the node has no agent enabled.
            if ($managed->routing_mode === DnsRoutingMode::ReverseProxy) {
                SyncNodeReverseProxyJob::dispatch($server->node_id)->afterCommit();
            }

            return $managed;
        } finally {
            $lock->release();
        }
    }
}
