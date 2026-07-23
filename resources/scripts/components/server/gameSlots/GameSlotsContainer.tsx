import React, { useState } from 'react';
import { useHistory } from 'react-router-dom';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faPlus } from '@fortawesome/free-solid-svg-icons';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import Spinner from '@/components/elements/Spinner';
import FlashMessageRender from '@/components/FlashMessageRender';
import { Button } from '@/components/elements/button/index';
import useFlash from '@/plugins/useFlash';
import { usePermissions } from '@/plugins/usePermissions';
import { ServerContext } from '@/state/server';
import { bytesToString } from '@/lib/formatters';
import { activateGameSlot, createGameSlot, deleteGameSlot } from '@/api/server/gameSlots';
import { GameSlot } from '@/api/server/gameSlots/types';
import getGameSlots from '@/api/swr/getGameSlots';
import GameSlotCard from './GameSlotCard';
import CreateSlotDialog from './CreateSlotDialog';
import SwitchConfirmationDialog from './SwitchConfirmationDialog';
import DeleteSlotDialog from './DeleteSlotDialog';
import SwitchProgress from './SwitchProgress';
import styles from './gameSlots.module.css';

const GameSlotsContainer = () => {
    const history = useHistory();
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const [canCreate, canSwitch, canDelete] = usePermissions(['gameslot.create', 'gameslot.switch', 'gameslot.delete']);
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const serverStatus = ServerContext.useStoreState((state) => state.server.data?.status ?? null);

    const [createOpen, setCreateOpen] = useState(false);
    const [switchTarget, setSwitchTarget] = useState<GameSlot | null>(null);
    const [deleteTarget, setDeleteTarget] = useState<GameSlot | null>(null);
    const [submitting, setSubmitting] = useState(false);

    // Poll while a switch is running (or the server is stuck in the switching
    // status) so progress and completion appear without a manual refresh.
    const isSwitching = serverStatus === 'switching_game';
    const { data, error, mutate } = getGameSlots(isSwitching);

    if (!data && !error) {
        return (
            <ServerContentBlock title={'Game Slots'}>
                <Spinner size={'large'} centered />
            </ServerContentBlock>
        );
    }

    if (error || !data) {
        return (
            <ServerContentBlock title={'Game Slots'}>
                <FlashMessageRender byKey={'game-slots'} className={'mb-4'} />
                <p className={'text-sm text-body-muted'}>Unable to load the game slots for this server.</p>
            </ServerContentBlock>
        );
    }

    const active = data.slots.find((s) => s.isActive);
    const operation = data.activeOperation;
    const operationActive = !!operation?.isActive || isSwitching;
    const atLimit = data.slotCount >= data.slotLimit;

    const withSubmit = async (key: string, fn: () => Promise<unknown>, onDone?: () => void) => {
        setSubmitting(true);
        clearFlashes('game-slots');
        try {
            await fn();
            await mutate();
            onDone?.();
        } catch (e) {
            clearAndAddHttpError({ key: 'game-slots', error: e });
        } finally {
            setSubmitting(false);
        }
    };

    const startSwitch = async (slot: GameSlot, restartAfter: boolean) => {
        setSubmitting(true);
        clearFlashes('game-slots');

        try {
            await activateGameSlot(uuid, slot.uuid, restartAfter);
            setSwitchTarget(null);
            history.replace('/');
        } catch (e) {
            clearAndAddHttpError({ key: 'game-slots', error: e });
            setSubmitting(false);
        }
    };

    return (
        <ServerContentBlock title={'Game Slots'}>
            <FlashMessageRender byKey={'game-slots'} className={'mb-4'} />

            <div className={'flex items-center justify-between gap-3 mb-5'}>
                <p className={'text-sm text-body-muted max-w-2xl'}>
                    Each game slot is a separate installation that shares this server&apos;s resources, ports, and disk.
                    Only one runs at a time — switching preserves the other games so you can return to them later.
                </p>
                {canCreate && (
                    <Button
                        onClick={() => setCreateOpen(true)}
                        disabled={atLimit || operationActive}
                        className={'shrink-0'}
                    >
                        <FontAwesomeIcon icon={faPlus} className={'w-3.5 h-3.5 mr-2'} />
                        Add slot
                    </Button>
                )}
            </div>

            <div className={styles.summary_grid}>
                <div className={styles.summary_tile}>
                    <span className={styles.summary_label}>Slots used</span>
                    <span className={styles.summary_value}>
                        {data.slotCount} of {data.slotLimit}
                    </span>
                </div>
                <div className={styles.summary_tile}>
                    <span className={styles.summary_label}>Active game</span>
                    <span className={styles.summary_value}>{active?.name ?? '—'}</span>
                </div>
                <div className={styles.summary_tile}>
                    <span className={styles.summary_label}>Combined slot usage</span>
                    <span className={styles.summary_value}>
                        {bytesToString(data.combinedSlotUsageBytes)}
                        <span className={'text-body-faint text-sm'}>
                            {' '}
                            / {data.serverDiskBytes > 0 ? bytesToString(data.serverDiskBytes) : <>&infin;</>}
                        </span>
                    </span>
                </div>
                <div className={styles.summary_tile}>
                    <span className={styles.summary_label}>Status</span>
                    <span className={styles.summary_value}>{operationActive ? 'Switching' : 'Ready'}</span>
                </div>
            </div>

            {operation && (operationActive || operation.state !== 'completed') && (
                <SwitchProgress operation={operation} />
            )}

            {atLimit && !operationActive && (
                <div
                    className={'rounded-md border border-line bg-page px-3 py-2 text-sm text-body-muted mb-4'}
                    role={'status'}
                >
                    You&apos;ve used all {data.slotLimit} game slots. Delete an inactive slot or ask an administrator to
                    raise the limit to add more.
                </div>
            )}

            <div className={styles.slot_grid}>
                {data.slots.map((slot) => (
                    <GameSlotCard
                        key={slot.uuid}
                        slot={slot}
                        switchDisabled={operationActive}
                        canSwitch={canSwitch}
                        canDelete={canDelete}
                        onSwitch={setSwitchTarget}
                        onDelete={setDeleteTarget}
                    />
                ))}
            </div>

            <CreateSlotDialog
                open={createOpen}
                onClose={() => setCreateOpen(false)}
                submitting={submitting}
                onCreate={(payload) =>
                    withSubmit(
                        'game-slots',
                        () => createGameSlot(uuid, payload),
                        () => setCreateOpen(false)
                    )
                }
            />

            <SwitchConfirmationDialog
                open={!!switchTarget}
                onClose={() => setSwitchTarget(null)}
                destination={switchTarget}
                active={active}
                overview={data}
                submitting={submitting}
                onConfirm={(restartAfter) => {
                    if (!switchTarget) return;
                    void startSwitch(switchTarget, restartAfter);
                }}
            />

            <DeleteSlotDialog
                slot={deleteTarget}
                onClose={() => setDeleteTarget(null)}
                submitting={submitting}
                onConfirm={(slot) =>
                    void withSubmit(
                        'game-slots',
                        () => deleteGameSlot(uuid, slot.uuid, slot.name),
                        () => setDeleteTarget(null)
                    )
                }
            />
        </ServerContentBlock>
    );
};

export default GameSlotsContainer;
