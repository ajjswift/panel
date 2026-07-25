export type GameSlotInstallStatus = 'not_installed' | 'installing' | 'installed' | 'failed';
export type GameSlotState = 'normal' | 'over_limit' | 'disabled' | 'recovery_required' | 'deleting';

export interface GameSlot {
    uuid: string;
    name: string;
    eggUuid: string;
    eggName: string;
    nestId: number;
    dockerImage: string;
    installationStatus: GameSlotInstallStatus;
    state: GameSlotState;
    isActive: boolean;
    diskUsageBytes: number;
    diskUsageIsCached: boolean;
    lastActivatedAt: Date | null;
    lastSwitchCompletedAt: Date | null;
    notes: string | null;
    createdAt: Date | null;
    updatedAt: Date | null;
}

export type SwitchOperationState =
    | 'pending'
    | 'running'
    | 'completed'
    | 'failed_rolled_back'
    | 'failed_requires_action';

export type SwitchStage =
    | 'pending'
    | 'stopping_server'
    | 'saving_source_files'
    | 'preparing_destination_files'
    | 'updating_configuration'
    | 'syncing_with_node'
    | 'installing'
    | 'validating'
    | 'restoring_power'
    | 'completed'
    | 'rolling_back'
    | 'failed';

export interface SwitchOperation {
    uuid: string;
    state: SwitchOperationState;
    currentStage: SwitchStage;
    sourceSlotUuid: string | null;
    destinationSlotUuid: string | null;
    isActive: boolean;
    restorePower: boolean;
    previousPowerState: string | null;
    rollbackState: string | null;
    errorCode: string | null;
    errorMessage: string | null;
    requestedAt: Date | null;
    startedAt: Date | null;
    completedAt: Date | null;
    failedAt: Date | null;
}

export interface GameSlotOverview {
    slots: GameSlot[];
    slotLimit: number;
    slotCount: number;
    serverDiskBytes: number;
    combinedSlotUsageBytes: number;
    isSwitching: boolean;
    recoverable: boolean;
    activeOperation: SwitchOperation | null;
    latestOperation: SwitchOperation | null;
}

export interface GameTemplateVariable {
    name: string;
    description: string;
    envVariable: string;
    defaultValue: string;
    rules: string;
    isEditable: boolean;
}

export interface GameTemplate {
    eggId: number;
    uuid: string;
    name: string;
    description: string;
    nestId: number;
    dockerImages: string[];
    variables: GameTemplateVariable[];
}
