<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\GameSlots\GetGameSlotRequest;

/**
 * Exposes the game templates (eggs) a user is allowed to pick when creating a
 * new slot. Only eggs an administrator has explicitly enabled for game
 * switching are ever returned, and each is reduced to the minimum a client
 * needs to render the creation wizard. Admin-only variables are filtered out so
 * a user cannot discover or set them.
 */
class GameSlotTemplateController extends ClientApiController
{
    public function index(GetGameSlotRequest $request, Server $server): array
    {
        $eggs = Egg::query()
            ->where('game_switch_enabled', true)
            ->with(['variables' => fn ($q) => $q->where('user_viewable', true)])
            ->orderBy('name')
            ->get();

        return [
            'object' => 'list',
            'data' => $eggs->map(fn (Egg $egg) => [
                'object' => 'game_template',
                'attributes' => [
                    'egg_id' => $egg->id,
                    'uuid' => $egg->uuid,
                    'name' => $egg->name,
                    'description' => $egg->description,
                    'nest_id' => $egg->nest_id,
                    'docker_images' => array_values($egg->docker_images ?? []),
                    'variables' => $egg->variables->map(fn ($variable) => [
                        'name' => $variable->name,
                        'description' => $variable->description,
                        'env_variable' => $variable->env_variable,
                        'default_value' => $variable->default_value,
                        'rules' => $variable->rules,
                        'is_editable' => (bool) $variable->user_editable,
                    ])->values(),
                ],
            ])->values(),
        ];
    }
}
