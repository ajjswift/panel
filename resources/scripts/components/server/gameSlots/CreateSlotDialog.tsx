import React, { useEffect, useMemo, useState } from 'react';
import { Dialog } from '@/components/elements/dialog';
import { Button } from '@/components/elements/button/index';
import Input from '@/components/elements/Input';
import Select from '@/components/elements/Select';
import Label from '@/components/elements/Label';
import Spinner from '@/components/elements/Spinner';
import { getGameTemplates } from '@/api/server/gameSlots';
import { GameTemplate } from '@/api/server/gameSlots/types';
import { ServerContext } from '@/state/server';

interface Props {
    open: boolean;
    onClose: () => void;
    onCreate: (data: { name: string; eggId: number; dockerImage?: string; environment: Record<string, string> }) => void;
    submitting: boolean;
}

export default ({ open, onClose, onCreate, submitting }: Props) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const [templates, setTemplates] = useState<GameTemplate[] | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);

    const [name, setName] = useState('');
    const [eggId, setEggId] = useState<number | null>(null);
    const [image, setImage] = useState('');
    const [env, setEnv] = useState<Record<string, string>>({});

    const template = useMemo(() => templates?.find((t) => t.eggId === eggId) ?? null, [templates, eggId]);

    useEffect(() => {
        if (!open) return;

        setTemplates(null);
        setLoadError(null);
        getGameTemplates(uuid)
            .then((data) => setTemplates(data))
            .catch(() => setLoadError('Unable to load the available games.'));
    }, [open, uuid]);

    // Reset the per-template fields whenever the selected game changes.
    useEffect(() => {
        if (!template) return;
        setImage(template.dockerImages[0] ?? '');
        setEnv(
            template.variables.reduce<Record<string, string>>((acc, v) => {
                acc[v.envVariable] = v.defaultValue ?? '';
                return acc;
            }, {})
        );
    }, [template]);

    const valid = name.trim().length > 0 && !!template && image.length > 0;

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title={'Add a game slot'}
            description={'Create a new game that shares this server\'s resources. Only one game runs at a time.'}
        >
            {templates === null ? (
                loadError ? (
                    <p className={'text-sm text-danger-text'}>{loadError}</p>
                ) : (
                    <Spinner centered />
                )
            ) : templates.length === 0 ? (
                <p className={'text-sm text-body-muted'}>
                    No games have been made available for switching on this panel. Ask an administrator to enable one.
                </p>
            ) : (
                <div className={'space-y-4'}>
                    <div>
                        <Label>Game</Label>
                        <Select value={eggId ?? ''} onChange={(e) => setEggId(Number(e.currentTarget.value) || null)}>
                            <option value={''} disabled>
                                Choose a game…
                            </option>
                            {templates.map((t) => (
                                <option key={t.eggId} value={t.eggId}>
                                    {t.name}
                                </option>
                            ))}
                        </Select>
                    </div>

                    <div>
                        <Label>Slot name</Label>
                        <Input
                            value={name}
                            onChange={(e) => setName(e.currentTarget.value)}
                            placeholder={template ? `My ${template.name} world` : 'Slot name'}
                        />
                    </div>

                    {template && template.dockerImages.length > 1 && (
                        <div>
                            <Label>Version / image</Label>
                            <Select value={image} onChange={(e) => setImage(e.currentTarget.value)}>
                                {template.dockerImages.map((img) => (
                                    <option key={img} value={img}>
                                        {img}
                                    </option>
                                ))}
                            </Select>
                        </div>
                    )}

                    {template &&
                        template.variables
                            .filter((v) => v.isEditable)
                            .map((v) => (
                                <div key={v.envVariable}>
                                    <Label>{v.name}</Label>
                                    <Input
                                        value={env[v.envVariable] ?? ''}
                                        onChange={(e) =>
                                            setEnv((prev) => ({ ...prev, [v.envVariable]: e.currentTarget.value }))
                                        }
                                    />
                                    {v.description && (
                                        <p className={'text-xs text-body-muted mt-1'}>{v.description}</p>
                                    )}
                                </div>
                            ))}

                    <div className={'rounded-md border border-line bg-page px-3 py-2 text-xs text-body-muted'}>
                        The new game is created inactive and its files are stored separately. It installs the first time
                        you switch to it, using this server&apos;s shared disk allowance.
                    </div>
                </div>
            )}

            <Dialog.Footer>
                <Button.Text onClick={onClose} disabled={submitting}>
                    Cancel
                </Button.Text>
                <Button
                    disabled={!valid || submitting}
                    onClick={() =>
                        onCreate({ name: name.trim(), eggId: eggId!, dockerImage: image, environment: env })
                    }
                >
                    Create slot
                </Button>
            </Dialog.Footer>
        </Dialog>
    );
};
