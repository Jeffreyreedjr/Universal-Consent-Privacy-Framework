<?php
/**
 * Banner template.
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

use UCPF\Settings;

$layout = Settings::get( 'banner_layout' );
if ( ! in_array( $layout, array( 'bar', 'modal', 'corner' ), true ) ) {
	$layout = 'bar';
}
$theme  = \UCPF\Theme_Manager::instance()->resolve_preset( Settings::get( 'banner_theme' ) );
$layout_class = 'ucpf-banner--' . esc_attr( $layout );
$cookie_policy_url = \UCPF\Page_Generator::instance()->get_page_url( 'cookie_policy' );
$dns_url = \UCPF\Page_Generator::instance()->get_rights_url( 'do_not_sell' );
$logo_url = Settings::get( 'logo_url' );
$business_name = Settings::get( 'business_name' );
$pack = \UCPF\Jurisdiction::instance()->resolve();
$copy = isset( $pack['copy'] ) && is_array( $pack['copy'] ) ? $pack['copy'] : array();
$banner_title = ! empty( $copy['banner_title'] ) ? $copy['banner_title'] : __( 'Cookies', 'universal-consent-privacy-framework' );
$banner_text  = ! empty( $copy['banner_text'] ) ? $copy['banner_text'] : __( 'We use essential cookies for security and optional cookies based on your choices. This plugin helps support privacy compliance; review with legal counsel.', 'universal-consent-privacy-framework' );
$prefs_title  = ! empty( $copy['prefs_title'] ) ? $copy['prefs_title'] : __( 'Cookie Preferences', 'universal-consent-privacy-framework' );
$prefs_intro  = ! empty( $copy['prefs_intro'] ) ? $copy['prefs_intro'] : __( 'Choose which optional cookie categories to allow. Essential cookies stay on. Use Tab to move, Space or Enter to toggle, and Escape to reject optional cookies.', 'universal-consent-privacy-framework' );
$fab_label    = ! empty( $copy['fab_label'] ) ? $copy['fab_label'] : __( 'Cookie Settings', 'universal-consent-privacy-framework' );
$choices_label = ! empty( $copy['privacy_choices_label'] ) ? $copy['privacy_choices_label'] : __( 'Your Privacy Choices', 'universal-consent-privacy-framework' );
$show_choices = ! empty( $pack['privacy_choices_link'] ) && $dns_url;
?>
<div id="ucpf-root" class="ucpf-theme-<?php echo esc_attr( $theme ); ?>" data-ucpf-layout="<?php echo esc_attr( $layout ); ?>" data-ucpf-pack="<?php echo esc_attr( $pack['id'] ); ?>">
	<div
		class="ucpf-banner <?php echo esc_attr( $layout_class ); ?> ucpf-banner--hidden"
		id="ucpf-banner"
		role="dialog"
		aria-modal="true"
		aria-labelledby="ucpf-banner-title"
		aria-describedby="ucpf-banner-desc"
		hidden
		data-ucpf-layout="<?php echo esc_attr( $layout ); ?>"
	>
		<div class="ucpf-modal__overlay" data-ucpf-close-overlay hidden></div>
		<div class="ucpf-banner__panel" tabindex="-1">
			<div class="ucpf-banner__inner">
				<div class="ucpf-banner__content">
					<?php if ( $logo_url ) : ?>
						<p class="ucpf-banner__logo">
							<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $business_name ? $business_name : get_bloginfo( 'name' ) ); ?>" width="120" height="40" decoding="async" />
						</p>
					<?php endif; ?>
					<p id="ucpf-banner-title" class="ucpf-banner__label"><?php echo esc_html( $banner_title ); ?></p>
					<p id="ucpf-banner-desc" class="ucpf-banner__text">
						<?php echo esc_html( $banner_text ); ?>
						<?php if ( $cookie_policy_url ) : ?>
							<a class="ucpf-policy-link" href="<?php echo esc_url( $cookie_policy_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Cookie Policy', 'universal-consent-privacy-framework' ); ?></a>
						<?php endif; ?>
						<?php if ( $show_choices ) : ?>
							<a class="ucpf-policy-link ucpf-privacy-choices-link" href="<?php echo esc_url( $dns_url ); ?>"><?php echo esc_html( $choices_label ); ?></a>
						<?php endif; ?>
					</p>
				</div>
				<div class="ucpf-banner__actions">
					<?php if ( Settings::get( 'show_customize' ) ) : ?>
						<button type="button" class="ucpf-btn ucpf-btn--pill ucpf-btn--ghost" data-ucpf-action="customize">
							<?php esc_html_e( 'Customize', 'universal-consent-privacy-framework' ); ?>
						</button>
					<?php endif; ?>
					<?php if ( Settings::get( 'show_reject_all' ) ) : ?>
						<button type="button" class="ucpf-btn ucpf-btn--pill ucpf-btn--primary-tier ucpf-btn--outline" data-ucpf-action="reject_all">
							<?php esc_html_e( 'Reject All', 'universal-consent-privacy-framework' ); ?>
						</button>
					<?php endif; ?>
					<?php if ( Settings::get( 'show_accept_all' ) ) : ?>
						<button type="button" class="ucpf-btn ucpf-btn--pill ucpf-btn--primary-tier ucpf-btn--fill" data-ucpf-action="accept_all">
							<?php esc_html_e( 'Accept All', 'universal-consent-privacy-framework' ); ?>
						</button>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>

	<div class="ucpf-prefs" id="ucpf-prefs" role="dialog" aria-modal="true" aria-labelledby="ucpf-prefs-title" hidden>
		<div class="ucpf-prefs__overlay" data-ucpf-close-overlay></div>
		<div class="ucpf-prefs__dialog" tabindex="-1">
			<h2 id="ucpf-prefs-title" class="ucpf-prefs__title"><?php echo esc_html( $prefs_title ); ?></h2>
			<p class="ucpf-prefs__intro"><?php echo esc_html( $prefs_intro ); ?></p>
			<?php if ( $cookie_policy_url ) : ?>
				<p class="ucpf-prefs__policy">
					<a class="ucpf-policy-link" href="<?php echo esc_url( $cookie_policy_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Read our Cookie Policy', 'universal-consent-privacy-framework' ); ?></a>
				</p>
			<?php endif; ?>
			<?php if ( $show_choices ) : ?>
				<p class="ucpf-prefs__policy">
					<a class="ucpf-policy-link ucpf-privacy-choices-link" href="<?php echo esc_url( $dns_url ); ?>"><?php echo esc_html( $choices_label ); ?></a>
				</p>
			<?php endif; ?>
			<div id="ucpf-prefs-categories" role="group" aria-label="<?php esc_attr_e( 'Cookie categories', 'universal-consent-privacy-framework' ); ?>"></div>
			<div class="ucpf-prefs__footer">
				<button type="button" class="ucpf-btn ucpf-btn--pill ucpf-btn--primary-tier ucpf-btn--outline" data-ucpf-action="reject_all"><?php esc_html_e( 'Reject All', 'universal-consent-privacy-framework' ); ?></button>
				<button type="button" class="ucpf-btn ucpf-btn--pill ucpf-btn--primary-tier ucpf-btn--fill" data-ucpf-action="save_preferences"><?php esc_html_e( 'Save Preferences', 'universal-consent-privacy-framework' ); ?></button>
				<button type="button" class="ucpf-btn ucpf-btn--pill ucpf-btn--primary-tier ucpf-btn--fill" data-ucpf-action="accept_all"><?php esc_html_e( 'Accept All', 'universal-consent-privacy-framework' ); ?></button>
			</div>
		</div>
	</div>

	<?php if ( Settings::get( 'floating_prefs_button' ) ) : ?>
		<button
			type="button"
			class="ucpf-fab"
			id="ucpf-fab"
			data-ucpf-open-preferences
			hidden
			aria-haspopup="dialog"
			aria-controls="ucpf-prefs"
			aria-label="<?php echo esc_attr( $fab_label ); ?>"
		>
			<?php echo esc_html( $fab_label ); ?>
		</button>
	<?php endif; ?>
	<?php if ( $show_choices ) : ?>
		<a
			class="ucpf-privacy-choices-fab"
			id="ucpf-privacy-choices"
			href="<?php echo esc_url( $dns_url ); ?>"
			aria-label="<?php echo esc_attr( $choices_label ); ?>"
		>
			<?php echo esc_html( $choices_label ); ?>
		</a>
	<?php endif; ?>
</div>
<?php
/**
 * Guest-critical bootstrap: page caches / Rocket Loader often delay or break consent.js
 * for logged-out visitors while PHP still defers tracking scripts. Reveal the banner
 * immediately when no consent cookie exists, and handle Accept/Reject without waiting.
 */
?>
<script id="ucpf-banner-boot" data-cfasync="false">
(function () {
  function markDone() {
    window.__ucpfConsentDone = true;
  }

  function hasConsentCookie() {
    return /(?:^|;\s*)ucpf_consent=/.test(document.cookie || '');
  }

  function hideBannerNow() {
    var banner = document.getElementById('ucpf-banner');
    if (!banner) return;
    banner.hidden = true;
    banner.setAttribute('hidden', 'hidden');
    banner.classList.remove('ucpf-banner--visible');
    banner.classList.add('ucpf-banner--hidden');
  }

  function reveal() {
    if (window.__ucpfConsentDone || window.__ucpfDiscover) return false;
    if (hasConsentCookie()) {
      markDone();
      return false;
    }
    var banner = document.getElementById('ucpf-banner');
    if (!banner) return false;
    banner.hidden = false;
    banner.removeAttribute('hidden');
    banner.classList.remove('ucpf-banner--hidden');
    banner.classList.add('ucpf-banner--visible');
    var overlay = banner.querySelector('.ucpf-modal__overlay');
    var layout = banner.getAttribute('data-ucpf-layout') || 'bar';
    if (overlay) {
      if (layout === 'modal') {
        overlay.hidden = false;
        overlay.removeAttribute('hidden');
      } else {
        overlay.hidden = true;
        overlay.setAttribute('hidden', 'hidden');
      }
    }
    return true;
  }

  function writeConsent(all) {
    var cats = {
      necessary: true,
      preferences: !!all,
      analytics: !!all,
      marketing: !!all,
      functional: !!all,
      security: !!all
    };
    var maxAge = (window.ucpfConfig && window.ucpfConfig.cookieLifetime) || (365 * 24 * 60 * 60);
    var payload = {
      uuid: (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()),
      state: all ? 'accepted_all' : 'rejected_all',
      categories: cats,
      services: {},
      version: (window.ucpfConfig && window.ucpfConfig.consentVersion) || '1.0.0',
      policy_version: (window.ucpfConfig && window.ucpfConfig.policyVersion) || '',
      timestamp: Math.floor(Date.now() / 1000),
      expires: Math.floor(Date.now() / 1000) + maxAge
    };
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = 'ucpf_consent=' + encodeURIComponent(JSON.stringify(payload)) +
      '; Path=/; Max-Age=' + maxAge + '; SameSite=Lax' + secure;
    markDone();
    hideBannerNow();
  }

  function onAction(type, e) {
    // When consent.js is ready, it owns Accept/Reject — do not write/reload in parallel.
    if (window.UCPF) {
      if (e && e.stopImmediatePropagation) e.stopImmediatePropagation();
      if (type === 'accept_all') return window.UCPF.acceptAll();
      if (type === 'reject_all') return window.UCPF.rejectAll();
      if (type === 'customize' && window.UCPF.openPreferences) return window.UCPF.openPreferences();
      return;
    }
    if (type === 'accept_all' || type === 'reject_all') {
      writeConsent(type === 'accept_all');
      window.location.reload();
    }
  }

  function bindClicks() {
    var root = document.getElementById('ucpf-root');
    if (!root || root.getAttribute('data-ucpf-boot-bound')) return;
    root.setAttribute('data-ucpf-boot-bound', '1');
    root.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('[data-ucpf-action]') : null;
      if (!btn) return;
      var type = btn.getAttribute('data-ucpf-action');
      if (!type) return;
      e.preventDefault();
      onAction(type, e);
    }, true);
  }

  function boot() {
    if (window.__ucpfConsentDone) {
      bindClicks();
      return;
    }
    reveal();
    bindClicks();
  }

  boot();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  }
  [500, 1500, 3000, 6000].forEach(function (ms) {
    window.setTimeout(boot, ms);
  });
})();
</script>
