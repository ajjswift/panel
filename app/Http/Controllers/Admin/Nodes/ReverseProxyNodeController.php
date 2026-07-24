<?php

namespace Pterodactyl\Http\Controllers\Admin\Nodes;

use Illuminate\Http\Request;
use Pterodactyl\Models\Node;
use Illuminate\Http\RedirectResponse;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Jobs\ReverseProxy\SyncNodeReverseProxyJob;
use Pterodactyl\Services\ReverseProxy\ReverseProxyKeyService;
use Pterodactyl\Services\ReverseProxy\ReverseProxyNodeRecordService;

class ReverseProxyNodeController extends Controller
{
    public function __construct(
        private AlertsMessageBag $alert,
        private ReverseProxyKeyService $keyService,
        private ReverseProxyNodeRecordService $nodeRecordService,
    ) {
    }

    public function update(Request $request, Node $node): RedirectResponse
    {
        $data = $request->validate([
            'reverse_proxy_enabled' => 'sometimes|boolean',
            'reverse_proxy_base_domain_id' => 'nullable|integer|exists:managed_domains,id',
            'reverse_proxy_control_port' => 'required|integer|between:1,65535',
        ]);

        $enabling = $request->boolean('reverse_proxy_enabled');

        $node->forceFill([
            'reverse_proxy_enabled' => $enabling,
            'reverse_proxy_base_domain_id' => $data['reverse_proxy_base_domain_id'] ?? null,
            'reverse_proxy_control_port' => $data['reverse_proxy_control_port'],
        ])->save();

        if ($enabling) {
            $this->keyService->ensure($node);

            // Point {slug}.{base-domain} at the node so the agent is reachable.
            try {
                $this->nodeRecordService->ensure($node->refresh());
            } catch (DisplayException $exception) {
                $this->alert->warning($exception->getMessage())->flash();

                return redirect()->route('admin.nodes.view.reverse-proxy', $node->id);
            }

            SyncNodeReverseProxyJob::dispatch($node->id);
        }

        $this->alert->success('Reverse proxy settings were saved.')->flash();

        return redirect()->route('admin.nodes.view.reverse-proxy', $node->id);
    }

    public function rotate(Request $request, Node $node): RedirectResponse
    {
        $this->keyService->rotate($node);
        $this->alert->success('New keys were generated. Re-run the install command on the node to apply them.')->flash();

        return redirect()->route('admin.nodes.view.reverse-proxy', $node->id);
    }
}
