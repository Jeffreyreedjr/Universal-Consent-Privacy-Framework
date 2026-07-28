<?php
/**
 * Generated Privacy Policy disclosure template.
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
	<p class="ucpf-legal__meta"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Effective date: %s', 'universal-consent-privacy-framework' ), $last_updated ) ); ?></p>

	<p><strong><?php esc_html_e( 'Important:', 'universal-consent-privacy-framework' ); ?></strong>
		<?php esc_html_e( 'This page explains how this website collects and uses information. It is a technical disclosure, not legal advice. It does not guarantee compliance with GDPR, CPRA, LGPD, or any other law. Have qualified counsel review it before you rely on it.', 'universal-consent-privacy-framework' ); ?>
	</p>

	<p><?php echo esc_html( sprintf(
		/* translators: 1: business name, 2: site name */
		__( 'This Privacy Policy describes how %1$s (“we”, “us”) collects, uses, protects, and shares information when you visit or use %2$s. That includes browsing the site, submitting forms, making purchases, using online services, or contacting us.', 'universal-consent-privacy-framework' ),
		$business_name,
		$site_name
	) ); ?></p>

	<h2><?php esc_html_e( 'Who is responsible', 'universal-consent-privacy-framework' ); ?></h2>
	<p><?php echo esc_html( sprintf( /* translators: %s: business */ __( 'The organization responsible for this website is %s.', 'universal-consent-privacy-framework' ), $business_name ) ); ?></p>
	<p><?php echo esc_html( sprintf( /* translators: %s: email */ __( 'Email: %s', 'universal-consent-privacy-framework' ), $contact_email ) ); ?></p>
	<?php if ( $phone ) : ?>
		<p><?php echo esc_html( sprintf( /* translators: %s: phone */ __( 'Phone: %s', 'universal-consent-privacy-framework' ), $phone ) ); ?></p>
	<?php endif; ?>
	<?php if ( ! empty( $business_address ) ) : ?>
		<p><?php echo esc_html( $business_address ); ?></p>
	<?php endif; ?>
	<?php if ( $data_request_url ) : ?>
		<p><a href="<?php echo esc_url( $data_request_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Submit a privacy or data rights request', 'universal-consent-privacy-framework' ); ?></a></p>
	<?php endif; ?>
	<?php if ( $dns_url ) : ?>
		<p><a href="<?php echo esc_url( $dns_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Do Not Sell or Share My Personal Information', 'universal-consent-privacy-framework' ); ?></a></p>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Your privacy choices', 'universal-consent-privacy-framework' ); ?></h2>
	<p><?php esc_html_e( 'You can manage optional cookies and similar technologies at any time with Cookie Settings on this website. Choose Accept All, Reject All (essential only), or Customize.', 'universal-consent-privacy-framework' ); ?></p>
	<p><?php esc_html_e( 'Under California law (CCPA / CPRA) and certain other US state privacy laws, you may also opt out of the “sale” or “sharing” of personal information. That can include some advertising uses. We do not sell personal information for money in the everyday sense. Some laws define sale or sharing more broadly. Use the Do Not Sell or Share link above when it is provided, the rights sections later on this page, or contact us to make that request.', 'universal-consent-privacy-framework' ); ?></p>
	<?php if ( $cookie_policy_url ) : ?>
		<p><a href="<?php echo esc_url( $cookie_policy_url ); ?>"><?php esc_html_e( 'Cookie Policy. How cookies work and how to change cookie choices.', 'universal-consent-privacy-framework' ); ?></a></p>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Information we collect', 'universal-consent-privacy-framework' ); ?></h2>
	<p><?php esc_html_e( 'We may collect information you provide directly. We may also collect information automatically through your browser or device. Third-party tools used to operate, secure, analyze, and improve this website may collect information as well.', 'universal-consent-privacy-framework' ); ?></p>
	<p><?php esc_html_e( 'Information you provide may include your name, email address, phone number, mailing address, billing or shipping address, and company name. It may also include account details, form responses, messages, appointment requests, order details, payment-related information handled by payment providers, uploaded files, survey answers, support requests, newsletter signups, and anything else you choose to submit.', 'universal-consent-privacy-framework' ); ?></p>
	<p><?php esc_html_e( 'Information collected automatically may include IP address, browser type, device type, operating system, and approximate location. It may also include pages visited, time on pages, links clicked, referring website, search terms, session activity, form interactions, shopping cart or checkout activity when e-commerce is enabled, performance data, security logs, and error logs.', 'universal-consent-privacy-framework' ); ?></p>

	<h2><?php esc_html_e( 'How we use information', 'universal-consent-privacy-framework' ); ?></h2>
	<p><?php esc_html_e( 'We may use collected information to operate this website, respond to inquiries, and provide customer service. We may also use it to process orders or requests, send confirmations or transactional messages, and manage appointments or bookings.', 'universal-consent-privacy-framework' ); ?></p>
	<p><?php esc_html_e( 'We may use it to improve website performance, analyze visitor behavior, prevent fraud, protect against spam or abuse, and secure the website. Where allowed, we may measure advertising performance and improve marketing. We may also use information to comply with legal obligations and enforce applicable terms or policies.', 'universal-consent-privacy-framework' ); ?></p>

	<h2><?php esc_html_e( 'Legal bases for EEA, UK, and similar laws', 'universal-consent-privacy-framework' ); ?></h2>
	<p><?php esc_html_e( 'Where GDPR or similar laws apply, we process personal data under one or more of these bases. Consent covers optional cookies, analytics, marketing, and similar tools. Contract covers providing requested services. Legal obligation covers required processing. Legitimate interests cover securing the site, preventing fraud, and improving essential operations, balanced against your rights and interests.', 'universal-consent-privacy-framework' ); ?></p>
	<p><?php esc_html_e( 'Where required, non-essential cookies, analytics, advertising, session replay, heatmaps, and similar technologies run only after you provide consent through our cookie banner or privacy controls. You may withdraw consent at any time.', 'universal-consent-privacy-framework' ); ?></p>

	<h2><?php esc_html_e( 'Cookies and tracking technologies', 'universal-consent-privacy-framework' ); ?></h2>
	<p><?php esc_html_e( 'This website may use cookies, pixels, scripts, tags, server logs, local storage, session storage, and similar technologies. These help the website function, remember preferences, secure sessions, prevent fraud, analyze traffic, and measure performance. Where allowed, they may also support advertising or marketing.', 'universal-consent-privacy-framework' ); ?></p>
	<ul>
		<li><?php esc_html_e( 'Strictly necessary. Security, login sessions, form protection, cart or checkout when applicable, consent storage, load balancing, and bot protection.', 'universal-consent-privacy-framework' ); ?></li>
		<li><?php esc_html_e( 'Analytics and statistics. Understand how visitors use the site, including pages, duration, and interactions, so we can improve content and performance.', 'universal-consent-privacy-framework' ); ?></li>
		<li><?php esc_html_e( 'Marketing and advertising. Measure ad performance, conversions, audiences, or retargeting where used and permitted.', 'universal-consent-privacy-framework' ); ?></li>
		<li><?php esc_html_e( 'Functional and preferences. Embeds, maps, videos, chat, accessibility tools, saved preferences, and similar enhancements.', 'universal-consent-privacy-framework' ); ?></li>
	</ul>
	<?php if ( $cookie_policy_url ) : ?>
		<p><a href="<?php echo esc_url( $cookie_policy_url ); ?>"><?php esc_html_e( 'See the Cookie Policy for the live cookie and technology inventory.', 'universal-consent-privacy-framework' ); ?></a></p>
	<?php endif; ?>

	<h2><?php esc_html_e( 'What this site uses', 'universal-consent-privacy-framework' ); ?></h2>
	<p><?php esc_html_e( 'The following sections come from privacy scans, active plugins, and the local vendor catalog on this website. Re-scan and regenerate pages after major changes so the lists stay current.', 'universal-consent-privacy-framework' ); ?></p>
</div>
