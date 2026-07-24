import React from 'react';
import tw from 'twin.macro';
import { NavLink, Redirect, useLocation, useRouteMatch } from 'react-router-dom';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import Can from '@/components/elements/Can';
import AllocationsSection from '@/components/server/network/AllocationsSection';
import SubdomainsSection from '@/components/server/network/SubdomainsSection';

const tabStyle = tw`inline-flex items-center px-4 py-2 rounded-md text-sm font-medium text-neutral-300 hover:text-neutral-100 hover:bg-neutral-600 focus:outline-none transition-colors duration-150`;

const NetworkContainer = () => {
    const match = useRouteMatch();
    const location = useLocation();
    const root = match.url.replace(/\/(allocations|subdomains|routing|domains)\/?$/, '');
    const section = location.pathname.endsWith('/domains') ? 'domains' : 'allocations';

    return (
        <ServerContentBlock showFlashKey={'server:network'} title={'Network'}>
            <div css={tw`mb-6 rounded-lg border border-line bg-surface p-1.5 inline-flex`}>
                <nav aria-label={'Network sections'} css={tw`flex gap-1`}>
                    <NavLink exact to={root} css={tabStyle} activeClassName={'bg-primary-600 !text-white'}>
                        Ports
                    </NavLink>
                    <Can action={'subdomain.read'}>
                        <NavLink to={`${root}/domains`} css={tabStyle} activeClassName={'bg-primary-600 !text-white'}>
                            Domains
                        </NavLink>
                    </Can>
                </nav>
            </div>

            {section === 'allocations' && <AllocationsSection />}
            {section === 'domains' && (
                <Can action={'subdomain.read'} renderOnError={<Redirect to={root} />}>
                    <SubdomainsSection />
                </Can>
            )}
        </ServerContentBlock>
    );
};

export default NetworkContainer;
