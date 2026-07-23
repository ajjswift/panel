import React, { useState } from 'react';
import { Dialog } from '@/components/elements/dialog';
import { Button } from '@/components/elements/button/index';
import Checkbox from '@/components/elements/inputs/Checkbox';
import { GameSlot, GameSlotOverview } from '@/api/server/gameSlots/types';
import { bytesToString } from '@/lib/formatters';

interface Props {
    open: boolean;
    onClose: () => void;
    destination: GameSlot | null;
    active: GameSlot | undefined;
    overview: GameSlotOverview;
    onConfirm: (restartAfter: boolean) => void;
    submitting: boolean;
}

export default ({ open, onClose, destination, active, overview, onConfirm, submitting }: Props) => {
    const [restartAfter, setRestartAfter] = useState(true);

    if (!destination) return null;

    const remaining = overview.serverDiskBytes - overview.combinedSlotUsageBytes;
    const lowDisk =
        destination.installationStatus !== 'installed' &&
        overview.serverDiskBytes > 0 &&
        remaining < overview.serverDiskBytes * 0.1;

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title={`Switch to ${destination.name}?`}
            description={'Only one game can run on this server at a time.'}
        >
            <div className={'space-y-3 text-sm text-body-muted'}>
                <p>
                    Switching will stop <strong className={'text-body'}>{active?.name ?? 'the current game'}</strong>{' '}
                    and start <strong className={'text-body'}>{destination.name}</strong> in its place. Both games keep
                    their own files — you can switch back at any time.
                </p>
                <ul className={'list-disc pl-5 space-y-1'}>
                    <li>The active game will be stopped and connected players will be disconnected.</li>
                    <li>Unsaved in-game progress may be lost if the game does not shut down cleanly.</li>
                    <li>
                        {destination.name} will use this server&apos;s existing resources, ports, and disk allowance.
                    </li>
                    {destination.installationStatus !== 'installed' && (
                        <li>This game has not been installed yet, so the switch will run its installer first.</li>
                    )}
                </ul>

                {lowDisk && (
                    <div
                        className={'rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-warning-text'}
                        role={'alert'}
                    >
                        Low disk space: about {bytesToString(Math.max(remaining, 0))} free of{' '}
                        {bytesToString(overview.serverDiskBytes)}. Installing a new game may fail if it runs out of
                        space.
                    </div>
                )}

                <label className={'flex items-center gap-2 pt-1 text-body cursor-pointer'}>
                    <Checkbox
                        checked={restartAfter}
                        onChange={(e: React.ChangeEvent<HTMLInputElement>) =>
                            setRestartAfter(e.currentTarget.checked)
                        }
                    />
                    Start the server after switching
                </label>
            </div>

            <Dialog.Footer>
                <Button.Text onClick={onClose} disabled={submitting}>
                    Cancel
                </Button.Text>
                <Button.Danger onClick={() => onConfirm(restartAfter)} disabled={submitting}>
                    Stop server and switch game
                </Button.Danger>
            </Dialog.Footer>
        </Dialog>
    );
};
