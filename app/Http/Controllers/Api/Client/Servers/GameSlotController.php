<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\GameSlot;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Services\GameSlots\GameSwitchService;
use Pterodactyl\Jobs\GameSlots\ScanGameSlotDiskUsageJob;
use Pterodactyl\Services\GameSlots\GameSlotUpdateService;
use Pterodactyl\Services\GameSlots\GameSlotAdoptionService;
use Pterodactyl\Services\GameSlots\GameSlotCreationService;
use Pterodactyl\Services\GameSlots\GameSlotDeletionService;
use Pterodactyl\Transformers\Api\Client\GameSlotTransformer;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Pterodactyl\Transformers\Api\Client\GameSwitchOperationTransformer;
use Pterodactyl\Http\Requests\Api\Client\Servers\GameSlots\GetGameSlotRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\GameSlots\StoreGameSlotRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\GameSlots\DeleteGameSlotRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\GameSlots\UpdateGameSlotRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\GameSlots\ActivateGameSlotRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\GameSlots\UpdateGameSlotStartupRequest;

class GameSlotController extends ClientApiController
{
    public function __construct(
        private GameSlotAdoptionService $adoptionService,
        private GameSlotCreationService $creationService,
        private GameSlotUpdateService $updateService,
        private GameSlotDeletionService $deletionService,
        private GameSwitchService $switchService,
    ) {
        parent::__construct();
    }

    /**
     * List the slots for a server, adopting a default slot for legacy servers
     * on first access so the response is always consistent.
     */
    public function index(GetGameSlotRequest $request, Server $server): array
    {
        $this->adoptionService->handle($server);

        // Kick off a (rate-limited) background disk scan so usage figures stay
        // fresh without blocking this request. Dispatched after the response so
        // it never delays or fails the page load.
        ScanGameSlotDiskUsageJob::dispatch($server->id)->afterResponse();

        $slots = $server->gameSlots()->with('egg')->orderBy('id')->get();
        $activeOperation = $server->activeGameSwitchOperation()->first();

        return $this->fractal->collection($slots)
            ->transformWith($this->getTransformer(GameSlotTransformer::class))
            ->addMeta([
                'slot_limit' => $server->game_slot_limit,
                'slot_count' => $slots->count(),
                'server_disk_bytes' => $server->disk * 1024 * 1024,
                'combined_slot_usage_bytes' => (int) $slots->sum('disk_usage_bytes'),
                'active_operation' => $activeOperation
                    ? $this->fractal->item($activeOperation)
                        ->transformWith($this->getTransformer(GameSwitchOperationTransformer::class))
                        ->toArray()['data']
                    : null,
            ])
            ->toArray();
    }

    /**
     * @throws \Throwable
     */
    public function store(StoreGameSlotRequest $request, Server $server): array
    {
        $slot = Activity::event('server:gameslot.create')
            ->property('name', $request->input('name'))
            ->transaction(fn () => $this->creationService->handle($server, [
                'name' => $request->input('name'),
                'egg_id' => (int) $request->input('egg_id'),
                'docker_image' => $request->input('docker_image'),
                'environment' => $request->input('environment', []),
            ], $request->user()));

        return $this->fractal->item($slot)
            ->transformWith($this->getTransformer(GameSlotTransformer::class))
            ->toArray();
    }

    public function view(GetGameSlotRequest $request, Server $server, GameSlot $gameSlot): array
    {
        return $this->fractal->item($gameSlot)
            ->transformWith($this->getTransformer(GameSlotTransformer::class))
            ->toArray();
    }

    public function update(UpdateGameSlotRequest $request, Server $server, GameSlot $gameSlot): array
    {
        $slot = $this->updateService->handleMetadata($gameSlot, $request->validated());

        Activity::event('server:gameslot.update')->subject($gameSlot)->property('name', $slot->name)->log();

        return $this->fractal->item($slot)
            ->transformWith($this->getTransformer(GameSlotTransformer::class))
            ->toArray();
    }

    public function updateStartup(UpdateGameSlotStartupRequest $request, Server $server, GameSlot $gameSlot): array
    {
        $slot = $this->updateService->handleStartup($gameSlot, [
            'docker_image' => $request->input('docker_image'),
            'environment' => $request->input('environment'),
        ], $request->user());

        Activity::event('server:gameslot.startup')->subject($gameSlot)->property('name', $slot->name)->log();

        return $this->fractal->item($slot)
            ->transformWith($this->getTransformer(GameSlotTransformer::class))
            ->toArray();
    }

    /**
     * Enqueue a switch to make the given slot active. Returns the operation so
     * the client can poll its progress; the HTTP request does not stay open for
     * the duration of the switch.
     *
     * @throws \Throwable
     */
    public function activate(ActivateGameSlotRequest $request, Server $server, GameSlot $gameSlot): array
    {
        $operation = Activity::event('server:gameslot.switch-start')
            ->subject($gameSlot)
            ->property([
                'source' => $server->activeGameSlot()->value('name'),
                'destination' => $gameSlot->name,
            ])
            ->transaction(fn () => $this->switchService->handle(
                $server,
                $gameSlot,
                $request->user(),
                $request->boolean('restart_after', true),
            ));

        return $this->fractal->item($operation)
            ->transformWith($this->getTransformer(GameSwitchOperationTransformer::class))
            ->toArray();
    }

    /**
     * @throws \Throwable
     */
    public function delete(DeleteGameSlotRequest $request, Server $server, GameSlot $gameSlot): JsonResponse
    {
        if (!hash_equals($gameSlot->name, (string) $request->input('confirm'))) {
            throw new BadRequestHttpException('The confirmation value did not match the slot name.');
        }

        $this->deletionService->handle($gameSlot);

        Activity::event('server:gameslot.delete')->subject($gameSlot)->property('name', $gameSlot->name)->log();

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }
}
