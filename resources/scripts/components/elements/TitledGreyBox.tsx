import React, { memo } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { IconProp } from '@fortawesome/fontawesome-svg-core';
import tw from 'twin.macro';
import isEqual from 'react-fast-compare';

interface Props {
    icon?: IconProp;
    title: string | React.ReactNode;
    className?: string;
    children: React.ReactNode;
}

const TitledGreyBox = ({ icon, title, children, className }: Props) => (
    <div css={tw`rounded-lg shadow-sm bg-neutral-700 border border-line overflow-hidden`} className={className}>
        <div css={tw`rounded-t p-3 border-b border-line`}>
            {typeof title === 'string' ? (
                <p css={tw`text-xs font-semibold uppercase tracking-wider text-neutral-300`}>
                    {icon && <FontAwesomeIcon icon={icon} css={tw`mr-2 text-neutral-300`} />}
                    {title}
                </p>
            ) : (
                title
            )}
        </div>
        <div css={tw`p-3`}>{children}</div>
    </div>
);

export default memo(TitledGreyBox, isEqual);
