import React, { useEffect } from 'react';
import useSWR from 'swr';
import tw from 'twin.macro';
import Spinner from '@/components/elements/Spinner';
import { ServerContext } from '@/state/server';
import { useFlashKey } from '@/plugins/useFlash';
import { getNetworkOverview } from '@/api/server/network/managedSubdomains';

const Summary = ({ label, value, detail }: { label: string; value: React.ReactNode; detail?: string }) => (
    <div css={tw`bg-neutral-700 border border-neutral-600 rounded-lg p-4 min-w-0`}>
        <p css={tw`text-xs uppercase tracking-wide text-neutral-400`}>{label}</p>
        <div css={tw`text-lg font-semibold text-neutral-100 mt-1 truncate`}>{value}</div>
        {detail && <p css={tw`text-xs text-neutral-300 mt-1`}>{detail}</p>}
    </div>
);

const NetworkOverview = () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { clearAndAddHttpError } = useFlashKey('server:network');
    const { data, error } = useSWR(['server:network-overview', uuid], () => getNetworkOverview(uuid), {
        revalidateOnFocus: false,
    });

    useEffect(() => clearAndAddHttpError(error), [error]);

    if (!data) return <Spinner size={'large'} centered />;

    const primary = data.primaryAllocation;
    const primaryAddress = primary ? `${primary.alias || primary.ip}:${primary.port}` : 'Not assigned';

    return (
        <section aria-labelledby={'network-overview-title'}>
            <div css={tw`mb-5`}>
                <h2 id={'network-overview-title'} css={tw`text-xl text-neutral-100 font-semibold`}>
                    Reachability overview
                </h2>
                <p css={tw`text-sm text-neutral-300 mt-1`}>
                    A concise view of how players and services reach this server.
                </p>
            </div>
            <div css={tw`grid gap-4 sm:grid-cols-2 xl:grid-cols-4`}>
                <Summary label={'Primary allocation'} value={primaryAddress} detail={'Direct IP and port'} />
                <Summary label={'Assigned allocations'} value={data.allocationCount} detail={'Existing ports only'} />
                <Summary
                    label={'Managed hostnames'}
                    value={data.hostnameCount === null ? 'No access' : `${data.hostnameCount} / ${data.policy.limit}`}
                    detail={data.policy.serviceProfile?.name || 'No active DNS profile'}
                />
                <Summary
                    label={'DNS health'}
                    value={
                        data.dnsHealth === null ? 'Not visible' : data.dnsHealth === 'healthy' ? 'Healthy' : 'Attention'
                    }
                    detail={
                        data.attentionCount
                            ? `${data.attentionCount} hostname${data.attentionCount === 1 ? '' : 's'} need attention`
                            : 'No synchronization warnings'
                    }
                />
            </div>

            <div css={tw`mt-5 bg-neutral-700 border border-neutral-600 rounded-lg p-5`}>
                <h3 css={tw`text-neutral-100 font-semibold`}>Connection path</h3>
                <div css={tw`mt-4 grid gap-3 md:grid-cols-3 items-stretch`}>
                    <div css={tw`rounded border border-neutral-600 bg-neutral-800 p-3`}>
                        <p css={tw`text-xs text-neutral-400 uppercase`}>Player</p>
                        <p css={tw`text-neutral-100 mt-1`}>Connection address</p>
                    </div>
                    <div css={tw`rounded border border-primary-700 bg-neutral-800 p-3`}>
                        <p css={tw`text-xs text-primary-300 uppercase`}>DNS discovery</p>
                        <p css={tw`text-neutral-100 mt-1`}>
                            {data.policy.serviceProfile?.supportsSrv ? 'Hostname + SRV' : 'Hostname resolution'}
                        </p>
                    </div>
                    <div css={tw`rounded border border-neutral-600 bg-neutral-800 p-3`}>
                        <p css={tw`text-xs text-neutral-400 uppercase`}>Server allocation</p>
                        <p css={tw`text-neutral-100 mt-1 break-all`}>{primaryAddress}</p>
                    </div>
                </div>
                <p css={tw`text-sm text-neutral-300 mt-4`}>
                    {data.policy.serviceProfile?.supportsSrv
                        ? 'The active service supports protocol-aware SRV discovery. The exact record plan is shown before a hostname is created.'
                        : 'DNS resolves a hostname to a host; it does not forward arbitrary ports. Players must include the port unless the service uses its expected default port.'}
                </p>
            </div>

            {data.policy.disabledReason && (
                <div role={'status'} css={tw`mt-5 rounded border border-yellow-700 bg-yellow-900 bg-opacity-20 p-4`}>
                    <p css={tw`text-yellow-200 font-medium`}>
                        {data.policy.enabled ? 'New managed hostname unavailable' : 'Managed subdomains unavailable'}
                    </p>
                    <p css={tw`text-sm text-neutral-200 mt-1`}>{data.policy.disabledReason}</p>
                </div>
            )}
            {data.policy.warnings.map((warning) => (
                <div
                    key={warning}
                    role={'status'}
                    css={tw`mt-3 rounded border border-yellow-700 p-3 text-sm text-yellow-100`}
                >
                    {warning}
                </div>
            ))}
            <div css={tw`mt-5 rounded border border-neutral-600 p-4`}>
                <p css={tw`text-neutral-200 font-medium`}>Routing</p>
                <p css={tw`text-sm text-neutral-400 mt-1`}>
                    Reverse-proxy routing is not configured. This release supports direct DNS and service-specific SRV
                    records only.
                </p>
            </div>
        </section>
    );
};

export default NetworkOverview;
