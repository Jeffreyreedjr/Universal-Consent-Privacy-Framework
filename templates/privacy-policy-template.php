<?php
/**
 * Generated Privacy Policy — comprehensive disclosure template.
 *
 * Variables from Page_Generator::render_template(): $site_name, $business_name,
 * $contact_email, $business_address, $last_updated, plus filtered extras.
 *
 * Dynamic inventory is injected via [ucpf_privacy_disclosures] on the page.
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included template; locals are not plugin globals.

$cookie_policy_url = isset( $cookie_policy_url ) ? $cookie_policy_url : '';
$data_request_url  = isset( $data_request_url ) ? $data_request_url : '';
$dns_url           = isset( $dns_url ) ? $dns_url : '';
$retention_days    = isset( $retention_days ) ? (int) $retention_days : 365;
$phone             = isset( $contact_phone ) ? $contact_phone : '';
?>
<div class="ucpf-legal ucpf-legal--privacy">
	<p class="ucpf-legal__label"><?php echo esc_html( $site_name ); ?></p>
	<h1 class="ucpf-legal__title"><?php esc_html_e( 'Privacy Policy', 'universal-consent-privacy-framework' ); ?></h1>
	<p class="ucpf-legal__meta"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Effective / last updated: %s', 'universal-consent-privacy-framework' ), $last_updated ) ); ?></p>

	<p><strong><?php esc_html_e( 'Important:', 'universal-consent-privacy-framework' ); ?></strong>
		<?php esc_html_e( 'This page is a technical disclosure generated to help explain how this website collects and uses information. It is not legal advice and does not guarantee compliance with GDPR, CPRA, LGPD, or any other law. Have qualified counsel review it before relying on it.', 'universal-consent-privacy-framework' ); ?>
	</p>

	<p><?php echo esc_html( sprintf(
		/* translators: 1: business name, 2: site name */
		__( 'This Privacy Policy explains how %1$s (“we”, “us”) collects, uses, protects, and shares information when you visit, interact with, submit forms, make purchases, use online services, or otherwise communicate through %2$s.', 'universal-consent-privacy-framework' ),
		$business_name,
		$site_name
	) ); ?></p>

	<h2><?php esc_html_e( 'Controller / contact', 'universal-consent-privacy-framework' ); ?></h2>
	<p><?php echo esc_html( sprintf( /* translators: %s: business */ __( 'The organization responsible for this website is %s.', 'universal-consent-privacy-framework' ), $business_name ) ); ?></p>
	<p><?php echo esc_html( sprintf( /* translators: %s: email */ __( 'Email: %s', 'universal-consent-privacy-framework' ), $contact_email ) ); ?></p>
	<?php if ( $phone ) : ?>
		<p><?php echo esc_html( sprintf( /* translators: %s: phone */ __( 'Phone: %s', 'universal-consent-privacy-framework' ), $phone ) ); ?></p>
	<?php endif; ?>
	<?php if ( ! empty( $business_address ) ) : ?>
		<p><?php echo esc_html( $business_address ); ?></p>
	<?php endif; ?>
	<?php if ( $data_request_url ) : ?>
		<p><a href="<?php echo esc_url( $data_request_url ); ?>"><?php esc_html_e( 'Submit a privacy / data rights request', 'universal-consent-privacy-framework' ); ?></a></p>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Information we collect', 'universal-consent-privacy-framework' ); ?></h2>
	<p><?php esc_html_e( 'We may collect information you provide directly, information collected automatically through your browser or device, and information collected through third-party tools used to operate, secure, analyze, and improve this website.', 'universal-consent-privacy-framework' ); ?></p>
	<p><?php esc_html_e( 'Information you may provide directly includes your name, email address, phone number, mailing address, billing or shipping address, company name, account information, form responses, messages, appointment requests, order details, payment-related information handled by payment providers, uploaded files, survey responses, support requests, newsletter signups, and any other information you choose to submit.', 'universal-consent-privacy-framework' ); ?></p>
	<p><?php esc_html_e( 'Information collected automatically may include IP address, browser type, device type, operating system, approximate location, pages visited, time spent on pages, links clicked, referring website, search terms, session activity, scroll or click activity, form interactions, shopping cart or checkout activity (if e-commerce is enabled), performance data, security logs, error logs, and similar technical information.', 'universal-consent-privacy-framework' ); ?></p>

	<h2><?php esc_html_e( 'How we use information', 'universal-consent-privacy-framework' ); ?></h2>
	<p><?php esc_html_e( 'We may use collected information to operate this website, respond to inquiries, provide customer service, process orders or requests, send confirmations or transactional messages, manage appointments or bookings, improve website performance, analyze visitor behavior, prevent fraud, protect against spam or abuse, secure the website, measure advertising performance (where allowed), improve marketing (where allowed), comply with legal obligations, and enforce applicable terms or policies.', 'universal-consent-privacy-framework' ); ?></p>

	<h2><?php esc_html_e( 'Legal bases (EEA / UK / similar laws)', 'universal-consent-privacy-framework' ); ?></h2>
	<p><?php esc_html_e( 'Where GDPR or similar laws apply, we process personal data under one or more of these bases: consent (for optional cookies, analytics, marketing, and similar tools); contract (to provide requested services); legal obligation; and legitimate interests (such as securing the site, preventing fraud, and improving essential operations), balanced against your rights and interests.', 'universal-consent-privacy-framework' ); ?></p>
	<p><?php esc_html_e( 'Where required, non-essential cookies, analytics, advertising, session replay, heatmaps, and similar technologies run only after you provide consent through our cookie banner or privacy controls. You may withdraw consent at any time.', 'universal-consent-privacy-framework' ); ?></p>

	<h2><?php esc_html_e( 'Cookies and tracking technologies', 'universal-consent-privacy-framework' ); ?></h2>
	<p><?php esc_html_e( 'This website may use cookies, pixels, scripts, tags, server logs, local storage, session storage, and similar technologies. These help the website function, remember preferences, secure sessions, prevent fraud, analyze traffic, measure performance, and (where allowed) support advertising or marketing.', 'universal-consent-privacy-framework' ); ?></p>
	<ul>
		<li><?php esc_html_e( 'Strictly necessary — security, login sessions, form protection, cart/checkout (if applicable), consent storage, load balancing, bot protection.', 'universal-consent-privacy-framework' ); ?></li>
		<li><?php esc_html_e( 'Analytics / statistics — understand how visitors use the site (pages, duration, interactions) to improve content and performance.', 'universal-consent-privacy-framework' ); ?></li>
		<li><?php esc_html_e( 'Marketing / advertising — measure ad performance, conversions, audiences, or retargeting where used and permitted.', 'universal-consent-privacy-framework' ); ?></li>
		<li><?php esc_html_e( 'Functional / preferences — embeds, maps, videos, chat, accessibility, saved preferences, and similar enhancements.', 'universal-consent-privacy-framework' ); ?></li>
	</ul>
	<?php if ( $cookie_policy_url ) : ?>
		<p><a href="<?php echo esc_url( $cookie_policy_url ); ?>"><?php esc_html_e( 'See the Cookie Policy for the live cookie and technology inventory.', 'universal-consent-privacy-framework' ); ?></a></p>
	<?php endif; ?>

	<h2><?php esc_html_e( 'What this site uses (live inventory)', 'universal-consent-privacy-framework' ); ?></h2>
	<p><?php esc_html_e( 'The following sections are generated from privacy scans, active plugins, and the local vendor catalog on this website. Re-scan and regenerate pages after major changes so the lists stay current.', 'universal-consent-privacy-framework' ); ?></p>
</div>
