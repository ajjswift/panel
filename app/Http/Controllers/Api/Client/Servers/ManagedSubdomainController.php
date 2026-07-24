<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Allocation;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Models\ManagedSubdomain;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Models\ManagedDnsSyncAttempt;
use Pterodactyl\Services\Dns\DnsTargetResolver;
use Pterodactyl\Jobs\Dns\SyncManagedSubdomainJob;
use Pterodactyl\Jobs\Dns\DeleteManagedSubdomainJob;
use Pterodactyl\Jobs\Dns\RefreshManagedSubdomainJob;
use Pterodactyl\Services\Dns\SubdomainPolicyResolver;
use Pterodactyl\Jobs\ReverseProxy\SyncNodeReverseProxyJob;
use Pterodactyl\Services\Dns\ManagedSubdomainPreviewService;
use Pterodactyl\Services\Dns\ManagedSubdomainCreationService;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Transformers\Api\Client\ManagedSubdomainTransformer;
use Pterodactyl\Http\Requests\Api\Client\Servers\Network\GetNetworkOverviewRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Network\GetManagedSubdomainRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Network\StoreManagedSubdomainRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Network\DeleteManagedSubdomainRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Network\RepairManagedSubdomainRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Network\UpdateManagedSubdomainRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Network\PreviewManagedSubdomainRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Network\RefreshManagedSubdomainRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Network\ReassignManagedSubdomainRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Network\GetManagedSubdomainHistoryRequest;

class ManagedSubdomainController extends ClientApiController
{
    public function __construct(
        private SubdomainPolicyResolver $policyResolver,
        private DnsTargetResolver $targetResolver,
        private ManagedSubdomainPreviewService $previewService,
        private ManagedSubdomainCreationService $creationService,
    ) {
        parent::__construct();
    }

    public function overview(GetNetworkOverviewRequest $request, Server $server): array
    {
        $policy = $this->policyResolver->resolve($server, $request->user());
        $primary = $server->allocation;
        $hostnames = $server->managedSubdomains()->whereNull('deleted_at');
        $policyData = $policy->toArray();
        if (!$policy->canView) {
            $policyData['eligible_domains'] = [];
            $policyData['service_profile'] = null;
            $policyData['used'] = 0;
            $policyData['remaining'] = 0;
        }

        return [
            'policy' => $policyData,
            'primary_allocation' => $primary ? [
                'id' => $primary->id,
                'ip' => $primary->ip,
                'alias' => $primary->ip_alias,
                'port' => $primary->port,
            ] : null,
            'allocation_count' => $server->allocations()->count(),
            'hostname_count' => $policy->canView ? (clone $hostnames)->count() : null,
            'attention_count' => $policy->canView ? (clone $hostnames)->whereNotIn('status', ['active', 'pending', 'creating', 'updating'])->count() : null,
            'dns_health' => $policy->canView ? ((clone $hostnames)->whereNotIn('status', ['active'])->exists() ? 'attention' : 'healthy') : null,
            'active_game_slot' => $server->activeGameSlot()->value('name'),
            'reverse_proxy_available' => $server->node->reverse_proxy_enabled
                && $server->node->dns_target_ipv4
                && $this->targetResolver->isPublicIp($server->node->dns_target_ipv4),
        ];
    }

    public function index(GetManagedSubdomainRequest $request, Server $server): array
    {
        return $this->fractal->collection(
            $server->managedSubdomains()->whereNull('deleted_at')->latest()->get()
        )->transformWith($this->getTransformer(ManagedSubdomainTransformer::class))->toArray();
    }

    public function preview(PreviewManagedSubdomainRequest $request, Server $server): array
    {
        $domain = ManagedDomain::query()->where('uuid', $request->input('domain_uuid'))->firstOrFail();
        $allocation = Allocation::query()->findOrFail($request->input('allocation_id'));

        return $this->previewService->handle($server, $request->user(), $domain, $allocation, $request->input('label'));
    }

    public function store(StoreManagedSubdomainRequest $request, Server $server): array
    {
        $domain = ManagedDomain::query()->where('uuid', $request->input('domain_uuid'))->firstOrFail();
        $allocation = Allocation::query()->findOrFail($request->input('allocation_id'));
        $managed = $this->creationService->handle(
            $server,
            $request->user(),
            $domain,
            $allocation,
            $request->input('label'),
        );

        return $this->fractal->item($managed)
            ->transformWith($this->getTransformer(ManagedSubdomainTransformer::class))
            ->toArray();
    }

    public function reassign(
        ReassignManagedSubdomainRequest $request,
        Server $server,
        ManagedSubdomain $managedSubdomain,
    ): array {
        $allocation = Allocation::query()->findOrFail($request->input('allocation_id'));
        $preview = $this->previewService->handle(
            $server,
            $request->user(),
            $managedSubdomain->domain,
            $allocation,
            $managedSubdomain->label,
            false,
        );

        Activity::event('server:subdomain.reassign')
            ->subject($managedSubdomain)
            ->property([
                'fqdn' => $managedSubdomain->fqdn,
                'old_allocation_id' => $managedSubdomain->allocation_id,
                'new_allocation_id' => $allocation->id,
            ])
            ->transaction(function () use ($managedSubdomain, $allocation, $preview) {
                $isProxy = ($preview['record_plan']['access_method'] ?? 'clean') === 'proxy';
                $detectedByHttpProbe = ($preview['record_plan']['web_detection_source'] ?? null) === 'http_probe';
                $detectedScheme = $preview['record_plan']['proxy_target_scheme'] ?? null;
                $managedSubdomain->forceFill([
                    'allocation_id' => $allocation->id,
                    'dns_service_profile_id' => $preview['service_profile']['id'],
                    'routing_mode' => $isProxy ? 'reverse_proxy' : 'direct_dns',
                    'detected_service' => $detectedByHttpProbe
                        ? sprintf('%s website', strtoupper($detectedScheme ?: 'HTTP'))
                        : $preview['service_profile']['name'],
                    'service_detection_source' => $detectedByHttpProbe
                        ? 'http_probe'
                        : $preview['service_profile']['detection_source'],
                    'desired_state_version' => $managedSubdomain->desired_state_version + 1,
                    'public_target_type' => $preview['public_target']['type'],
                    'public_target' => $preview['public_target']['value'],
                    'target_port' => $allocation->port,
                    'connection_address' => $preview['record_plan']['connection_address'],
                    'desired_record_plan' => $preview['record_plan'],
                    'status' => 'pending',
                    'proxy_dns_status' => null,
                    'proxy_cert_status' => null,
                    'proxy_status' => null,
                    'proxy_cert_expires_at' => null,
                    'proxy_reported_at' => null,
                ])->saveOrFail();
            });

        SyncManagedSubdomainJob::dispatch($managedSubdomain->id, $managedSubdomain->desired_state_version);
        SyncNodeReverseProxyJob::dispatch($server->node_id);

        return $this->fractal->item($managedSubdomain->refresh())
            ->transformWith($this->getTransformer(ManagedSubdomainTransformer::class))
            ->toArray();
    }

    public function update(
        UpdateManagedSubdomainRequest $request,
        Server $server,
        ManagedSubdomain $managedSubdomain,
    ): array {
        if (!$managedSubdomain->allocation) {
            throw new DisplayException('This hostname cannot be edited until its missing allocation is repaired.');
        }

        $preview = $this->previewService->handle(
            $server,
            $request->user(),
            $managedSubdomain->domain,
            $managedSubdomain->allocation,
            $request->input('label'),
            false,
        );
        $lock = Cache::lock('managed-dns:fqdn:' . $preview['fqdn'], 30);
        if (!$lock->get()) {
            throw new DisplayException('This hostname is currently being changed by another request. Try again shortly.');
        }

        try {
            if (ManagedSubdomain::query()->where('fqdn', $preview['fqdn'])->where('id', '!=', $managedSubdomain->id)->exists()) {
                throw new DisplayException('This hostname is already in use. Choose another name.');
            }

            Activity::event('server:subdomain.update')
                ->subject($managedSubdomain)
                ->property(['old_fqdn' => $managedSubdomain->fqdn, 'new_fqdn' => $preview['fqdn']])
                ->transaction(function () use ($managedSubdomain, $preview) {
                    $managedSubdomain->forceFill([
                        'label' => $preview['label'],
                        'fqdn' => $preview['fqdn'],
                        'desired_state_version' => $managedSubdomain->desired_state_version + 1,
                        'connection_address' => $preview['record_plan']['connection_address'],
                        'desired_record_plan' => $preview['record_plan'],
                        'status' => 'pending',
                        'proxy_dns_status' => null,
                        'proxy_cert_status' => null,
                        'proxy_status' => null,
                        'proxy_cert_expires_at' => null,
                        'proxy_reported_at' => null,
                    ])->saveOrFail();
                });
        } finally {
            $lock->release();
        }

        SyncManagedSubdomainJob::dispatch($managedSubdomain->id, $managedSubdomain->desired_state_version);

        return $this->fractal->item($managedSubdomain->refresh())
            ->transformWith($this->getTransformer(ManagedSubdomainTransformer::class))
            ->toArray();
    }

    public function repair(
        RepairManagedSubdomainRequest $request,
        Server $server,
        ManagedSubdomain $managedSubdomain,
    ): JsonResponse {
        $policy = $this->policyResolver->resolve($server, $request->user(), true);
        if (!$policy->enabled && !$request->user()->root_admin) {
            throw new DisplayException($policy->disabledReason ?? 'DNS repair is not currently available.');
        }

        $managedSubdomain->forceFill(['status' => 'pending'])->save();
        SyncManagedSubdomainJob::dispatch($managedSubdomain->id, $managedSubdomain->desired_state_version);

        Activity::event('server:subdomain.repair')->subject($managedSubdomain)->property('fqdn', $managedSubdomain->fqdn)->log();

        return new JsonResponse([], JsonResponse::HTTP_ACCEPTED);
    }

    public function refresh(
        RefreshManagedSubdomainRequest $request,
        Server $server,
        ManagedSubdomain $managedSubdomain,
    ): JsonResponse {
        RefreshManagedSubdomainJob::dispatch($managedSubdomain->id);

        return new JsonResponse([], JsonResponse::HTTP_ACCEPTED);
    }

    public function delete(
        DeleteManagedSubdomainRequest $request,
        Server $server,
        ManagedSubdomain $managedSubdomain,
    ): JsonResponse {
        if ($managedSubdomain->status->value === 'deleting') {
            return new JsonResponse([], JsonResponse::HTTP_ACCEPTED);
        }

        $managedSubdomain->forceFill(['status' => 'deleting'])->save();
        DeleteManagedSubdomainJob::dispatch($managedSubdomain->id);

        Activity::event('server:subdomain.delete')
            ->subject($managedSubdomain)
            ->property('fqdn', $managedSubdomain->fqdn)
            ->log();

        return new JsonResponse([], JsonResponse::HTTP_ACCEPTED);
    }

    public function history(
        GetManagedSubdomainHistoryRequest $request,
        Server $server,
        ManagedSubdomain $managedSubdomain,
    ): array {
        return [
            'data' => $managedSubdomain->syncAttempts()->latest()->limit(50)->get()->map(fn (ManagedDnsSyncAttempt $attempt) => [
                'uuid' => $attempt->uuid,
                'action' => $attempt->action,
                'status' => $attempt->status,
                'attempt' => $attempt->attempt,
                'error_code' => $attempt->error_code,
                'error_message' => $attempt->sanitized_error_message,
                'started_at' => $attempt->started_at?->toAtomString(),
                'completed_at' => $attempt->completed_at?->toAtomString(),
            ])->all(),
        ];
    }
}
