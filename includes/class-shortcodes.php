<?php
/**
 * Shortcodes.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode handler.
 */
class Shortcodes {

	/**
	 * Instance.
	 *
	 * @var Shortcodes|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Shortcodes
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init shortcodes.
	 */
	public function init() {
		add_shortcode( 'ucpf_consent_preferences', array( $this, 'consent_preferences' ) );
		add_shortcode( 'ucpf_cookie_table', array( $this, 'cookie_table' ) );
		add_shortcode( 'ucpf_privacy_disclosures', array( $this, 'privacy_disclosures' ) );
		add_shortcode( 'ucpf_data_request_form', array( $this, 'data_request_form' ) );
		add_shortcode( 'ucpf_do_not_sell_form', array( $this, 'do_not_sell_form' ) );
		add_shortcode( 'ucpf_privacy_summary', array( $this, 'privacy_summary' ) );
		add_shortcode( 'ucpf_gravity_form', array( $this, 'gravity_form_shortcode' ) );
	}

	/**
	 * Consent preferences shortcode.
	 *
	 * @return string
	 */
	public function consent_preferences() {
		ob_start();
		?>
		<div class="ucpf-legal">
			<p class="ucpf-legal__label"><?php esc_html_e( 'Consent Preferences', 'universal-consent-privacy-framework' ); ?></p>
			<button type="button" class="ucpf-btn ucpf-btn--pill ucpf-btn--primary-tier ucpf-btn--fill" data-ucpf-open-preferences>
				<?php esc_html_e( 'Open Cookie Settings', 'universal-consent-privacy-framework' ); ?>
			</button>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Cookie table shortcode — full visitor-facing inventory from latest scan.
	 *
	 * @return string
	 */
	public function cookie_table() {
		$inv       = Cookie_Scanner::instance()->get_policy_inventory();
		$scan_date = ! empty( $inv['date'] ) ? $inv['date'] : __( 'Not scanned yet', 'universal-consent-privacy-framework' );
		$cookies   = isset( $inv['cookies'] ) ? $inv['cookies'] : array();
		$storage   = isset( $inv['storage'] ) ? $inv['storage'] : array();
		$tech      = isset( $inv['technologies'] ) ? $inv['technologies'] : array();
		$cats      = isset( $inv['categories'] ) ? $inv['categories'] : array();

		ob_start();
		?>
		<div class="ucpf-legal ucpf-legal--table">
			<p class="ucpf-legal__label"><?php esc_html_e( 'What we use', 'universal-consent-privacy-framework' ); ?></p>
			<p><?php esc_html_e( 'We group cookies and similar technologies into the categories below. Essential items always run. Everything else waits for your choice in Cookie Settings.', 'universal-consent-privacy-framework' ); ?></p>
			<?php if ( $cats ) : ?>
				<ul class="ucpf-legal__categories">
					<?php foreach ( $cats as $cat ) : ?>
						<li>
							<strong><?php echo esc_html( $cat['label'] ); ?></strong>
							<?php if ( ! empty( $cat['required'] ) ) : ?>
								— <?php esc_html_e( 'always on', 'universal-consent-privacy-framework' ); ?>
							<?php endif; ?>
							<?php if ( ! empty( $cat['description'] ) ) : ?>
								<br /><span class="description"><?php echo esc_html( $cat['description'] ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<p class="ucpf-legal__label"><?php esc_html_e( 'Cookies on this site', 'universal-consent-privacy-framework' ); ?></p>
			<p class="ucpf-legal__meta"><?php echo esc_html( sprintf( /* translators: %s: scan date */ __( 'Inventory from last privacy scan: %s', 'universal-consent-privacy-framework' ), $scan_date ) ); ?></p>
			<p><?php esc_html_e( 'This list is built from cookies actually observed on this website (deduplicated). Names, services, and purposes are filled from our catalog when available.', 'universal-consent-privacy-framework' ); ?></p>
			<div class="ucpf-cookie-table-wrap">
				<table class="ucpf-cookie-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Cookie', 'universal-consent-privacy-framework' ); ?></th>
							<th><?php esc_html_e( 'Service', 'universal-consent-privacy-framework' ); ?></th>
							<th><?php esc_html_e( 'Category', 'universal-consent-privacy-framework' ); ?></th>
							<th><?php esc_html_e( 'Purpose', 'universal-consent-privacy-framework' ); ?></th>
							<th><?php esc_html_e( 'Consent', 'universal-consent-privacy-framework' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $cookies ) ) : ?>
							<tr><td colspan="5"><?php esc_html_e( 'No cookies in the stored inventory yet. Run Cookie Scanner (Deep privacy scan or import), then refresh this Cookie Policy page.', 'universal-consent-privacy-framework' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $cookies as $cookie ) : ?>
								<tr>
									<td>
										<code><?php echo esc_html( $cookie['name'] ); ?></code>
										<?php if ( ! empty( $cookie['domain'] ) ) : ?>
											<span class="ucpf-cookie-sub"><?php echo esc_html( $cookie['domain'] ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php
										$svc_show = ! empty( $cookie['display_label'] ) ? $cookie['display_label'] : $cookie['service_name'];
										echo esc_html( $svc_show );
										?>
										<?php if ( ! empty( $cookie['provider'] ) && $cookie['provider'] !== $svc_show ) : ?>
											<span class="ucpf-cookie-sub"><?php echo esc_html( $cookie['provider'] ); ?></span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( ! empty( $cookie['category_label'] ) ? $cookie['category_label'] : $cookie['category'] ); ?></td>
									<td><?php echo esc_html( $cookie['purpose'] ); ?></td>
									<td><?php echo esc_html( ! empty( $cookie['consent_label'] ) ? $cookie['consent_label'] : ( ! empty( $cookie['consent_required'] ) ? __( 'Optional (consent)', 'universal-consent-privacy-framework' ) : __( 'Essential', 'universal-consent-privacy-framework' ) ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $storage ) : ?>
				<p class="ucpf-legal__label"><?php esc_html_e( 'Browser storage', 'universal-consent-privacy-framework' ); ?></p>
				<p><?php esc_html_e( 'Some features also use localStorage or sessionStorage (not HTTP cookies). Keys observed on this site:', 'universal-consent-privacy-framework' ); ?></p>
				<div class="ucpf-cookie-table-wrap">
					<table class="ucpf-cookie-table ucpf-cookie-table--compact">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Type', 'universal-consent-privacy-framework' ); ?></th>
								<th><?php esc_html_e( 'Key', 'universal-consent-privacy-framework' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $storage as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['kind'] ); ?></td>
									<td><code><?php echo esc_html( $row['key'] ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php if ( $tech ) : ?>
				<p class="ucpf-legal__label"><?php esc_html_e( 'Other technologies on this site', 'universal-consent-privacy-framework' ); ?></p>
				<p><?php esc_html_e( 'Scripts, beacons, and embeds detected during the privacy scan. Some may set cookies only after you accept optional categories (for example analytics tags).', 'universal-consent-privacy-framework' ); ?></p>
				<div class="ucpf-cookie-table-wrap">
					<table class="ucpf-cookie-table ucpf-cookie-table--compact">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Name', 'universal-consent-privacy-framework' ); ?></th>
								<th><?php esc_html_e( 'Category', 'universal-consent-privacy-framework' ); ?></th>
								<th><?php esc_html_e( 'Type', 'universal-consent-privacy-framework' ); ?></th>
								<th><?php esc_html_e( 'Consent required', 'universal-consent-privacy-framework' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $tech as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['name'] ); ?></td>
									<td><?php echo esc_html( $row['category_label'] ); ?></td>
									<td><?php echo esc_html( $row['type'] ); ?></td>
									<td><?php echo ! empty( $row['consent_required'] ) ? esc_html__( 'Yes', 'universal-consent-privacy-framework' ) : esc_html__( 'No / essential', 'universal-consent-privacy-framework' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<p class="ucpf-legal__label"><?php esc_html_e( 'How to change your choices', 'universal-consent-privacy-framework' ); ?></p>
			<p><?php esc_html_e( 'Use Cookie Settings (floating button or Customize on the banner) anytime to accept, reject, or fine-tune optional categories. Your choice is stored in the ucpf_consent cookie on this site only.', 'universal-consent-privacy-framework' ); ?></p>
			<p>
				<button type="button" class="ucpf-btn ucpf-btn--pill ucpf-btn--primary-tier ucpf-btn--fill" data-ucpf-open-preferences>
					<?php esc_html_e( 'Open Cookie Settings', 'universal-consent-privacy-framework' ); ?>
				</button>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Live Privacy Policy disclosures: cookies, plugins, destinations, rights text.
	 *
	 * @return string
	 */
	public function privacy_disclosures() {
		$inv           = Cookie_Scanner::instance()->get_policy_inventory();
		$scan_date     = ! empty( $inv['date'] ) ? $inv['date'] : __( 'Not scanned yet', 'universal-consent-privacy-framework' );
		$cookies       = isset( $inv['cookies'] ) ? $inv['cookies'] : array();
		$storage       = isset( $inv['storage'] ) ? $inv['storage'] : array();
		$tech          = isset( $inv['technologies'] ) ? $inv['technologies'] : array();
		$plugins       = isset( $inv['plugins'] ) ? $inv['plugins'] : array();
		$destinations  = isset( $inv['destinations'] ) ? $inv['destinations'] : array();
		$service_keys  = isset( $inv['service_keys'] ) ? $inv['service_keys'] : array();
		$business_name = Settings::get( 'business_name' ) ?: get_bloginfo( 'name' );
		$contact_email = Settings::get( 'contact_email' ) ?: get_option( 'admin_email' );
		$address       = Settings::get( 'business_address' );
		$phone         = Settings::get( 'business_phone' );
		$retention     = (int) Settings::get( 'legal_retention_days', 365 );
		$data_url      = Page_Generator::instance()->get_rights_url( 'data_request' );
		$dns_url       = Page_Generator::instance()->get_rights_url( 'do_not_sell' );
		$cookie_url    = Page_Generator::instance()->get_page_url( 'cookie_policy' );

		$has = static function ( $needles ) use ( $service_keys, $plugins, $tech, $destinations ) {
			$hay = strtolower( implode( ' ', array_merge( $service_keys, wp_list_pluck( $plugins, 'service_key' ), wp_list_pluck( $plugins, 'name' ), wp_list_pluck( $tech, 'name' ), wp_list_pluck( $destinations, 'name' ), wp_list_pluck( $destinations, 'host' ) ) ) );
			foreach ( (array) $needles as $n ) {
				if ( $n && false !== strpos( $hay, strtolower( (string) $n ) ) ) {
					return true;
				}
			}
			return false;
		};

		ob_start();
		?>
		<div class="ucpf-legal ucpf-legal--privacy-disclosures">
			<p class="ucpf-legal__meta"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Inventory from last privacy scan / plugin map: %s', 'universal-consent-privacy-framework' ), $scan_date ) ); ?></p>

			<h3><?php esc_html_e( 'Cookies observed on this site', 'universal-consent-privacy-framework' ); ?></h3>
			<p><?php esc_html_e( 'Names, services, categories, and purposes from the latest scan, enriched with the local vendor catalog.', 'universal-consent-privacy-framework' ); ?></p>
			<div class="ucpf-cookie-table-wrap">
				<table class="ucpf-cookie-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Cookie', 'universal-consent-privacy-framework' ); ?></th>
							<th><?php esc_html_e( 'Service / provider', 'universal-consent-privacy-framework' ); ?></th>
							<th><?php esc_html_e( 'Category', 'universal-consent-privacy-framework' ); ?></th>
							<th><?php esc_html_e( 'Purpose', 'universal-consent-privacy-framework' ); ?></th>
							<th><?php esc_html_e( 'Consent', 'universal-consent-privacy-framework' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $cookies ) ) : ?>
							<tr><td colspan="5"><?php esc_html_e( 'No cookies in inventory yet. Run Cookie Scanner, then regenerate or refresh this Privacy Policy.', 'universal-consent-privacy-framework' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $cookies as $cookie ) : ?>
								<tr>
									<td><code><?php echo esc_html( $cookie['name'] ); ?></code></td>
									<td>
										<?php
										$svc_show = ! empty( $cookie['display_label'] ) ? $cookie['display_label'] : $cookie['service_name'];
										echo esc_html( $svc_show );
										?>
										<?php if ( ! empty( $cookie['provider'] ) && $cookie['provider'] !== $svc_show ) : ?>
											<span class="ucpf-cookie-sub"><?php echo esc_html( $cookie['provider'] ); ?></span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( ! empty( $cookie['category_label'] ) ? $cookie['category_label'] : $cookie['category'] ); ?></td>
									<td><?php echo esc_html( $cookie['purpose'] ); ?></td>
									<td><?php echo esc_html( ! empty( $cookie['consent_label'] ) ? $cookie['consent_label'] : ( ! empty( $cookie['consent_required'] ) ? __( 'Optional (consent)', 'universal-consent-privacy-framework' ) : __( 'Essential', 'universal-consent-privacy-framework' ) ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $storage ) : ?>
				<h3><?php esc_html_e( 'Browser storage keys', 'universal-consent-privacy-framework' ); ?></h3>
				<div class="ucpf-cookie-table-wrap">
					<table class="ucpf-cookie-table ucpf-cookie-table--compact">
						<thead><tr>
							<th><?php esc_html_e( 'Type', 'universal-consent-privacy-framework' ); ?></th>
							<th><?php esc_html_e( 'Key', 'universal-consent-privacy-framework' ); ?></th>
						</tr></thead>
						<tbody>
							<?php foreach ( $storage as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['kind'] ); ?></td>
									<td><code><?php echo esc_html( $row['key'] ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Scripts, pixels, beacons, and embeds', 'universal-consent-privacy-framework' ); ?></h3>
			<p><?php esc_html_e( 'Tracking can occur without cookies. These technologies were observed during scanning.', 'universal-consent-privacy-framework' ); ?></p>
			<div class="ucpf-cookie-table-wrap">
				<table class="ucpf-cookie-table ucpf-cookie-table--compact">
					<thead><tr>
						<th><?php esc_html_e( 'Name / host', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Category', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Type', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Consent', 'universal-consent-privacy-framework' ); ?></th>
					</tr></thead>
					<tbody>
						<?php if ( empty( $tech ) ) : ?>
							<tr><td colspan="4"><?php esc_html_e( 'No third-party technologies listed yet. Run a deep privacy scan for a fuller picture.', 'universal-consent-privacy-framework' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $tech as $row ) : ?>
								<tr>
									<td>
										<?php echo esc_html( $row['name'] ); ?>
										<?php if ( ! empty( $row['host'] ) && $row['host'] !== $row['name'] ) : ?>
											<span class="ucpf-cookie-sub"><code><?php echo esc_html( $row['host'] ); ?></code></span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $row['category_label'] ); ?></td>
									<td><?php echo esc_html( $row['type'] ); ?></td>
									<td><?php echo ! empty( $row['consent_required'] ) ? esc_html__( 'Optional (consent)', 'universal-consent-privacy-framework' ) : esc_html__( 'Essential / unclassified', 'universal-consent-privacy-framework' ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<h3><?php esc_html_e( 'WordPress plugins that may process data', 'universal-consent-privacy-framework' ); ?></h3>
			<p><?php esc_html_e( 'Active plugins mapped in our local catalog (forms, builders, analytics, security, commerce, etc.). Unmapped plugins may still process data — review your install inventory with counsel.', 'universal-consent-privacy-framework' ); ?></p>
			<div class="ucpf-cookie-table-wrap">
				<table class="ucpf-cookie-table">
					<thead><tr>
						<th><?php esc_html_e( 'Plugin / service', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Provider', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Category', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'What it may do', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Provider privacy info', 'universal-consent-privacy-framework' ); ?></th>
					</tr></thead>
					<tbody>
						<?php if ( empty( $plugins ) ) : ?>
							<tr><td colspan="5"><?php esc_html_e( 'No mapped plugins detected. Catalog coverage grows with plugin updates.', 'universal-consent-privacy-framework' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $plugins as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['service_name'] ? $row['service_name'] : $row['name'] ); ?></td>
									<td><?php echo esc_html( $row['provider'] ); ?></td>
									<td><?php echo esc_html( $row['category'] ); ?></td>
									<td><?php echo esc_html( $row['description'] ); ?></td>
									<td>
										<?php if ( ! empty( $row['privacy_url'] ) ) : ?>
											<a href="<?php echo esc_url( $row['privacy_url'] ); ?>" rel="noopener noreferrer" target="_blank"><?php esc_html_e( 'Privacy policy', 'universal-consent-privacy-framework' ); ?></a>
										<?php else : ?>
											—
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<h3><?php esc_html_e( 'Where data may go (third-party destinations)', 'universal-consent-privacy-framework' ); ?></h3>
			<p><?php esc_html_e( 'Depending on the tools enabled and your consent choices, information may be processed by the providers below (or their subprocessors). Each provider applies its own privacy terms.', 'universal-consent-privacy-framework' ); ?></p>
			<div class="ucpf-cookie-table-wrap">
				<table class="ucpf-cookie-table">
					<thead><tr>
						<th><?php esc_html_e( 'Destination', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Host / pattern', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Category', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Purpose', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'More info', 'universal-consent-privacy-framework' ); ?></th>
					</tr></thead>
					<tbody>
						<?php if ( empty( $destinations ) ) : ?>
							<tr><td colspan="5"><?php esc_html_e( 'No destinations listed yet. After a scan, known analytics, ads, CDN, and form providers appear here.', 'universal-consent-privacy-framework' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $destinations as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['name'] ); ?><?php if ( ! empty( $row['provider'] ) && $row['provider'] !== $row['name'] ) : ?> <span class="ucpf-cookie-sub"><?php echo esc_html( $row['provider'] ); ?></span><?php endif; ?></td>
									<td><code><?php echo esc_html( $row['host'] ); ?></code></td>
									<td><?php echo esc_html( $row['category'] ); ?></td>
									<td><?php echo esc_html( $row['purpose'] ); ?></td>
									<td>
										<?php if ( ! empty( $row['privacy_url'] ) ) : ?>
											<a href="<?php echo esc_url( $row['privacy_url'] ); ?>" rel="noopener noreferrer" target="_blank"><?php esc_html_e( 'Provider policy', 'universal-consent-privacy-framework' ); ?></a>
										<?php else : ?>
											—
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $has( array( 'google_analytics', 'google-analytics', 'gtag', 'site kit' ) ) ) : ?>
				<h3><?php esc_html_e( 'Google Analytics', 'universal-consent-privacy-framework' ); ?></h3>
				<p><?php esc_html_e( 'This website may use Google Analytics to understand how visitors find and use the site (pages viewed, device/browser, approximate location, referrers, events, and similar usage data). Google may use cookies or similar technologies. We do not intentionally send names, email addresses, phone numbers, or payment card numbers to Google Analytics. Where required by law, Analytics loads only after appropriate consent.', 'universal-consent-privacy-framework' ); ?></p>
			<?php endif; ?>

			<?php if ( $has( array( 'google_tag_manager', 'tag manager', 'googletagmanager' ) ) ) : ?>
				<h3><?php esc_html_e( 'Google Tag Manager', 'universal-consent-privacy-framework' ); ?></h3>
				<p><?php esc_html_e( 'Google Tag Manager may be used to manage tags and integrations (analytics, ads, consent signals). Data collected depends on the tags configured. Where required, tags that load analytics or advertising should respect consent choices before firing.', 'universal-consent-privacy-framework' ); ?></p>
			<?php endif; ?>

			<?php if ( $has( array( 'clarity', 'microsoft' ) ) ) : ?>
				<h3><?php esc_html_e( 'Microsoft Clarity / Advertising', 'universal-consent-privacy-framework' ); ?></h3>
				<p><?php esc_html_e( 'Microsoft Clarity or Microsoft Advertising may capture behavioral metrics, heatmaps, clicks, scrolls, session replay, device/browser information, and approximate location to improve usability, security, and (where allowed) advertising. Microsoft processes data under its privacy statement. Where required, these tools load only after consent.', 'universal-consent-privacy-framework' ); ?></p>
			<?php endif; ?>

			<?php if ( $has( array( 'cloudflare' ) ) ) : ?>
				<h3><?php esc_html_e( 'Cloudflare (security, CDN, analytics)', 'universal-consent-privacy-framework' ); ?></h3>
				<p><?php esc_html_e( 'Cloudflare may process technical information (IP addresses, request headers, URLs, security events, bot scores, traffic logs) to deliver, cache, and protect the site. Some Cloudflare security cookies may be treated as strictly necessary for availability and abuse prevention.', 'universal-consent-privacy-framework' ); ?></p>
			<?php endif; ?>

			<?php if ( $has( array( 'meta_pixel', 'facebook', 'pixel' ) ) ) : ?>
				<h3><?php esc_html_e( 'Meta / Facebook technologies', 'universal-consent-privacy-framework' ); ?></h3>
				<p><?php esc_html_e( 'Meta Pixel or related tools may measure conversions, build audiences, or support advertising. These tools typically require marketing consent where GDPR-style rules apply. You may also have opt-out rights under US state privacy laws for sale/sharing.', 'universal-consent-privacy-framework' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Forms and user submissions', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'If you submit a form, request information, book an appointment, place an order, or contact us, we may use that information to respond, fulfill the request, prevent spam, and keep records. Form data may be processed by form plugins, email/SMTP providers, CRM tools, spam protection, and hosting providers.', 'universal-consent-privacy-framework' ); ?></p>

			<h2><?php esc_html_e( 'Payments, shipping, and email', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'If this site offers purchases, donations, subscriptions, or bookings, payment details are typically handled by third-party payment processors. We do not intentionally store full card numbers unless a clearly disclosed compliant system requires it. Shipping/fulfillment data may be shared with carriers and logistics tools. Marketing emails are sent only where allowed; you can unsubscribe from marketing while still receiving transactional messages.', 'universal-consent-privacy-framework' ); ?></p>

			<h2><?php esc_html_e( 'How information is shared', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'We may share information with service providers (hosting, CDN, analytics, advertising, payments, email, CRM, security, developers) as needed to operate the site, or when required by law. We do not sell personal information for money in the everyday sense. Some privacy laws define “sale” or “sharing” broadly to include certain advertising or cross-context behavioral advertising — see California rights below.', 'universal-consent-privacy-framework' ); ?></p>

			<h2><?php esc_html_e( 'International transfers', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'Information may be processed or stored outside your state, province, or country (including by US-based providers). Where required, transfers may rely on adequacy decisions, standard contractual clauses, or other lawful mechanisms. Review each provider’s documentation for details.', 'universal-consent-privacy-framework' ); ?></p>

			<h2><?php esc_html_e( 'Data retention', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php echo esc_html( sprintf(
				/* translators: %d: days */
				__( 'We retain information only as long as reasonably necessary for the purposes described here, unless a longer period is required or allowed by law. Configured operational retention for certain privacy records on this site is approximately %d days, and other systems (orders, security logs, analytics) may use different periods.', 'universal-consent-privacy-framework' ),
				max( 1, $retention )
			) ); ?></p>

			<h2><?php esc_html_e( 'Security', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'We use reasonable technical and organizational measures (such as TLS, access controls, firewalls, malware scanning, and monitoring). No method of transmission or storage is completely secure.', 'universal-consent-privacy-framework' ); ?></p>

			<h2><?php esc_html_e( 'Your privacy rights (global)', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'Depending on where you live, you may have rights to access, correct, delete, restrict, or object to processing; withdraw consent; request portability; opt out of certain advertising or sale/sharing; limit use of sensitive personal information; and lodge a complaint with a supervisory authority.', 'universal-consent-privacy-framework' ); ?></p>
			<?php if ( $data_url ) : ?>
				<p><a href="<?php echo esc_url( $data_url ); ?>"><?php esc_html_e( 'Use our data request form', 'universal-consent-privacy-framework' ); ?></a></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'California privacy rights (CCPA / CPRA)', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'California residents may have the right to know what personal information is collected, used, disclosed, sold, or shared; to request deletion or correction; to opt out of sale or sharing; to limit use of sensitive personal information; and to non-discrimination for exercising these rights.', 'universal-consent-privacy-framework' ); ?></p>
			<p><?php esc_html_e( 'Categories commonly involved on websites like this include identifiers (name, email), commercial information (orders), internet activity (browsing, interactions), and approximate geolocation. Purposes include providing services, security, analytics, and marketing where allowed.', 'universal-consent-privacy-framework' ); ?></p>
			<?php if ( $dns_url ) : ?>
				<p><a href="<?php echo esc_url( $dns_url ); ?>"><?php esc_html_e( 'Do Not Sell or Share / Limit Use', 'universal-consent-privacy-framework' ); ?></a></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Other US state privacy laws', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'Residents of states such as Colorado, Connecticut, Virginia, Utah, and others may have similar rights to access, delete, correct, or opt out of targeted advertising, sale, or profiling. Use the contact methods or rights forms on this site to submit a request. We may need to verify your identity.', 'universal-consent-privacy-framework' ); ?></p>

			<h2><?php esc_html_e( 'European, UK, Swiss, and similar rights', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'Where GDPR or UK GDPR applies, you may have the rights listed above, including withdrawing consent for optional tracking. You may also contact your local data protection authority.', 'universal-consent-privacy-framework' ); ?></p>

			<h2><?php esc_html_e( 'Brazil (LGPD), Canada (including Quebec), and other regions', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'Other regions grant access, correction, deletion, and consent-related rights. This website’s jurisdiction packs and consent banner help support those workflows technically; local counsel should confirm legal requirements for your organization.', 'universal-consent-privacy-framework' ); ?></p>

			<h2><?php esc_html_e( 'Sensitive information and children', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'Do not submit sensitive personal information through this website unless specifically requested and necessary. This website is not directed to children under the age required by applicable law. We do not knowingly collect personal information from children without appropriate parental consent.', 'universal-consent-privacy-framework' ); ?></p>

			<h2><?php esc_html_e( 'Automated processing and breaches', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'We may use automated tools for analytics, spam/fraud detection, security, and performance. We do not intentionally use automated decision-making that produces legal or similarly significant effects without appropriate safeguards. If a personal-data breach occurs, we will investigate and notify individuals or authorities where required by law.', 'universal-consent-privacy-framework' ); ?></p>

			<h2><?php esc_html_e( 'Embedded content and links', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'Embedded videos, maps, social posts, or other third-party content may collect data as if you visited those sites directly. Review third-party privacy policies before interacting with embeds or external links.', 'universal-consent-privacy-framework' ); ?></p>

			<h2><?php esc_html_e( 'Changes', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'We may update this Privacy Policy when tools, laws, or business practices change. The updated version will be posted on this page with a revised date.', 'universal-consent-privacy-framework' ); ?></p>

			<h2><?php esc_html_e( 'Contact', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php echo esc_html( $business_name ); ?></p>
			<p><?php echo esc_html( sprintf( /* translators: %s: email */ __( 'Email: %s', 'universal-consent-privacy-framework' ), $contact_email ) ); ?></p>
			<?php if ( $phone ) : ?>
				<p><?php echo esc_html( sprintf( /* translators: %s: phone */ __( 'Phone: %s', 'universal-consent-privacy-framework' ), $phone ) ); ?></p>
			<?php endif; ?>
			<?php if ( $address ) : ?>
				<p><?php echo esc_html( $address ); ?></p>
			<?php endif; ?>
			<?php if ( $cookie_url ) : ?>
				<p><a href="<?php echo esc_url( $cookie_url ); ?>"><?php esc_html_e( 'Cookie Policy', 'universal-consent-privacy-framework' ); ?></a></p>
			<?php endif; ?>

			<p class="description"><em><?php esc_html_e( 'Generated by Universal Consent & Privacy Framework as a technical aid. Not a compliance certification.', 'universal-consent-privacy-framework' ); ?></em></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Data request form shortcode.
	 *
	 * @return string
	 */
	public function data_request_form() {
		if ( ! Settings::get( 'enable_data_request_forms', true ) ) {
			return '';
		}
		$gf = $this->render_configured_form( 'data_request' );
		return '' !== $gf ? $gf : $this->render_request_form( 'access' );
	}

	/**
	 * Do not sell form shortcode.
	 *
	 * @return string
	 */
	public function do_not_sell_form() {
		if ( ! Settings::get( 'enable_data_request_forms', true ) ) {
			return '';
		}
		$gf = $this->render_configured_form( 'do_not_sell' );
		return '' !== $gf ? $gf : $this->render_request_form( 'do_not_sell' );
	}

	/**
	 * Explicit Gravity Form embed: [ucpf_gravity_form id="12"] or shortcode="...".
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function gravity_form_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'        => '',
				'shortcode' => '',
			),
			$atts,
			'ucpf_gravity_form'
		);

		if ( ! empty( $atts['shortcode'] ) ) {
			return $this->wrap_gravity_output( do_shortcode( $atts['shortcode'] ) );
		}

		$form_id = absint( $atts['id'] );
		if ( ! $form_id ) {
			return '';
		}

		return $this->wrap_gravity_output(
			do_shortcode(
				sprintf(
					'[gravityform id="%d" title="false" description="false" ajax="true"]',
					$form_id
				)
			)
		);
	}

	/**
	 * Privacy summary shortcode.
	 *
	 * @return string
	 */
	public function privacy_summary() {
		ob_start();
		?>
		<div class="ucpf-legal">
			<p><?php esc_html_e( 'This site uses a consent banner to help you control optional cookies and scripts. Final legal review is the site owner\'s responsibility.', 'universal-consent-privacy-framework' ); ?></p>
			<button type="button" class="ucpf-btn ucpf-btn--pill ucpf-btn--ghost" data-ucpf-open-preferences><?php esc_html_e( 'Manage consent', 'universal-consent-privacy-framework' ); ?></button>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render GF when form ID or custom shortcode is configured.
	 *
	 * @param string $which data_request|do_not_sell.
	 * @return string Empty when built-in should be used.
	 */
	private function render_configured_form( $which ) {
		$custom = trim( (string) Settings::get( 'gf_' . $which . '_shortcode', '' ) );
		$form_id = absint( Settings::get( 'gf_' . $which . '_form_id', 0 ) );

		if ( '' === $custom && $form_id < 1 ) {
			return '';
		}

		if ( '' !== $custom ) {
			return $this->wrap_gravity_output( do_shortcode( $custom ) );
		}

		if ( ! class_exists( 'GFCommon' ) && ! function_exists( 'gravity_form' ) ) {
			return '<div class="ucpf-legal"><p class="ucpf-form__notice">' . esc_html__( 'Gravity Forms is not active. Activate it or clear the form ID to use the built-in request form.', 'universal-consent-privacy-framework' ) . '</p></div>';
		}

		return $this->wrap_gravity_output(
			do_shortcode(
				sprintf(
					'[gravityform id="%d" title="false" description="false" ajax="true"]',
					$form_id
				)
			)
		);
	}

	/**
	 * Wrap third-party form markup for UCPF legal styling.
	 *
	 * @param string $html Form HTML.
	 * @return string
	 */
	private function wrap_gravity_output( $html ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return '';
		}
		return '<div class="ucpf-legal ucpf-gf-wrap">' . $html . '</div>';
	}

	/**
	 * Render DSAR form.
	 *
	 * @param string $default_type Default request type.
	 * @return string
	 */
	private function render_request_form( $default_type = 'access' ) {
		ob_start();
		$is_dns = ( 'do_not_sell' === $default_type );
		?>
		<form class="ucpf-form ucpf-data-request-form" data-ucpf-form="data-request">
			<input type="hidden" name="request_type" value="<?php echo esc_attr( $default_type ); ?>" />
			<div class="ucpf-field">
				<label for="ucpf-dsar-email"><?php esc_html_e( 'Email address', 'universal-consent-privacy-framework' ); ?></label>
				<input class="ucpf-field__input" type="email" id="ucpf-dsar-email" name="email" required autocomplete="email" />
			</div>
			<?php if ( $is_dns ) : ?>
				<?php
				$pack = \UCPF\Jurisdiction::instance()->resolve();
				$show_limit = ! empty( $pack['show_limit_sensitive'] );
				$dns_title = ! empty( $pack['copy']['dns_title'] ) ? $pack['copy']['dns_title'] : __( 'Do Not Sell or Share My Personal Information', 'universal-consent-privacy-framework' );
				$dns_intro = ! empty( $pack['copy']['dns_intro'] ) ? $pack['copy']['dns_intro'] : '';
				?>
				<p class="ucpf-form__notice"><strong><?php echo esc_html( $dns_title ); ?></strong><?php echo $dns_intro ? ' ' . esc_html( $dns_intro ) : ''; ?></p>
				<p class="ucpf-form__notice"><?php esc_html_e( 'This form helps support privacy rights workflows. It is not legal advice and does not guarantee regulatory compliance.', 'universal-consent-privacy-framework' ); ?></p>
				<fieldset class="ucpf-field">
					<legend><?php esc_html_e( 'What would you like to opt out of?', 'universal-consent-privacy-framework' ); ?></legend>
					<label><input type="checkbox" name="opt_out_sale" value="1" checked /> <?php esc_html_e( 'Sale of personal information', 'universal-consent-privacy-framework' ); ?></label><br />
					<label><input type="checkbox" name="opt_out_sharing" value="1" checked /> <?php esc_html_e( 'Sharing of personal information (including for cross-context behavioral advertising)', 'universal-consent-privacy-framework' ); ?></label><br />
					<label><input type="checkbox" name="opt_out_targeted" value="1" checked /> <?php esc_html_e( 'Targeted advertising', 'universal-consent-privacy-framework' ); ?></label><br />
					<?php if ( $show_limit ) : ?>
						<label><input type="checkbox" name="limit_sensitive" value="1" /> <?php esc_html_e( 'Limit use of sensitive personal information', 'universal-consent-privacy-framework' ); ?></label><br />
					<?php endif; ?>
				</fieldset>
				<fieldset class="ucpf-field">
					<legend><?php esc_html_e( 'Apply this request to', 'universal-consent-privacy-framework' ); ?></legend>
					<label><input type="radio" name="scope" value="site" checked /> <?php esc_html_e( 'This website only', 'universal-consent-privacy-framework' ); ?></label><br />
					<label><input type="radio" name="scope" value="controller" /> <?php esc_html_e( 'All websites operated by this business (when a privacy API is configured)', 'universal-consent-privacy-framework' ); ?></label><br />
					<label><input type="radio" name="scope" value="selected" /> <?php esc_html_e( 'Selected businesses / websites (processed by the privacy team)', 'universal-consent-privacy-framework' ); ?></label>
				</fieldset>
				<p class="ucpf-field">
					<label>
						<input type="checkbox" name="global_privacy_mode" value="1" />
						<?php esc_html_e( 'Also block all nonessential tracking on this site (analytics, embeds, personalization)', 'universal-consent-privacy-framework' ); ?>
					</label>
				</p>
				<p class="ucpf-form__notice"><?php esc_html_e( 'We enforce this on this site immediately (scripts and tags). Cross-site enforcement for the same business uses Global Privacy Control in your browser and/or an optional agency privacy API — we do not set cross-domain tracking cookies.', 'universal-consent-privacy-framework' ); ?></p>
			<?php endif; ?>
			<div class="ucpf-field ucpf-field--honeypot" aria-hidden="true">
				<label for="ucpf-website"><?php esc_html_e( 'Website', 'universal-consent-privacy-framework' ); ?></label>
				<input class="ucpf-field__input" type="text" id="ucpf-website" name="website" tabindex="-1" autocomplete="off" />
			</div>
			<div class="ucpf-field">
				<label for="ucpf-dsar-message"><?php esc_html_e( 'Message (optional)', 'universal-consent-privacy-framework' ); ?></label>
				<textarea class="ucpf-field__input ucpf-field__textarea" id="ucpf-dsar-message" name="message" rows="4"></textarea>
			</div>
			<button type="submit" class="ucpf-btn ucpf-btn--pill ucpf-btn--primary-tier ucpf-btn--fill"><?php esc_html_e( 'Submit request', 'universal-consent-privacy-framework' ); ?></button>
			<p class="ucpf-form__status" role="status" aria-live="polite" hidden></p>
		</form>
		<?php
		return ob_get_clean();
	}
}

