<?php

namespace Pterodactyl\Transformers\Api\Client;

use Pterodactyl\Models\GameSwitchOperation;

class GameSwitchOperationTransformer extends BaseClientTransformer
{
    public function getResourceName(): string
    {
        return GameSwitchOperation::RESOURCE_NAME;
    }

    /**
     * Only the sanitized, user-facing view of an operation is exposed. The
     * internal_error_context column (which may reference infrastructure
     * details) is never returned to clients.
     */
    public function transform(GameSwitchOperation $operation): array
    {
        return [
            'uuid' => $operation->uuid,
            'state' => $operation->state,
            'current_stage' => $operation->current_stage,
            'source_slot_uuid' => $operation->sourceSlot?->uuid,
            'destination_slot_uuid' => $operation->destinationSlot?->uuid,
            'is_active' => !$operation->isTerminal(),
            'restore_power' => $operation->restore_power,
            'previous_power_state' => $operation->previous_power_state,
            'rollback_state' => $operation->rollback_state,
            'error_code' => $operation->error_code,
            'error_message' => $operation->error_message,
            'requested_at' => $operation->created_at?->toAtomString(),
            'started_at' => $operation->started_at?->toAtomString(),
            'completed_at' => $operation->completed_at?->toAtomString(),
            'failed_at' => $operation->failed_at?->toAtomString(),
        ];
    }
}
