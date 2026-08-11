<?php

namespace Pterodactyl\Http\Controllers\Admin;

use Ramsey\Uuid\Uuid;
use Illuminate\View\View;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Reseller;
use Illuminate\Http\RedirectResponse;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Http\Requests\Admin\ResellerFormRequest;
use Pterodactyl\Services\Resellers\ResellerQuotaService;

class ResellerController extends Controller
{
    public function __construct(
        private AlertsMessageBag $alert,
        private ResellerQuotaService $quota,
    ) {
    }

    public function index(): View
    {
        $resellers = Reseller::query()
            ->with('owner')
            ->withCount('tenants')
            ->orderBy('name')
            ->paginate(50);

        return view('admin.resellers.index', [
            'resellers' => $resellers,
            'quota' => $this->quota,
        ]);
    }

    public function create(): View
    {
        return view('admin.resellers.new', [
            'nodes' => Node::query()->orderBy('name')->get(),
            // Only accounts that are neither an admin, an existing reseller, nor
            // somebody else's tenant can be promoted.
            'candidates' => User::query()
                ->where('root_admin', false)
                ->whereNull('reseller_id')
                ->whereDoesntHave('reseller')
                ->orderBy('email')
                ->get(),
        ]);
    }

    public function view(Reseller $reseller): View
    {
        return view('admin.resellers.view', [
            'reseller' => $reseller->load('owner', 'nodes'),
            'nodes' => Node::query()->orderBy('name')->get(),
            'usage' => $this->quota->usage($reseller),
        ]);
    }

    public function store(ResellerFormRequest $request): RedirectResponse
    {
        $reseller = new Reseller();
        $reseller->forceFill(array_merge(
            $request->normalize(array_diff(array_keys($request->rules()), ['node_ids', 'node_ids.*'])),
            ['uuid' => Uuid::uuid4()->toString()],
        ))->save();

        $reseller->nodes()->sync($request->input('node_ids', []));

        $this->alert->success('The reseller has been created.')->flash();

        return redirect()->route('admin.resellers.view', $reseller->id);
    }

    public function update(ResellerFormRequest $request, Reseller $reseller): RedirectResponse
    {
        $reseller->forceFill(
            $request->normalize(array_diff(array_keys($request->rules()), ['node_ids', 'node_ids.*']))
        )->save();

        $reseller->nodes()->sync($request->input('node_ids', []));

        $this->alert->success('The reseller has been updated.')->flash();

        return redirect()->route('admin.resellers.view', $reseller->id);
    }

    /**
     * @throws DisplayException
     */
    public function delete(Reseller $reseller): RedirectResponse
    {
        // Refusing while servers exist is deliberate: deleting the reseller row
        // nulls its tenants' reseller_id, which would silently orphan those
        // servers out of anyone's quota accounting.
        $servers = $reseller->servers()->count();
        if ($servers > 0) {
            throw new DisplayException("This reseller still has $servers server(s). Delete or reassign them before removing the reseller.");
        }

        $reseller->delete();

        $this->alert->success('The reseller has been deleted. Its users remain on the panel as ordinary accounts.')->flash();

        return redirect()->route('admin.resellers');
    }
}
