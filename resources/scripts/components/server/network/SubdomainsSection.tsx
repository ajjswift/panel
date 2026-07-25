import React, { useEffect, useMemo, useRef, useState } from 'react';
import useSWR from 'swr';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faPlus, faGlobe, faCopy, faCheck, faCircleNotch, faPen, faTrashAlt } from '@fortawesome/free-solid-svg-icons';
import classNames from 'classnames';
import { Dialog } from '@/components/elements/dialog';
import { Button } from '@/components/elements/button/index';
import Input from '@/components/elements/Input';
import Select from '@/components/elements/Select';
import Label from '@/components/elements/Label';
import Spinner from '@/components/elements/Spinner';
import CopyOnClick from '@/components/elements/CopyOnClick';
import { ServerContext } from '@/state/server';
import useFlash, { useFlashKey } from '@/plugins/useFlash';
import { httpErrorToHuman } from '@/api/http';
import {
    createManagedSubdomain,
    deleteManagedSubdomain,
    getManagedSubdomains,
    getNetworkOverview,
    ManagedSubdomain,
    ManagedSubdomainPreview,
    previewManagedSubdomain,
    reassignManagedSubdomain,
    repairManagedSubdomain,
    updateManagedSubdomain,
} from '@/api/server/network/managedSubdomains';
import { friendlyStatus, settingUp } from '@/components/server/network/managedSubdomainStatus';

const toneChip: Record<'ok' | 'busy' | 'attention', string> = {
    ok: 'bg-success/10 text-success-text',
    busy: 'bg-primary-500/10 text-primary-400',
    attention: 'bg-warning/10 text-warning-text',
};

// Clean what the user types into a valid DNS label as they go.
const sanitizeLabel = (value: string): string =>
    value
        .toLowerCase()
        .replace(/[^a-z0-9-]/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-/, '')
        .slice(0, 63);

interface AddressForm {
    open: boolean;
    editing?: ManagedSubdomain | null;
    onClose: () => void;
    onSaved: () => void;
}

const AddressFormDialog = ({ open, editing, onClose, onSaved }: AddressForm) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const allocations = ServerContext.useStoreState((state) => state.server.data!.allocations);
    const { clearAndAddHttpError, clearFlashes } = useFlashKey('server:network');
    const { data: overview } = useSWR(['server:network-overview', uuid], () => getNetworkOverview(uuid), {
        revalidateOnFocus: false,
    });

    const domains = overview?.policy.eligibleDomains || [];
    const [name, setName] = useState('');
    const [domainUuid, setDomainUuid] = useState('');
    const [allocationId, setAllocationId] = useState<number>(allocations[0]?.id || 0);
    const [preview, setPreview] = useState<ManagedSubdomainPreview | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [previewing, setPreviewing] = useState(false);
    const [saving, setSaving] = useState(false);
    const previewTimer = useRef<number>();

    // Seed the form when it opens.
    useEffect(() => {
        if (!open) return;
        setName(editing?.label ?? '');
        setAllocationId(editing?.allocation?.id ?? allocations[0]?.id ?? 0);
        setDomainUuid(editing?.domain.uuid ?? domains[0]?.uuid ?? '');
        setPreview(null);
        setPreviewError(null);
    }, [open]);

    useEffect(() => {
        if (!domainUuid && domains[0]) setDomainUuid(domains[0].uuid);
    }, [domains, domainUuid]);

    const label = sanitizeLabel(name);
    const suffix = domains.find((d) => d.uuid === domainUuid)?.domain || '';

    // Live preview: quietly ask the backend what the address will look like.
    useEffect(() => {
        let active = true;
        window.clearTimeout(previewTimer.current);
        setPreview(null);
        setPreviewError(null);
        setPreviewing(false);
        if (!open || !label || !domainUuid || !allocationId) return;

        previewTimer.current = window.setTimeout(() => {
            setPreviewing(true);
            previewManagedSubdomain(uuid, { label, domainUuid, allocationId })
                .then((result) => active && setPreview(result))
                .catch((error) => {
                    if (!active) return;
                    setPreview(null);

                    // The preview endpoint sees the address being edited as
                    // already owned. Suppress that self-conflict, but surface
                    // genuine conflicts (and other preview failures) inline.
                    if (!editing || `${label}.${suffix}` !== editing.fqdn) {
                        setPreviewError(httpErrorToHuman(error));
                    }
                })
                .then(() => active && setPreviewing(false));
        }, 500);

        return () => {
            active = false;
            window.clearTimeout(previewTimer.current);
        };
    }, [label, domainUuid, allocationId, open]);

    const submit = () => {
        clearFlashes();
        setSaving(true);
        const request = editing
            ? Promise.all([
                  editing.label !== label ? updateManagedSubdomain(uuid, editing.uuid, label) : Promise.resolve(),
                  editing.allocation?.id !== allocationId
                      ? reassignManagedSubdomain(uuid, editing.uuid, allocationId)
                      : Promise.resolve(),
              ])
            : createManagedSubdomain(uuid, { label, domainUuid, allocationId });

        Promise.resolve(request)
            .then(() => {
                onSaved();
                onClose();
            })
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setSaving(false));
    };

    const canSubmit = !!label && !!domainUuid && !!allocationId && !saving;

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title={editing ? 'Edit address' : 'Add an address'}
            description={
                editing
                    ? 'Change the name players use to reach your server.'
                    : 'Give your server a friendly address instead of a number.'
            }
        >
            {domains.length === 0 ? (
                <p className={'text-sm text-body-muted'}>
                    No domains are available for your server yet. Ask your host to add one.
                </p>
            ) : (
                <div className={'space-y-4'}>
                    <div>
                        <Label>Choose a name</Label>
                        <div className={'flex items-stretch gap-2'}>
                            <Input
                                value={name}
                                autoFocus
                                maxLength={63}
                                placeholder={'myserver'}
                                onChange={(e) => setName(e.currentTarget.value)}
                                hasError={!!previewError}
                                className={'flex-1'}
                            />
                            {domains.length > 1 ? (
                                <Select
                                    value={domainUuid}
                                    onChange={(e) => setDomainUuid(e.currentTarget.value)}
                                    className={'w-auto'}
                                >
                                    {domains.map((d) => (
                                        <option key={d.uuid} value={d.uuid}>
                                            .{d.domain}
                                        </option>
                                    ))}
                                </Select>
                            ) : (
                                <span className={'flex items-center px-3 text-sm text-body-muted whitespace-nowrap'}>
                                    .{suffix}
                                </span>
                            )}
                        </div>
                        {label && (
                            <p className={'text-xs text-body-muted mt-1.5'}>
                                Your address will be{' '}
                                <span className={'font-medium text-body'}>
                                    {label}.{suffix}
                                </span>
                            </p>
                        )}
                    </div>

                    {allocations.length > 1 && (
                        <div>
                            <Label>Which port?</Label>
                            <Select
                                value={allocationId}
                                onChange={(e) => setAllocationId(Number(e.currentTarget.value))}
                            >
                                {allocations.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        Port {a.port}
                                        {a.isDefault ? ' (main)' : ''}
                                    </option>
                                ))}
                            </Select>
                        </div>
                    )}

                    <div className={'min-h-[3.5rem] rounded-md border border-line bg-page px-3 py-2.5'}>
                        {previewing ? (
                            <p className={'text-sm text-body-muted flex items-center gap-2'}>
                                <FontAwesomeIcon icon={faCircleNotch} spin className={'w-3.5 h-3.5'} />
                                Checking…
                            </p>
                        ) : previewError ? (
                            <p role={'alert'} className={'text-sm text-danger-text'}>
                                {previewError}
                            </p>
                        ) : preview ? (
                            <>
                                <p className={'text-sm text-body'}>
                                    Players connect with{' '}
                                    <span className={'font-semibold'}>{preview.recordPlan.playerAddress}</span>
                                </p>
                                <p className={'text-xs text-body-muted mt-0.5'}>{preview.recordPlan.friendlyNote}</p>
                            </>
                        ) : (
                            <p className={'text-sm text-body-muted'}>Type a name to see your address.</p>
                        )}
                    </div>
                </div>
            )}

            <Dialog.Footer>
                <Button.Text onClick={onClose} disabled={saving}>
                    Cancel
                </Button.Text>
                <Button onClick={submit} disabled={!canSubmit || domains.length === 0}>
                    {editing ? 'Save' : 'Create address'}
                </Button>
            </Dialog.Footer>
        </Dialog>
    );
};

const SubdomainsSection = () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { clearAndAddHttpError, clearFlashes } = useFlashKey('server:network');
    const { addFlash } = useFlash();
    const lastStates = useRef<Record<string, string>>({});
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<ManagedSubdomain | null>(null);
    const [confirmDelete, setConfirmDelete] = useState<ManagedSubdomain | null>(null);
    const [busy, setBusy] = useState<string | null>(null);

    const overview = useSWR(['server:network-overview', uuid], () => getNetworkOverview(uuid), {
        revalidateOnFocus: false,
    });
    const records = useSWR(['server:managed-subdomains', uuid], () => getManagedSubdomains(uuid), {
        revalidateOnFocus: false,
    });

    const hasBusy = useMemo(() => (records.data || []).some((r) => friendlyStatus(r).tone === 'busy'), [records.data]);

    useEffect(() => {
        clearAndAddHttpError(overview.error || records.error);
    }, [overview.error, records.error]);

    // Poll while something is setting up so the state updates on its own.
    useEffect(() => {
        if (!hasBusy) return;
        const timer = window.setInterval(() => {
            records.mutate();
            overview.mutate();
        }, 3000);
        return () => window.clearInterval(timer);
    }, [hasBusy, uuid]);

    // Friendly toasts as addresses come online or need attention.
    useEffect(() => {
        if (!records.data) return;
        const prev = lastStates.current;
        const next: Record<string, string> = {};
        records.data.forEach((r) => {
            const state = friendlyStatus(r);
            next[r.uuid] = state.tone;
            const was = prev[r.uuid];
            if (was && was !== 'ok' && state.tone === 'ok') {
                addFlash({ key: 'server:network', type: 'success', title: 'Address ready', message: `${r.fqdn} is live.` });
            } else if (was && was !== 'attention' && state.tone === 'attention') {
                addFlash({
                    key: 'server:network',
                    type: 'error',
                    title: 'Address needs attention',
                    message: r.errorMessage || `${r.fqdn} could not be set up.`,
                });
            }
        });
        lastStates.current = next;
    }, [records.data]);

    const remove = (record: ManagedSubdomain) => {
        clearFlashes();
        setBusy(record.uuid);
        deleteManagedSubdomain(uuid, record.uuid)
            .then(() => records.mutate())
            .catch((error) => clearAndAddHttpError(error))
            .then(() => {
                setBusy(null);
                setConfirmDelete(null);
            });
    };

    const retry = (record: ManagedSubdomain) => {
        clearFlashes();
        setBusy(record.uuid);
        repairManagedSubdomain(uuid, record.uuid)
            .then(() => records.mutate())
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setBusy(null));
    };

    if (!overview.data || !records.data) return <Spinner size={'large'} centered />;
    const policy = overview.data.policy;

    return (
        <section aria-labelledby={'domains-title'}>
            <AddressFormDialog
                open={formOpen}
                editing={editing}
                onClose={() => {
                    setFormOpen(false);
                    setEditing(null);
                }}
                onSaved={() => {
                    records.mutate();
                    overview.mutate();
                }}
            />

            <Dialog.Confirm
                open={!!confirmDelete}
                onClose={() => setConfirmDelete(null)}
                title={'Remove this address?'}
                confirm={'Remove address'}
                onConfirmed={() => confirmDelete && remove(confirmDelete)}
            >
                Players will no longer be able to use{' '}
                <span className={'font-semibold text-body'}>{confirmDelete?.fqdn}</span>. Your server and its port are
                not affected.
            </Dialog.Confirm>

            <div className={'flex items-start justify-between gap-3 mb-5'}>
                <div>
                    <h2 id={'domains-title'} className={'text-xl font-semibold text-body'}>
                        Domains
                    </h2>
                    <p className={'text-sm text-body-muted mt-1'}>
                        Give your server a friendly address players can remember, instead of an IP and port.
                        {policy.limit > 0 && ` ${policy.used} of ${policy.limit} used.`}
                    </p>
                </div>
                {policy.canCreate && (
                    <Button className={'shrink-0'} onClick={() => setFormOpen(true)}>
                        <FontAwesomeIcon icon={faPlus} className={'w-3.5 h-3.5 mr-2'} />
                        Add address
                    </Button>
                )}
            </div>

            {!policy.canCreate && policy.disabledReason && (
                <div
                    role={'status'}
                    className={'rounded-md border border-warning/40 bg-warning/10 px-4 py-3 text-sm text-warning-text mb-4'}
                >
                    {policy.disabledReason}
                </div>
            )}

            {records.data.length === 0 ? (
                <div className={'rounded-lg border border-dashed border-line-strong p-10 text-center'}>
                    <FontAwesomeIcon icon={faGlobe} className={'w-8 h-8 text-body-faint mb-3'} />
                    <p className={'text-body font-medium'}>No addresses yet</p>
                    <p className={'text-sm text-body-muted mt-1 max-w-md mx-auto'}>
                        Add one to turn a hard-to-remember IP and port into something like{' '}
                        <span className={'font-medium text-body'}>play.yourname.com</span>.
                    </p>
                    {policy.canCreate && (
                        <Button className={'mt-4'} onClick={() => setFormOpen(true)}>
                            <FontAwesomeIcon icon={faPlus} className={'w-3.5 h-3.5 mr-2'} />
                            Add address
                        </Button>
                    )}
                </div>
            ) : (
                <div className={'space-y-3'}>
                    {records.data.map((record) => {
                        const state = friendlyStatus(record);
                        const rowBusy = busy === record.uuid;

                        return (
                            <article key={record.uuid} className={'rounded-lg border border-line bg-surface p-4'}>
                                <div className={'flex flex-wrap items-start justify-between gap-3'}>
                                    <div className={'min-w-0'}>
                                        <div className={'flex items-center gap-2 flex-wrap'}>
                                            <h3 className={'text-body font-semibold break-all'}>{record.fqdn}</h3>
                                            <span
                                                className={classNames(
                                                    'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-2xs font-semibold uppercase tracking-wide',
                                                    toneChip[state.tone]
                                                )}
                                            >
                                                {state.tone === 'busy' && (
                                                    <FontAwesomeIcon icon={faCircleNotch} spin className={'w-2.5 h-2.5'} />
                                                )}
                                                {state.label}
                                            </span>
                                        </div>
                                        {record.status === 'active' ? (
                                            <p className={'text-sm text-body-muted mt-1'}>
                                                Players connect with{' '}
                                                <CopyOnClick text={record.connectionAddress}>
                                                    <button
                                                        className={
                                                            'font-medium text-body hover:text-primary-400 transition-colors'
                                                        }
                                                    >
                                                        {record.connectionAddress}
                                                        <FontAwesomeIcon icon={faCopy} className={'w-3 h-3 ml-1.5'} />
                                                    </button>
                                                </CopyOnClick>
                                            </p>
                                        ) : (
                                            <p className={'text-sm text-body-muted mt-1'}>
                                                {record.errorMessage || state.hint}
                                            </p>
                                        )}
                                    </div>

                                    <div className={'flex items-center gap-1.5 shrink-0'}>
                                        {state.tone === 'attention' && policy.canRepair && (
                                            <Button.Text
                                                size={Button.Sizes.Small}
                                                disabled={rowBusy}
                                                onClick={() => retry(record)}
                                            >
                                                {rowBusy ? (
                                                    <FontAwesomeIcon icon={faCircleNotch} spin className={'w-3.5 h-3.5'} />
                                                ) : (
                                                    <>
                                                        <FontAwesomeIcon icon={faCheck} className={'w-3.5 h-3.5 mr-1.5'} />
                                                        Try again
                                                    </>
                                                )}
                                            </Button.Text>
                                        )}
                                        {policy.canUpdate && !settingUp.includes(record.status) && (
                                            <Button.Text
                                                size={Button.Sizes.Small}
                                                shape={Button.Shapes.IconSquare}
                                                aria-label={`Edit ${record.fqdn}`}
                                                disabled={rowBusy}
                                                onClick={() => {
                                                    setEditing(record);
                                                    setFormOpen(true);
                                                }}
                                            >
                                                <FontAwesomeIcon icon={faPen} className={'w-3.5 h-3.5'} />
                                            </Button.Text>
                                        )}
                                        {policy.canDelete && (
                                            <Button.Danger
                                                size={Button.Sizes.Small}
                                                shape={Button.Shapes.IconSquare}
                                                variant={Button.Variants.Secondary}
                                                aria-label={`Remove ${record.fqdn}`}
                                                disabled={rowBusy || record.status === 'deleting'}
                                                onClick={() => setConfirmDelete(record)}
                                            >
                                                <FontAwesomeIcon icon={faTrashAlt} className={'w-3.5 h-3.5'} />
                                            </Button.Danger>
                                        )}
                                    </div>
                                </div>
                            </article>
                        );
                    })}
                </div>
            )}
        </section>
    );
};

export default SubdomainsSection;
