<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\GameSlot;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\GameSwitchOperation;
use Pterodactyl\Services\GameSlots\GameSwitchService;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Pterodactyl\Transformers\Api\Client\GameSwitchOperationTransformer;
use Pterodactyl\Http\Requests\Api\Client\Servers\GameSlots\GetGameSlotRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\GameSlots\ActivateGameSlotRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\GameSlots\GetGameSwitchHistoryRequest;

class GameSwitchOperationController extends ClientApiController
{
    public function __construct(private GameSwitchService $switchService)
    {
        parent::__construct();
    }

    /**
     * Return the current (pending/running) switch operation for the server, or
     * null when the server is not switching. Requires only the read permission
     * so progress can be polled by anyone who can view the slots.
     */
    public function current(GetGameSlotRequest $request, Server $server): array
    {
        $operation = $server->activeGameSwitchOperation()->first();

        if (!$operation) {
            return ['data' => null];
        }

        return $this->fractal->item($operation)
            ->transformWith($this->getTransformer(GameSwitchOperationTransformer::class))
            ->toArray();
    }

    /**
     * Paginated history of switch operations for the server.
     */
    public function index(GetGameSwitchHistoryRequest $request, Server $server): array
    {
        $limit = min($request->query('per_page') ?? 25, 50);

        return $this->fractal->collection(
            $server->gameSwitchOperations()->orderByDesc('id')->paginate($limit)
        )
            ->transformWith($this->getTransformer(GameSwitchOperationTransformer::class))
            ->toArray();
    }

    public function view(GetGameSwitchHistoryRequest $request, Server $server, GameSwitchOperation $gameSwitchOperation): array
    {
        return $this->fractal->item($gameSwitchOperation)
            ->transformWith($this->getTransformer(GameSwitchOperationTransformer::class))
            ->toArray();
    }

    /**
     * Retry a failed switch that was safely rolled back. Retrying a switch that
     * requires administrator action is intentionally not offered here — those
     * are resolved through the administrative recovery tools. This starts a
     * fresh switch to the same destination rather than resuming the old
     * operation, which keeps the client path free of any half-completed state.
     *
     * @throws \Throwable
     */
    public function retry(ActivateGameSlotRequest $request, Server $server, GameSwitchOperation $gameSwitchOperation): array
    {
        if ($gameSwitchOperation->state !== GameSwitchOperation::STATE_FAILED_ROLLED_BACK) {
            throw new BadRequestHttpException('This operation cannot be retried. If the server needs recovery, contact an administrator.');
        }

        /** @var GameSlot $destination */
        $destination = $gameSwitchOperation->destinationSlot()->firstOrFail();

        $operation = Activity::event('server:gameslot.switch-start')
            ->subject($destination)
            ->property(['destination' => $destination->name, 'retry_of' => $gameSwitchOperation->uuid])
            ->transaction(fn () => $this->switchService->handle(
                $server,
                $destination,
                $request->user(),
                $request->boolean('restart_after', true),
            ));

        return $this->fractal->item($operation)
            ->transformWith($this->getTransformer(GameSwitchOperationTransformer::class))
            ->toArray();
    }
}
