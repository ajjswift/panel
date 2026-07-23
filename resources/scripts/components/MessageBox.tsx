import * as React from 'react';
import tw, { TwStyle } from 'twin.macro';
import styled from 'styled-components/macro';

export type FlashMessageType = 'success' | 'info' | 'warning' | 'error';

interface Props {
    title?: string;
    children: string;
    type?: FlashMessageType;
}

const styling = (type?: FlashMessageType): TwStyle | string => {
    switch (type) {
        case 'error':
            return tw`bg-danger bg-opacity-10 border-danger border-opacity-40`;
        case 'info':
            return tw`bg-primary-500 bg-opacity-10 border-primary-500 border-opacity-40`;
        case 'success':
            return tw`bg-success bg-opacity-10 border-success border-opacity-40`;
        case 'warning':
            return tw`bg-warning bg-opacity-10 border-warning border-opacity-40`;
        default:
            return '';
    }
};

const getBackground = (type?: FlashMessageType): TwStyle | string => {
    switch (type) {
        case 'error':
            return tw`bg-red-600`;
        case 'info':
            return tw`bg-primary-600`;
        case 'success':
            return tw`bg-green-600`;
        case 'warning':
            return tw`bg-yellow-600`;
        default:
            return '';
    }
};

const Container = styled.div<{ $type?: FlashMessageType }>`
    ${tw`p-2 border items-center leading-normal rounded-md flex w-full text-sm text-neutral-100`};
    ${(props) => styling(props.$type)};
`;
Container.displayName = 'MessageBox.Container';

const MessageBox = ({ title, children, type }: Props) => (
    <Container css={tw`lg:inline-flex`} $type={type} role={'alert'}>
        {title && (
            <span
                className={'title'}
                css={[
                    tw`flex rounded-full uppercase px-2 py-1 text-xs font-bold mr-3 leading-none text-white`,
                    getBackground(type),
                ]}
            >
                {title}
            </span>
        )}
        <span css={tw`mr-2 text-left flex-auto`}>{children}</span>
    </Container>
);
MessageBox.displayName = 'MessageBox';

export default MessageBox;
