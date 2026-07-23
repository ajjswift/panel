import styled, { css } from 'styled-components/macro';
import tw from 'twin.macro';

interface Props {
    hideDropdownArrow?: boolean;
}

const Select = styled.select<Props>`
    ${tw`shadow-none block p-3 pr-8 rounded border w-full text-sm transition-colors duration-150 ease-linear`};

    &,
    &:hover:not(:disabled),
    &:focus {
        ${tw`outline-none`};
    }

    -webkit-appearance: none;
    -moz-appearance: none;
    background-size: 1rem;
    background-repeat: no-repeat;
    background-position-x: calc(100% - 0.75rem);
    background-position-y: center;

    &::-ms-expand {
        display: none;
    }

    ${(props) =>
        !props.hideDropdownArrow &&
        css`
            ${tw`bg-neutral-600 border-neutral-500 text-neutral-100`};
            background-image: var(--select-arrow);

            &:hover:not(:disabled),
            &:focus {
                ${tw`border-neutral-400`};
            }

            &:focus {
                box-shadow: 0 0 0 2px rgb(var(--color-focus-ring) / 0.35);
            }
        `};
`;

export default Select;
