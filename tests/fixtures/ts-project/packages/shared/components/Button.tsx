import { forwardRef, memo } from './memo';

/** Props of the shared button. */
export interface ButtonProps {
    label: string;
    variant?: 'primary' | 'secondary';
    onClick?: () => void;
}

/**
 * A clickable button.
 *
 * Second paragraph is not part of the summary.
 */
export function Button({ label, variant = 'primary', onClick }: ButtonProps) {
    return (
        <button className={variant} onClick={onClick}>
            {label}
        </button>
    );
}

export const IconButton = memo((props: ButtonProps) => <Button {...props} variant="secondary" />);

export const LinkButton = forwardRef(function LinkButtonInner(props: ButtonProps, ref: unknown) {
    return <Button {...props} />;
});
