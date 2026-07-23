<?php

namespace Pterodactyl\Services\Servers;

use Illuminate\Support\Arr;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Allocation;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Enum\SubdomainPolicy;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Enum\SubdomainCompatibility;
use Pterodactyl\Exceptions\DisplayException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Services\GameSlots\GameSlotLimitReconciliationService;

class BuildModificationService
{
    /**
     * BuildModificationService constructor.
     */
    public function __construct(
        private ConnectionInterface $connection,
        private DaemonServerRepository $daemonServerRepository,
        private ServerConfigurationStructureService $structureService,
        private GameSlotLimitReconciliationService $slotReconciliationService,
    ) {
    }

    /**
     * Change the build details for a specified server.
     *
     * @throws \Throwable
     * @throws DisplayException
     */
    public function handle(Server $server, array $data): Server
    {
        /** @var Server $server */
        $server = $this->connection->transaction(function () use ($server, $data) {
            $this->processAllocations($server, $data);

            if (isset($data['allocation_id']) && $data['allocation_id'] != $server->allocation_id) {
                try {
                    Allocation::query()->where('id', $data['allocation_id'])->where('server_id', $server->id)->firstOrFail();
                } catch (ModelNotFoundException) {
                    throw new DisplayException('The requested default allocation is not currently assigned to this server.');
                }
            }

            // If any of these values are passed through in the data array go ahead and set
            // them correctly on the server model.
            $merge = Arr::only($data, ['oom_disabled', 'memory', 'swap', 'io', 'cpu', 'threads', 'disk', 'allocation_id']);
            $subdomainPolicy = Arr::get($data, 'subdomain_policy', $server->subdomain_policy);
            if (
                $subdomainPolicy === SubdomainPolicy::Enabled->value
                && $server->egg->subdomain_compatibility !== SubdomainCompatibility::Compatible->value
            ) {
                throw new DisplayException('Managed subdomains cannot be enabled because this server egg is marked incompatible.');
            }
            if (
                $subdomainPolicy === SubdomainPolicy::Enabled->value
                && !$server->egg->subdomain_server_override_allowed
                && $server->egg->subdomain_default_policy !== SubdomainPolicy::Enabled->value
            ) {
                throw new DisplayException('This egg does not allow a server-level managed-subdomain override.');
            }
            if (
                Arr::get($data, 'dns_service_profile_id')
                && !$server->egg->subdomain_server_override_allowed
                && (int) Arr::get($data, 'dns_service_profile_id') !== $server->egg->dns_service_profile_id
            ) {
                throw new DisplayException('This egg does not allow a server-level DNS service-profile override.');
            }

            $server->forceFill(array_merge($merge, [
                'database_limit' => Arr::get($data, 'database_limit', 0) ?? null,
                'allocation_limit' => Arr::get($data, 'allocation_limit', 0) ?? null,
                'backup_limit' => Arr::get($data, 'backup_limit', 0) ?? 0,
                // A slot limit of at least 1 is always enforced; reducing the
                // limit never deletes existing slots (see the reconciliation in
                // the game-slot services), it only prevents new ones.
                'game_slot_limit' => max(1, (int) Arr::get($data, 'game_slot_limit', $server->game_slot_limit)),
                'subdomain_policy' => $subdomainPolicy,
                'subdomain_limit' => max(0, (int) Arr::get($data, 'subdomain_limit', $server->subdomain_limit)),
                'dns_service_profile_id' => Arr::get($data, 'dns_service_profile_id', $server->dns_service_profile_id) ?: null,
                'subdomain_domain_restrictions' => Arr::has($data, 'subdomain_domain_restrictions')
                    ? array_values(array_filter(array_map('intval', (array) $data['subdomain_domain_restrictions'])))
                    : $server->subdomain_domain_restrictions,
                'subdomain_policy_source' => Arr::get($data, 'subdomain_policy_source', $server->subdomain_policy_source),
                'subdomain_admin_notes' => Arr::get($data, 'subdomain_admin_notes', $server->subdomain_admin_notes),
            ]))->saveOrFail();

            // Reconcile slot state with the (possibly reduced) allowance without
            // ever deleting customer data.
            if ($server->usesGameSlots()) {
                $this->slotReconciliationService->handle($server);
            }

            return $server->refresh();
        });

        $updateData = $this->structureService->handle($server);

        // Because Wings always fetches an updated configuration from the Panel when booting
        // a server this type of exception can be safely "ignored" and just written to the logs.
        // Ideally this request succeeds, so we can apply resource modifications on the fly, but
        // if it fails we can just continue on as normal.
        if (!empty($updateData['build'])) {
            try {
                $this->daemonServerRepository->setServer($server)->sync();
            } catch (DaemonConnectionException $exception) {
                Log::warning($exception, ['server_id' => $server->id]);
            }
        }

        return $server;
    }

    /**
     * Process the allocations being assigned in the data and ensure they are available for a server.
     *
     * @throws DisplayException
     */
    private function processAllocations(Server $server, array &$data): void
    {
        if (empty($data['add_allocations']) && empty($data['remove_allocations'])) {
            return;
        }

        // Handle the addition of allocations to this server. Only assign allocations that are not currently
        // assigned to a different server, and only allocations on the same node as the server.
        if (!empty($data['add_allocations'])) {
            $query = Allocation::query()
                ->where('node_id', $server->node_id)
                ->whereIn('id', $data['add_allocations'])
                ->whereNull('server_id');

            // Keep track of all the allocations we're just now adding so that we can use the first
            // one to reset the default allocation to.
            $freshlyAllocated = $query->pluck('id')->first(); // @phpstan-ignore larastan.noUnnecessaryCollectionCall

            $query->update(['server_id' => $server->id, 'notes' => null]);
        }

        if (!empty($data['remove_allocations'])) {
            foreach ($data['remove_allocations'] as $allocation) {
                // If we are attempting to remove the default allocation for the server, see if we can reassign
                // to the first provided value in add_allocations. If there is no new first allocation then we
                // will throw an exception back.
                if ($allocation === ($data['allocation_id'] ?? $server->allocation_id)) {
                    if (empty($freshlyAllocated)) {
                        throw new DisplayException('You are attempting to delete the default allocation for this server but there is no fallback allocation to use.');
                    }

                    // Update the default allocation to be the first allocation that we are creating.
                    $data['allocation_id'] = $freshlyAllocated;
                }
            }

            // Remove any of the allocations we got that are currently assigned to this server on
            // this node. Also set the notes to null, otherwise when re-allocated to a new server those
            // notes will be carried over.
            Allocation::query()->where('node_id', $server->node_id)
                ->where('server_id', $server->id)
                // Only remove the allocations that we didn't also attempt to add to the server...
                ->whereIn('id', array_diff($data['remove_allocations'], $data['add_allocations'] ?? []))
                ->update([
                    'notes' => null,
                    'server_id' => null,
                ]);
        }
    }
}
