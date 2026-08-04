/**
 * @param {{ title: string, value: string }} props
 */
export function Card( { title, value } ) {
	return (
		<div className="ucpf-card" data-ucpf-animate="card" role="listitem">
			<h2 className="ucpf-card__title">{ title }</h2>
			<p className="ucpf-card__value">{ value }</p>
		</div>
	);
}
