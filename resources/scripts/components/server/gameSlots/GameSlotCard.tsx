import React from 'react';
import classNames from 'classnames';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faGamepad, faTrashAlt, faExchangeAlt } from '@fortawesome/free-solid-svg-icons';
import { GameSlot } from '@/api/server/gameSlots/types';
import { bytesToString } from '@/lib/formatters';
import { Button } from '@/components/elements/button/index';
import Tooltip from '@/components/elements/tooltip/Tooltip';
import styles from './gameSlots.module.css';

interface Props {
    slot: GameSlot;
    switchDisabled: boolean;
    canSwitch: boolean;
    canDelete: boolean;
    onSwitch: (slot: GameSlot) => void;
    onDelete: (slot: GameSlot) => void;
}

const INSTALL_BADGE: Record<GameSlot['installationStatus'], { label: string; cls: string }> = {
    not_installed: { label: 'Not installed', cls: styles.badge_neutral },
    installing: { label: 'Installing', cls: styles.badge_warning },
    installed: { label: 'Ready', cls: styles.badge_success },
    failed: { label: 'Install failed', cls: styles.badge_danger },
};

const STATE_BADGE: Partial<Record<GameSlot['state'], { label: string; cls: string }>> = {
    over_limit: { label: 'Over slot limit', cls: styles.badge_warning },
    disabled: { label: 'Disabled by admin', cls: styles.badge_danger },
    recovery_required: { label: 'Recovery required', cls: styles.badge_danger },
    deleting: { label: 'Deleting', cls: styles.badge_warning },
};

export default ({ slot, switchDisabled, canSwitch, canDelete, onSwitch, onDelete }: Props) => {
    const install = INSTALL_BADGE[slot.installationStatus];
    const stateBadge = STATE_BADGE[slot.state];
    const blocked = slot.state !== 'normal';

    return (
        <div className={classNames(styles.slot_card, { [styles.active]: slot.isActive })}>
            <div className={styles.slot_header}>
                <div className={styles.slot_icon} aria-hidden>
                    <FontAwesomeIcon icon={faGamepad} />
                </div>
                <div className={'min-w-0 flex-1'}>
                    <p className={styles.slot_name} title={slot.name}>
                        {slot.name}
                    </p>
                    <p className={styles.slot_game}>{slot.eggName}</p>
                </div>
                {slot.isActive && (
                    <span className={classNames(styles.badge, styles.badge_active)}>Active</span>
                )}
            </div>

            <div className={'flex flex-wrap gap-1.5 mb-3'}>
                <span className={classNames(styles.badge, install.cls)}>{install.label}</span>
                {stateBadge && <span className={classNames(styles.badge, stateBadge.cls)}>{stateBadge.label}</span>}
            </div>

            <dl className={styles.slot_meta}>
                <dt>Disk usage</dt>
                <dd>
                    {bytesToString(slot.diskUsageBytes)}
                    {!slot.diskUsageIsCached && <span className={'text-body-faint'}> (pending)</span>}
                </dd>
                <dt>Last active</dt>
                <dd>{slot.lastActivatedAt ? slot.lastActivatedAt.toLocaleDateString() : 'Never'}</dd>
            </dl>

            <div className={styles.slot_actions}>
                {slot.isActive ? (
                    <Button.Text disabled className={'flex-1'} size={Button.Sizes.Small}>
                        Currently active
                    </Button.Text>
                ) : (
                    <Tooltip
                        disabled={canSwitch && !switchDisabled && !blocked}
                        content={
                            !canSwitch
                                ? 'You do not have permission to switch games.'
                                : blocked
                                ? 'This slot must be resolved by an administrator before it can be activated.'
                                : 'Another operation is in progress.'
                        }
                    >
                        <span className={'flex-1'}>
                            <Button
                                className={'w-full'}
                                size={Button.Sizes.Small}
                                disabled={!canSwitch || switchDisabled || blocked}
                                onClick={() => onSwitch(slot)}
                            >
                                <FontAwesomeIcon icon={faExchangeAlt} className={'w-3.5 h-3.5 mr-2'} />
                                Switch to this game
                            </Button>
                        </span>
                    </Tooltip>
                )}

                {!slot.isActive && canDelete && (
                    <Tooltip content={'Delete this slot'}>
                        <Button.Danger
                            size={Button.Sizes.Small}
                            shape={Button.Shapes.IconSquare}
                            disabled={switchDisabled || slot.installationStatus === 'installing'}
                            onClick={() => onDelete(slot)}
                            aria-label={`Delete the ${slot.name} slot`}
                        >
                            <FontAwesomeIcon icon={faTrashAlt} className={'w-3.5 h-3.5'} />
                        </Button.Danger>
                    </Tooltip>
                )}
            </div>
        </div>
    );
};
