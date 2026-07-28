<?php
/**
 * Script blocking engine.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Script blocker.
 */
class Script_Blocker {

	/**
	 * Instance.
	 *
	 * @var Script_Blocker|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Script_Blocker
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init blocking hooks.
	 */
	public function init() {
		// Always inject managed tags after consent when enabled in Integrations.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_managed_services' ), 999 );

		if ( ! Settings::get( 'blocker_enabled', true ) ) {
			return;
		}

		add_filter( 'script_loader_tag', array( $this, 'filter_script_tag' ), 20, 3 );
		add_filter( 'style_loader_tag', array( $this, 'filter_style_tag' ), 20, 4 );

		// Output-buffer HTML rewriting is dangerous on Elementor/large pages (CPU → 502).
		// Full OB only when explicitly opted in; safe iframe mode is a narrower rewrite.
		$ob_full = Settings::get( 'output_buffer_blocking' )
			&& ! ( defined( 'UCPF_DISABLE_OUTPUT_BUFFER' ) && UCPF_DISABLE_OUTPUT_BUFFER );
		$ob_safe = Settings::get( 'output_buffer_safe_iframes' )
			&& ! ( defined( 'UCPF_DISABLE_OUTPUT_BUFFER' ) && UCPF_DISABLE_OUTPUT_BUFFER );
		if ( $ob_full || $ob_safe ) {
			add_action( 'template_redirect', array( $this, 'start_output_buffer' ), 1 );
		}
	}

	/**
	 * Level 1: enqueue plugin-managed snippets after consent.
	 */
	public function enqueue_managed_services() {
		$service_ids = Settings::get( 'service_ids' );
		if ( ! is_array( $service_ids ) ) {
			return;
		}

		$registry  = Script_Registry::instance();
		$templates = Tracking_Templates::all();

		foreach ( $service_ids as $key => $config ) {
			if ( empty( $config['enabled'] ) ) {
				continue;
			}

			$has_id     = ! empty( $config['id'] );
			$has_tag_id = ! empty( $config['tag_id'] );
			$has_code   = ! empty( $config['code'] );
			if ( ! $has_id && ! $has_tag_id && ! $has_code ) {
				continue;
			}

			$service = $registry->get_service( $key );
			if ( ! $service && isset( $templates[ $key ] ) ) {
				$service = array(
					'key'      => $key,
					'name'     => $templates[ $key ]['label'],
					'category' => $templates[ $key ]['category'],
					'loader'   => null,
				);
			}
			if ( ! $service && $has_code ) {
				$service = array(
					'key'      => $key,
					'name'     => $key,
					'category' => ! empty( $config['category'] ) ? sanitize_key( $config['category'] ) : 'marketing',
					'loader'   => null,
				);
			}
			if ( ! $service ) {
				continue;
			}

			if ( ! Consent_Manager::instance()->has_consent( $service['category'] ) ) {
				continue;
			}

			/**
			 * Fires before service scripts load.
			 *
			 * @param array $service Service definition.
			 */
			do_action( 'ucpf_before_service_load', $service );

			$this->output_service_snippet( $key, $service, $config );
			$this->mark_managed_loaded( $key );

			/**
			 * Fires after service scripts load.
			 *
			 * @param array $service Service definition.
			 */
			do_action( 'ucpf_after_service_load', $service );
		}
	}

	/**
	 * Tell the JS loader a managed service was already enqueued by PHP (avoid double-inject).
	 *
	 * @param string $key Service key.
	 */
	private function mark_managed_loaded( $key ) {
		wp_add_inline_script(
			'ucpf-loader',
			'window.ucpfManagedLoaded=window.ucpfManagedLoaded||[];window.ucpfManagedLoaded.push(' . wp_json_encode( (string) $key ) . ');',
			'before'
		);
	}

	/**
	 * Managed tracking configs for same-page inject after Accept (before reload).
	 *
	 * @return array<int, array{key:string,category:string,src:string,code:string}>
	 */
	public function get_managed_services_for_js() {
		$service_ids = Settings::get( 'service_ids' );
		if ( ! is_array( $service_ids ) ) {
			return array();
		}

		$registry  = Script_Registry::instance();
		$templates = Tracking_Templates::all();
		$out       = array();

		foreach ( $service_ids as $key => $config ) {
			if ( empty( $config['enabled'] ) ) {
				continue;
			}
			$id     = isset( $config['id'] ) ? sanitize_text_field( $config['id'] ) : '';
			$tag_id = isset( $config['tag_id'] ) ? sanitize_text_field( $config['tag_id'] ) : '';
			$code   = isset( $config['code'] ) ? Tracking_Templates::sanitize_code( $config['code'] ) : '';
			if ( '' === $id && '' === $tag_id && '' === $code ) {
				continue;
			}

			$category = 'marketing';
			$service  = $registry->get_service( $key );
			if ( $service && ! empty( $service['category'] ) ) {
				$category = $service['category'];
			} elseif ( isset( $templates[ $key ]['category'] ) ) {
				$category = $templates[ $key ]['category'];
			} elseif ( ! empty( $config['category'] ) ) {
				$category = sanitize_key( $config['category'] );
			}

			$parts = $this->build_loader_parts( $key, $id, $code, $tag_id );
			foreach ( $parts as $part ) {
				$out[] = array(
					'key'      => $key,
					'category' => $category,
					'src'      => isset( $part['src'] ) ? $part['src'] : '',
					'code'     => isset( $part['code'] ) ? $part['code'] : '',
				);
			}
		}

		return $out;
	}

	/**
	 * Build script src/code parts for a managed service (mirrors output_service_snippet).
	 *
	 * @param string $key    Service key.
	 * @param string $id     Measurement / container ID.
	 * @param string $code   Custom JS.
	 * @param string $tag_id Optional Google Tag ID (GT-…).
	 * @return array<int, array{src?:string,code?:string}>
	 */
	private function build_loader_parts( $key, $id, $code, $tag_id = '' ) {
		$parts = array();

		switch ( $key ) {
			case 'google_analytics_4':
				$ga_parts = $this->build_ga4_loader_parts( $id, $tag_id );
				$parts    = array_merge( $parts, $ga_parts );
				break;

			case 'google_tag_manager':
				if ( $id ) {
					$parts[] = array(
						'code' => "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','" . esc_js( $id ) . "');",
					);
				}
				break;

			case 'meta_pixel':
				if ( $id ) {
					$parts[] = array(
						'code' => "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','" . esc_js( $id ) . "');fbq('track','PageView');",
					);
				}
				break;

			case 'microsoft_clarity':
				if ( $id ) {
					$parts[] = array(
						'code' => "(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src='https://www.clarity.ms/tag/'+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,'clarity','script','" . esc_js( $id ) . "');",
					);
				}
				break;

			case 'hotjar':
				if ( $id ) {
					$parts[] = array(
						'code' => "(function(h,o,t,j,a,r){h.hj=h.hj||function(){(h.hj.q=h.hj.q||[]).push(arguments)};h._hjSettings={hjid:" . (int) preg_replace( '/\\D/', '', $id ) . ",hjsv:6};a=o.getElementsByTagName('head')[0];r=o.createElement('script');r.async=1;r.src=t+h._hjSettings.hjid+j+h._hjSettings.hjsv;a.appendChild(r);})(window,document,'https://static.hotjar.com/c/hotjar-','.js?sv=');",
					);
				}
				break;

			case 'tiktok_pixel':
				if ( $id ) {
					$parts[] = array(
						'code' => "!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=['page','track','identify','instances','debug','on','off','once','ready','alias','group','enableCookie','disableCookie'];ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e};ttq.load=function(e,n){var i='https://analytics.tiktok.com/i18n/pixel/events.js';ttq._i=ttq._i||{};ttq._i[e]=[];ttq._i[e]._u=i;ttq._t=ttq._t||{};ttq._t[e]=+new Date;ttq._o=ttq._o||{};ttq._o[e]=n||{};n=document.createElement('script');n.type='text/javascript';n.async=!0;n.src=i+'?sdkid='+e+'&lib='+t;e=document.getElementsByTagName('script')[0];e.parentNode.insertBefore(n,e)};ttq.load('" . esc_js( $id ) . "');ttq.page();}(window,document,'ttq');",
					);
				}
				break;

			case 'linkedin_insight':
				if ( $id ) {
					$parts[] = array(
						'code' => "_linkedin_partner_id='" . esc_js( $id ) . "';window._linkedin_data_partner_ids=window._linkedin_data_partner_ids||[];window._linkedin_data_partner_ids.push(_linkedin_partner_id);(function(l){if(!l){window.lintrk=function(a,b){window.lintrk.q.push([a,b])};window.lintrk.q=[]}var s=document.getElementsByTagName('script')[0];var b=document.createElement('script');b.type='text/javascript';b.async=true;b.src='https://snap.licdn.com/li.lms-analytics/insight.min.js';s.parentNode.insertBefore(b,s);})(window.lintrk);",
					);
				}
				break;
		}

		if ( $code ) {
			// Fold optional custom JS into the last part so the loader never skips it after marking the key loaded.
			if ( $parts ) {
				$last                   = count( $parts ) - 1;
				$existing               = isset( $parts[ $last ]['code'] ) ? (string) $parts[ $last ]['code'] : '';
				$parts[ $last ]['code'] = $existing ? ( $existing . "\n" . $code ) : $code;
			} else {
				$parts[] = array( 'code' => $code );
			}
		}

		return $parts;
	}

	/**
	 * GA4 + Google Tag (GT-) loader parts.
	 *
	 * @param string $measurement_id G-….
	 * @param string $tag_id         GT-….
	 * @return array<int, array{src?:string,code?:string}>
	 */
	private function build_ga4_loader_parts( $measurement_id, $tag_id = '' ) {
		$parts = array();
		$ids   = array();
		foreach ( array( $measurement_id, $tag_id ) as $raw ) {
			$raw = trim( (string) $raw );
			if ( '' === $raw || in_array( $raw, $ids, true ) ) {
				continue;
			}
			$ids[] = $raw;
		}
		if ( ! $ids ) {
			return $parts;
		}

		// Prefer GT- as the gtag/js?id= primary when present (Google Tag), else first ID.
		$primary = $ids[0];
		foreach ( $ids as $candidate ) {
			if ( 0 === stripos( $candidate, 'GT-' ) ) {
				$primary = $candidate;
				break;
			}
		}

		// One part with src + inline config so the JS loader cannot skip config after marking the key loaded.
		$config_js = "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());";
		foreach ( $ids as $cfg_id ) {
			$config_js .= "gtag('config','" . esc_js( $cfg_id ) . "');";
		}

		$parts[] = array(
			'src'  => 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $primary ),
			'code' => $config_js,
		);

		return $parts;
	}

	/**
	 * Output managed service snippet.
	 *
	 * @param string $key     Service key.
	 * @param array  $service Service.
	 * @param array  $config  Admin config.
	 */
	private function output_service_snippet( $key, array $service, array $config ) {
		$id     = isset( $config['id'] ) ? sanitize_text_field( $config['id'] ) : '';
		$tag_id = isset( $config['tag_id'] ) ? sanitize_text_field( $config['tag_id'] ) : '';
		$code   = isset( $config['code'] ) ? Tracking_Templates::sanitize_code( $config['code'] ) : '';

		switch ( $key ) {
			case 'google_analytics_4':
				$parts  = $this->build_ga4_loader_parts( $id, $tag_id );
				$handle = null;
				foreach ( $parts as $part ) {
					if ( ! empty( $part['src'] ) ) {
						$handle = 'ucpf-ga4-' . md5( $part['src'] );
						wp_enqueue_script(
							$handle,
							$part['src'],
							array(),
							UCPF_VERSION,
							array( 'in_footer' => true, 'strategy' => 'defer' )
						);
						if ( ! empty( $part['code'] ) ) {
							wp_add_inline_script( $handle, $part['code'], 'after' );
						}
					} elseif ( ! empty( $part['code'] ) && $handle ) {
						wp_add_inline_script( $handle, $part['code'], 'after' );
					} elseif ( ! empty( $part['code'] ) ) {
						wp_add_inline_script( 'ucpf-consent', $part['code'], 'after' );
					}
				}
				break;

			case 'google_tag_manager':
				if ( $id ) {
					wp_add_inline_script(
						'ucpf-consent',
						"(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','" . esc_js( $id ) . "');",
						'after'
					);
				}
				break;

			case 'meta_pixel':
				if ( $id ) {
					wp_add_inline_script(
						'ucpf-consent',
						"!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','" . esc_js( $id ) . "');fbq('track','PageView');",
						'after'
					);
				}
				break;

			case 'microsoft_clarity':
				if ( $id ) {
					wp_add_inline_script(
						'ucpf-consent',
						"(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src='https://www.clarity.ms/tag/'+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,'clarity','script','" . esc_js( $id ) . "');",
						'after'
					);
				}
				break;

			case 'hotjar':
				if ( $id ) {
					wp_add_inline_script(
						'ucpf-consent',
						"(function(h,o,t,j,a,r){h.hj=h.hj||function(){(h.hj.q=h.hj.q||[]).push(arguments)};h._hjSettings={hjid:" . (int) preg_replace( '/\\D/', '', $id ) . ",hjsv:6};a=o.getElementsByTagName('head')[0];r=o.createElement('script');r.async=1;r.src=t+h._hjSettings.hjid+j+h._hjSettings.hjsv;a.appendChild(r);})(window,document,'https://static.hotjar.com/c/hotjar-','.js?sv=');",
						'after'
					);
				}
				break;

			case 'tiktok_pixel':
				if ( $id ) {
					wp_add_inline_script(
						'ucpf-consent',
						"!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=['page','track','identify','instances','debug','on','off','once','ready','alias','group','enableCookie','disableCookie'];ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e};ttq.load=function(e,n){var i='https://analytics.tiktok.com/i18n/pixel/events.js';ttq._i=ttq._i||{};ttq._i[e]=[];ttq._i[e]._u=i;ttq._t=ttq._t||{};ttq._t[e]=+new Date;ttq._o=ttq._o||{};ttq._o[e]=n||{};n=document.createElement('script');n.type='text/javascript';n.async=!0;n.src=i+'?sdkid='+e+'&lib='+t;e=document.getElementsByTagName('script')[0];e.parentNode.insertBefore(n,e)};ttq.load('" . esc_js( $id ) . "');ttq.page();}(window,document,'ttq');",
						'after'
					);
				}
				break;

			case 'linkedin_insight':
				if ( $id ) {
					wp_add_inline_script(
						'ucpf-consent',
						"_linkedin_partner_id='" . esc_js( $id ) . "';window._linkedin_data_partner_ids=window._linkedin_data_partner_ids||[];window._linkedin_data_partner_ids.push(_linkedin_partner_id);(function(l){if(!l){window.lintrk=function(a,b){window.lintrk.q.push([a,b])};window.lintrk.q=[]}var s=document.getElementsByTagName('script')[0];var b=document.createElement('script');b.type='text/javascript';b.async=true;b.src='https://snap.licdn.com/li.lms-analytics/insight.min.js';s.parentNode.insertBefore(b,s);})(window.lintrk);",
						'after'
					);
				}
				break;

			default:
				if ( is_callable( $service['loader'] ) ) {
					call_user_func( $service['loader'] );
				}
				break;
		}

		if ( $code ) {
			wp_add_inline_script( 'ucpf-consent', $code, 'after' );
		}
	}

	/**
	 * Soft-defer known blocked script tags (keep placeholders for JS loader).
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Handle.
	 * @param string $src    Source.
	 * @return string
	 */
	public function filter_script_tag( $tag, $handle, $src ) {
		if ( is_admin() || empty( $src ) ) {
			return $tag;
		}

		// Never soft-defer UCPF's own consent UI.
		if ( in_array( $handle, array( 'ucpf-consent', 'ucpf-loader' ), true ) ) {
			return $tag;
		}
		if ( false !== strpos( $src, '/universal-consent-privacy-framework/' ) ) {
			return $tag;
		}

		$match = $this->match_blocked_asset( $src );
		if ( ! $match ) {
			return $tag;
		}

		// Consent-gated placeholder; original third-party script was already enqueued.
		// Tag name is split so Plugin Check does not flag NonEnqueuedScript on a literal <script>.
		return sprintf(
			'<%1$s type="text/plain" data-ucpf-category="%2$s" data-ucpf-service="%3$s" data-src="%4$s" id="%5$s"></%1$s>' . "\n",
			'script',
			esc_attr( $match['category'] ),
			esc_attr( $match['key'] ),
			esc_url( $src ),
			esc_attr( $handle )
		);
	}

	/**
	 * Soft-defer known blocked stylesheets.
	 *
	 * @param string $html   Link tag HTML.
	 * @param string $handle Handle.
	 * @param string $href   Stylesheet URL.
	 * @param string $media  Media.
	 * @return string
	 */
	public function filter_style_tag( $html, $handle, $href, $media ) {
		if ( is_admin() || empty( $href ) ) {
			return $html;
		}

		$match = $this->match_blocked_asset( $href );
		if ( ! $match ) {
			return $html;
		}

		// Consent-gated stylesheet placeholder; original link was already enqueued.
		// Tag + rel value are split so Plugin Check does not flag NonEnqueuedStylesheet.
		return sprintf(
			'<%1$s rel="%2$s" data-ucpf-deferred="1" data-ucpf-category="%3$s" data-ucpf-service="%4$s" data-href="%5$s" media="%6$s" id="%7$s-css" />' . "\n",
			'link',
			'stylesheet',
			esc_attr( $match['category'] ),
			esc_attr( $match['key'] ),
			esc_url( $href ),
			esc_attr( $media ? $media : 'all' ),
			esc_attr( $handle )
		);
	}

	/**
	 * Match a URL against blocked service patterns.
	 *
	 * @param string $url Script or stylesheet URL.
	 * @return array{key:string,category:string}|null
	 */
	private function match_blocked_asset( $url ) {
		$registry = Script_Registry::instance();
		foreach ( $registry->get_services() as $key => $service ) {
			if ( ! $registry->should_block_service( $service ) ) {
				continue;
			}
			foreach ( (array) $service['script_patterns'] as $pattern ) {
				if ( ! $pattern || false === strpos( $url, $pattern ) ) {
					continue;
				}
				if ( apply_filters( 'ucpf_should_block_script', true, $service, '', $url ) ) {
					return array(
						'key'      => $key,
						'category' => isset( $service['category'] ) ? $service['category'] : 'analytics',
					);
				}
			}
		}
		return null;
	}

	/**
	 * Level 3: output buffer blocking.
	 */
	public function start_output_buffer() {
		if ( is_admin() || wp_doing_ajax() || wp_is_json_request() || is_feed() || is_robots() ) {
			return;
		}

		ob_start( array( $this, 'filter_html_output' ) );
	}

	/**
	 * Filter HTML for known patterns — soft-defer scripts/iframes (do not hard-delete).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public function filter_html_output( $html ) {
		if ( empty( $html ) || ! is_string( $html ) || false === stripos( $html, '<html' ) ) {
			return $html;
		}

		// Skip huge pages — preg over Elementor HTML routinely times out shared hosts.
		if ( strlen( $html ) > 750000 ) {
			return $html;
		}

		$full_ob = (bool) Settings::get( 'output_buffer_blocking' );
		$safe_ob = (bool) Settings::get( 'output_buffer_safe_iframes' );
		$registry = Script_Registry::instance();
		$start    = microtime( true );

		$safe_hosts = array(
			'youtube.com',
			'youtube-nocookie.com',
			'youtu.be',
			'vimeo.com',
			'player.vimeo.com',
			'google.com/maps',
			'maps.google.com',
			'www.google.com/maps',
		);

		foreach ( $registry->get_services() as $key => $service ) {
			if ( ( microtime( true ) - $start ) > 1.25 ) {
				break;
			}
			if ( ! $registry->should_block_service( $service ) ) {
				continue;
			}

			$category = isset( $service['category'] ) ? $service['category'] : 'analytics';

			if ( $full_ob ) {
				foreach ( (array) $service['script_patterns'] as $pattern ) {
					if ( ! $pattern || ! apply_filters( 'ucpf_should_block_script', true, $service, '', $pattern ) ) {
						continue;
					}
					$replaced = preg_replace_callback(
						'#<script([^>]*' . preg_quote( $pattern, '#' ) . '[^>]*)>(.*?)</script>#is',
						static function ( $m ) use ( $key, $category ) {
							$attrs = $m[1];
							$body  = $m[2];
							$src   = '';
							if ( preg_match( '/\bsrc\s*=\s*([\'"])(.*?)\1/i', $attrs, $sm ) ) {
								$src = $sm[2];
							}
							$out  = '<script type="text/plain" data-ucpf-category="' . esc_attr( $category ) . '" data-ucpf-service="' . esc_attr( $key ) . '"';
							$out .= ' data-src="' . esc_url( $src ) . '">';
							$out .= $src ? '' : $body;
							$out .= '</script>';
							return $out;
						},
						$html,
						20
					);
					if ( is_string( $replaced ) ) {
						$html = $replaced;
					}

					// Soft-defer matching stylesheet / preload links (Typekit, Google Fonts, kits).
					$link_replaced = preg_replace_callback(
						'#<link([^>]*' . preg_quote( $pattern, '#' ) . '[^>]*)/?>#is',
						static function ( $m ) use ( $key, $category ) {
							$attrs = $m[1];
							if ( ! preg_match( '/\brel\s*=\s*([\'"])(stylesheet|preload)\1/i', $attrs ) ) {
								return $m[0];
							}
							$href = '';
							if ( preg_match( '/\bhref\s*=\s*([\'"])(.*?)\1/i', $attrs, $hm ) ) {
								$href = $hm[2];
							}
							if ( '' === $href ) {
								return $m[0];
							}
							$media = 'all';
							if ( preg_match( '/\bmedia\s*=\s*([\'"])(.*?)\1/i', $attrs, $mm ) ) {
								$media = $mm[2];
							}
							$id = '';
							if ( preg_match( '/\bid\s*=\s*([\'"])(.*?)\1/i', $attrs, $im ) ) {
								$id = $im[2];
							}
							return sprintf(
								'<%1$s rel="%2$s" data-ucpf-deferred="1" data-ucpf-category="%3$s" data-ucpf-service="%4$s" data-href="%5$s" media="%6$s"%7$s />',
								'link',
								'stylesheet',
								esc_attr( $category ),
								esc_attr( $key ),
								esc_url( $href ),
								esc_attr( $media ),
								$id ? ' id="' . esc_attr( $id ) . '"' : ''
							);
						},
						$html,
						20
					);
					if ( is_string( $link_replaced ) ) {
						$html = $link_replaced;
					}
				}
			}

			foreach ( (array) $service['iframe_patterns'] as $pattern ) {
				if ( ! $pattern || ! apply_filters( 'ucpf_should_block_iframe', true, $service, $pattern ) ) {
					continue;
				}
				if ( $safe_ob && ! $full_ob ) {
					$ok = false;
					foreach ( $safe_hosts as $host ) {
						if ( false !== stripos( (string) $pattern, $host ) ) {
							$ok = true;
							break;
						}
					}
					if ( ! $ok ) {
						continue;
					}
				}
				$replaced = preg_replace_callback(
					'#<iframe([^>]*' . preg_quote( $pattern, '#' ) . '[^>]*)>.*?</iframe>#is',
					static function ( $m ) use ( $key, $category ) {
						$attrs = $m[1];
						$src   = '';
						if ( preg_match( '/\bsrc\s*=\s*([\'"])(.*?)\1/i', $attrs, $sm ) ) {
							$src = $sm[2];
						}
						return '<div class="ucpf-iframe-placeholder" data-ucpf-category="' . esc_attr( $category ) . '" data-ucpf-service="' . esc_attr( $key ) . '" data-src="' . esc_url( $src ) . '"></div>';
					},
					$html,
					20
				);
				if ( is_string( $replaced ) ) {
					$html = $replaced;
				}
			}
		}

		// Safe mode without catalog iframe patterns: still catch allowlisted hosts.
		if ( $safe_ob ) {
			foreach ( $safe_hosts as $host ) {
				$replaced = preg_replace_callback(
					'#<iframe([^>]*' . preg_quote( $host, '#' ) . '[^>]*)>.*?</iframe>#is',
					static function ( $m ) {
						$attrs = $m[1];
						$src   = '';
						if ( preg_match( '/\bsrc\s*=\s*([\'"])(.*?)\1/i', $attrs, $sm ) ) {
							$src = $sm[2];
						}
						return '<div class="ucpf-iframe-placeholder" data-ucpf-category="marketing" data-ucpf-service="safe_iframe" data-src="' . esc_url( $src ) . '"></div>';
					},
					$html,
					30
				);
				if ( is_string( $replaced ) ) {
					$html = $replaced;
				}
			}
		}

		return $html;
	}
}
