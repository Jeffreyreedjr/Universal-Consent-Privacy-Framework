/**
 * @param {{ href?: string, variant?: 'primary'|'ghost', children: import('react').ReactNode, onClick?: function }} props
 */
export function Button( { href, variant = 'primary', children, onClick } ) {
	const className = `ucpf-btn ucpf-btn--${ variant }`;
	if ( href ) {
		return (
			<a className={ className } href={ href }>
				{ children }
			</a>
		);
	}
	return (
		<button type="button" className={ className } onClick={ onClick }>
			{ children }
		</button>
	);
}
