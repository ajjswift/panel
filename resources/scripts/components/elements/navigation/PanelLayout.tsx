import React, { Fragment, useEffect, useState } from 'react';
import { Dialog, Transition } from '@headlessui/react';
import { Link } from 'react-router-dom';
import { useLocation } from 'react-router';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faBars } from '@fortawesome/free-solid-svg-icons';
import classNames from 'classnames';
import { useStoreState } from 'easy-peasy';
import { ApplicationStore } from '@/state';
import { usePersistedState } from '@/plugins/usePersistedState';
import Sidebar, { SidebarSection } from '@/components/elements/navigation/Sidebar';

interface Props {
    sections: SidebarSection[];
    header?: React.ReactNode;
    showAccountLink?: boolean;
}

/**
 * Sidebar-first application shell. Renders a collapsible sidebar on desktop
 * and an off-canvas navigation drawer (with focus trap, escape and backdrop
 * dismissal via Headless UI) on smaller screens.
 */
const PanelLayout: React.FC<Props> = ({ sections, header, showAccountLink, children }) => {
    const name = useStoreState((state: ApplicationStore) => state.settings.data!.name);
    const [collapsed, setCollapsed] = usePersistedState<boolean>('sidebar:collapsed', false);
    const [drawerOpen, setDrawerOpen] = useState(false);
    const location = useLocation();

    // Close the drawer after navigating.
    useEffect(() => {
        setDrawerOpen(false);
    }, [location.pathname]);

    return (
        <div className={'min-h-screen lg:flex'}>
            {/* Desktop sidebar */}
            <aside
                className={classNames(
                    'hidden lg:block shrink-0 sticky top-0 h-screen z-30 transition-[width] duration-200 ease-out motion-reduce:transition-none',
                    collapsed ? 'w-[4.5rem]' : 'w-60'
                )}
            >
                <Sidebar
                    sections={sections}
                    header={header}
                    collapsed={collapsed || false}
                    onToggleCollapsed={() => setCollapsed(!collapsed)}
                    showAccountLink={showAccountLink}
                />
            </aside>

            {/* Mobile drawer */}
            <Transition show={drawerOpen} as={Fragment}>
                <Dialog onClose={() => setDrawerOpen(false)} className={'relative z-40 lg:hidden'}>
                    <Transition.Child
                        as={Fragment}
                        enter={'transition-opacity duration-150 ease-out'}
                        enterFrom={'opacity-0'}
                        enterTo={'opacity-100'}
                        leave={'transition-opacity duration-150 ease-in'}
                        leaveFrom={'opacity-100'}
                        leaveTo={'opacity-0'}
                    >
                        <div className={'fixed inset-0 bg-black/60'} aria-hidden />
                    </Transition.Child>
                    <Transition.Child
                        as={Fragment}
                        enter={'transition-transform duration-200 ease-out motion-reduce:transition-none'}
                        enterFrom={'-translate-x-full'}
                        enterTo={'translate-x-0'}
                        leave={'transition-transform duration-150 ease-in motion-reduce:transition-none'}
                        leaveFrom={'translate-x-0'}
                        leaveTo={'-translate-x-full'}
                    >
                        <Dialog.Panel className={'fixed inset-y-0 left-0 w-72 max-w-[85vw]'}>
                            <Sidebar sections={sections} header={header} inDrawer showAccountLink={showAccountLink} />
                        </Dialog.Panel>
                    </Transition.Child>
                </Dialog>
            </Transition>

            <div className={'flex-1 min-w-0 flex flex-col'}>
                {/* Mobile top bar */}
                <header
                    className={
                        'lg:hidden sticky top-0 z-20 flex items-center gap-2 h-14 px-2 bg-surface border-b border-line shadow-sm'
                    }
                >
                    <button
                        type={'button'}
                        onClick={() => setDrawerOpen(true)}
                        aria-label={'Open navigation'}
                        className={
                            'flex items-center justify-center w-10 h-10 rounded-md text-body-muted hover:text-body hover:bg-surface-hover transition-colors duration-150'
                        }
                    >
                        <FontAwesomeIcon icon={faBars} />
                    </button>
                    <Link to={'/'} className={'font-header font-semibold text-base text-body no-underline truncate'}>
                        {name}
                    </Link>
                </header>
                <main className={'flex-1 min-w-0'}>{children}</main>
            </div>
        </div>
    );
};

export default PanelLayout;
