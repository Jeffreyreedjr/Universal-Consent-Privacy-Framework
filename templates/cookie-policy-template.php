<?php
/**
 * Cookie Policy generated-page template.
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included template; locals are not plugin globals.

$privacy_policy_url = isset( $privacy_policy_url ) ? $privacy_policy_url : '';
$data_request_url   = isset( $data_request_url ) ? $data_request_url : '';
$dns_url            = isset( $dns_url ) ? $dns_url : '';
$contact_email      = isset( $contact_email ) ? $contact_email : '';
?>
<div class="ucpf-legal">
	<p class="ucpf-legal__label"><?php echo esc_html( $site_name ); ?></p>
	<h1 class="ucpf-legal__title"><?php esc_html_e( 'Cookie Policy', 'universal-consent-privacy-framework' ); ?></h1>
	<p class="ucpf-legal__meta"><?php
		echo esc_html(
			sprintf(
				/* translators: %s: last updated date */
				__( 'Last updated: %s', 'universal-consent-privacy-framework' ),
				$last_updated
			)
		);
	?></p>
	<p><?php
		echo esc_html(
			sprintf(
				/* translators: %s: business name */
				__( 'This Cookie Policy explains how %s (“we”) uses cookies and similar technologies on this website.', 'universal-consent-privacy-framework' ),
				$business_name
			)
		);
	?></p>
	<p><?php esc_html_e( 'Cookies are small text files stored on your device. Similar technologies include localStorage, pixels, and embedded scripts. Essential cookies keep the site secure and working. Optional cookies such as analytics, marketing, and embeds load only after you give consent through our Cookie Settings banner.', 'universal-consent-privacy-framework' ); ?></p>
	<p><?php esc_html_e( 'The inventory below comes from a privacy scan of this site. It lists cookies and related technologies we observed, with category and purpose where known. Re-scan and refresh this page after major site changes so the list stays current. This is a technical disclosure, not a legal compliance guarantee.', 'universal-consent-privacy-framework' ); ?></p>

	<h2><?php esc_html_e( 'Managing your cookie choices', 'universal-consent-privacy-framework' ); ?></h2>
	<p><?php esc_html_e( 'You can Accept All, Reject All (essential only), or Customize categories at any time with Cookie Settings on this site. Rejecting optional cookies, or pressing Escape on the banner, keeps only essential technologies active.', 'universal-consent-privacy-framework' ); ?></p>

	<h2><?php esc_html_e( 'Privacy rights and Do Not Sell or Share', 'universal-consent-privacy-framework' ); ?></h2>
	<p><?php esc_html_e( 'Depending on where you live, you may have rights to access, correct, delete, or restrict use of personal information. You may also withdraw consent for optional cookies and opt out of certain advertising, sale, or sharing of personal information as those terms are defined under US state privacy laws such as CCPA / CPRA.', 'universal-consent-privacy-framework' ); ?></p>
	<p><?php esc_html_e( 'Cookie Settings on this site control optional cookies and similar technologies. Broader privacy rights requests such as access, deletion, and Do Not Sell or Share are handled through the links and contact methods below. They are explained in full in our Privacy Policy.', 'universal-consent-privacy-framework' ); ?></p>
	<ul>
		<?php if ( $privacy_policy_url ) : ?>
			<li><a href="<?php echo esc_url( $privacy_policy_url ); ?>"><?php esc_html_e( 'Privacy Policy (full rights disclosure)', 'universal-consent-privacy-framework' ); ?></a></li>
		<?php endif; ?>
		<?php if ( $dns_url ) : ?>
			<li><a href="<?php echo esc_url( $dns_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Do Not Sell or Share My Personal Information', 'universal-consent-privacy-framework' ); ?></a></li>
		<?php endif; ?>
		<?php if ( $data_request_url ) : ?>
			<li><a href="<?php echo esc_url( $data_request_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Submit a privacy or data rights request', 'universal-consent-privacy-framework' ); ?></a></li>
		<?php endif; ?>
		<?php if ( $contact_email ) : ?>
			<li><?php echo esc_html( sprintf( /* translators: %s: email */ __( 'Email: %s', 'universal-consent-privacy-framework' ), $contact_email ) ); ?></li>
		<?php endif; ?>
	</ul>
	<?php if ( ! $dns_url && ! $data_request_url ) : ?>
		<p><?php esc_html_e( 'If dedicated rights forms are not linked above, contact us using the email listed here or in the Privacy Policy to submit a request. We may need to verify your identity.', 'universal-consent-privacy-framework' ); ?></p>
	<?php endif; ?>
</div>
