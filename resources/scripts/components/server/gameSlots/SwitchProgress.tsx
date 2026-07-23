import React from 'react';
import classNames from 'classnames';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCheck, faCircleNotch, faExclamationTriangle } from '@fortawesome/free-solid-svg-icons';
import { SwitchOperation, SwitchStage } from '@/api/server/gameSlots/types';
import styles from './gameSlots.module.css';

// Ordered, user-facing stages of a normal switch. Terminal/rollback stages are
// handled separately so this list stays a clean linear progression.
const STAGES: { key: SwitchStage; label: string }[] = [
    { key: 'stopping_server', label: 'Waiting for the server to stop' },
    { key: 'saving_source_files', label: 'Saving the current game\'s files' },
    { key: 'preparing_destination_files', label: 'Preparing the new game\'s files' },
    { key: 'updating_configuration', label: 'Updating startup configuration' },
    { key: 'syncing_with_node', label: 'Synchronizing with the node' },
    { key: 'installing', label: 'Installing the game' },
    { key: 'validating', label: 'Validating the installation' },
    { key: 'restoring_power', label: 'Restoring the server state' },
];

const indexForStage = (stage: SwitchStage): number => {
    const i = STAGES.findIndex((s) => s.key === stage);
    if (stage === 'completed') return STAGES.length;
    if (stage === 'pending') return 0;
    return i < 0 ? 0 : i;
};

interface Props {
    operation: SwitchOperation;
}

export default ({ operation }: Props) => {
    const isFailed =
        operation.state === 'failed_rolled_back' || operation.state === 'failed_requires_action';
    const isRollingBack = operation.currentStage === 'rolling_back';
    const currentIndex = indexForStage(operation.currentStage);
    const percent = isFailed ? 100 : Math.round((currentIndex / STAGES.length) * 100);

    return (
        <div className={styles.progress_wrap} role={'status'} aria-live={'polite'}>
            <div className={'flex items-center justify-between gap-3'}>
                <div>
                    <h3 className={'text-sm font-semibold text-body'}>
                        {isFailed ? 'Game switch did not complete' : 'Switching games'}
                    </h3>
                    <p className={'text-xs text-body-muted mt-0.5'}>
                        {isRollingBack
                            ? 'Restoring your previous game…'
                            : isFailed
                            ? operation.errorMessage
                            : 'You can safely leave this page — the switch continues on the server.'}
                    </p>
                </div>
                {!isFailed && (
                    <span className={'text-sm font-semibold text-body tabular-nums'}>{percent}%</span>
                )}
            </div>

            {!isFailed && (
                <div className={styles.progress_track} aria-hidden>
                    <div className={styles.progress_fill} style={{ width: `${Math.max(percent, 6)}%` }} />
                </div>
            )}

            <ul className={styles.stage_list}>
                {STAGES.map((stage, index) => {
                    const done = !isFailed && index < currentIndex;
                    const current = !isFailed && index === currentIndex;

                    return (
                        <li
                            key={stage.key}
                            className={classNames(styles.stage_row, { [styles.done]: done, [styles.current]: current })}
                        >
                            <span className={styles.stage_dot}>
                                {done ? (
                                    <FontAwesomeIcon icon={faCheck} className={'w-3 h-3 text-success-text'} />
                                ) : current ? (
                                    <FontAwesomeIcon icon={faCircleNotch} spin className={'w-3 h-3 text-primary-400'} />
                                ) : isFailed && index >= currentIndex ? (
                                    <FontAwesomeIcon
                                        icon={faExclamationTriangle}
                                        className={'w-3 h-3 text-body-faint'}
                                    />
                                ) : (
                                    <span className={'w-1.5 h-1.5 rounded-full bg-line-strong'} />
                                )}
                            </span>
                            {stage.label}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
};
