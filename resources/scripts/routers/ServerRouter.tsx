import TransferListener from '@/components/server/TransferListener';
import React, { useEffect, useState } from 'react';
import { Route, Switch, useRouteMatch } from 'react-router-dom';
import { faLayerGroup, faTerminal } from '@fortawesome/free-solid-svg-icons';
import TransitionRouter from '@/TransitionRouter';
import WebsocketHandler from '@/components/server/WebsocketHandler';
import { ServerContext } from '@/state/server';
import Spinner from '@/components/elements/Spinner';
import { NotFound, ServerError } from '@/components/elements/ScreenBlock';
import { httpErrorToHuman } from '@/api/http';
import { useStoreState } from 'easy-peasy';
import InstallListener from '@/components/server/InstallListener';
import ErrorBoundary from '@/components/elements/ErrorBoundary';
import { useLocation } from 'react-router';
import ConflictStateRenderer from '@/components/server/ConflictStateRenderer';
import PermissionRoute from '@/components/elements/PermissionRoute';
import PanelLayout from '@/components/elements/navigation/PanelLayout';
import { SidebarSection } from '@/components/elements/navigation/Sidebar';
import ServerIdentity from '@/components/server/ServerIdentity';
import routes from '@/routers/routes';

export default () => {
    const match = useRouteMatch<{ id: string }>();
    const location = useLocation();

    const rootAdmin = useStoreState((state) => state.user.data!.rootAdmin);
    const [error, setError] = useState('');

    const id = ServerContext.useStoreState((state) => state.server.data?.id);
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const inConflictState = ServerContext.useStoreState((state) => state.server.inConflictState);
    const isSwitchingGame = ServerContext.useStoreState((state) => state.server.data?.status === 'switching_game');
    const featureLimits = ServerContext.useStoreState((state) => state.server.data?.featureLimits);
    const serverId = ServerContext.useStoreState((state) => state.server.data?.internalId);
    const getServer = ServerContext.useStoreActions((actions) => actions.server.getServer);
    const clearServerState = ServerContext.useStoreActions((actions) => actions.clearServerState);

    const to = (value: string, url = false) => {
        if (value === '/') {
            return url ? match.url : match.path;
        }
        return `${(url ? match.url : match.path).replace(/\/*$/, '')}/${value.replace(/^\/+/, '')}`;
    };

    const visible = routes.server.filter((route) => {
        if (!route.name) {
            return false;
        }

        if (route.path === '/game-slots') {
            return (featureLimits?.gameSlots ?? 0) > 1;
        }

        if (route.path === '/backups') {
            return (featureLimits?.backups ?? 0) > 0;
        }

        return true;
    });
    const sections: SidebarSection[] = [
        {
            items: [{ label: 'Dashboard', icon: faLayerGroup, to: '/', exact: true }],
        },
        {
            title: 'Server',
            items: visible
                .filter((route) => route.group !== 'management')
                .map((route) => ({
                    label: route.name!,
                    icon: route.icon || faTerminal,
                    to: to(route.path, true),
                    exact: route.exact,
                    permission: route.permission,
                    indicator:
                        route.path === '/game-slots' && isSwitchingGame
                            ? ({ label: 'Switching games', tone: 'active' } as const)
                            : undefined,
                })),
        },
        {
            title: 'Management',
            items: [
                ...visible
                    .filter((route) => route.group === 'management')
                    .map((route) => ({
                        label: route.name!,
                        icon: route.icon || faTerminal,
                        to: to(route.path, true),
                        exact: route.exact,
                        permission: route.permission,
                    })),
                ...(rootAdmin && serverId
                    ? [
                          {
                              label: 'Manage in Admin',
                              icon: faLayerGroup,
                              href: `/admin/servers/view/${serverId}`,
                              external: true,
                          },
                      ]
                    : []),
            ],
        },
    ];

    useEffect(
        () => () => {
            clearServerState();
        },
        []
    );

    useEffect(() => {
        setError('');

        getServer(match.params.id).catch((error) => {
            console.error(error);
            setError(httpErrorToHuman(error));
        });

        return () => {
            clearServerState();
        };
    }, [match.params.id]);

    return (
        <React.Fragment key={'server-router'}>
            <PanelLayout sections={!uuid || !id ? [sections[0]] : sections} header={<ServerIdentity />}>
                {!uuid || !id ? (
                    error ? (
                        <ServerError message={error} />
                    ) : (
                        <Spinner size={'large'} centered />
                    )
                ) : (
                    <>
                        <InstallListener />
                        <TransferListener />
                        <WebsocketHandler />
                        {inConflictState &&
                        (!rootAdmin || (rootAdmin && !location.pathname.endsWith(`/server/${id}`))) ? (
                            <ConflictStateRenderer />
                        ) : (
                            <ErrorBoundary>
                                <TransitionRouter>
                                    <Switch location={location}>
                                        {routes.server.map(({ path, permission, component: Component }) => (
                                            <PermissionRoute key={path} permission={permission} path={to(path)} exact>
                                                <Spinner.Suspense>
                                                    <Component />
                                                </Spinner.Suspense>
                                            </PermissionRoute>
                                        ))}
                                        <Route path={'*'} component={NotFound} />
                                    </Switch>
                                </TransitionRouter>
                            </ErrorBoundary>
                        )}
                    </>
                )}
            </PanelLayout>
        </React.Fragment>
    );
};
