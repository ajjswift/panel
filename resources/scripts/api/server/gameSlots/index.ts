import http, { FractalResponseData } from '@/api/http';
import { GameSlot, GameSlotOverview, GameTemplate, SwitchOperation } from './types';

export const rawDataToGameSlot = ({ attributes }: FractalResponseData): GameSlot => ({
    uuid: attributes.uuid,
    name: attributes.name,
    eggUuid: attributes.egg_uuid,
    eggName: attributes.egg_name,
    nestId: attributes.nest_id,
    dockerImage: attributes.docker_image,
    installationStatus: attributes.installation_status,
    state: attributes.state,
    isActive: attributes.is_active,
    diskUsageBytes: attributes.disk_usage_bytes,
    diskUsageIsCached: attributes.disk_usage_is_cached,
    lastActivatedAt: attributes.last_activated_at ? new Date(attributes.last_activated_at) : null,
    lastSwitchCompletedAt: attributes.last_switch_completed_at
        ? new Date(attributes.last_switch_completed_at)
        : null,
    notes: attributes.notes,
    createdAt: attributes.created_at ? new Date(attributes.created_at) : null,
    updatedAt: attributes.updated_at ? new Date(attributes.updated_at) : null,
});

export const rawDataToSwitchOperation = (data: FractalResponseData | null): SwitchOperation | null => {
    if (!data) return null;
    const { attributes } = data;

    return {
        uuid: attributes.uuid,
        state: attributes.state,
        currentStage: attributes.current_stage,
        sourceSlotUuid: attributes.source_slot_uuid,
        destinationSlotUuid: attributes.destination_slot_uuid,
        isActive: attributes.is_active,
        restorePower: attributes.restore_power,
        previousPowerState: attributes.previous_power_state,
        rollbackState: attributes.rollback_state,
        errorCode: attributes.error_code,
        errorMessage: attributes.error_message,
        requestedAt: attributes.requested_at ? new Date(attributes.requested_at) : null,
        startedAt: attributes.started_at ? new Date(attributes.started_at) : null,
        completedAt: attributes.completed_at ? new Date(attributes.completed_at) : null,
        failedAt: attributes.failed_at ? new Date(attributes.failed_at) : null,
    };
};

export const getGameSlots = async (uuid: string): Promise<GameSlotOverview> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/game-slots`);

    return {
        slots: (data.data || []).map(rawDataToGameSlot),
        slotLimit: data.meta.slot_limit,
        slotCount: data.meta.slot_count,
        serverDiskBytes: data.meta.server_disk_bytes,
        combinedSlotUsageBytes: data.meta.combined_slot_usage_bytes,
        activeOperation: rawDataToSwitchOperation(data.meta.active_operation),
    };
};

export const getGameTemplates = async (uuid: string): Promise<GameTemplate[]> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/game-slots/templates`);

    return (data.data || []).map(({ attributes }: FractalResponseData) => ({
        eggId: attributes.egg_id,
        uuid: attributes.uuid,
        name: attributes.name,
        description: attributes.description,
        nestId: attributes.nest_id,
        dockerImages: attributes.docker_images,
        variables: (attributes.variables || []).map((v: Record<string, unknown>) => ({
            name: v.name,
            description: v.description,
            envVariable: v.env_variable,
            defaultValue: v.default_value,
            rules: v.rules,
            isEditable: v.is_editable,
        })),
    }));
};

export const createGameSlot = async (
    uuid: string,
    data: { name: string; eggId: number; dockerImage?: string; environment?: Record<string, string> }
): Promise<GameSlot> => {
    const response = await http.post(`/api/client/servers/${uuid}/game-slots`, {
        name: data.name,
        egg_id: data.eggId,
        docker_image: data.dockerImage,
        environment: data.environment,
    });

    return rawDataToGameSlot(response.data);
};

export const updateGameSlot = async (
    uuid: string,
    slot: string,
    data: { name?: string; notes?: string | null }
): Promise<GameSlot> => {
    const response = await http.patch(`/api/client/servers/${uuid}/game-slots/${slot}`, data);

    return rawDataToGameSlot(response.data);
};

export const activateGameSlot = async (
    uuid: string,
    slot: string,
    restartAfter: boolean
): Promise<SwitchOperation> => {
    const response = await http.post(`/api/client/servers/${uuid}/game-slots/${slot}/activate`, {
        restart_after: restartAfter,
    });

    return rawDataToSwitchOperation(response.data)!;
};

export const deleteGameSlot = async (uuid: string, slot: string, confirm: string): Promise<void> => {
    await http.delete(`/api/client/servers/${uuid}/game-slots/${slot}`, { data: { confirm } });
};

export const getCurrentOperation = async (uuid: string): Promise<SwitchOperation | null> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/game-slots/operations/current`);

    return rawDataToSwitchOperation(data.data);
};

export const retryOperation = async (
    uuid: string,
    operation: string,
    restartAfter: boolean
): Promise<SwitchOperation> => {
    const response = await http.post(
        `/api/client/servers/${uuid}/game-slots/operations/${operation}/retry`,
        { restart_after: restartAfter }
    );

    return rawDataToSwitchOperation(response.data)!;
};
