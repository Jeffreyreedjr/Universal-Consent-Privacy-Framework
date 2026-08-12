<?php
/**
 * Optional Cloudflare edge cache purge (debounced, rate-limited).
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Cloudflare Cache Purge API helper.
 *
 * Schedules a single coalesced purge after plugin/theme updates so edge-cached
 * HTML/CSS cannot keep a broken deploy. Does not nuke origin optimizers.
 *
 * Credentials: API token + site domain (Zone ID resolved via Cloudflare API).
 */
class Cloudflare_Cache {

	const CRON_HOOK      = 'ucpf_cloudflare_purge_edge';
	const LOCK_TRANSIENT = 'ucpf_cf_purge_lock';
	const STATUS_OPTION  = 'ucpf_cloudflare_purge_last';
	const PENDING_FILES  = 'ucpf_cf_pending_files';

	/**
	 * @var Cloudflare_Cache|null
	 */
	private static $instance = null;

	/**
	 * @return Cloudflare_Cache
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register cron consumer + admin fallback when external cron never fires.
	 */
	public function init() {
		add_action( self::CRON_HOOK, array( $this, 'purge_edge' ), 10, 1 );
		// If a previous request queued a purge but died before shutdown, finish it in admin.
		add_action( 'admin_init', array( $this, 'run_pending_purge_on_shutdown' ), 30 );
	}

	/**
	 * Default hostname from the WordPress site URL.
	 *
	 * @return string
	 */
	public static function default_domain() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Sanitize a Cloudflare zone hostname (no scheme/path).
	 *
	 * @param string $raw Raw input.
	 * @return string
	 */
	public static function sanitize_domain( $raw ) {
		$raw = strtolower( trim( (string) $raw ) );
		$raw = preg_replace( '#^https?://#', '', $raw );
		$raw = preg_replace( '#/.*$#', '', $raw );
		$raw = preg_replace( '/:\d+$/', '', $raw );
		$raw = preg_replace( '/[^a-z0-9.-]/', '', $raw );
		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Whether purge is configured and enabled.
	 *
	 * @return bool
	 */
	public function is_ready() {
		$enabled = (bool) Settings::get( 'cloudflare_purge_enabled', false );
		/**
		 * Filter master enable for Cloudflare edge purge.
		 *
		 * @param bool $enabled Settings flag.
		 */
		$enabled = (bool) apply_filters( 'ucpf_cloudflare_purge_enabled', $enabled );
		if ( ! $enabled ) {
			return false;
		}
		$token = $this->get_api_token();
		if ( '' === $token ) {
			return false;
		}
		$zone = $this->get_zone_id( true );
		return '' !== $zone;
	}

	/**
	 * Queue a Cloudflare edge purge without WP-Cron / spawn_cron.
	 *
	 * Some hosts use external cron runners that break `wp_schedule_single_event`
	 * + `spawn_cron` loopbacks. We run on shutdown of this request instead.
	 *
	 * @param string          $reason Reason slug.
	 * @param string[]|string $files  Optional absolute CSS/asset URLs to purge by file.
	 * @return bool True when queued for shutdown purge or soft-hooked.
	 */
	public function schedule_purge( $reason = '', $files = array() ) {
		$reason = sanitize_key( (string) $reason );
		if ( '' === $reason ) {
			$reason = 'update';
		}

		$this->queue_pending_files( $files );

		// Soft CF plugin hooks even without our token (best-effort, no spam lock).
		if ( ! $this->is_ready() ) {
			$this->try_soft_purge_hooks( $reason );
			return false;
		}

		$this->try_soft_purge_hooks( $reason );
		update_option( 'ucpf_cf_purge_pending_reason', $reason, false );

		// Drop any legacy cron jobs from older builds (broken under external cron runners).
		wp_clear_scheduled_hook( self::CRON_HOOK );

		if ( ! has_action( 'shutdown', array( $this, 'run_pending_purge_on_shutdown' ) ) ) {
			add_action( 'shutdown', array( $this, 'run_pending_purge_on_shutdown' ), 20 );
		}
		return true;
	}

	/**
	 * Shutdown / admin fallback: run a queued Cloudflare purge (no WP-Cron).
	 *
	 * @return void
	 */
	public function run_pending_purge_on_shutdown() {
		$reason = get_option( 'ucpf_cf_purge_pending_reason', '' );
		if ( '' === $reason || false === $reason ) {
			return;
		}
		delete_option( 'ucpf_cf_purge_pending_reason' );
		$this->purge_edge( sanitize_key( (string) $reason ) );
	}

	/**
	 * Merge URLs into the pending Cloudflare file-purge list (max 30).
	 *
	 * @param string[]|string $files URLs.
	 * @return void
	 */
	private function queue_pending_files( $files ) {
		if ( is_string( $files ) && '' !== $files ) {
			$files = array( $files );
		}
		if ( ! is_array( $files ) || empty( $files ) ) {
			return;
		}
		$existing = get_option( self::PENDING_FILES, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		foreach ( $files as $file ) {
			$file = esc_url_raw( (string) $file );
			if ( '' === $file ) {
				continue;
			}
			$existing[] = $file;
			// Also queue without query string — Cache Files may key either way.
			$bare = preg_replace( '/\?.*$/', '', $file );
			if ( is_string( $bare ) && $bare && $bare !== $file ) {
				$existing[] = $bare;
			}
		}
		$existing = array_values( array_unique( array_filter( $existing ) ) );
		if ( count( $existing ) > 30 ) {
			$existing = array_slice( $existing, 0, 30 );
		}
		update_option( self::PENDING_FILES, $existing, false );
	}

	/**
	 * Run purge now (cron or manual). Honors 10-minute lock.
	 *
	 * @param string $reason Reason slug.
	 * @return array{ok:bool,message:string,code:int}
	 */
	public function purge_edge( $reason = '' ) {
		$reason = sanitize_key( (string) $reason );
		if ( '' === $reason ) {
			$reason = 'update';
		}

		if ( ! $this->is_ready() ) {
			$this->try_soft_purge_hooks( $reason );
			$result = array(
				'ok'      => false,
				'message' => __( 'Cloudflare purge skipped (disabled or missing domain / API token).', 'universal-consent-privacy-framework' ),
				'code'    => 0,
			);
			$this->store_status( $result, $reason );
			return $result;
		}

		$lock_seconds = (int) apply_filters( 'ucpf_cloudflare_purge_lock_seconds', 600 );
		if ( $lock_seconds < 60 ) {
			$lock_seconds = 60;
		}

		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			// Still try file/prefix purge — Elementor CSS poison cannot wait on the 10-minute lock.
			$zone  = $this->get_zone_id( true );
			$token = $this->get_api_token();
			if ( $zone && $token ) {
				$api = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $zone ) . '/purge_cache';
				$this->purge_elementor_css_prefixes( $api, $token, $reason );
				$this->purge_pending_files( $api, $token );
			}
			$result = array(
				'ok'      => false,
				'message' => __( 'Cloudflare purge skipped (rate limit — try again later).', 'universal-consent-privacy-framework' ),
				'code'    => 429,
			);
			$this->store_status( $result, $reason );
			return $result;
		}

		// Claim lock before network call so concurrent crons do not double-fire.
		set_transient( self::LOCK_TRANSIENT, 1, $lock_seconds );

		$zone  = $this->get_zone_id( true );
		$token = $this->get_api_token();
		$url   = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $zone ) . '/purge_cache';

		// Best-effort file + prefix purge for Elementor CSS before zone-wide purge.
		$this->purge_elementor_css_prefixes( $url, $token, $reason );
		$this->purge_pending_files( $url, $token );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'purge_everything' => true ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$result = array(
				'ok'      => false,
				'message' => $response->get_error_message(),
				'code'    => 0,
			);
			$this->store_status( $result, $reason );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'UCPF Cloudflare purge failed: ' . $result['message'] );
			}
			return $result;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$ok   = ( $code >= 200 && $code < 300 && is_array( $body ) && ! empty( $body['success'] ) );

		$message = $ok
			? __( 'Cloudflare cache purged successfully.', 'universal-consent-privacy-framework' )
			: __( 'Cloudflare purge API returned an error.', 'universal-consent-privacy-framework' );

		if ( ! $ok && is_array( $body ) && ! empty( $body['errors'][0]['message'] ) ) {
			$message = sanitize_text_field( (string) $body['errors'][0]['message'] );
		}

		$result = array(
			'ok'      => $ok,
			'message' => $message,
			'code'    => $code,
		);
		$this->store_status( $result, $reason );

		if ( $ok ) {
			$this->try_soft_purge_hooks( $reason );
		} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'UCPF Cloudflare purge HTTP ' . $code . ': ' . $message );
		}

		return $result;
	}

	/**
	 * Last purge status for admin UI.
	 *
	 * @return array{time?:int,reason?:string,ok?:bool,message?:string,code?:int}
	 */
	public function get_last_status() {
		$status = get_option( self::STATUS_OPTION, array() );
		return is_array( $status ) ? $status : array();
	}

	/**
	 * Resolve Zone ID from domain + token and store it (for purge / is_ready).
	 *
	 * @param bool $force Re-query Cloudflare even if a Zone ID is cached.
	 * @return string Zone ID or empty.
	 */
	public function resolve_and_store_zone_id( $force = false ) {
		static $busy = false;
		if ( $busy ) {
			return $this->stored_zone_id();
		}
		$busy = true;
		try {
			$existing = $this->stored_zone_id();
			if ( $existing && ! $force ) {
				return $existing;
			}

			$token  = $this->get_api_token();
			$domain = $this->get_domain();
			if ( '' === $token || '' === $domain ) {
				return '';
			}

			$candidates = array( $domain );
			if ( 0 === strpos( $domain, 'www.' ) ) {
				$candidates[] = substr( $domain, 4 );
			} else {
				$candidates[] = 'www.' . $domain;
			}
			$candidates = array_values( array_unique( array_filter( $candidates ) ) );

			foreach ( $candidates as $name ) {
				$zone = $this->lookup_zone_id( $name, $token );
				if ( $zone ) {
					Settings::update( array( 'cloudflare_zone_id' => $zone ) );
					return $zone;
				}
			}

			return '';
		} finally {
			$busy = false;
		}
	}

	/**
	 * @param array  $result Result from purge_edge.
	 * @param string $reason Reason slug.
	 * @return void
	 */
	private function store_status( array $result, $reason ) {
		update_option(
			self::STATUS_OPTION,
			array(
				'time'    => time(),
				'reason'  => sanitize_key( (string) $reason ),
				'ok'      => ! empty( $result['ok'] ),
				'message' => isset( $result['message'] ) ? sanitize_text_field( (string) $result['message'] ) : '',
				'code'    => isset( $result['code'] ) ? (int) $result['code'] : 0,
			),
			false
		);
	}

	/**
	 * Best-effort hooks for the official Cloudflare WordPress plugin.
	 *
	 * @param string $reason Reason slug.
	 * @return void
	 */
	private function try_soft_purge_hooks( $reason ) {
		unset( $reason );
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- intentional third-party hooks.
		if ( has_action( 'cloudflare_purge_everything' ) ) {
			do_action( 'cloudflare_purge_everything' );
		}
		if ( class_exists( '\CF\WordPress\Hooks' ) && method_exists( '\CF\WordPress\Hooks', 'purgeCacheEverything' ) ) {
			try {
				$hooks = new \CF\WordPress\Hooks();
				$hooks->purgeCacheEverything();
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Ignore soft-integration failures.
			}
		}
		// phpcs:enable
	}

	/**
	 * Purge Elementor CSS prefixes / hosts before zone-wide purge_everything.
	 *
	 * Cloudflare prefix purge requires an eligible plan + token; failures are ignored
	 * because purge_everything still runs afterward.
	 *
	 * @param string $api_url Full purge_cache API URL.
	 * @param string $token   API token.
	 * @param string $reason  Reason slug.
	 * @return void
	 */
	private function purge_elementor_css_prefixes( $api_url, $token, $reason ) {
		unset( $reason );
		$host = $this->get_domain();
		if ( '' === $host ) {
			$host = self::default_domain();
		}
		if ( '' === $host || '' === $token || '' === $api_url ) {
			return;
		}

		$prefixes = array(
			$host . '/wp-content/uploads/elementor/css',
		);
		if ( 0 === strpos( $host, 'www.' ) ) {
			$bare       = substr( $host, 4 );
			$prefixes[] = $bare . '/wp-content/uploads/elementor/css';
		} else {
			$prefixes[] = 'www.' . $host . '/wp-content/uploads/elementor/css';
		}
		$prefixes = array_values( array_unique( array_filter( $prefixes ) ) );

		/**
		 * Filter Cloudflare prefix list for Elementor CSS purge.
		 *
		 * @param string[] $prefixes Host/path prefixes (no scheme).
		 * @param string   $host     Configured domain.
		 */
		$prefixes = apply_filters( 'ucpf_cloudflare_elementor_css_prefixes', $prefixes, $host );
		if ( empty( $prefixes ) || ! is_array( $prefixes ) ) {
			return;
		}

		wp_remote_post(
			$api_url,
			array(
				'timeout' => 12,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'prefixes' => array_values( $prefixes ) ) ),
			)
		);
	}

	/**
	 * Purge queued absolute file URLs (Elementor post-*.css etc.). Works on Free plans.
	 *
	 * @param string $api_url Full purge_cache API URL.
	 * @param string $token   API token.
	 * @return void
	 */
	private function purge_pending_files( $api_url, $token ) {
		$files = get_option( self::PENDING_FILES, array() );
		delete_option( self::PENDING_FILES );
		if ( ! is_array( $files ) || empty( $files ) || '' === $token || '' === $api_url ) {
			return;
		}
		$clean = array();
		foreach ( $files as $file ) {
			$file = esc_url_raw( (string) $file );
			if ( $file ) {
				$clean[] = $file;
			}
		}
		$clean = array_values( array_unique( $clean ) );
		if ( empty( $clean ) ) {
			return;
		}
		// Cloudflare Free allows up to 30 URLs per request.
		foreach ( array_chunk( $clean, 30 ) as $chunk ) {
			wp_remote_post(
				$api_url,
				array(
					'timeout' => 12,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode( array( 'files' => $chunk ) ),
				)
			);
		}
	}

	/**
	 * Configured hostname (falls back to site host).
	 *
	 * @return string
	 */
	private function get_domain() {
		$domain = self::sanitize_domain( (string) Settings::get( 'cloudflare_domain', '' ) );
		if ( '' === $domain ) {
			$domain = self::sanitize_domain( self::default_domain() );
		}
		return $domain;
	}

	/**
	 * @param bool $resolve_if_empty When true, look up Zone ID from domain via API.
	 * @return string
	 */
	private function get_zone_id( $resolve_if_empty = false ) {
		$zone = $this->stored_zone_id();
		if ( '' !== $zone || ! $resolve_if_empty ) {
			return $zone;
		}
		return $this->resolve_and_store_zone_id( false );
	}

	/**
	 * @return string
	 */
	private function stored_zone_id() {
		$zone = (string) Settings::get( 'cloudflare_zone_id', '' );
		$zone = preg_replace( '/[^a-zA-Z0-9]/', '', $zone );
		return is_string( $zone ) ? $zone : '';
	}

	/**
	 * @param string $name  Zone hostname.
	 * @param string $token API token.
	 * @return string
	 */
	private function lookup_zone_id( $name, $token ) {
		$name = self::sanitize_domain( $name );
		if ( '' === $name || '' === $token ) {
			return '';
		}

		$url = add_query_arg(
			array(
				'name'   => $name,
				'status' => 'active',
			),
			'https://api.cloudflare.com/client/v4/zones'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body['success'] ) ) {
			return '';
		}
		if ( empty( $body['result'][0]['id'] ) ) {
			return '';
		}
		$zone = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $body['result'][0]['id'] );
		return is_string( $zone ) ? $zone : '';
	}

	/**
	 * @return string
	 */
	private function get_api_token() {
		if ( defined( 'UCPF_CLOUDFLARE_API_TOKEN' ) && UCPF_CLOUDFLARE_API_TOKEN ) {
			return trim( (string) UCPF_CLOUDFLARE_API_TOKEN );
		}
		$token = (string) Settings::get( 'cloudflare_api_token', '' );
		return trim( $token );
	}
}
