import React from 'react';
import tw from 'twin.macro';
import useSWR from 'swr';
import { NavLink, Redirect, useLocation, useRouteMatch } from 'react-router-dom';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import AllocationsSection from '@/components/server/network/AllocationsSection';
import SubdomainsSection from '@/components/server/network/SubdomainsSection';
import { getNetworkOverview } from '@/api/server/network/managedSubdomains';
import { usePermissions } from '@/plugins/usePermissions';
import { ServerContext } from '@/state/server';

const tabStyle = tw`inline-flex items-center px-4 py-2 rounded-md text-sm font-medium text-neutral-300 hover:text-neutral-100 hover:bg-neutral-600 focus:outline-none transition-colors duration-150`;

const NetworkContainer = () => {
    const match = useRouteMatch();
    const location = useLocation();
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const root = match.url.replace(/\/(allocations|subdomains|routing|domains)\/?$/, '');
    const section = location.pathname.endsWith('/domains') ? 'domains' : 'allocations';

    // Shares its cache entry with SubdomainsSection, so this costs no extra
    // request on the way to the Domains tab.
    const { data: overview } = useSWR(['server:network-overview', uuid], () => getNetworkOverview(uuid), {
        revalidateOnFocus: false,
    });

    const [canReadSubdomains] = usePermissions(['subdomain.read']);

    // Servers deliberately allowed no managed hostnames get no Domains tab at
    // all. Only hide once we actually know: while the overview is loading (or
    // if it failed) the tab stays put, so the common case never waits on a
    // request and a broken overview never strands existing records.
    const showDomains = canReadSubdomains && (overview?.policy.visible ?? true);

    return (
        <ServerContentBlock showFlashKey={'server:network'} title={'Network'}>
            {/* With nothing to switch to, a lone always-active tab is just noise. */}
            {showDomains && (
                <div css={tw`mb-6 rounded-lg border border-line bg-surface p-1.5 inline-flex`}>
                    <nav aria-label={'Network sections'} css={tw`flex gap-1`}>
                        <NavLink exact to={root} css={tabStyle} activeClassName={'bg-primary-600 !text-white'}>
                            Ports
                        </NavLink>
                        <NavLink to={`${root}/domains`} css={tabStyle} activeClassName={'bg-primary-600 !text-white'}>
                            Domains
                        </NavLink>
                    </nav>
                </div>
            )}

            {section === 'allocations' && <AllocationsSection />}
            {section === 'domains' && (showDomains ? <SubdomainsSection /> : <Redirect to={root} />)}
        </ServerContentBlock>
    );
};

export default NetworkContainer;
