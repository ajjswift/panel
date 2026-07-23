import React from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faDesktop, faMoon, faSun } from '@fortawesome/free-solid-svg-icons';
import classNames from 'classnames';
import { ThemeMode, useTheme } from '@/lib/theme';
import Tooltip from '@/components/elements/tooltip/Tooltip';

const options: { mode: ThemeMode; icon: typeof faSun; label: string }[] = [
    { mode: 'light', icon: faSun, label: 'Light theme' },
    { mode: 'dark', icon: faMoon, label: 'Dark theme' },
    { mode: 'system', icon: faDesktop, label: 'Match system theme' },
];

/**
 * Segmented light/dark/system selector. In compact mode it renders a single
 * icon button that cycles between the three modes (used in the collapsed
 * sidebar).
 */
export default ({ compact, className }: { compact?: boolean; className?: string }) => {
    const { mode, setMode } = useTheme();

    if (compact) {
        const current = options.find((o) => o.mode === mode) || options[2];
        const next = options[(options.findIndex((o) => o.mode === mode) + 1) % options.length];

        return (
            <Tooltip placement={'right'} content={`Theme: ${mode} — switch to ${next.mode}`}>
                <button
                    type={'button'}
                    aria-label={`Theme: ${mode}. Activate to switch to ${next.mode} theme.`}
                    onClick={() => setMode(next.mode)}
                    className={classNames(
                        'flex items-center justify-center w-9 h-9 rounded-md transition-colors duration-150',
                        'text-body-muted hover:text-body hover:bg-surface-hover',
                        className
                    )}
                >
                    <FontAwesomeIcon icon={current.icon} className={'w-4 h-4'} fixedWidth />
                </button>
            </Tooltip>
        );
    }

    return (
        <div
            role={'radiogroup'}
            aria-label={'Interface theme'}
            className={classNames(
                'inline-flex items-center gap-0.5 p-0.5 rounded-md bg-page border border-line',
                className
            )}
        >
            {options.map((option) => (
                <Tooltip key={option.mode} placement={'top'} content={option.label}>
                    <button
                        type={'button'}
                        role={'radio'}
                        aria-checked={mode === option.mode}
                        aria-label={option.label}
                        onClick={() => setMode(option.mode)}
                        className={classNames(
                            'flex items-center justify-center flex-1 h-7 px-2.5 rounded-[5px] transition-colors duration-150',
                            mode === option.mode
                                ? 'bg-surface text-body shadow-sm border border-line-strong'
                                : 'text-body-muted hover:text-body'
                        )}
                    >
                        <FontAwesomeIcon icon={option.icon} className={'w-3.5 h-3.5'} fixedWidth />
                    </button>
                </Tooltip>
            ))}
        </div>
    );
};
