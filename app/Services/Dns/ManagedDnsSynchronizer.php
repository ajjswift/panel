<?php

namespace Pterodactyl\Services\Dns;

use Carbon\Carbon;
use Ramsey\Uuid\Uuid;
use Pterodactyl\Facades\Activity;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\ManagedDnsRecord;
use Pterodactyl\Models\ManagedSubdomain;
use Pterodactyl\Enum\ManagedSubdomainStatus;
use Pterodactyl\Models\ManagedDnsSyncAttempt;
use Pterodactyl\Exceptions\Service\Dns\DnsProviderException;

class ManagedDnsSynchronizer
{
    public function __construct(
        private DnsProviderFactory $providerFactory,
        private SubdomainPolicyResolver $policyResolver,
    ) {
    }

    public function synchronize(ManagedSubdomain $managed, int $desiredStateVersion): void
    {
        $startedAt = microtime(true);
        $managed->loadMissing(['domain', 'server.egg', 'allocation', 'records']);
        if ($managed->desired_state_version !== $desiredStateVersion || $managed->deleted_at) {
            return;
        }

        if (!$managed->allocation || $managed->allocation->server_id !== $managed->server_id) {
            $managed->forceFill([
                'status' => ManagedSubdomainStatus::RepairRequired,
                'last_error_code' => 'allocation_missing',
                'sanitized_error_message' => 'The associated allocation no longer exists on this server.',
            ])->save();

            return;
        }

        $policy = $this->policyResolver->resolve($managed->server, null, true);
        if (!$policy->enabled) {
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

        $attempt = $this->newAttempt($managed, 'synchronize');
        $provider = $this->providerFactory->for($managed->domain);
        $created = [];
        $steps = [];

        $managed->forceFill([
            'status' => $managed->records->isEmpty() ? ManagedSubdomainStatus::Creating : ManagedSubdomainStatus::Updating,
            'last_error_code' => null,
            'sanitized_error_message' => null,
        ])->save();

        try {
            foreach ($managed->desired_record_plan['records'] as $recordPlan) {
                $record = ManagedDnsRecord::query()->firstOrNew([
                    'managed_subdomain_id' => $managed->id,
                    'type' => $recordPlan['type'],
                    'name' => $recordPlan['name'],
                ]);

                $record->forceFill($this->localRecordData($managed, $recordPlan) + ['sync_status' => 'syncing']);
                $record->saveOrFail();

                if ($record->provider_record_id) {
                    try {
                        $provider->getRecord($managed->domain, $record->provider_record_id);
                        $response = $provider->updateRecord($managed->domain, $record->provider_record_id, $recordPlan);
                        $steps[] = 'updated:' . $response['id'];
                    } catch (DnsProviderException $exception) {
                        if ($exception->providerErrorCode !== 'provider_record_missing') {
                            throw $exception;
                        }

                        $record->forceFill(['provider_record_id' => null])->saveOrFail();
                    }
                }

                if (!$record->provider_record_id) {
                    $this->assertNoProviderConflict($managed, $recordPlan['name']);
                    $response = $provider->createRecord($managed->domain, $recordPlan);
                    $record->forceFill(['provider_record_id' => $response['id']])->saveOrFail();
                    $created[] = $record;
                    $steps[] = 'created:' . $response['id'];
                }

                $provider->getRecord($managed->domain, $record->provider_record_id);
                $record->forceFill([
                    'sync_status' => 'active',
                    'last_synchronized_at' => Carbon::now(),
                    'last_error_code' => null,
                ])->saveOrFail();

                $attempt->forceFill(['completed_steps' => $steps])->saveOrFail();
            }

            $desiredKeys = collect($managed->desired_record_plan['records'])
                ->map(fn (array $record) => $record['type'] . ':' . $record['name']);

            foreach ($managed->records()->get() as $record) {
                if ($desiredKeys->contains($record->type . ':' . $record->name) || !$record->provider_record_id) {
                    continue;
                }

                $provider->deleteRecord($managed->domain, $record->provider_record_id);
                $record->forceFill(['sync_status' => 'deleted', 'last_synchronized_at' => Carbon::now()])->saveOrFail();
                $steps[] = 'deleted-obsolete:' . $record->provider_record_id;
            }

            $managed->forceFill([
                'status' => ManagedSubdomainStatus::Active,
                'last_synchronized_at' => Carbon::now(),
                'last_error_code' => null,
                'sanitized_error_message' => null,
                'provider_drift_detected_at' => null,
            ])->saveOrFail();
            $managed->domain->forceFill([
                'last_provider_check_at' => Carbon::now(),
                'last_provider_status' => 'healthy',
                'last_provider_error_code' => null,
            ])->save();

            $attempt->forceFill([
                'status' => 'completed',
                'completed_steps' => $steps,
                'completed_at' => Carbon::now(),
            ])->saveOrFail();

            Activity::event('server:subdomain.sync-complete')
                ->anonymous()
                ->subject($managed)
                ->property(['fqdn' => $managed->fqdn, 'records' => count($steps)])
                ->log();
            Log::info('Managed DNS synchronization completed.', [
                'managed_subdomain_id' => $managed->id,
                'service_profile_id' => $managed->dns_service_profile_id,
                'record_count' => count($steps),
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (\Throwable $exception) {
            $rollbackFailed = false;
            foreach (array_reverse($created) as $record) {
                try {
                    $provider->deleteRecord($managed->domain, $record->provider_record_id);
                    $record->forceFill([
                        'provider_record_id' => null,
                        'sync_status' => 'rolled_back',
                    ])->save();
                } catch (\Throwable) {
                    $rollbackFailed = true;
                    $record->forceFill(['sync_status' => 'repair_required'])->save();
                }
            }

            $code = $exception instanceof DnsProviderException ? $exception->providerErrorCode : 'synchronization_failed';
            $message = $exception instanceof DnsProviderException
                ? $exception->getMessage()
                : 'DNS synchronization failed. Retry the operation or contact an administrator.';

            $managed->forceFill([
                'status' => $rollbackFailed ? ManagedSubdomainStatus::RepairRequired : ManagedSubdomainStatus::Failed,
                'last_error_code' => $code,
                'sanitized_error_message' => $message,
            ])->save();
            if ($exception instanceof DnsProviderException) {
                $managed->domain->forceFill([
                    'last_provider_check_at' => Carbon::now(),
                    'last_provider_status' => in_array($code, ['provider_rate_limited', 'provider_unavailable', 'provider_unreachable'], true) ? 'degraded' : 'error',
                    'last_provider_error_code' => $code,
                ])->save();
            }

            $attempt->forceFill([
                'status' => 'failed',
                'completed_steps' => $steps,
                'error_code' => $code,
                'sanitized_error_message' => $message,
                'rollback_status' => $rollbackFailed ? 'failed' : 'completed',
                'completed_at' => Carbon::now(),
            ])->save();

            Activity::event('server:subdomain.sync-failed')
                ->anonymous()
                ->subject($managed)
                ->property(['fqdn' => $managed->fqdn, 'error_code' => $code])
                ->log();
            Log::warning('Managed DNS synchronization failed.', [
                'managed_subdomain_id' => $managed->id,
                'error_code' => $code,
                'rollback_failed' => $rollbackFailed,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            throw $exception;
        }
    }

    public function delete(ManagedSubdomain $managed): void
    {
        $managed->loadMissing(['domain', 'records']);
        $attempt = $this->newAttempt($managed, 'delete');
        $provider = $this->providerFactory->for($managed->domain);
        $steps = [];

        $managed->forceFill(['status' => ManagedSubdomainStatus::Deleting])->save();

        try {
            foreach ($managed->records as $record) {
                if (!$record->provider_record_id || $record->sync_status === 'deleted') {
                    continue;
                }

                $provider->deleteRecord($managed->domain, $record->provider_record_id);
                $record->forceFill([
                    'sync_status' => 'deleted',
                    'last_synchronized_at' => Carbon::now(),
                ])->saveOrFail();
                $steps[] = 'deleted:' . $record->provider_record_id;
                $attempt->forceFill(['completed_steps' => $steps])->save();
            }

            $managed->forceFill([
                'deleted_at' => Carbon::now(),
                'last_synchronized_at' => Carbon::now(),
                'last_error_code' => null,
                'sanitized_error_message' => null,
            ])->save();
            $attempt->forceFill([
                'status' => 'completed',
                'completed_steps' => $steps,
                'completed_at' => Carbon::now(),
            ])->save();

            Activity::event('server:subdomain.delete-complete')
                ->anonymous()
                ->subject($managed)
                ->property('fqdn', $managed->fqdn)
                ->log();
        } catch (\Throwable $exception) {
            $code = $exception instanceof DnsProviderException ? $exception->providerErrorCode : 'deletion_failed';
            $managed->forceFill([
                'status' => ManagedSubdomainStatus::RepairRequired,
                'last_error_code' => $code,
                'sanitized_error_message' => 'DNS cleanup could not be confirmed. No local ownership data was removed.',
            ])->save();
            $attempt->forceFill([
                'status' => 'failed',
                'completed_steps' => $steps,
                'error_code' => $code,
                'sanitized_error_message' => $managed->sanitized_error_message,
                'completed_at' => Carbon::now(),
            ])->save();

            throw $exception;
        }
    }

    private function assertNoProviderConflict(ManagedSubdomain $managed, string $name): void
    {
        $provider = $this->providerFactory->for($managed->domain);
        $ownedIds = $managed->records()->whereNotNull('provider_record_id')->pluck('provider_record_id')->all();
        $records = [];
        $names = array_unique([
            strtolower(rtrim($name, '.')),
            strtolower(rtrim($managed->fqdn, '.')),
            '_minecraft._tcp.' . strtolower(rtrim($managed->fqdn, '.')),
            '*.' . strtolower(rtrim($managed->domain->domain, '.')),
        ]);
        foreach ($names as $candidate) {
            $records = array_merge($records, $provider->listRecords($managed->domain, $candidate));
        }

        foreach ($records as $record) {
            if (!in_array($record['id'] ?? null, $ownedIds, true)) {
                throw new DnsProviderException('record_conflict', 'This hostname is already in use. Choose another name or contact an administrator.');
            }
        }
    }

    private function newAttempt(ManagedSubdomain $managed, string $action): ManagedDnsSyncAttempt
    {
        $attempt = new ManagedDnsSyncAttempt();
        $attempt->forceFill([
            'uuid' => Uuid::uuid4()->toString(),
            'managed_subdomain_id' => $managed->id,
            'desired_state_version' => $managed->desired_state_version,
            'action' => $action,
            'status' => 'running',
            'attempt' => $managed->syncAttempts()->where('desired_state_version', $managed->desired_state_version)->count() + 1,
            'completed_steps' => [],
            'started_at' => Carbon::now(),
        ]);
        $attempt->saveOrFail();

        return $attempt;
    }

    /**
     * @param array<string, mixed> $plan
     *
     * @return array<string, mixed>
     */
    private function localRecordData(ManagedSubdomain $managed, array $plan): array
    {
        return [
            'provider' => $managed->domain->provider,
            'zone_id' => $managed->domain->zone_id,
            'content' => $plan['content'] ?? null,
            'ttl' => $plan['ttl'],
            'proxied' => false,
            'srv_priority' => $plan['priority'] ?? null,
            'srv_weight' => $plan['weight'] ?? null,
            'srv_port' => $plan['port'] ?? null,
            'srv_target' => $plan['target'] ?? null,
        ];
    }
}
