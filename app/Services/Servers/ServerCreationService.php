<?php

namespace Pterodactyl\Services\Servers;

use Ramsey\Uuid\Uuid;
use Illuminate\Support\Arr;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\User;
use Webmozart\Assert\Assert;
use Pterodactyl\Models\Server;
use Illuminate\Support\Collection;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Enum\SubdomainPolicy;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Enum\SubdomainCompatibility;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Models\Objects\DeploymentObject;
use Pterodactyl\Repositories\Eloquent\ServerRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Services\Deployment\FindViableNodesService;
use Pterodactyl\Repositories\Eloquent\ServerVariableRepository;
use Pterodactyl\Services\Deployment\AllocationSelectionService;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Service\Deployment\NoViableAllocationException;

class ServerCreationService
{
    /**
     * ServerCreationService constructor.
     */
    public function __construct(
        private AllocationSelectionService $allocationSelectionService,
        private ConnectionInterface $connection,
        private DaemonServerRepository $daemonServerRepository,
        private FindViableNodesService $findViableNodesService,
        private ServerRepository $repository,
        private ServerDeletionService $serverDeletionService,
        private ServerVariableRepository $serverVariableRepository,
        private VariableValidatorService $validatorService,
    ) {
    }

    /**
     * Create a server on the Panel and trigger a request to the Daemon to begin the server
     * creation process. This function will attempt to set as many additional values
     * as possible given the input data. For example, if an allocation_id is passed with
     * no node_id the node_is will be picked from the allocation.
     *
     * @throws \Throwable
     * @throws DisplayException
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Pterodactyl\Exceptions\Repository\RecordNotFoundException
     * @throws \Pterodactyl\Exceptions\Service\Deployment\NoViableNodeException
     * @throws NoViableAllocationException
     */
    public function handle(array $data, ?DeploymentObject $deployment = null): Server
    {
        Assert::false(empty($data['egg_id']), 'Expected a non-empty egg_id in server creation data.');
        $egg = Egg::query()->with('variables')->findOrFail(Arr::get($data, 'egg_id'));

        // If a deployment object has been passed we need to get the allocation
        // that the server should use, and assign the node from that allocation.
        if ($deployment instanceof DeploymentObject) {
            $allocation = $this->configureDeployment($data, $deployment, $egg->initial_allocation_count);
            $data['allocation_id'] = $allocation->id;
            $data['node_id'] = $allocation->node_id;
        }

        // Auto-configure the node based on the selected allocation
        // if no node was defined.
        if (empty($data['node_id'])) {
            Assert::false(empty($data['allocation_id']), 'Expected a non-empty allocation_id in server creation data.');

            $data['node_id'] = Allocation::query()->findOrFail($data['allocation_id'])->node_id;
        }

        if (empty($data['nest_id'])) {
            $data['nest_id'] = $egg->nest_id;
        }

        if (
            Arr::get($data, 'subdomain_policy', SubdomainPolicy::Inherit->value) === SubdomainPolicy::Enabled->value
            && $egg->subdomain_compatibility !== SubdomainCompatibility::Compatible->value
        ) {
            throw new DisplayException('Managed subdomains cannot be enabled because the selected egg is marked incompatible.');
        }
        if (
            Arr::get($data, 'subdomain_policy', SubdomainPolicy::Inherit->value) === SubdomainPolicy::Enabled->value
            && !$egg->subdomain_server_override_allowed
            && $egg->subdomain_default_policy !== SubdomainPolicy::Enabled->value
        ) {
            throw new DisplayException('The selected egg does not allow a server-level managed-subdomain override.');
        }
        if (
            Arr::get($data, 'dns_service_profile_id')
            && !$egg->subdomain_server_override_allowed
            && (int) Arr::get($data, 'dns_service_profile_id') !== $egg->dns_service_profile_id
        ) {
            throw new DisplayException('The selected egg does not allow a server-level DNS service-profile override.');
        }

        // Due to the design of the Daemon, we need to persist this server to the disk
        // before we can actually create it on the Daemon.
        //
        // If that connection fails out we will attempt to perform a cleanup by just
        // deleting the server itself from the system.
        /** @var Server $server */
        $server = $this->connection->transaction(function () use ($data, $egg, $deployment) {
            $data = $this->configureInitialAllocations(
                $egg,
                $data,
                $deployment instanceof DeploymentObject && $deployment->isDedicated()
            );

            $eggVariableData = $this->validatorService
                ->setUserLevel(User::USER_LEVEL_ADMIN)
                ->handle($egg->id, Arr::get($data, 'environment', []));

            // Create the server and assign any additional allocations to it.
            $server = $this->createModel($data);

            $this->storeAssignedAllocations($server, $data);
            $this->storeEggVariables($server, $eggVariableData);

            return $server;
        }, 5);

        try {
            $this->daemonServerRepository->setServer($server)->create(
                Arr::get($data, 'start_on_completion', false) ?? false
            );
        } catch (DaemonConnectionException $exception) {
            $this->serverDeletionService->withForce()->handle($server);

            throw $exception;
        }

        return $server;
    }

    /**
     * Ensure that a new server has the minimum number of allocations required by its egg and
     * copy mapped allocation ports into the corresponding egg environment variables.
     *
     * Allocation indexes on egg variables are one-based: index 1 is the primary allocation,
     * while indexes 2 and above refer to additional allocations in assignment order.
     *
     * @throws NoViableAllocationException
     */
    private function configureInitialAllocations(Egg $egg, array $data, bool $dedicated): array
    {
        $required = max(1, $egg->initial_allocation_count);
        $primaryId = (int) Arr::get($data, 'allocation_id');
        $additionalIds = collect(Arr::get($data, 'allocation_additional', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0 && $id !== $primaryId)
            ->unique()
            ->values();
        $selectedIds = collect([$primaryId])->merge($additionalIds);

        /** @var Collection<int, Allocation> $provided */
        $provided = Allocation::query()
            ->whereIn('id', $selectedIds->all())
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        /** @var Allocation|null $primary */
        $primary = $provided->get($primaryId);
        if (is_null($primary) || !is_null($primary->server_id)) {
            throw new NoViableAllocationException(trans('exceptions.deployment.no_viable_allocations'));
        }

        foreach ($selectedIds as $id) {
            /** @var Allocation|null $allocation */
            $allocation = $provided->get($id);
            if (
                is_null($allocation)
                || !is_null($allocation->server_id)
                || $allocation->node_id !== (int) Arr::get($data, 'node_id')
            ) {
                throw new NoViableAllocationException(trans('exceptions.deployment.no_viable_allocations'));
            }
        }

        $missing = max(0, $required - $selectedIds->count());
        if ($missing > 0) {
            $query = Allocation::query()
                ->whereNull('server_id')
                ->where('node_id', $primary->node_id)
                ->whereNotIn('id', $selectedIds->all());

            if ($dedicated) {
                $query->where('ip', $primary->ip);
            } else {
                // Prefer allocations on the primary IP, but allow another IP on the same node
                // when necessary for non-dedicated deployments.
                $query->orderByRaw('CASE WHEN ip = ? THEN 0 ELSE 1 END', [$primary->ip]);
            }

            $automatic = $query->orderBy('port')->lockForUpdate()->limit($missing)->get();
            $selectedIds = $selectedIds->merge($automatic->pluck('id'));
        }

        if ($selectedIds->count() < $required) {
            throw new NoViableAllocationException(trans('exceptions.deployment.insufficient_initial_allocations', ['required' => $required, 'available' => $selectedIds->count()]));
        }

        /** @var Collection<int, Allocation> $allocations */
        $allocations = Allocation::query()->whereIn('id', $selectedIds->all())->get()->keyBy('id');
        $ordered = $selectedIds->map(fn ($id) => $allocations->get($id));

        $environment = Arr::get($data, 'environment', []);
        foreach ($egg->variables as $variable) {
            if (is_null($variable->allocation_index)) {
                continue;
            }

            if ($variable->allocation_index < 1 || $variable->allocation_index > $required) {
                throw new DisplayException(sprintf('Egg variable %s references allocation %d, but the egg only requires %d allocations.', $variable->env_variable, $variable->allocation_index, $required));
            }

            /** @var Allocation $allocation */
            $allocation = $ordered->get($variable->allocation_index - 1);
            $environment[$variable->env_variable] = (string) $allocation->port;
        }

        $data['environment'] = $environment;
        $data['allocation_additional'] = $selectedIds->slice(1)->values()->all();

        return $data;
    }

    /**
     * Gets an allocation to use for automatic deployment.
     *
     * @throws DisplayException
     * @throws NoViableAllocationException
     * @throws \Pterodactyl\Exceptions\Service\Deployment\NoViableNodeException
     */
    private function configureDeployment(array $data, DeploymentObject $deployment, int $requiredAllocations): Allocation
    {
        /** @var Collection $nodes */
        $nodes = $this->findViableNodesService->setLocations($deployment->getLocations())
            ->setDisk(Arr::get($data, 'disk'))
            ->setMemory(Arr::get($data, 'memory'))
            ->handle();

        return $this->allocationSelectionService->setDedicated($deployment->isDedicated())
            ->setNodes($nodes->pluck('id')->toArray())
            ->setRequiredAllocations($requiredAllocations)
            ->setPorts($deployment->getPorts())
            ->handle();
    }

    /**
     * Store the server in the database and return the model.
     *
     * @throws \Pterodactyl\Exceptions\Model\DataValidationException
     */
    private function createModel(array $data): Server
    {
        $uuid = $this->generateUniqueUuidCombo();

        /** @var Server $model */
        $model = $this->repository->create([
            'external_id' => Arr::get($data, 'external_id'),
            'uuid' => $uuid,
            'uuidShort' => substr($uuid, 0, 8),
            'node_id' => Arr::get($data, 'node_id'),
            'name' => Arr::get($data, 'name'),
            'description' => Arr::get($data, 'description') ?? '',
            'status' => Server::STATUS_INSTALLING,
            'skip_scripts' => Arr::get($data, 'skip_scripts') ?? isset($data['skip_scripts']),
            'owner_id' => Arr::get($data, 'owner_id'),
            'memory' => Arr::get($data, 'memory'),
            'swap' => Arr::get($data, 'swap'),
            'disk' => Arr::get($data, 'disk'),
            'io' => Arr::get($data, 'io'),
            'cpu' => Arr::get($data, 'cpu'),
            'threads' => Arr::get($data, 'threads'),
            'oom_disabled' => Arr::get($data, 'oom_disabled') ?? true,
            'allocation_id' => Arr::get($data, 'allocation_id'),
            'nest_id' => Arr::get($data, 'nest_id'),
            'egg_id' => Arr::get($data, 'egg_id'),
            'startup' => Arr::get($data, 'startup'),
            'image' => Arr::get($data, 'image'),
            'database_limit' => Arr::get($data, 'database_limit') ?? 0,
            'allocation_limit' => Arr::get($data, 'allocation_limit') ?? 0,
            'backup_limit' => Arr::get($data, 'backup_limit') ?? 0,
            'game_slot_limit' => max(1, (int) (Arr::get($data, 'game_slot_limit') ?? 1)),
            'subdomain_policy' => Arr::get($data, 'subdomain_policy') ?? SubdomainPolicy::Inherit->value,
            'subdomain_limit' => max(0, (int) (Arr::get($data, 'subdomain_limit') ?? 0)),
            'dns_service_profile_id' => Arr::get($data, 'dns_service_profile_id'),
            'subdomain_domain_restrictions' => Arr::get($data, 'subdomain_domain_restrictions'),
            'subdomain_policy_source' => Arr::get($data, 'subdomain_policy_source'),
            'subdomain_admin_notes' => Arr::get($data, 'subdomain_admin_notes'),
        ]);

        return $model;
    }

    /**
     * Configure the allocations assigned to this server.
     */
    private function storeAssignedAllocations(Server $server, array $data): void
    {
        $records = [$data['allocation_id']];
        if (isset($data['allocation_additional']) && is_array($data['allocation_additional'])) {
            $records = array_merge($records, $data['allocation_additional']);
        }

        Allocation::query()->whereIn('id', $records)->update([
            'server_id' => $server->id,
        ]);
    }

    /**
     * Process environment variables passed for this server and store them in the database.
     */
    private function storeEggVariables(Server $server, Collection $variables): void
    {
        $records = $variables->map(function ($result) use ($server) {
            return [
                'server_id' => $server->id,
                'variable_id' => $result->id,
                'variable_value' => $result->value ?? '',
            ];
        })->toArray();

        if (!empty($records)) {
            $this->serverVariableRepository->insert($records);
        }
    }

    /**
     * Create a unique UUID and UUID-Short combo for a server.
     */
    private function generateUniqueUuidCombo(): string
    {
        $uuid = Uuid::uuid4()->toString();

        if (!$this->repository->isUniqueUuidCombo($uuid, substr($uuid, 0, 8))) {
            return $this->generateUniqueUuidCombo();
        }

        return $uuid;
    }
}
