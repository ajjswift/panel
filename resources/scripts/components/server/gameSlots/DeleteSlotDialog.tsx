import React, { useEffect, useState } from 'react';
import { Dialog } from '@/components/elements/dialog';
import { Button } from '@/components/elements/button/index';
import Input from '@/components/elements/Input';
import { GameSlot } from '@/api/server/gameSlots/types';

interface Props {
    slot: GameSlot | null;
    onClose: () => void;
    onConfirm: (slot: GameSlot) => void;
    submitting: boolean;
}

export default ({ slot, onClose, onConfirm, submitting }: Props) => {
    const [value, setValue] = useState('');

    useEffect(() => {
        setValue('');
    }, [slot?.uuid]);

    if (!slot) return null;

    const matches = value === slot.name;

    return (
        <Dialog
            open={!!slot}
            onClose={onClose}
            title={`Delete ${slot.name}?`}
            description={'This permanently removes the game and all of its files. This cannot be undone.'}
        >
            <p className={'text-sm text-body-muted mb-3'}>
                Type <strong className={'text-body'}>{slot.name}</strong> to confirm.
            </p>
            <Input
                value={value}
                onChange={(e) => setValue(e.currentTarget.value)}
                aria-label={'Type the slot name to confirm deletion'}
                autoFocus
            />
            <Dialog.Footer>
                <Button.Text onClick={onClose} disabled={submitting}>
                    Cancel
                </Button.Text>
                <Button.Danger onClick={() => onConfirm(slot)} disabled={!matches || submitting}>
                    Permanently delete slot
                </Button.Danger>
            </Dialog.Footer>
        </Dialog>
    );
};
