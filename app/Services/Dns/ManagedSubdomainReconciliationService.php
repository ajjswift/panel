<?php

namespace Pterodactyl\Services\Dns;

use Carbon\Carbon;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Enum\DnsRoutingMode;
use Pterodactyl\Models\ManagedDnsRecord;
use Pterodactyl\Models\ManagedSubdomain;
use Pterodactyl\Enum\ManagedSubdomainStatus;
use Pterodactyl\Jobs\Dns\SyncManagedSubdomainJob;
use Pterodactyl\Services\Dns\Results\DnsRecordPlan;
use Pterodactyl\Jobs\ReverseProxy\SyncNodeReverseProxyJob;
use Pterodactyl\Exceptions\Service\Dns\DnsProviderException;

class ManagedSubdomainReconciliationService
{
    public function __construct(
        private SubdomainPolicyResolver $policyResolver,
        private DnsServiceProfileResolver $profileResolver,
        private DnsTargetResolver $targetResolver,
        private DnsRecordPlanner $recordPlanner,
        private DnsProviderFactory $providerFactory,
        private AllocationServiceDetector $serviceDetector,
    ) {
    }

    public function reconcile(Server $server, bool $checkProvider = false): void
    {
        if ($server->status === Server::STATUS_SWITCHING_GAME) {
            return;
        }

        $policy = $this->policyResolver->resolve($server, null, true);
        ['profile' => $profile] = $this->profileResolver->resolve($server);

        $server->managedSubdomains()
            ->whereNull('deleted_at')
            ->with(['allocation', 'domain', 'records'])
            ->each(function (ManagedSubdomain $managed) use ($policy, $profile, $checkProvider) {
                if (!$managed->allocation || $managed->allocation->server_id !== $managed->server_id) {
                    $managed->forceFill([
                        'status' => ManagedSubdomainStatus::RepairRequired,
                        'last_error_code' => 'allocation_missing',
                        'sanitized_error_message' => 'The associated allocation is no longer assigned to this server.',
                    ])->save();

                    return;
                }

                if (!$policy->enabled || !$profile) {
                    $managed->forceFill([
                        'status' => match($policy->disabledReasonCode) {
                            'over_limit' => ManagedSubdomainStatus::OverLimit,
                            'egg_incompatible', 'service_not_supported' => ManagedSubdomainStatus::Incompatible,
                            default => ManagedSubdomainStatus::Restricted,
                        },
                        'last_error_code' => $policy->disabledReasonCode,
                        'sanitized_error_message' => $policy->disabledReason,
                    ])->save();

                    return;
                }

                try {
                    $target = $this->targetResolver->resolve($managed->allocation, $managed->domain);
                    $reverseProxyTarget = $this->targetResolver->resolveForReverseProxy($managed->allocation);

                    // Re-probe the port (HTTP → website; else Minecraft handshake
                    // → SRV) so a subdomain follows the service if it changes. The
                    // planner falls back to a working record in every other case,
                    // so reconciliation never dead-ends here.
                    $detected = $this->serviceDetector->detect($managed->allocation);
                    $srvTarget = $detected->isMinecraftJava()
                        ? $this->targetResolver->resolveForSrv($managed->allocation)
                        : null;

                    $plan = $this->recordPlanner->build(
                        $managed->fqdn,
                        $managed->allocation,
                        $managed->domain,
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
                } catch (\Throwable $exception) {
                    $managed->forceFill([
                        'status' => ManagedSubdomainStatus::RepairRequired,
                        'last_error_code' => 'target_resolution_failed',
                        'sanitized_error_message' => 'A safe public DNS target can no longer be determined.',
                    ])->save();

                    return;
                }

                $routingMode = $plan->accessMethod === DnsRecordPlan::ACCESS_PROXY
                    ? DnsRoutingMode::ReverseProxy
                    : DnsRoutingMode::DirectDns;
                $changed = $managed->dns_service_profile_id !== $profile->id
                    || $managed->routing_mode !== $routingMode
                    || $managed->public_target_type !== $publicTarget->recordType
                    || $managed->public_target !== $publicTarget->value
                    || $managed->target_port !== $managed->allocation->port
                    || $managed->desired_record_plan !== $plan->toArray();

                if ($changed) {
                    $wasProxy = $managed->routing_mode === DnsRoutingMode::ReverseProxy;
                    $detectedByHttpProbe = $plan->webDetectionSource === 'http_probe';
                    $detectedMinecraft = $plan->minecraftDetected;
                    $managed->forceFill([
                        'dns_service_profile_id' => $profile->id,
                        'routing_mode' => $routingMode,
                        'detected_service' => $detectedByHttpProbe
                            ? sprintf('%s website', strtoupper($plan->proxyTargetScheme ?: 'HTTP'))
                            : ($detectedMinecraft ? 'Minecraft Java' : $profile->name),
                        'service_detection_source' => $detectedByHttpProbe
                            ? 'http_probe'
                            : ($detectedMinecraft ? 'minecraft_probe' : 'direct_dns_fallback'),
                        'desired_state_version' => $managed->desired_state_version + 1,
                        'public_target_type' => $publicTarget->recordType,
                        'public_target' => $publicTarget->value,
                        'target_port' => $managed->allocation->port,
                        'connection_address' => $plan->connectionAddress,
                        'desired_record_plan' => $plan->toArray(),
                        'status' => ManagedSubdomainStatus::Pending,
                        'proxy_dns_status' => null,
                        'proxy_cert_status' => null,
                        'proxy_status' => null,
                        'proxy_cert_expires_at' => null,
                        'proxy_reported_at' => null,
                        'last_error_code' => null,
                        'sanitized_error_message' => null,
                    ])->save();

                    SyncManagedSubdomainJob::dispatch($managed->id, $managed->desired_state_version);
                    if ($wasProxy || $routingMode === DnsRoutingMode::ReverseProxy) {
                        SyncNodeReverseProxyJob::dispatch($managed->server->node_id);
                    }

                    return;
                }

                if ($checkProvider) {
                    $this->checkDrift($managed);
                }
            });
    }

    public function checkDrift(ManagedSubdomain $managed): void
    {
        $managed->loadMissing(['domain', 'records']);
        $provider = $this->providerFactory->for($managed->domain);

        try {
            foreach ($managed->records->where('sync_status', 'active') as $record) {
                if (!$record->provider_record_id) {
                    throw new DnsProviderException('record_missing', 'A managed provider record is missing.');
                }

                $actual = $provider->getRecord($managed->domain, $record->provider_record_id);
                if (!$this->recordMatches($record, $actual)) {
                    throw new DnsProviderException('record_drift', 'A managed provider record was changed outside the panel.');
                }
            }

            $managed->forceFill(['provider_drift_detected_at' => null])->save();
            $managed->domain->forceFill([
                'last_provider_check_at' => Carbon::now(),
                'last_provider_status' => 'healthy',
                'last_provider_error_code' => null,
            ])->save();
        } catch (DnsProviderException $exception) {
            $isDrift = in_array($exception->providerErrorCode, ['record_drift', 'provider_record_missing'], true);
            $managed->forceFill([
                'status' => ManagedSubdomainStatus::RepairRequired,
                'provider_drift_detected_at' => Carbon::now(),
                'last_error_code' => $exception->providerErrorCode,
                'sanitized_error_message' => $isDrift
                    ? 'DNS drift was detected. Review the record and use Repair DNS to restore it.'
                    : 'The DNS provider could not be verified. Try again later or ask an administrator to check the parent domain.',
            ])->save();
            $managed->domain->forceFill([
                'last_provider_check_at' => Carbon::now(),
                'last_provider_status' => $isDrift ? 'healthy' : 'error',
                'last_provider_error_code' => $isDrift ? null : $exception->providerErrorCode,
            ])->save();
            Log::warning('Managed DNS drift detected.', [
                'managed_subdomain_id' => $managed->id,
                'error_code' => $exception->providerErrorCode,
            ]);
        }
    }

    /**
     * Compare provider state with the exact state owned by the panel without
     * considering provider-generated metadata.
     *
     * @param array<string, mixed> $actual
     */
    private function recordMatches(ManagedDnsRecord $record, array $actual): bool
    {
        if (($actual['type'] ?? null) !== $record->type || strtolower(rtrim((string) ($actual['name'] ?? ''), '.')) !== strtolower(rtrim($record->name, '.'))) {
            return false;
        }

        if ($record->type === 'SRV') {
            $data = $actual['data'] ?? [];

            return (int) ($data['priority'] ?? -1) === $record->srv_priority
                && (int) ($data['weight'] ?? -1) === $record->srv_weight
                && (int) ($data['port'] ?? -1) === $record->srv_port
                && strtolower(rtrim((string) ($data['target'] ?? ''), '.')) === strtolower(rtrim((string) $record->srv_target, '.'));
        }

        return strtolower(rtrim((string) ($actual['content'] ?? ''), '.')) === strtolower(rtrim((string) $record->content, '.'))
            && (bool) ($actual['proxied'] ?? false) === (bool) $record->proxied;
    }
}
