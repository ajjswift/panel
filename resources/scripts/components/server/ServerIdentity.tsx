import React from 'react';
import classNames from 'classnames';
import { ServerContext } from '@/state/server';
import styles from '@/components/elements/navigation/sidebar.module.css';

const statusLabel = (status: string | null): string => {
    switch (status) {
        case 'running':
            return 'Running';
        case 'starting':
            return 'Starting';
        case 'stopping':
            return 'Stopping';
        case 'offline':
            return 'Offline';
        default:
            return 'Connecting';
    }
};

const statusClass = (status: string | null): string => {
    switch (status) {
        case 'running':
            return 'bg-success';
        case 'starting':
        case 'stopping':
            return 'bg-warning animate-pulse';
        case 'offline':
            return 'bg-danger';
        default:
            return 'bg-line-strong';
    }
};

/**
 * Server identity block shown at the top of the sidebar while managing a
 * server: name, address-agnostic status text and a status dot. Status is not
 * conveyed through color alone.
 */
export default () => {
    const name = ServerContext.useStoreState((state) => state.server.data?.name);
    const status = ServerContext.useStoreState((state) => state.status.value);

    if (!name) {
        return null;
    }

    return (
        <div className={'rounded-md border border-line bg-page px-3 py-2.5'}>
            <p className={'text-sm font-medium text-body truncate'} title={name}>
                {name}
            </p>
            <p className={'flex items-center gap-1.5 mt-1 text-xs text-body-muted'}>
                <span className={classNames(styles.status_dot, statusClass(status))} aria-hidden />
                {statusLabel(status)}
            </p>
        </div>
    );
};
