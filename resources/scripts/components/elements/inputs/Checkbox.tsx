import React, { forwardRef } from 'react';
import classNames from 'classnames';
import styles from './styles.module.css';

type Props = Omit<React.ComponentProps<'input'>, 'type'>;

export default forwardRef<HTMLInputElement, Props>(({ className, ...props }, ref) => (
    <input
        ref={ref}
        type={'checkbox'}
        className={classNames('form-checkbox', styles.checkbox_input, className)}
        {...props}
    />
));
