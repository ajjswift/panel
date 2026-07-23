import React from 'react';
import tw from 'twin.macro';
import { NavLink, Redirect, useLocation, useRouteMatch } from 'react-router-dom';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import Can from '@/components/elements/Can';
import NetworkOverview from '@/components/server/network/NetworkOverview';
import AllocationsSection from '@/components/server/network/AllocationsSection';
import SubdomainsSection from '@/components/server/network/SubdomainsSection';
import RoutingSection from '@/components/server/network/RoutingSection';

const tabStyle = tw`inline-flex items-center px-3 py-2 rounded-md text-sm font-medium text-neutral-300 hover:text-neutral-100 hover:bg-neutral-700 focus:outline-none`;

const NetworkContainer = () => {
    const match = useRouteMatch();
    const location = useLocation();
    const root = match.url.replace(/\/(allocations|subdomains|routing)\/?$/, '');
    const section = location.pathname.endsWith('/allocations')
        ? 'allocations'
        : location.pathname.endsWith('/subdomains')
        ? 'subdomains'
        : location.pathname.endsWith('/routing')
        ? 'routing'
        : 'overview';

    return (
        <ServerContentBlock showFlashKey={'server:network'} title={'Network'}>
            <div css={tw`mb-6 rounded-lg border border-neutral-600 bg-neutral-800 p-2`}>
                <nav aria-label={'Network sections'} css={tw`flex gap-1 overflow-x-auto`}>
                    <NavLink
                        exact
                        to={root}
                        css={tabStyle}
                        activeClassName={'bg-primary-600 text-white'}
                        aria-label={'Network overview'}
                    >
                        Overview
                    </NavLink>
                    <Can action={'allocation.read'}>
                        <NavLink
                            to={`${root}/allocations`}
                            css={tabStyle}
                            activeClassName={'bg-primary-600 text-white'}
                        >
                            Allocations
                        </NavLink>
                    </Can>
                    <Can action={'subdomain.read'}>
                        <NavLink to={`${root}/subdomains`} css={tabStyle} activeClassName={'bg-primary-600 text-white'}>
                            Subdomains
                        </NavLink>
                    </Can>
                    <NavLink to={`${root}/routing`} css={tabStyle} activeClassName={'bg-primary-600 text-white'}>
                        Routing
                    </NavLink>
                </nav>
            </div>

            {section === 'overview' && <NetworkOverview />}
            {section === 'allocations' && (
                <Can action={'allocation.read'} renderOnError={<Redirect to={root} />}>
                    <AllocationsSection />
                </Can>
            )}
            {section === 'subdomains' && (
                <Can action={'subdomain.read'} renderOnError={<Redirect to={root} />}>
                    <SubdomainsSection />
                </Can>
            )}
            {section === 'routing' && <RoutingSection />}
        </ServerContentBlock>
    );
};

export default NetworkContainer;
