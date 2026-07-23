import { useEffect, useState } from 'react';

export type ThemeMode = 'light' | 'dark' | 'system';
export type ResolvedTheme = 'light' | 'dark';

const STORAGE_KEY = 'pterodactyl:theme';

const subscribers = new Set<() => void>();

const systemTheme = (): ResolvedTheme =>
    window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';

export const getThemeMode = (): ThemeMode => {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored === 'light' || stored === 'dark' || stored === 'system') {
            return stored;
        }
    } catch (e) {
        // Storage unavailable — fall through to the default.
    }
    return 'system';
};

export const resolveTheme = (mode: ThemeMode = getThemeMode()): ResolvedTheme =>
    mode === 'system' ? systemTheme() : mode;

const apply = (resolved: ResolvedTheme) => {
    const root = document.documentElement;
    root.setAttribute('data-theme', resolved);
    root.classList.toggle('dark', resolved === 'dark');
    root.style.colorScheme = resolved;
};

export const setThemeMode = (mode: ThemeMode) => {
    try {
        localStorage.setItem(STORAGE_KEY, mode);
    } catch (e) {
        // Persisting is best-effort; the theme still applies for this session.
    }
    apply(resolveTheme(mode));
    subscribers.forEach((fn) => fn());
};

// Keep the applied theme in sync when the OS preference changes while in
// "system" mode. Safe to call more than once.
let listening = false;
const listenForSystemChanges = () => {
    if (listening || !window.matchMedia) return;
    listening = true;

    const media = window.matchMedia('(prefers-color-scheme: dark)');
    const onChange = () => {
        if (getThemeMode() === 'system') {
            apply(systemTheme());
            subscribers.forEach((fn) => fn());
        }
    };

    if (typeof media.addEventListener === 'function') {
        media.addEventListener('change', onChange);
    } else if (typeof media.addListener === 'function') {
        media.addListener(onChange);
    }
};

/**
 * React hook exposing the persisted theme mode and the resolved (light/dark)
 * theme. Components using this re-render when the theme changes, which lets
 * canvas-based consumers (charts, terminal) pick up new colors.
 */
export const useTheme = () => {
    const [mode, setMode] = useState<ThemeMode>(() => getThemeMode());
    const [resolved, setResolved] = useState<ResolvedTheme>(() => resolveTheme());

    useEffect(() => {
        listenForSystemChanges();
        const listener = () => {
            setMode(getThemeMode());
            setResolved(resolveTheme());
        };
        subscribers.add(listener);
        return () => {
            subscribers.delete(listener);
        };
    }, []);

    return { mode, resolved, setMode: setThemeMode };
};

/**
 * Read a design token (CSS custom property) from the document at call time.
 * Values defined as RGB triplets are converted to usable rgb() strings so
 * canvas consumers (chart.js, xterm) can use them directly.
 */
export const themeToken = (name: string, alpha?: number): string => {
    const raw = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    if (!raw) return '';
    if (/^\d/.test(raw)) {
        return alpha !== undefined ? `rgb(${raw} / ${alpha})` : `rgb(${raw})`;
    }
    return raw;
};
