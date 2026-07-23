<?php

namespace Pterodactyl\Services\GameSlots;

use Pterodactyl\Models\User;
use Pterodactyl\Models\GameSlot;
use Pterodactyl\Models\GameSwitchOperation;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Services\Servers\VariableValidatorService;

class GameSlotUpdateService
{
    public function __construct(private VariableValidatorService $validatorService)
    {
    }

    /**
     * Update user-facing metadata for the slot.
     *
     * @param array{name?: string, notes?: string|null} $data
     */
    public function handleMetadata(GameSlot $slot, array $data): GameSlot
    {
        $slot->forceFill(array_intersect_key($data, array_flip(['name', 'notes'])))->save();

        return $slot;
    }

    /**
     * Update the slot's startup configuration (Docker image and variables).
     * Only user-editable variables are accepted for non-admin callers; the
     * remaining values are preserved as-is.
     *
     * @param array{docker_image?: string|null, environment?: array} $data
     *
     * @throws DisplayException
     */
    public function handleStartup(GameSlot $slot, array $data, ?User $user = null): GameSlot
    {
        $this->guardSlotMutable($slot);

        if (!empty($data['docker_image'])) {
            $allowed = array_values($slot->egg->docker_images ?? []);
            if (!in_array($data['docker_image'], $allowed, true)) {
                throw new DisplayException('The selected Docker image is not allowed for this game.');
            }

            $slot->docker_image = $data['docker_image'];
        }

        if (!empty($data['environment'])) {
            $validator = $this->validatorService;
            if (!is_null($user)) {
                $validator = $validator->setUserLevel(User::USER_LEVEL_USER);
            }

            $results = $validator->handle($slot->egg_id, $data['environment']);

            $environment = $slot->environment?->getArrayCopy() ?? [];
            foreach ($results as $result) {
                $environment[$result->key] = $result->value ?? '';
            }

            $slot->environment = new \ArrayObject($environment);
        }

        $slot->save();

        return $slot;
    }

    /**
     * @throws DisplayException
     */
    private function guardSlotMutable(GameSlot $slot): void
    {
        if ($slot->installation_status === GameSlot::INSTALL_INSTALLING) {
            throw new DisplayException('This slot is currently installing and cannot be modified.');
        }

        $busy = $slot->server->gameSwitchOperations()
            ->whereIn('state', GameSwitchOperation::ACTIVE_STATES)
            ->exists();
        if ($busy) {
            throw new DisplayException('A game switch is currently in progress for this server; slot configuration is locked until it completes.');
        }

        // The active slot's live configuration is managed through the normal
        // Startup page; editing it here would silently desynchronize the two.
        if ($slot->is_active) {
            throw new DisplayException('The active slot\'s startup configuration is managed from the server\'s Startup page.');
        }
    }
}
