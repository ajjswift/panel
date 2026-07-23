import React, { useEffect, useMemo, useRef, useState } from 'react';
import useSWR from 'swr';
import tw from 'twin.macro';
import Modal from '@/components/elements/Modal';
import Button from '@/components/elements/Button';
import Input from '@/components/elements/Input';
import Select from '@/components/elements/Select';
import Spinner from '@/components/elements/Spinner';
import Can from '@/components/elements/Can';
import { ServerContext } from '@/state/server';
import useFlash, { useFlashKey } from '@/plugins/useFlash';
import {
    createManagedSubdomain,
    deleteManagedSubdomain,
    getManagedSubdomains,
    getNetworkOverview,
    ManagedSubdomain,
    ManagedSubdomainPreview,
    previewManagedSubdomain,
    reassignManagedSubdomain,
    refreshManagedSubdomain,
    repairManagedSubdomain,
    updateManagedSubdomain,
} from '@/api/server/network/managedSubdomains';

const workingStatuses = ['pending', 'creating', 'updating', 'deleting'];

const statusTone = (status: string) => {
    if (status === 'active') return tw`bg-green-900 text-green-200 border-green-700`;
    if (workingStatuses.includes(status)) return tw`bg-blue-900 text-blue-200 border-blue-700`;
    return tw`bg-yellow-900 text-yellow-100 border-yellow-700`;
};

const CreateSubdomainModal = ({
    visible,
    onDismissed,
    onCreated,
}: {
    visible: boolean;
    onDismissed: () => void;
    onCreated: (subdomain: ManagedSubdomain) => void;
}) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const allocations = ServerContext.useStoreState((state) => state.server.data!.allocations);
    const { clearAndAddHttpError, clearFlashes } = useFlashKey('server:network');
    const { data: overview } = useSWR(['server:network-overview', uuid], () => getNetworkOverview(uuid), {
        revalidateOnFocus: false,
    });
    const [label, setLabel] = useState('');
    const [domainUuid, setDomainUuid] = useState('');
    const [allocationId, setAllocationId] = useState<number>(allocations[0]?.id || 0);
    const [preview, setPreview] = useState<ManagedSubdomainPreview | null>(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (!domainUuid && overview?.policy.eligibleDomains[0]) {
            setDomainUuid(overview.policy.eligibleDomains[0].uuid);
        }
    }, [overview, domainUuid]);

    const values = { label, domainUuid, allocationId };
    const canSubmit = !!label.trim() && !!domainUuid && !!allocationId;

    const runPreview = () => {
        clearFlashes();
        setLoading(true);
        previewManagedSubdomain(uuid, values)
            .then(setPreview)
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setLoading(false));
    };

    const create = () => {
        clearFlashes();
        setLoading(true);
        createManagedSubdomain(uuid, values)
            .then((record) => {
                onCreated(record);
                setLabel('');
                setPreview(null);
                onDismissed();
            })
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setLoading(false));
    };

    return (
        <Modal visible={visible} onDismissed={onDismissed} showSpinnerOverlay={loading}>
            <div role={'dialog'} aria-modal={'true'} aria-labelledby={'create-managed-hostname-title'}>
                <h2 id={'create-managed-hostname-title'} css={tw`text-xl text-neutral-100 font-semibold`}>
                    Create managed hostname
                </h2>
                <p css={tw`text-sm text-neutral-300 mt-1`}>
                    Select an existing allocation. This workflow will not change the server&apos;s allocations.
                </p>
                <div css={tw`grid gap-4 mt-5 sm:grid-cols-2`}>
                    <label css={tw`block text-sm text-neutral-200`}>
                        Label
                        <Input
                            css={tw`mt-1`}
                            value={label}
                            maxLength={63}
                            placeholder={'play'}
                            onChange={(event) => {
                                setLabel(event.currentTarget.value);
                                setPreview(null);
                            }}
                        />
                    </label>
                    <label css={tw`block text-sm text-neutral-200`}>
                        Parent domain
                        <Select
                            css={tw`mt-1`}
                            value={domainUuid}
                            onChange={(event) => {
                                setDomainUuid(event.currentTarget.value);
                                setPreview(null);
                            }}
                        >
                            {(overview?.policy.eligibleDomains || []).map((domain) => (
                                <option key={domain.uuid} value={domain.uuid}>
                                    {domain.domain}
                                </option>
                            ))}
                        </Select>
                    </label>
                    <label css={tw`block text-sm text-neutral-200 sm:col-span-2`}>
                        Existing server allocation
                        <Select
                            css={tw`mt-1`}
                            value={allocationId}
                            onChange={(event) => {
                                setAllocationId(Number(event.currentTarget.value));
                                setPreview(null);
                            }}
                        >
                            {allocations.map((allocation) => (
                                <option key={allocation.id} value={allocation.id}>
                                    {allocation.alias || allocation.ip}:{allocation.port}
                                    {allocation.isDefault ? ' — primary' : ''}
                                </option>
                            ))}
                        </Select>
                    </label>
                </div>

                {preview && (
                    <div css={tw`mt-5 rounded-lg border border-primary-700 bg-neutral-800 p-4`}>
                        <div css={tw`grid gap-3 sm:grid-cols-2`}>
                            <div>
                                <p css={tw`text-xs uppercase text-neutral-400`}>Hostname</p>
                                <p css={tw`text-neutral-100 break-all`}>{preview.fqdn}</p>
                            </div>
                            <div>
                                <p css={tw`text-xs uppercase text-neutral-400`}>Player connection address</p>
                                <p css={tw`text-neutral-100 break-all`}>{preview.recordPlan.connectionAddress}</p>
                            </div>
                            <div>
                                <p css={tw`text-xs uppercase text-neutral-400`}>Active service</p>
                                <p css={tw`text-neutral-100`}>{preview.serviceProfile.name}</p>
                            </div>
                            <div>
                                <p css={tw`text-xs uppercase text-neutral-400`}>Public DNS target</p>
                                <p css={tw`text-neutral-100 break-all`}>
                                    {preview.publicTarget.type} {preview.publicTarget.value}
                                </p>
                            </div>
                        </div>
                        <p css={tw`text-sm text-neutral-200 mt-4`}>{preview.recordPlan.explanation}</p>
                        <div css={tw`mt-3 space-y-2`}>
                            {preview.recordPlan.records.map((record, index) => (
                                <div
                                    key={`${record.type}-${record.name}-${index}`}
                                    css={tw`text-xs font-mono text-neutral-300`}
                                >
                                    {String(record.type)} {String(record.name)}
                                    {record.content ? ` → ${String(record.content)}` : ''}
                                    {record.port ? ` → ${String(record.target)}:${String(record.port)}` : ''}
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                <div css={tw`mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end`}>
                    <Button type={'button'} color={'grey'} isSecondary onClick={onDismissed}>
                        Cancel
                    </Button>
                    {!preview ? (
                        <Button type={'button'} disabled={!canSubmit} onClick={runPreview}>
                            Preview DNS records
                        </Button>
                    ) : (
                        <Button type={'button'} disabled={!canSubmit} onClick={create}>
                            Confirm and create
                        </Button>
                    )}
                </div>
            </div>
        </Modal>
    );
};

const SubdomainsSection = () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const allocations = ServerContext.useStoreState((state) => state.server.data!.allocations);
    const { clearAndAddHttpError, clearFlashes } = useFlashKey('server:network');
    const { addFlash } = useFlash();
    const lastStates = useRef<Record<string, { fqdn: string; status: string }>>({});
    const [creating, setCreating] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);
    const overview = useSWR(['server:network-overview', uuid], () => getNetworkOverview(uuid), {
        revalidateOnFocus: false,
    });
    const records = useSWR(['server:managed-subdomains', uuid], () => getManagedSubdomains(uuid), {
        revalidateOnFocus: false,
    });

    const hasWorkingRecord = useMemo(
        () => (records.data || []).some((record) => workingStatuses.includes(record.status)),
        [records.data]
    );

    useEffect(() => {
        clearAndAddHttpError(overview.error || records.error);
    }, [overview.error, records.error]);

    useEffect(() => {
        if (!hasWorkingRecord) return;
        const timer = window.setInterval(() => {
            records.mutate();
            overview.mutate();
        }, 3000);

        return () => window.clearInterval(timer);
    }, [hasWorkingRecord, uuid]);

    useEffect(() => {
        if (!records.data) return;
        const previous = lastStates.current;
        const next: Record<string, { fqdn: string; status: string }> = {};
        records.data.forEach((record) => {
            next[record.uuid] = { fqdn: record.fqdn, status: record.status };
            const old = previous[record.uuid];
            if (old && workingStatuses.includes(old.status) && record.status === 'active') {
                addFlash({
                    key: 'server:network',
                    type: 'success',
                    title: 'DNS synchronized',
                    message: `${record.fqdn} is active.`,
                });
            } else if (
                old &&
                workingStatuses.includes(old.status) &&
                ['failed', 'repair_required'].includes(record.status)
            ) {
                addFlash({
                    key: 'server:network',
                    type: 'error',
                    title: 'DNS needs attention',
                    message: record.errorMessage || `${record.fqdn} could not be synchronized.`,
                });
            } else if (
                old?.status === 'active' &&
                ['incompatible', 'restricted', 'over_limit'].includes(record.status)
            ) {
                addFlash({
                    key: 'server:network',
                    type: 'warning',
                    title: 'Managed hostname changed',
                    message: record.errorMessage || `${record.fqdn} now needs administrator attention.`,
                });
            }
        });
        Object.entries(previous).forEach(([id, record]) => {
            if (record.status === 'deleting' && !next[id]) {
                addFlash({
                    key: 'server:network',
                    type: 'success',
                    title: 'Hostname deleted',
                    message: `${record.fqdn} and its owned DNS records were removed.`,
                });
            }
        });
        lastStates.current = next;
    }, [records.data]);

    const action = (record: ManagedSubdomain, operation: 'repair' | 'refresh' | 'delete') => {
        if (operation === 'delete' && !window.confirm(`Delete ${record.fqdn} and its owned DNS records?`)) return;
        clearFlashes();
        setBusy(record.uuid);
        const request =
            operation === 'delete'
                ? deleteManagedSubdomain(uuid, record.uuid)
                : operation === 'repair'
                ? repairManagedSubdomain(uuid, record.uuid)
                : refreshManagedSubdomain(uuid, record.uuid);
        request
            .then(() => records.mutate())
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setBusy(null));
    };

    const reassign = (record: ManagedSubdomain, allocationId: number) => {
        if (allocationId === record.allocation?.id) return;
        setBusy(record.uuid);
        reassignManagedSubdomain(uuid, record.uuid, allocationId)
            .then(() => records.mutate())
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setBusy(null));
    };

    const rename = (record: ManagedSubdomain) => {
        const label = window.prompt('Enter the new hostname label', record.label)?.trim();
        if (!label || label === record.label) return;
        setBusy(record.uuid);
        updateManagedSubdomain(uuid, record.uuid, label)
            .then(() => records.mutate())
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setBusy(null));
    };

    if (!overview.data || !records.data) return <Spinner size={'large'} centered />;
    const policy = overview.data.policy;

    return (
        <section aria-labelledby={'network-subdomains-title'}>
            <CreateSubdomainModal
                visible={creating}
                onDismissed={() => setCreating(false)}
                onCreated={(record) => records.mutate([record, ...(records.data || [])], false)}
            />
            <div css={tw`sm:flex sm:items-start sm:justify-between mb-5`}>
                <div>
                    <h2 id={'network-subdomains-title'} css={tw`text-xl text-neutral-100 font-semibold`}>
                        Managed subdomains
                    </h2>
                    <p css={tw`text-sm text-neutral-300 mt-1`}>
                        {policy.used} of {policy.limit} hostnames used. DNS changes synchronize in the background.
                    </p>
                </div>
                {policy.canCreate && (
                    <Button css={tw`mt-4 w-full sm:mt-0 sm:w-auto`} onClick={() => setCreating(true)}>
                        Create hostname
                    </Button>
                )}
            </div>

            {!policy.canCreate && policy.disabledReason && (
                <div role={'status'} css={tw`rounded border border-yellow-700 bg-yellow-900 bg-opacity-20 p-4 mb-4`}>
                    <p css={tw`text-yellow-100 font-medium`}>Creation unavailable</p>
                    <p css={tw`text-sm text-neutral-200 mt-1`}>{policy.disabledReason}</p>
                </div>
            )}

            {records.data.length === 0 ? (
                <div css={tw`rounded-lg border border-dashed border-neutral-500 p-8 text-center`}>
                    <p css={tw`text-neutral-200 font-medium`}>No managed hostnames</p>
                    <p css={tw`text-sm text-neutral-400 mt-1`}>
                        Create one to connect an approved hostname to an allocation already assigned to this server.
                    </p>
                </div>
            ) : (
                <div css={tw`space-y-4`}>
                    {records.data.map((record) => (
                        <article key={record.uuid} css={tw`rounded-lg border border-neutral-600 bg-neutral-700 p-4`}>
                            <div css={tw`flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between`}>
                                <div css={tw`min-w-0`}>
                                    <div css={tw`flex flex-wrap items-center gap-2`}>
                                        <h3 css={tw`text-neutral-100 font-semibold break-all`}>{record.fqdn}</h3>
                                        <span
                                            css={[
                                                tw`border rounded px-2 py-0.5 text-xs capitalize`,
                                                statusTone(record.status),
                                            ]}
                                        >
                                            {record.status.replace(/_/g, ' ')}
                                        </span>
                                    </div>
                                    <p css={tw`text-sm text-neutral-300 mt-1`}>
                                        Connect with{' '}
                                        <span css={tw`font-mono text-neutral-100`}>{record.connectionAddress}</span>
                                    </p>
                                    <p css={tw`text-xs text-neutral-400 mt-1`}>
                                        {record.detectedService} · {record.recordPlan.explanation}
                                    </p>
                                </div>
                                <div css={tw`text-xs text-neutral-400 sm:text-right`}>
                                    <p>{record.recordCount} owned DNS record(s)</p>
                                    <p>
                                        {record.lastSynchronizedAt
                                            ? 'Last synchronized'
                                            : 'Awaiting first synchronization'}
                                    </p>
                                </div>
                            </div>

                            {record.errorMessage && (
                                <p
                                    role={'alert'}
                                    css={tw`mt-3 rounded border border-yellow-700 p-3 text-sm text-yellow-100`}
                                >
                                    {record.errorMessage}
                                </p>
                            )}

                            <div css={tw`mt-4 grid gap-3 md:grid-cols-2`}>
                                <Can
                                    action={'subdomain.reassign'}
                                    renderOnError={
                                        <div css={tw`text-xs text-neutral-300`}>
                                            Target allocation
                                            <p css={tw`mt-2 text-sm text-neutral-100`}>
                                                {record.allocation
                                                    ? `${record.allocation.alias || record.allocation.ip}:${
                                                          record.allocation.port
                                                      }`
                                                    : 'Allocation missing'}
                                            </p>
                                        </div>
                                    }
                                >
                                    <label css={tw`text-xs text-neutral-300`}>
                                        Target allocation
                                        <Select
                                            css={tw`mt-1`}
                                            disabled={busy === record.uuid || workingStatuses.includes(record.status)}
                                            value={record.allocation?.id || ''}
                                            onChange={(event) => reassign(record, Number(event.currentTarget.value))}
                                        >
                                            {allocations.map((allocation) => (
                                                <option key={allocation.id} value={allocation.id}>
                                                    {allocation.alias || allocation.ip}:{allocation.port}
                                                </option>
                                            ))}
                                        </Select>
                                    </label>
                                </Can>
                                <div css={tw`flex flex-wrap gap-2 items-end md:justify-end`}>
                                    {policy.canUpdate && (
                                        <Button
                                            size={'xsmall'}
                                            color={'grey'}
                                            isSecondary
                                            disabled={busy === record.uuid || workingStatuses.includes(record.status)}
                                            onClick={() => rename(record)}
                                        >
                                            Edit label
                                        </Button>
                                    )}
                                    <Can action={'subdomain.refresh'}>
                                        <Button
                                            size={'xsmall'}
                                            color={'grey'}
                                            isSecondary
                                            disabled={busy === record.uuid}
                                            onClick={() => action(record, 'refresh')}
                                        >
                                            Check DNS
                                        </Button>
                                    </Can>
                                    {policy.canRepair && record.status !== 'active' && (
                                        <Button
                                            size={'xsmall'}
                                            color={'primary'}
                                            isSecondary
                                            disabled={busy === record.uuid}
                                            onClick={() => action(record, 'repair')}
                                        >
                                            Repair DNS
                                        </Button>
                                    )}
                                    {policy.canDelete && (
                                        <Button
                                            size={'xsmall'}
                                            color={'red'}
                                            isSecondary
                                            disabled={busy === record.uuid}
                                            onClick={() => action(record, 'delete')}
                                        >
                                            Delete
                                        </Button>
                                    )}
                                </div>
                            </div>
                        </article>
                    ))}
                </div>
            )}
        </section>
    );
};

export default SubdomainsSection;
