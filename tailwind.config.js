const colors = require('tailwindcss/colors');

// All theme colors are driven by CSS custom properties declared in
// resources/scripts/assets/tailwind.css. Each variable holds an RGB triplet
// (e.g. "23 32 51") so opacity modifiers keep working. The closure form is
// required (rather than the `<alpha-value>` placeholder) because twin.macro
// resolves colors at build time and would emit the placeholder literally.
const varColor = (name) => ({ opacityVariable, opacityValue } = {}) => {
    if (opacityValue !== undefined) {
        return `rgb(var(--${name}) / ${opacityValue})`;
    }
    if (opacityVariable !== undefined) {
        return `rgb(var(--${name}) / var(${opacityVariable}, 1))`;
    }
    return `rgb(var(--${name}))`;
};

const scale = (name) =>
    [50, 100, 200, 300, 400, 500, 600, 700, 800, 900].reduce(
        (obj, stop) => ({ ...obj, [stop]: varColor(`${name}-${stop}`) }),
        {}
    );

// The neutral ramp flips between the dark (navy) and light (soft neutral)
// themes: shade numbers describe elevation/emphasis, not absolute lightness.
const gray = scale('gray');
// Brand purple. "primary" and "blue" intentionally share this ramp so legacy
// blue-styled controls pick up the brand color automatically.
const brand = scale('brand');
// Signature orange accent. "cyan" is remapped here so the legacy cyan
// indicators (nav, console, charts) become the accent color.
const accent = scale('accent');

module.exports = {
    content: [
        './resources/scripts/**/*.{js,ts,tsx}',
    ],
    darkMode: ['class', '[data-theme="dark"]'],
    theme: {
        extend: {
            fontFamily: {
                header: ['"IBM Plex Sans"', '"Roboto"', 'system-ui', 'sans-serif'],
            },
            colors: {
                // Fixed near-black used for the console/terminal, which stays
                // dark in both themes by design.
                black: '#0a0f1e',
                primary: brand,
                blue: brand,
                purple: brand,
                gray: gray,
                neutral: gray,
                cyan: accent,
                accent: accent,
                // Semantic state colors; theme-aware (adjusted per theme for
                // contrast against the current background).
                danger: {
                    DEFAULT: varColor('color-danger'),
                    hover: varColor('color-danger-hover'),
                    text: varColor('color-danger-text'),
                },
                success: {
                    DEFAULT: varColor('color-success'),
                    text: varColor('color-success-text'),
                },
                warning: {
                    DEFAULT: varColor('color-warning'),
                    text: varColor('color-warning-text'),
                },
                // Semantic surfaces for new/updated components.
                page: varColor('color-background'),
                surface: {
                    DEFAULT: varColor('color-surface'),
                    elevated: varColor('color-surface-elevated'),
                    hover: varColor('color-surface-hover'),
                },
                body: {
                    DEFAULT: varColor('color-text'),
                    muted: varColor('color-text-muted'),
                    faint: varColor('color-text-faint'),
                },
                line: {
                    DEFAULT: varColor('color-border'),
                    strong: varColor('color-border-strong'),
                },
            },
            borderRadius: {
                DEFAULT: 'var(--radius-md, 0.5rem)',
                sm: 'var(--radius-sm, 0.375rem)',
                md: 'var(--radius-md, 0.5rem)',
                lg: 'var(--radius-lg, 0.75rem)',
            },
            boxShadow: {
                sm: 'var(--shadow-sm)',
                DEFAULT: 'var(--shadow-sm)',
                md: 'var(--shadow-md)',
                lg: 'var(--shadow-lg)',
            },
            backgroundImage: {
                'gradient-brand': 'var(--gradient-brand)',
                'gradient-brand-soft': 'var(--gradient-brand-soft)',
            },
            fontSize: {
                '2xs': '0.625rem',
            },
            transitionDuration: {
                250: '250ms',
            },
            borderColor: theme => ({
                default: theme('colors.line.DEFAULT', 'currentColor'),
            }),
        },
    },
    plugins: [
        require('@tailwindcss/line-clamp'),
        require('@tailwindcss/forms')({
            strategy: 'class',
        }),
    ]
};
