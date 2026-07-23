import tw from 'twin.macro';
import { createGlobalStyle } from 'styled-components/macro';
// @ts-expect-error untyped font file
import font from '@fontsource-variable/ibm-plex-sans/files/ibm-plex-sans-latin-wght-normal.woff2';

export default createGlobalStyle`
    @font-face {
        font-family: 'IBM Plex Sans';
        font-style: normal;
        font-display: swap;
        font-weight: 100 700;
        src: url(${font}) format('woff2-variations');
        unicode-range: U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD;
    }

    body {
        ${tw`font-sans`};
        background-color: rgb(var(--color-background));
        color: rgb(var(--color-text));
        letter-spacing: 0.015em;
        transition: background-color 150ms linear;
    }

    h1, h2, h3, h4, h5, h6 {
        ${tw`font-medium tracking-normal font-header`};
        color: rgb(var(--gray-50));
    }

    p {
        ${tw`leading-snug font-sans`};
        color: rgb(var(--color-text));
    }

    ::selection {
        background: rgb(var(--brand-500) / 0.35);
    }

    form {
        ${tw`m-0`};
    }

    textarea, select, input, button {
        ${tw`outline-none`};
    }

    /* Highly visible, consistent keyboard focus indicator in both themes. */
    a:focus-visible,
    button:focus-visible,
    [role='button']:focus-visible,
    [tabindex]:focus-visible {
        outline: 2px solid rgb(var(--color-focus-ring));
        outline-offset: 2px;
    }

    input[type=number]::-webkit-outer-spin-button,
    input[type=number]::-webkit-inner-spin-button {
        -webkit-appearance: none !important;
        margin: 0;
    }

    input[type=number] {
        -moz-appearance: textfield !important;
    }

    /* Scroll Bar Style */
    ::-webkit-scrollbar {
        background: none;
        width: 14px;
        height: 14px;
    }

    ::-webkit-scrollbar-thumb {
        border: 4px solid transparent;
        background-clip: padding-box;
        border-radius: 9999px;
        background-color: rgb(var(--color-border-strong));
    }

    ::-webkit-scrollbar-thumb:hover {
        background-color: rgb(var(--color-text-faint));
    }

    ::-webkit-scrollbar-track-piece {
        margin: 4px 0;
    }

    ::-webkit-scrollbar-corner {
        background: transparent;
    }
`;
