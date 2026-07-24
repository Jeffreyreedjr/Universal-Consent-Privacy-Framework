/**
 * @param {{ variant?: 'error'|'info', live?: boolean, children: import('react').ReactNode }} props
 */
export function Notice( { variant = 'info', live = false, children } ) {
	const className =
		variant === 'error' ? 'ucpf-admin__warnings' : 'ucpf-wizard__status';
	return (
		<div
			className={ className }
			role={ variant === 'error' ? 'alert' : 'status' }
			aria-live={ live ? 'polite' : undefined }
		>
			{ children }
		</div>
	);
}
