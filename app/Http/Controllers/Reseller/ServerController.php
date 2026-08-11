<?php

namespace Pterodactyl\Http\Controllers\Reseller;

use Illuminate\View\View;
use Illuminate\Http\Request;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Allocation;
use Illuminate\Http\RedirectResponse;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Resellers\ResellerContext;
use Pterodactyl\Services\Servers\SuspensionService;
use Pterodactyl\Repositories\Eloquent\NestRepository;
use Pterodactyl\Services\Servers\ServerCreationService;
use Pterodactyl\Services\Servers\ServerDeletionService;
use Pterodactyl\Services\Resellers\ResellerQuotaService;
use Pterodactyl\Http\Requests\Reseller\ServerFormRequest;
use Pterodactyl\Services\Servers\BuildModificationService;
use Pterodactyl\Services\Servers\DetailsModificationService;
use Pterodactyl\Http\Requests\Reseller\ServerBuildFormRequest;
use Pterodactyl\Http\Requests\Reseller\ServerDetailsFormRequest;

class ServerController extends Controller
{
    public function __construct(
        private AlertsMessageBag $alert,
        private BuildModificationService $buildModificationService,
        private ResellerContext $context,
        private DetailsModificationService $detailsModificationService,
        private ServerCreationService $creationService,
        private ServerDeletionService $deletionService,
        private NestRepository $nestRepository,
        private ResellerQuotaService $quota,
        private SuspensionService $suspensionService,
    ) {
    }

    public function index(Request $request): View
    {
        $servers = $this->context->servers()
            ->with('node', 'user', 'allocation')
            ->when($request->input('filter.owner_id'), function ($query, $owner) {
                $query->where('owner_id', $owner);
            })
            ->when($request->input('filter.name'), function ($query, $name) {
                $query->where('name', 'LIKE', "%$name%");
            })
            ->orderBy('id')
            ->paginate(config()->get('pterodactyl.paginate.admin.servers'));

        return view('reseller.servers.index', ['servers' => $servers]);
    }

    public function create(): View|RedirectResponse
    {
        $nodes = $this->context->nodes()->with('allocations')->get();
        if ($nodes->isEmpty()) {
            $this->alert->warning('No nodes have been made available to you yet — contact the panel administrator.')->flash();

            return redirect()->route('reseller.index');
        }

        // getWithEggs() eager-loads `eggs.variables`, which new-server.js needs to
        // render the Service Variables inputs. A plain with('eggs') leaves that
        // relation off the payload and the section renders empty.
        $nests = $this->nestRepository->getWithEggs();

        \JavaScript::put([
            'nodeData' => $this->nodeDataForSelects($nodes),
            'nests' => $nests->map(function (Nest $item) {
                return array_merge($item->toArray(), [
                    'eggs' => $item->eggs->keyBy('id')->toArray(),
                ]);
            })->keyBy('id'),
        ]);

        return view('reseller.servers.new', [
            'nodes' => $nodes,
            'nests' => $nests,
            'owners' => $this->context->users()->orderBy('username')->get(),
        ]);
    }

    /**
     * @throws \Throwable
     */
    public function store(ServerFormRequest $request): RedirectResponse
    {
        $data = $request->except(['_token']);
        if (!empty($data['custom_image'])) {
            $data['image'] = $data['custom_image'];
            unset($data['custom_image']);
        }

        $this->quota->assertCanAllocateServer($this->context->reseller(), $data);

        $server = $this->creationService->handle($data);

        $this->alert->success('The server has been created and is now installing.')->flash();

        return redirect()->route('reseller.servers.view', $server->id);
    }

    public function view(int $server): View
    {
        return view('reseller.servers.view', [
            'server' => $this->context->findServer($server)->load('node', 'user', 'allocation', 'egg'),
            'owners' => $this->context->users()->orderBy('username')->get(),
        ]);
    }

    /**
     * @throws \Throwable
     */
    public function updateDetails(ServerDetailsFormRequest $request, int $server): RedirectResponse
    {
        $model = $this->context->findServer($server);

        $this->detailsModificationService->handle($model, $request->normalize());

        $this->alert->success('The server details have been updated.')->flash();

        return redirect()->route('reseller.servers.view', $model->id);
    }

    /**
     * @throws \Throwable
     */
    public function updateBuild(ServerBuildFormRequest $request, int $server): RedirectResponse
    {
        $model = $this->context->findServer($server);

        // Pass the server being edited so its current consumption is refunded
        // before the new figures are checked — otherwise shrinking a server
        // could still trip the cap.
        $this->quota->assertCanAllocateServer($this->context->reseller(), $request->normalize(), $model);

        $this->buildModificationService->handle($model, $request->normalize());

        $this->alert->success('The server build has been updated.')->flash();

        return redirect()->route('reseller.servers.view', $model->id);
    }

    /**
     * @throws \Throwable
     */
    public function toggleSuspension(Request $request, int $server): RedirectResponse
    {
        $model = $this->context->findServer($server);
        $action = $request->input('action') === SuspensionService::ACTION_UNSUSPEND
            ? SuspensionService::ACTION_UNSUSPEND
            : SuspensionService::ACTION_SUSPEND;

        $this->suspensionService->toggle($model, $action);

        $this->alert->success('The server has been ' . $action . 'ed.')->flash();

        return redirect()->route('reseller.servers.view', $model->id);
    }

    /**
     * @throws \Throwable
     */
    public function delete(int $server): RedirectResponse
    {
        $model = $this->context->findServer($server);

        // Never force: a reseller must not be able to leave orphaned files on a
        // node by deleting through a failing daemon.
        $this->deletionService->handle($model);

        $this->alert->success('The server has been deleted.')->flash();

        return redirect()->route('reseller.servers');
    }

    /**
     * Same shape as NodeRepository::getNodesForServerCreation(), but limited to
     * the nodes this reseller has been granted.
     *
     * @param \Illuminate\Database\Eloquent\Collection<int, Node> $nodes
     *
     * @return array<int, array{id: int, text: string, allocations: array<int, array{id: int, text: string}>}>
     */
    private function nodeDataForSelects($nodes): array
    {
        return $nodes->map(function (Node $node) {
            return [
                'id' => (int) $node->id,
                'text' => (string) $node->name,
                'allocations' => $node->allocations
                    ->where('server_id', null)
                    ->map(fn (Allocation $allocation) => [
                        'id' => (int) $allocation->id,
                        'text' => sprintf('%s:%s', $allocation->ip, $allocation->port),
                    ])->values()->all(),
            ];
        })->values()->all();
    }
}
