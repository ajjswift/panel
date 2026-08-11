import React, { useState } from 'react';
import { Link, NavLink } from 'react-router-dom';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import {
    faAngleDoubleLeft,
    faAngleDoubleRight,
    faCogs,
    faExternalLinkAlt,
    faHandshake,
    faSearch,
    faSignOutAlt,
    faUserCircle,
} from '@fortawesome/free-solid-svg-icons';
import classNames from 'classnames';
import { useStoreState } from 'easy-peasy';
import { ApplicationStore } from '@/state';
import http from '@/api/http';
import SearchModal from '@/components/dashboard/search/SearchModal';
import Tooltip from '@/components/elements/tooltip/Tooltip';
import Can from '@/components/elements/Can';
import ThemeSwitcher from '@/components/elements/ThemeSwitcher';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import styles from './sidebar.module.css';

export interface SidebarItem {
    label: string;
    icon: IconDefinition;
    // Internal route target; rendered as a router NavLink.
    to?: string;
    // External target; rendered as a plain anchor.
    href?: string;
    exact?: boolean;
    // When set the item is wrapped in a <Can> check (server context only).
    permission?: string | string[] | null;
    external?: boolean;
    // Optional short status indicator (e.g. "switching"). Rendered as a small
    // pulsing dot with an accessible label so it reads without color alone.
    indicator?: { label: string; tone: 'active' | 'warning' | 'danger' };
}

export interface SidebarSection {
    title?: string;
    items: SidebarItem[];
}

interface Props {
    sections: SidebarSection[];
    // Optional identity block rendered under the brand (e.g. current server).
    header?: React.ReactNode;
    collapsed?: boolean;
    onToggleCollapsed?: () => void;
    // Inside the mobile drawer the sidebar is always expanded and has no
    // collapse control.
    inDrawer?: boolean;
    // Hidden when the main navigation already contains account links.
    showAccountLink?: boolean;
}

const WithTooltip = ({ show, label, children }: { show: boolean; label: string; children: React.ReactElement }) =>
    show ? (
        <Tooltip placement={'right'} content={label}>
            {children}
        </Tooltip>
    ) : (
        children
    );

const toneClass: Record<'active' | 'warning' | 'danger', string> = {
    active: 'bg-primary-400',
    warning: 'bg-warning',
    danger: 'bg-danger',
};

const ItemContent = ({ item, collapsed }: { item: SidebarItem; collapsed: boolean }) => (
    <>
        <span className={'relative'}>
            <FontAwesomeIcon icon={item.icon} fixedWidth />
            {item.indicator && collapsed && (
                <span
                    className={classNames(
                        'absolute -top-1 -right-1 w-2 h-2 rounded-full animate-pulse',
                        toneClass[item.indicator.tone]
                    )}
                    aria-hidden
                />
            )}
        </span>
        {!collapsed && <span className={styles.label}>{item.label}</span>}
        {!collapsed && item.indicator && (
            <span
                className={classNames('inline-flex items-center gap-1 text-2xs font-medium text-body-muted')}
                aria-label={item.indicator.label}
            >
                <span
                    className={classNames('w-1.5 h-1.5 rounded-full animate-pulse', toneClass[item.indicator.tone])}
                    aria-hidden
                />
            </span>
        )}
        {!collapsed && item.external && (
            <FontAwesomeIcon icon={faExternalLinkAlt} className={'!w-3 !h-3 text-body-faint'} />
        )}
    </>
);

const NavItem = ({ item, collapsed }: { item: SidebarItem; collapsed: boolean }) => {
    const link =
        item.to !== undefined ? (
            <NavLink to={item.to} exact={item.exact} className={styles.item} activeClassName={styles.active}>
                <ItemContent item={item} collapsed={collapsed} />
            </NavLink>
        ) : (
            <a
                href={item.href}
                className={styles.item}
                {...(item.external ? { target: '_blank', rel: 'noreferrer' } : {})}
            >
                <ItemContent item={item} collapsed={collapsed} />
            </a>
        );

    const wrapped = (
        <WithTooltip show={collapsed} label={item.label}>
            {link}
        </WithTooltip>
    );

    return item.permission ? (
        <Can action={item.permission} matchAny>
            {wrapped}
        </Can>
    ) : (
        wrapped
    );
};

const ActionItem = ({
    label,
    icon,
    onClick,
    collapsed,
    danger,
}: {
    label: string;
    icon: IconDefinition;
    onClick: () => void;
    collapsed: boolean;
    danger?: boolean;
}) => (
    <WithTooltip show={collapsed} label={label}>
        <button
            type={'button'}
            onClick={onClick}
            className={classNames(styles.item, danger && styles.danger, 'w-[calc(100%-1rem)] text-left')}
        >
            <FontAwesomeIcon icon={icon} fixedWidth />
            {!collapsed && <span className={styles.label}>{label}</span>}
        </button>
    </WithTooltip>
);

export default ({
    sections,
    header,
    collapsed = false,
    onToggleCollapsed,
    inDrawer = false,
    showAccountLink = true,
}: Props) => {
    const name = useStoreState((state: ApplicationStore) => state.settings.data!.name);
    const logo = useStoreState((state: ApplicationStore) => state.settings.data!.logo);
    const rootAdmin = useStoreState((state: ApplicationStore) => state.user.data!.rootAdmin);
    const reseller = useStoreState((state: ApplicationStore) => state.user.data!.reseller);
    const [isLoggingOut, setIsLoggingOut] = useState(false);
    const [searchVisible, setSearchVisible] = useState(false);

    const isCollapsed = collapsed && !inDrawer;

    const onTriggerLogout = () => {
        setIsLoggingOut(true);
        http.post('/auth/logout').finally(() => {
            // @ts-expect-error this is valid
            window.location = '/';
        });
    };

    return (
        <nav className={classNames(styles.sidebar, isCollapsed && styles.collapsed)} aria-label={'Primary navigation'}>
            <SpinnerOverlay visible={isLoggingOut} />
            {searchVisible && (
                <SearchModal appear visible={searchVisible} onDismissed={() => setSearchVisible(false)} />
            )}
            <Link to={'/'} className={styles.brand} aria-label={name}>
                {logo ? (
                    <img src={logo} alt={''} className={styles.brand_logo} aria-hidden />
                ) : (
                    <div className={styles.brand_mark} aria-hidden>
                        {(name || 'P').charAt(0).toUpperCase()}
                    </div>
                )}
                {!isCollapsed && <div className={styles.brand_name}>{name}</div>}
            </Link>
            {header && !isCollapsed && <div className={'px-4 pb-2 shrink-0'}>{header}</div>}
            <div className={'flex-1 overflow-y-auto overflow-x-hidden py-1'}>
                <ActionItem
                    label={'Search'}
                    icon={faSearch}
                    onClick={() => setSearchVisible(true)}
                    collapsed={isCollapsed}
                />
                {sections.map((section, i) => (
                    <div key={section.title || i} role={'group'} aria-label={section.title}>
                        {section.title &&
                            (isCollapsed ? (
                                <div className={styles.section_rule} aria-hidden />
                            ) : (
                                <div className={styles.section_title}>{section.title}</div>
                            ))}
                        {section.items.map((item) => (
                            <NavItem key={item.label} item={item} collapsed={isCollapsed} />
                        ))}
                    </div>
                ))}
            </div>
            <div className={styles.footer}>
                {showAccountLink && (
                    <NavItem item={{ label: 'Account', icon: faUserCircle, to: '/account' }} collapsed={isCollapsed} />
                )}
                {rootAdmin && (
                    <NavItem
                        item={{ label: 'Admin', icon: faCogs, href: '/admin', external: true }}
                        collapsed={isCollapsed}
                    />
                )}
                {reseller && (
                    <NavItem
                        item={{ label: 'Reseller', icon: faHandshake, href: '/reseller', external: true }}
                        collapsed={isCollapsed}
                    />
                )}
                <ActionItem
                    label={'Sign Out'}
                    icon={faSignOutAlt}
                    onClick={onTriggerLogout}
                    collapsed={isCollapsed}
                    danger
                />
                <div className={classNames('flex items-center', isCollapsed ? 'justify-center py-1.5' : 'px-4 py-2')}>
                    <ThemeSwitcher compact={isCollapsed} className={isCollapsed ? undefined : 'w-full'} />
                </div>
                {!inDrawer && onToggleCollapsed && (
                    <WithTooltip show={isCollapsed} label={'Expand sidebar'}>
                        <button
                            type={'button'}
                            onClick={onToggleCollapsed}
                            aria-expanded={!collapsed}
                            className={classNames(styles.item, 'w-[calc(100%-1rem)] text-left')}
                        >
                            <FontAwesomeIcon icon={collapsed ? faAngleDoubleRight : faAngleDoubleLeft} fixedWidth />
                            {!isCollapsed && <span className={styles.label}>Collapse</span>}
                        </button>
                    </WithTooltip>
                )}
            </div>
        </nav>
    );
};
