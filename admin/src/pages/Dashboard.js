import { useLayoutEffect, useMemo, useRef } from 'react';
import { Card } from '../components/Card';
import { Button } from '../components/Button';
import { Notice } from '../components/Notice';

const GROUP_ORDER = [ 'consent', 'pages', 'scan', 'signals', 'stack' ];

/**
 * @param {string} template
 * @param {...(string|number)} args
 */
function sprintf( template, ...args ) {
	let i = 0;
	return String( template || '' ).replace( /%(\d+)\$[sd]|%[sd]/g, ( match, idx ) => {
		if ( idx ) {
			return String( args[ Number( idx ) - 1 ] ?? '' );
		}
		return String( args[ i++ ] ?? '' );
	} );
}

/**
 * @param {{ check: object, i18n: object, compact?: boolean }} props
 */
function HealthRow( { check, i18n, compact } ) {
	const status = check.status || 'warn';
	const statusLabel =
		status === 'ok'
			? i18n.chipOk || 'OK'
			: status === 'fail'
				? i18n.chipFail || 'Fail'
				: i18n.chipWarn || 'Warn';

	return (
		<li
			className={ `ucpf-health__item ucpf-health__item--${ status }${
				compact ? ' ucpf-health__item--compact' : ''
			}` }
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
							{ check.action_label || i18n.fix || 'Fix' }
						</a>
					</p>
				) }
			</div>
		</li>
	);
}

/**
 * @param {{ check: object, i18n: object }} props
 */
function AttentionCard( { check, i18n } ) {
	const status = check.status || 'warn';
	const statusLabel =
		status === 'fail' ? i18n.chipFail || 'Fail' : i18n.chipWarn || 'Warn';

	return (
		<article
			className={ `ucpf-dash__attention-card ucpf-dash__attention-card--${ status }` }
			data-ucpf-animate="card"
		>
			<div className="ucpf-dash__attention-card-top">
				<span className="ucpf-dash__attention-badge">{ statusLabel }</span>
				<h3 className="ucpf-dash__attention-title">{ check.label }</h3>
			</div>
			<p className="ucpf-dash__attention-detail">{ check.detail }</p>
			{ check.action_url ? (
				<p className="ucpf-dash__attention-action">
					<a className="ucpf-btn ucpf-btn--primary" href={ check.action_url }>
						{ check.action_label || i18n.fix || 'Fix' }
					</a>
				</p>
			) : null }
		</article>
	);
}

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
	const brandMarkUrl = data.brandMarkUrl || '';
	const urls = data.urls || {};
	const i18n = data.i18n || {};
	const scan = data.scanSummary || {};

	const counts = useMemo( () => {
		let ok = 0;
		let warn = 0;
		let fail = 0;
		health.forEach( ( c ) => {
			const s = c.status || 'warn';
			if ( s === 'ok' ) {
				ok += 1;
			} else if ( s === 'fail' ) {
				fail += 1;
			} else {
				warn += 1;
			}
		} );
		return { ok, warn, fail, total: health.length };
	}, [ health ] );

	const overall =
		counts.fail > 0 ? 'blocked' : counts.warn > 0 ? 'attention' : 'ready';

	const attention = useMemo(
		() => health.filter( ( c ) => c.status === 'fail' || c.status === 'warn' ),
		[ health ]
	);

	const passing = useMemo(
		() => health.filter( ( c ) => ( c.status || 'warn' ) === 'ok' ),
		[ health ]
	);

	const passingByGroup = useMemo( () => {
		const map = {};
		passing.forEach( ( c ) => {
			const g = c.group || 'stack';
			if ( ! map[ g ] ) {
				map[ g ] = [];
			}
			map[ g ].push( c );
		} );
		return GROUP_ORDER.filter( ( g ) => map[ g ] && map[ g ].length ).map( ( g ) => ( {
			id: g,
			items: map[ g ],
		} ) );
	}, [ passing ] );

	const groupLabel = ( id ) => {
		const key =
			id === 'consent'
				? 'groupConsent'
				: id === 'pages'
					? 'groupPages'
					: id === 'scan'
						? 'groupScan'
						: id === 'signals'
							? 'groupSignals'
							: 'groupStack';
		const defaults = {
			groupConsent: 'Consent UI',
			groupPages: 'Legal pages',
			groupScan: 'Scanning',
			groupSignals: 'Signals & jurisdiction',
			groupStack: 'Stack',
		};
		return i18n[ key ] || defaults[ key ] || id;
	};

	const statusWord =
		overall === 'ready'
			? i18n.statusReady || 'Ready'
			: overall === 'blocked'
				? i18n.statusBlocked || 'Blocked'
				: i18n.statusAttention || 'Needs attention';

	const passingLabel = sprintf(
		i18n.passingOf || '%1$d of %2$d passing',
		counts.ok,
		counts.total || 0
	);

	const scorePct =
		counts.total > 0 ? Math.round( ( counts.ok / counts.total ) * 100 ) : 0;

	const inventoryDetail = scan.lastScanDate
		? sprintf(
			i18n.scanInventoryDetail || '%1$d known · %2$d unknown',
			scan.knownCookies || 0,
			scan.unknownCookies || 0
		)
		: i18n.noScanYet || 'No scan yet';

	useLayoutEffect( () => {
		const root = rootRef.current;
		if ( ! root ) {
			return undefined;
		}

		const reduce =
			typeof window !== 'undefined' &&
			window.matchMedia &&
			window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

		if ( reduce ) {
			return undefined;
		}

		root.classList.add( 'ucpf-dash--enter' );
		const bento = root.querySelector( '.ucpf-bento' );
		if ( bento ) {
			bento.classList.add( 'ucpf-bento--intro' );
		}

		const cards = root.querySelectorAll( '[data-ucpf-animate="card"]' );
		cards.forEach( ( el, i ) => {
			el.style.setProperty( '--ucpf-enter-delay', `${ Math.min( i * 0.05, 0.5 ) }s` );
			el.classList.add( 'ucpf-enter' );
		} );

		const clearIntro = () => {
			if ( bento ) {
				bento.classList.remove( 'ucpf-bento--intro' );
			}
			root.classList.remove( 'ucpf-dash--enter' );
		};

		const timer = window.setTimeout( clearIntro, 900 );
		return () => {
			window.clearTimeout( timer );
			clearIntro();
		};
	}, [] );

	return (
		<div className="ucpf-dash" ref={ rootRef }>
			<header className="ucpf-dash__header">
				<p className="ucpf-shell__brand" style={ { color: 'var(--ucpf-admin-muted)' } }>
					{ brandMarkUrl ? (
						<img
							className="ucpf-shell__brand-mark"
							src={ brandMarkUrl }
							alt=""
							width={ 28 }
							height={ 28 }
							decoding="async"
						/>
					) : null }
					<span className="ucpf-shell__brand-text">{ productName }</span>
				</p>
				<h1 className="ucpf-shell__title">{ i18n.title || 'Privacy Consent Dashboard' }</h1>
				<p className="ucpf-shell__lede">
					{ i18n.lede ||
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

			{ ! wizardDone && (
				<div className="ucpf-dash__cta" data-ucpf-animate="card">
					<div className="ucpf-card">
						<h2 className="ucpf-card__title">{ i18n.getStarted || 'Get started' }</h2>
						<p>
							{ i18n.getStartedBody ||
								'Run the setup wizard to scan cookies, choose services, generate policies, and enable the banner.' }
						</p>
						<p>
							<Button href={ urls.wizard } variant="primary">
								{ i18n.openWizard || 'Open Setup Wizard' }
							</Button>
						</p>
					</div>
				</div>
			) }

			{ health.length > 0 && (
				<section
					className={ `ucpf-dash__hero ucpf-dash__hero--${ overall }` }
					aria-labelledby="ucpf-dash-status"
					data-ucpf-animate="card"
				>
					<div className="ucpf-dash__hero-score" aria-hidden="true">
						<svg className="ucpf-dash__ring" viewBox="0 0 72 72" width="72" height="72">
							<circle className="ucpf-dash__ring-track" cx="36" cy="36" r="30" />
							<circle
								className="ucpf-dash__ring-value"
								cx="36"
								cy="36"
								r="30"
								style={ {
									strokeDasharray: `${ ( scorePct / 100 ) * 188.4 } 188.4`,
								} }
							/>
						</svg>
						<span className="ucpf-dash__ring-pct">{ scorePct }%</span>
					</div>
					<div className="ucpf-dash__hero-copy">
						<p className="ucpf-dash__hero-kicker">{ i18n.healthTitle || 'Install health' }</p>
						<h2 id="ucpf-dash-status" className="ucpf-dash__hero-status">
							{ statusWord }
						</h2>
						<p className="ucpf-dash__hero-passing">{ passingLabel }</p>
						{ scan.lastScanDate ? (
							<p className="ucpf-dash__hero-scan">
								<span className="ucpf-dash__hero-scan-label">
									{ i18n.scanInventory || 'Cookie inventory' }
								</span>
								{ ' · ' }
								{ inventoryDetail }
							</p>
						) : (
							<p className="ucpf-dash__hero-scan">{ inventoryDetail }</p>
						) }
					</div>
					<ul className="ucpf-dash__chips" aria-label={ i18n.healthTitle || 'Install health' }>
						<li className="ucpf-dash__chip ucpf-dash__chip--ok">
							<strong>{ counts.ok }</strong>
							<span>{ i18n.chipOk || 'OK' }</span>
						</li>
						<li className="ucpf-dash__chip ucpf-dash__chip--warn">
							<strong>{ counts.warn }</strong>
							<span>{ i18n.chipWarn || 'Warn' }</span>
						</li>
						<li className="ucpf-dash__chip ucpf-dash__chip--fail">
							<strong>{ counts.fail }</strong>
							<span>{ i18n.chipFail || 'Fail' }</span>
						</li>
					</ul>
				</section>
			) }

			{ health.length > 0 && (
				<section className="ucpf-dash__attention" aria-labelledby="ucpf-attention-heading">
					<h2 id="ucpf-attention-heading" className="ucpf-dash__section-title">
						{ i18n.needsAttention || 'Needs attention' }
					</h2>
					{ attention.length === 0 ? (
						<p className="ucpf-dash__all-clear" data-ucpf-animate="card">
							{ i18n.allPassing ||
								'All checks passing — good to hand off after a final legal review.' }
						</p>
					) : (
						<div className="ucpf-dash__attention-grid">
							{ attention.map( ( check ) => (
								<AttentionCard
									key={ check.id || check.label }
									check={ check }
									i18n={ i18n }
								/>
							) ) }
						</div>
					) }
				</section>
			) }

			<section className="ucpf-dash__actions" aria-labelledby="ucpf-actions-heading">
				<h2 id="ucpf-actions-heading" className="ucpf-dash__section-title">
					{ i18n.quickActions || 'Quick actions' }
				</h2>
				<div className="ucpf-dash__action-strip" data-ucpf-animate="card">
					{ wizardDone ? (
						<Button href={ urls.wizard } variant="ghost">
							{ i18n.reopenWizard || 'Re-open Setup Wizard' }
						</Button>
					) : (
						<Button href={ urls.wizard } variant="primary">
							{ i18n.openWizard || 'Open Setup Wizard' }
						</Button>
					) }
					<Button href={ urls.scanner } variant={ wizardDone ? 'primary' : 'ghost' }>
						{ i18n.openScanner || 'Cookie Scanner' }
					</Button>
					{ urls.banner ? (
						<Button href={ urls.banner } variant="ghost">
							{ i18n.openBanner || 'Banner & Branding' }
						</Button>
					) : null }
					{ urls.pages ? (
						<Button href={ urls.pages } variant="ghost">
							{ i18n.openPages || 'Generated Pages' }
						</Button>
					) : null }
				</div>
			</section>

			<div className="ucpf-bento" role="list">
				<Card
					title={ i18n.compliance || 'Compliance mode' }
					value={ settings.compliance_mode || '—' }
				/>
				<Card
					title={ i18n.policy || 'Policy version' }
					value={ settings.policy_version || '—' }
				/>
				<Card
					title={ i18n.bannerBlocker || 'Banner / blocker' }
					value={ `${
						settings.banner_enabled
							? i18n.bannerOn || 'Banner on'
							: i18n.bannerOff || 'Banner off'
					} / ${
						settings.blocker_enabled
							? i18n.blockerOn || 'Blocker on'
							: i18n.blockerOff || 'Blocker off'
					}` }
				/>
				<Card
					title={ i18n.wpConsent || 'WP Consent API' }
					value={
						data.wpConsentApi
							? i18n.wpConsentYes || 'Compatible (shim active)'
							: i18n.wpConsentShim || 'Bundled shim only'
					}
				/>
			</div>

			{ passing.length > 0 && (
				<details
					className="ucpf-dash__passing"
					open={ counts.fail > 0 }
					data-ucpf-animate="card"
				>
					<summary className="ucpf-dash__passing-summary">
						<span className="ucpf-dash__passing-title">
							{ i18n.passingChecks || 'Passing checks' }
						</span>
						<span className="ucpf-dash__passing-count">{ counts.ok }</span>
					</summary>
					{ passingByGroup.map( ( group ) => (
						<div key={ group.id } className="ucpf-dash__passing-group">
							<h3 className="ucpf-dash__passing-group-title">{ groupLabel( group.id ) }</h3>
							<ul className="ucpf-health ucpf-health--compact">
								{ group.items.map( ( check ) => (
									<HealthRow
										key={ check.id || check.label }
										check={ check }
										i18n={ i18n }
										compact
									/>
								) ) }
							</ul>
						</div>
					) ) }
				</details>
			) }
		</div>
	);
}
