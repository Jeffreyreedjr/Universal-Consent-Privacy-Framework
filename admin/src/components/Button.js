/**
 * @param {unknown} url
 * @return {string}
 */
function safeUiHref( url ) {
	const u = String( url || '' ).trim();
	if ( ! u ) {
		return '';
	}
	if ( u.startsWith( '/' ) && ! u.startsWith( '//' ) ) {
		return u;
	}
	try {
		const parsed = new URL(
			u,
			typeof window !== 'undefined' ? window.location.origin : 'https://example.invalid'
		);
		if ( parsed.protocol === 'http:' || parsed.protocol === 'https:' ) {
			return u;
		}
	} catch ( e ) {
		/* ignore */
	}
	return '';
}

/**
 * @param {{ href?: string, variant?: 'primary'|'ghost', children: import('react').ReactNode, onClick?: function }} props
 */
export function Button( { href, variant = 'primary', children, onClick } ) {
	const className = `ucpf-btn ucpf-btn--${ variant }`;
	if ( href != null && href !== '' ) {
		const safeHref = safeUiHref( href );
		if ( ! safeHref ) {
			return null;
		}
		return (
			<a className={ className } href={ safeHref }>
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
