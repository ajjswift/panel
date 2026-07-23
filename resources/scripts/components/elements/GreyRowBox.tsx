import styled from 'styled-components/macro';
import tw from 'twin.macro';

export default styled.div<{ $hoverable?: boolean }>`
    ${tw`flex rounded-lg no-underline text-neutral-200 items-center bg-neutral-700 p-4 border border-line shadow-sm transition-colors duration-150 overflow-hidden`};

    ${(props) => props.$hoverable !== false && tw`hover:border-line-strong hover:shadow-md`};

    & .icon {
        ${tw`rounded-full w-16 flex items-center justify-center bg-neutral-600 border border-line p-3`};
    }
`;
