import React from 'react';
import { Route, Switch } from 'react-router-dom';
import { faLayerGroup } from '@fortawesome/free-solid-svg-icons';
import DashboardContainer from '@/components/dashboard/DashboardContainer';
import { NotFound } from '@/components/elements/ScreenBlock';
import TransitionRouter from '@/TransitionRouter';
import { useLocation } from 'react-router';
import Spinner from '@/components/elements/Spinner';
import PanelLayout from '@/components/elements/navigation/PanelLayout';
import { SidebarSection } from '@/components/elements/navigation/Sidebar';
import routes from '@/routers/routes';

const sections: SidebarSection[] = [
    {
        items: [{ label: 'Dashboard', icon: faLayerGroup, to: '/', exact: true }],
    },
    {
        title: 'Account',
        items: routes.account
            .filter((route) => !!route.name)
            .map((route) => ({
                label: route.name!,
                icon: route.icon || faLayerGroup,
                to: `/account/${route.path}`.replace(/\/+$/, '').replace('//', '/') || '/account',
                exact: route.exact,
            })),
    },
];

export default () => {
    const location = useLocation();

    return (
        <PanelLayout sections={sections} showAccountLink={false}>
            <TransitionRouter>
                <React.Suspense fallback={<Spinner centered />}>
                    <Switch location={location}>
                        <Route path={'/'} exact>
                            <DashboardContainer />
                        </Route>
                        {routes.account.map(({ path, component: Component }) => (
                            <Route key={path} path={`/account/${path}`.replace('//', '/')} exact>
                                <Component />
                            </Route>
                        ))}
                        <Route path={'*'}>
                            <NotFound />
                        </Route>
                    </Switch>
                </React.Suspense>
            </TransitionRouter>
        </PanelLayout>
    );
};
