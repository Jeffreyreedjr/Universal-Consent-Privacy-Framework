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
		add_shortcode( 'ucpf_clarity_disclosure', array( $this, 'clarity_site_disclosure' ) );
		add_shortcode( 'ucpf_data_request_form', array( $this, 'data_request_form' ) );
		add_shortcode( 'ucpf_do_not_sell_form', array( $this, 'do_not_sell_form' ) );
		add_shortcode( 'ucpf_privacy_summary', array( $this, 'privacy_summary' ) );
	}

	/**
	 * Optional homepage/footer site disclosure for Microsoft Clarity (GDPR-safe).
	 *
	 * Unlike Microsoft’s sample “by using this site you agree” browsewrap text, this
	 * points visitors to Cookie Settings and the Privacy Policy — Clarity still waits
	 * for Analytics consent where the jurisdiction pack requires it.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function clarity_site_disclosure( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'force' => '0',
			),
			$atts,
			'ucpf_clarity_disclosure'
		);

		$force = in_array( strtolower( (string) $atts['force'] ), array( '1', 'true', 'yes' ), true );
		if ( ! $force && ! $this->site_uses_clarity() ) {
			return '';
		}

		$privacy_url = Page_Generator::instance()->get_page_url( 'privacy_policy' );
		$cookie_url  = Page_Generator::instance()->get_page_url( 'cookie_policy' );

		ob_start();
		?>
		<p class="ucpf-site-disclosure ucpf-site-disclosure--clarity">
			<?php
			esc_html_e(
				'We improve our products and site experience with Microsoft Clarity (behavior analytics, heatmaps, and session replay). Where required, Clarity loads only after you accept Analytics in Cookie Settings — not from mere browsing alone.',
				'universal-consent-privacy-framework'
			);
			?>
			<?php if ( $privacy_url ) : ?>
				<?php
				echo ' ';
				echo wp_kses(
					sprintf(
						/* translators: %s: privacy policy URL */
						__( 'See our <a href="%s">Privacy Policy</a> for details, including how Microsoft may process data.', 'universal-consent-privacy-framework' ),
						esc_url( $privacy_url )
					),
					array( 'a' => array( 'href' => true ) )
				);
				?>
			<?php endif; ?>
			<?php if ( $cookie_url ) : ?>
				<?php
				echo ' ';
				echo wp_kses(
					sprintf(
						/* translators: %s: cookie policy URL */
						__( '<a href="%s">Cookie Policy</a>', 'universal-consent-privacy-framework' ),
						esc_url( $cookie_url )
					),
					array( 'a' => array( 'href' => true ) )
				);
				?>
			<?php endif; ?>
			<?php echo ' '; ?>
			<button type="button" class="ucpf-btn ucpf-btn--link" data-ucpf-open-preferences>
				<?php esc_html_e( 'Cookie Settings', 'universal-consent-privacy-framework' ); ?>
			</button>
		</p>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Whether Clarity appears enabled or detected for disclosures.
	 *
	 * @return bool
	 */
	private function site_uses_clarity() {
		$ids = Settings::get( 'service_ids', array() );
		if ( is_array( $ids ) && ! empty( $ids['microsoft_clarity'] ) && is_array( $ids['microsoft_clarity'] ) ) {
			$row = $ids['microsoft_clarity'];
			if ( ! empty( $row['enabled'] ) && ( ! empty( $row['id'] ) || ! empty( $row['code'] ) ) ) {
				return true;
			}
		}

		$inv  = Cookie_Scanner::instance()->get_policy_inventory();
		$hay  = strtolower(
			implode(
				' ',
				array_merge(
					isset( $inv['service_keys'] ) ? (array) $inv['service_keys'] : array(),
					wp_list_pluck( isset( $inv['plugins'] ) ? (array) $inv['plugins'] : array(), 'service_key' ),
					wp_list_pluck( isset( $inv['plugins'] ) ? (array) $inv['plugins'] : array(), 'name' ),
					wp_list_pluck( isset( $inv['technologies'] ) ? (array) $inv['technologies'] : array(), 'name' ),
					wp_list_pluck( isset( $inv['destinations'] ) ? (array) $inv['destinations'] : array(), 'host' )
				)
			)
		);
		return ( false !== strpos( $hay, 'clarity' ) );
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
								. <?php esc_html_e( 'Always on.', 'universal-consent-privacy-framework' ); ?>
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
			<p><?php esc_html_e( 'This list is built from cookies actually observed on this website. Names, services, and purposes are filled from our catalog when available.', 'universal-consent-privacy-framework' ); ?></p>
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
									<td><?php echo ! empty( $row['consent_required'] ) ? esc_html__( 'Yes', 'universal-consent-privacy-framework' ) : esc_html__( 'No or essential', 'universal-consent-privacy-framework' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<p class="ucpf-legal__label"><?php esc_html_e( 'How to change your choices', 'universal-consent-privacy-framework' ); ?></p>
			<p><?php esc_html_e( 'Use Cookie Settings anytime to accept, reject, or fine-tune optional categories. You can open it from the floating button or from Customize on the banner. Your choice is stored in the ucpf_consent cookie on this site only.', 'universal-consent-privacy-framework' ); ?></p>
			<p>
				<button type="button" class="ucpf-btn ucpf-btn--pill ucpf-btn--primary-tier ucpf-btn--fill" data-ucpf-open-preferences>
					<?php esc_html_e( 'Open Cookie Settings', 'universal-consent-privacy-framework' ); ?>
				</button>
			</p>

			<?php
			$privacy_url = Page_Generator::instance()->get_page_url( 'privacy_policy' );
			$data_url    = Page_Generator::instance()->get_rights_url( 'data_request' );
			$dns_url     = Page_Generator::instance()->get_rights_url( 'do_not_sell' );
			$email       = Settings::get( 'contact_email' ) ?: get_option( 'admin_email' );
			?>
			<p class="ucpf-legal__label"><?php esc_html_e( 'Privacy rights and Do Not Sell or Share', 'universal-consent-privacy-framework' ); ?></p>
			<p><?php esc_html_e( 'Cookie Settings control optional cookies on this site. Separate privacy rights such as access, deletion, and opting out of sale or sharing of personal information under US state laws are described in our Privacy Policy.', 'universal-consent-privacy-framework' ); ?></p>
			<ul>
				<?php if ( $privacy_url ) : ?>
					<li><a href="<?php echo esc_url( $privacy_url ); ?>"><?php esc_html_e( 'Privacy Policy', 'universal-consent-privacy-framework' ); ?></a></li>
				<?php endif; ?>
				<?php if ( $dns_url ) : ?>
					<li><a href="<?php echo esc_url( $dns_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Do Not Sell or Share My Personal Information', 'universal-consent-privacy-framework' ); ?></a></li>
				<?php endif; ?>
				<?php if ( $data_url ) : ?>
					<li><a href="<?php echo esc_url( $data_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Submit a privacy or data rights request', 'universal-consent-privacy-framework' ); ?></a></li>
				<?php endif; ?>
				<?php if ( $email ) : ?>
					<li><?php echo esc_html( sprintf( /* translators: %s: email */ __( 'Contact: %s', 'universal-consent-privacy-framework' ), $email ) ); ?></li>
				<?php endif; ?>
			</ul>
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

		$service_ids = Settings::get( 'service_ids', array() );
		if ( is_array( $service_ids ) ) {
			foreach ( $service_ids as $sid_key => $sid_row ) {
				if ( is_array( $sid_row ) && ! empty( $sid_row['enabled'] ) && ( ! empty( $sid_row['id'] ) || ! empty( $sid_row['code'] ) ) ) {
					$service_keys[] = (string) $sid_key;
				}
			}
		}

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
			<p class="ucpf-legal__meta"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Inventory from last privacy scan and plugin map: %s', 'universal-consent-privacy-framework' ), $scan_date ) ); ?></p>

			<h3><?php esc_html_e( 'Cookies observed on this site', 'universal-consent-privacy-framework' ); ?></h3>
			<p><?php esc_html_e( 'Names, services, categories, and purposes from the latest scan. Entries are enriched with the local vendor catalog when a match is known.', 'universal-consent-privacy-framework' ); ?></p>
			<div class="ucpf-cookie-table-wrap">
				<table class="ucpf-cookie-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Cookie', 'universal-consent-privacy-framework' ); ?></th>
							<th><?php esc_html_e( 'Service or provider', 'universal-consent-privacy-framework' ); ?></th>
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
						<th><?php esc_html_e( 'Name or host', 'universal-consent-privacy-framework' ); ?></th>
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
									<td><?php echo ! empty( $row['consent_required'] ) ? esc_html__( 'Optional (consent)', 'universal-consent-privacy-framework' ) : esc_html__( 'Essential or unclassified', 'universal-consent-privacy-framework' ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<h3><?php esc_html_e( 'WordPress plugins that may process data', 'universal-consent-privacy-framework' ); ?></h3>
			<p><?php esc_html_e( 'Active plugins mapped in our local catalog, including forms, builders, analytics, security, and commerce tools. Unmapped plugins may still process data. Review your install inventory with counsel.', 'universal-consent-privacy-framework' ); ?></p>
			<div class="ucpf-cookie-table-wrap">
				<table class="ucpf-cookie-table">
					<thead><tr>
						<th><?php esc_html_e( 'Plugin or service', 'universal-consent-privacy-framework' ); ?></th>
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
											<?php esc_html_e( 'Not linked', 'universal-consent-privacy-framework' ); ?>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<h3><?php esc_html_e( 'Where data may go', 'universal-consent-privacy-framework' ); ?></h3>
			<p><?php esc_html_e( 'Depending on the tools enabled and your consent choices, information may be processed by the providers below or their subprocessors. Each provider applies its own privacy terms.', 'universal-consent-privacy-framework' ); ?></p>
			<div class="ucpf-cookie-table-wrap">
				<table class="ucpf-cookie-table">
					<thead><tr>
						<th><?php esc_html_e( 'Destination', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Host or pattern', 'universal-consent-privacy-framework' ); ?></th>
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
											<?php esc_html_e( 'Not linked', 'universal-consent-privacy-framework' ); ?>
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
				<p><?php esc_html_e( 'This website may use Google Analytics to understand how visitors find and use the site. That can include pages viewed, device and browser details, approximate location, referrers, and events. Google may use cookies or similar technologies. We do not intentionally send names, email addresses, phone numbers, or payment card numbers to Google Analytics. Where required by law, Analytics loads only after appropriate consent.', 'universal-consent-privacy-framework' ); ?></p>
			<?php endif; ?>

			<?php if ( $has( array( 'google_tag_manager', 'tag manager', 'googletagmanager' ) ) ) : ?>
				<h3><?php esc_html_e( 'Google Tag Manager', 'universal-consent-privacy-framework' ); ?></h3>
				<p><?php esc_html_e( 'Google Tag Manager may be used to manage tags and integrations for analytics, ads, and consent signals. Data collected depends on the tags configured. Where required, tags that load analytics or advertising should respect consent choices before firing.', 'universal-consent-privacy-framework' ); ?></p>
			<?php endif; ?>

			<?php if ( $has( array( 'microsoft_clarity', 'clarity.ms', 'clarity' ) ) ) : ?>
				<h3><?php esc_html_e( 'Microsoft Clarity', 'universal-consent-privacy-framework' ); ?></h3>
				<p><?php
					esc_html_e(
						'We partner with Microsoft Clarity to understand how you use and interact with this website. Clarity may capture behavioral metrics, heatmaps, clicks, scrolls, and session replay, and may collect device and browser information and approximate location, using first- and third-party cookies and similar technologies. We use this information to improve products and services, optimize the site, support fraud and security monitoring, and (where enabled) advertising or Microsoft Advertising integrations.',
						'universal-consent-privacy-framework'
					);
				?></p>
				<p><?php
					esc_html_e(
						'Where GDPR-style rules or our jurisdiction pack require consent, Clarity loads only after you accept Analytics (or an equivalent category). It does not run from mere site use before that choice. You can change or withdraw consent anytime via Cookie Settings.',
						'universal-consent-privacy-framework'
					);
				?></p>
				<p><?php
					echo wp_kses(
						sprintf(
							/* translators: %s: Microsoft Privacy Statement URL */
							__( 'For more information about how Microsoft collects and uses your data, visit the <a href="%s" rel="noopener noreferrer" target="_blank">Microsoft Privacy Statement</a>.', 'universal-consent-privacy-framework' ),
							esc_url( 'https://privacy.microsoft.com/privacystatement' )
						),
						array(
							'a' => array(
								'href'   => true,
								'rel'    => true,
								'target' => true,
							),
						)
					);
				?></p>
			<?php elseif ( $has( array( 'microsoft_advertising', 'bing ads', 'bat.bing.com', 'uetag' ) ) ) : ?>
				<h3><?php esc_html_e( 'Microsoft Advertising', 'universal-consent-privacy-framework' ); ?></h3>
				<p><?php esc_html_e( 'Microsoft Advertising (Bing UET) may measure visits and conversions for ads. Where required, it loads only after Marketing consent. Microsoft processes data under its privacy statement.', 'universal-consent-privacy-framework' ); ?></p>
			<?php endif; ?>

			<?php if ( $has( array( 'cloudflare' ) ) ) : ?>
				<h3><?php esc_html_e( 'Cloudflare', 'universal-consent-privacy-framework' ); ?></h3>
				<p><?php esc_html_e( 'Cloudflare may process technical information such as IP addresses, request headers, URLs, security events, bot scores, and traffic logs. This helps deliver, cache, and protect the site. Some Cloudflare security cookies may be treated as strictly necessary for availability and abuse prevention.', 'universal-consent-privacy-framework' ); ?></p>
			<?php endif; ?>

			<?php if ( $has( array( 'meta_pixel', 'facebook', 'pixel' ) ) ) : ?>
				<h3><?php esc_html_e( 'Meta and Facebook technologies', 'universal-consent-privacy-framework' ); ?></h3>
				<p><?php esc_html_e( 'Meta Pixel or related tools may measure conversions, build audiences, or support advertising. These tools typically require marketing consent where GDPR-style rules apply. You may also have opt-out rights under US state privacy laws for sale or sharing.', 'universal-consent-privacy-framework' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Forms and user submissions', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'If you submit a form, request information, book an appointment, place an order, or contact us, we may use that information to respond, fulfill the request, prevent spam, and keep records. Form data may be processed by form plugins, email or SMTP providers, CRM tools, spam protection, and hosting providers.', 'universal-consent-privacy-framework' ); ?></p>

			<h2><?php esc_html_e( 'Payments, shipping, and email', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'If this site offers purchases, donations, subscriptions, or bookings, payment details are typically handled by third-party payment processors. We do not intentionally store full card numbers unless a clearly disclosed compliant system requires it. Shipping or fulfillment data may be shared with carriers and logistics tools. Marketing emails are sent only where allowed. You can unsubscribe from marketing and still receive transactional messages.', 'universal-consent-privacy-framework' ); ?></p>

			<h2><?php esc_html_e( 'How information is shared', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'We may share information with service providers such as hosting, CDN, analytics, advertising, payments, email, CRM, security, and developers as needed to operate the site, or when required by law. We do not sell personal information for money in the everyday sense. Some privacy laws define “sale” or “sharing” broadly to include certain advertising or cross-context behavioral advertising. See California rights below.', 'universal-consent-privacy-framework' ); ?></p>

			<h2><?php esc_html_e( 'International transfers', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'Information may be processed or stored outside your state, province, or country, including by US-based providers. Where required, transfers may rely on adequacy decisions, standard contractual clauses, or other lawful mechanisms. Review each provider’s documentation for details.', 'universal-consent-privacy-framework' ); ?></p>

			<h2><?php esc_html_e( 'Data retention', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php echo esc_html( sprintf(
				/* translators: %d: days */
				__( 'We retain information only as long as reasonably necessary for the purposes described here, unless a longer period is required or allowed by law. Configured operational retention for certain privacy records on this site is approximately %d days. Other systems such as orders, security logs, and analytics may use different periods.', 'universal-consent-privacy-framework' ),
				max( 1, $retention )
			) ); ?></p>

			<h2><?php esc_html_e( 'Security', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'We use reasonable technical and organizational measures such as TLS, access controls, firewalls, malware scanning, and monitoring. No method of transmission or storage is completely secure.', 'universal-consent-privacy-framework' ); ?></p>
			<?php if ( Settings::get( 'login_security_notice', true ) ) : ?>
				<h3><?php esc_html_e( 'Account login activity', 'universal-consent-privacy-framework' ); ?></h3>
				<p><?php echo esc_html( Login_Notice::notice_text() ); ?></p>
				<p><?php esc_html_e( 'Password-policy and login-security tools (for example plugins that enforce password rotation or record sign-in history) may retain those events separately from cookie consent logs. Staff and customers with accounts should expect this monitoring as part of protecting the site.', 'universal-consent-privacy-framework' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Your privacy rights', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'Depending on where you live, you may have rights to access, correct, delete, restrict, or object to processing. You may also withdraw consent, request portability, opt out of certain advertising or sale or sharing, limit use of sensitive personal information, and lodge a complaint with a supervisory authority.', 'universal-consent-privacy-framework' ); ?></p>
			<?php if ( $data_url ) : ?>
				<p><a href="<?php echo esc_url( $data_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Submit a privacy or data rights request', 'universal-consent-privacy-framework' ); ?></a></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'California privacy rights (CCPA / CPRA). Do Not Sell or Share', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'California residents may have the right to know what personal information is collected, used, disclosed, sold, or shared. They may also request deletion or correction, opt out of sale or sharing, limit use of sensitive personal information, and be free from discrimination for exercising these rights.', 'universal-consent-privacy-framework' ); ?></p>
			<p><?php esc_html_e( 'Categories commonly involved on websites like this include identifiers such as name and email, commercial information such as orders, internet activity such as browsing and interactions, and approximate geolocation. Purposes include providing services, security, analytics, and marketing where allowed.', 'universal-consent-privacy-framework' ); ?></p>
			<p><strong><?php esc_html_e( 'Do Not Sell or Share.', 'universal-consent-privacy-framework' ); ?></strong>
				<?php esc_html_e( 'To opt out of sale or sharing of personal information as defined under California law and similar US state laws, use the link below when available, or email us. Cookie Settings alone may not cover every advertising or sharing activity covered by those laws.', 'universal-consent-privacy-framework' ); ?>
			</p>
			<?php if ( $dns_url ) : ?>
				<p><a href="<?php echo esc_url( $dns_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Do Not Sell or Share My Personal Information', 'universal-consent-privacy-framework' ); ?></a></p>
			<?php elseif ( $contact_email ) : ?>
				<p><?php echo esc_html( sprintf( /* translators: %s: email */ __( 'Email your Do Not Sell or Share request to: %s', 'universal-consent-privacy-framework' ), $contact_email ) ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Other US state privacy laws', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'Residents of states such as Colorado, Connecticut, Virginia, Utah, and others may have similar rights to access, delete, correct, or opt out of targeted advertising, sale, or profiling. Use the contact methods or rights forms on this site to submit a request. We may need to verify your identity.', 'universal-consent-privacy-framework' ); ?></p>

			<h2><?php esc_html_e( 'European, UK, Swiss, and similar rights', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'Where GDPR or UK GDPR applies, you may have the rights listed above, including withdrawing consent for optional tracking. You may also contact your local data protection authority.', 'universal-consent-privacy-framework' ); ?></p>

			<h2><?php esc_html_e( 'Brazil, Canada, and other regions', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'Other regions grant access, correction, deletion, and consent-related rights. This website’s jurisdiction packs and consent banner help support those workflows technically. Local counsel should confirm legal requirements for your organization.', 'universal-consent-privacy-framework' ); ?></p>

			<h2><?php esc_html_e( 'Sensitive information and children', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'Do not submit sensitive personal information through this website unless specifically requested and necessary. This website is not directed to children under the age required by applicable law. We do not knowingly collect personal information from children without appropriate parental consent.', 'universal-consent-privacy-framework' ); ?></p>

			<h2><?php esc_html_e( 'Automated processing and breaches', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'We may use automated tools for analytics, spam and fraud detection, security, and performance. We do not intentionally use automated decision-making that produces legal or similarly significant effects without appropriate safeguards. If a personal data breach occurs, we will investigate and notify individuals or authorities where required by law.', 'universal-consent-privacy-framework' ); ?></p>

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

			<p class="description"><em><?php esc_html_e( 'Generated by Universal Consent and Privacy Framework as a technical aid. Not a compliance certification.', 'universal-consent-privacy-framework' ); ?></em></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Data request shortcode — link to external rights URL (forms are not hosted on this site).
	 *
	 * @return string
	 */
	public function data_request_form() {
		return $this->rights_external_link( 'data_request', __( 'Privacy rights request', 'universal-consent-privacy-framework' ) );
	}

	/**
	 * Do not sell shortcode — link to external rights URL.
	 *
	 * @return string
	 */
	public function do_not_sell_form() {
		$pack  = Jurisdiction::instance()->resolve();
		$label = ! empty( $pack['copy']['dns_title'] ) ? $pack['copy']['dns_title'] : __( 'Do Not Sell or Share', 'universal-consent-privacy-framework' );
		return $this->rights_external_link( 'do_not_sell', $label );
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
	 * Render a link to the configured external rights page.
	 *
	 * @param string $which data_request|do_not_sell.
	 * @param string $label Link text.
	 * @return string
	 */
	private function rights_external_link( $which, $label ) {
		$url = Page_Generator::instance()->get_rights_url( $which );
		if ( ! $url ) {
			return '';
		}
		ob_start();
		?>
		<div class="ucpf-legal">
			<p><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $label ); ?></a></p>
		</div>
		<?php
		return ob_get_clean();
	}
}

