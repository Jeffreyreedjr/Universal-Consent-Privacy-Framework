import { useEffect, useRef } from 'react';
import { gsap } from 'gsap';
import { Card } from '../components/Card';
import { Button } from '../components/Button';
import { Notice } from '../components/Notice';

/**
 * @param {{ data: object }} props
 */
export function Dashboard( { data } ) {
	const rootRef = useRef( null );
	const health = Array.isArray( data.healthChecks ) ? data.healthChecks : [];
	const settings = data.settings || {};
	const warnings = Array.isArray( data.warnings ) ? data.warnings : [];
	const wizardDone = !! data.wizardCompleted;
	const productName = data.productName || 'UCPF';
	const urls = data.urls || {};

	useEffect( () => {
		const root = rootRef.current;
		if ( ! root ) {
			return undefined;
		}
		const mm = gsap.matchMedia();
		mm.add( '(prefers-reduced-motion: no-preference)', () => {
			const cards = root.querySelectorAll( '[data-ucpf-animate="card"]' );
			gsap.fromTo(
				cards,
				{ opacity: 0, y: 16 },
				{ opacity: 1, y: 0, duration: 0.45, stagger: 0.06, ease: 'power2.out', clearProps: 'transform' }
			);
		} );
		return () => mm.revert();
	}, [] );

	return (
		<div className="ucpf-dash" ref={ rootRef }>
			<header className="ucpf-dash__header">
				<p className="ucpf-shell__brand" style={ { color: 'var(--ucpf-admin-muted)' } }>
					{ productName }
				</p>
				<h1 className="ucpf-shell__title">{ data.i18n?.title || 'Privacy Consent Dashboard' }</h1>
				<p className="ucpf-shell__lede">
					{ data.i18n?.lede ||
						'Helps support privacy compliance. Final legal review is the site owner\'s responsibility. Local-first: no phone-home.' }
				</p>
			</header>

			{ warnings.length > 0 && (
				<Notice variant="error" live>
					{ warnings.map( ( w, i ) => (
						<p key={ i } className="ucpf-admin__warning">
							{ w }
						</p>
					) ) }
				</Notice>
			) }

			<div className="ucpf-dash__cta" data-ucpf-animate="card" style={ { marginBottom: '1.25rem' } }>
				{ ! wizardDone ? (
					<div className="ucpf-card">
						<h2 className="ucpf-card__title">{ data.i18n?.getStarted || 'Get started' }</h2>
						<p>
							{ data.i18n?.getStartedBody ||
								'Run the setup wizard to scan cookies, choose services, generate policies, and enable the banner.' }
						</p>
						<p>
							<Button href={ urls.wizard } variant="primary">
								{ data.i18n?.openWizard || 'Open Setup Wizard' }
							</Button>
						</p>
					</div>
				) : (
					<p>
						<Button href={ urls.wizard } variant="ghost">
							{ data.i18n?.reopenWizard || 'Re-open Setup Wizard' }
						</Button>{ ' ' }
						<Button href={ urls.scanner } variant="primary">
							{ data.i18n?.openScanner || 'Cookie Scanner' }
						</Button>
					</p>
				) }
			</div>

			{ health.length > 0 && (
				<section aria-labelledby="ucpf-health-heading">
					<h2 id="ucpf-health-heading" className="ucpf-shell__title" style={ { fontSize: '1.25rem' } }>
						{ data.i18n?.healthTitle || 'Install health' }
					</h2>
					<p className="ucpf-shell__lede">
						{ data.i18n?.healthLede ||
							'Quick checklist for deploys. Fix anything marked warn or fail before handoff.' }
					</p>
					<ul className="ucpf-health">
						{ health.map( ( check ) => {
							const status = check.status || 'warn';
							const statusLabel =
								status === 'ok' ? 'OK' : status === 'fail' ? 'Fail' : 'Warn';
							return (
								<li
									key={ check.id || check.label }
									className={ `ucpf-health__item ucpf-health__item--${ status }` }
									data-ucpf-animate="card"
								>
									<span className="ucpf-health__badge" aria-label={ statusLabel }>
										{ statusLabel }
									</span>
									<div className="ucpf-health__body">
										<strong className="ucpf-health__label">{ check.label }</strong>
										<p className="ucpf-health__detail">{ check.detail }</p>
										{ check.action_url && status !== 'ok' && (
											<p className="ucpf-health__action">
												<a href={ check.action_url }>
													{ check.action_label || 'Fix' }
												</a>
											</p>
										) }
									</div>
								</li>
							);
						} ) }
					</ul>
				</section>
			) }

			<div className="ucpf-bento" role="list">
				<Card
					title={ data.i18n?.compliance || 'Compliance mode' }
					value={ settings.compliance_mode || '—' }
				/>
				<Card
					title={ data.i18n?.policy || 'Policy version' }
					value={ settings.policy_version || '—' }
				/>
				<Card
					title={ data.i18n?.bannerBlocker || 'Banner / blocker' }
					value={ `${ settings.banner_enabled ? 'Banner on' : 'Banner off' } / ${
						settings.blocker_enabled ? 'Blocker on' : 'Blocker off'
					}` }
				/>
				<Card
					title={ data.i18n?.wpConsent || 'WP Consent API' }
					value={
						data.wpConsentApi
							? data.i18n?.wpConsentYes || 'Compatible (shim active)'
							: data.i18n?.wpConsentShim || 'Bundled shim only'
					}
				/>
			</div>
		</div>
	);
}
