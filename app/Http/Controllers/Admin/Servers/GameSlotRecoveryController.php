<?php

namespace Pterodactyl\Http\Controllers\Admin\Servers;

use Illuminate\View\View;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\GameSlot;
use Illuminate\Http\RedirectResponse;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Models\GameSwitchOperation;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\GameSlots\GameSwitchRecoveryService;

/**
 * Administrative recovery view and actions for game switching. Every mutating
 * action is a deliberate, confirmed step that corrects database/state drift
 * without ever deleting a slot's stored files.
 */
class GameSlotRecoveryController extends Controller
{
    public function __construct(
        private AlertsMessageBag $alert,
        private GameSwitchRecoveryService $recoveryService,
    ) {
    }

    public function index(Server $server): View
    {
        $slots = $server->gameSlots()->with('egg')->orderBy('id')->get();
        $operations = $server->gameSwitchOperations()->orderByDesc('id')->limit(25)->get();

        return view('admin.servers.view.game-slots', [
            'server' => $server,
            'slots' => $slots,
            'operations' => $operations,
            'diagnostics' => $this->recoveryService->diagnostics($server),
        ]);
    }

    public function retry(Server $server, GameSwitchOperation $operation): RedirectResponse
    {
        $this->guardBelongsToServer($server, $operation->server_id);

        try {
            $this->recoveryService->retry($operation);
            $this->alert->success('The switch operation has been re-queued and will resume from its last checkpoint.')->flash();
        } catch (DisplayException $exception) {
            $this->alert->danger($exception->getMessage())->flash();
        }

        return redirect()->route('admin.servers.view.game-slots', $server->id);
    }

    public function clearLock(Server $server): RedirectResponse
    {
        try {
            $this->recoveryService->clearLock($server);
            $this->alert->success('The switch lock has been cleared for this server.')->flash();
        } catch (DisplayException $exception) {
            $this->alert->danger($exception->getMessage())->flash();
        }

        return redirect()->route('admin.servers.view.game-slots', $server->id);
    }

    public function forceActive(Server $server, GameSlot $slot): RedirectResponse
    {
        $this->guardBelongsToServer($server, $slot->server_id);

        try {
            $this->recoveryService->forceActiveSlot($server, $slot);
            $this->alert->success("The \"{$slot->name}\" slot has been pinned as the active slot.")->flash();
        } catch (DisplayException $exception) {
            $this->alert->danger($exception->getMessage())->flash();
        }

        return redirect()->route('admin.servers.view.game-slots', $server->id);
    }

    public function toggleDisabled(Server $server, GameSlot $slot): RedirectResponse
    {
        $this->guardBelongsToServer($server, $slot->server_id);

        if ($slot->is_active) {
            $this->alert->danger('The active slot cannot be disabled.')->flash();

            return redirect()->route('admin.servers.view.game-slots', $server->id);
        }

        $disabled = $slot->state === GameSlot::STATE_DISABLED;
        $slot->forceFill(['state' => $disabled ? GameSlot::STATE_NORMAL : GameSlot::STATE_DISABLED])->save();

        $this->alert->success($disabled ? 'The slot has been re-enabled.' : 'The slot has been disabled.')->flash();

        return redirect()->route('admin.servers.view.game-slots', $server->id);
    }

    private function guardBelongsToServer(Server $server, int $serverId): void
    {
        abort_unless($serverId === $server->id, 404);
    }
}
