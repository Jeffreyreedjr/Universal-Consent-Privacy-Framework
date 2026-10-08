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

		// Always-on cheap HTML park (Site Kit gtag, CF beacon, YT/Vimeo) — must NOT depend on
		// blocker_enabled. One fleet site had blocker off and Site Kit kept firing gtag live.
		if ( ! ( defined( 'UCPF_DISABLE_OUTPUT_BUFFER' ) && UCPF_DISABLE_OUTPUT_BUFFER ) ) {
			add_action( 'template_redirect', array( $this, 'start_output_buffer' ), 1 );
		}
		// Always park Google/Site Kit handles even when full catalog blocker is off.
		add_filter( 'script_loader_tag', array( $this, 'filter_google_script_tag' ), 99998, 3 );

		if ( ! Settings::get( 'blocker_enabled', true ) ) {
			return;
		}

		// Run late so Hummingbird / Autoptimize src rewrites still leave the WP handle
		// for handle-based park (Mailchimp pixel → /hummingbird-assets/{hash}.js).
		add_filter( 'script_loader_tag', array( $this, 'filter_script_tag' ), 99999, 3 );
		add_filter( 'style_loader_tag', array( $this, 'filter_style_tag' ), 20, 4 );
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

			if ( ! Tracking_Templates::row_has_ids( $key, $config ) ) {
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
			$has_code = ! empty( $config['code'] );
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
			if ( ! Tracking_Templates::row_has_ids( $key, $config ) ) {
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

			$parts = $this->build_loader_parts( $key, $config );
			foreach ( $parts as $part ) {
				$out[] = array(
					'key'      => $key,
					'part_id'  => isset( $part['part_id'] ) ? (string) $part['part_id'] : $key,
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
	 * @param array  $config Service config row.
	 * @return array<int, array{src?:string,code?:string}>
	 */
	private function build_loader_parts( $key, array $config ) {
		$id     = isset( $config['id'] ) ? sanitize_text_field( $config['id'] ) : '';
		$tag_id = isset( $config['tag_id'] ) ? sanitize_text_field( $config['tag_id'] ) : '';
		$code   = isset( $config['code'] ) ? Tracking_Templates::sanitize_code( $config['code'] ) : '';
		$parts  = array();

		switch ( $key ) {
			case 'google_analytics_4':
				$ga_parts = $this->build_ga4_loader_parts( $id, $tag_id );
				$parts    = array_merge( $parts, $ga_parts );
				break;

			case 'google_tag_manager':
				$parts = $this->build_gtm_loader_parts( Tracking_Templates::gtm_containers_from_row( $config ) );
				break;

			case 'google_ads':
				$parts = $this->build_google_ads_loader_parts( $id );
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
	 * Build GTM bootstrap snippet for one Tag Manager container (GTM- only).
	 *
	 * @param string $container_id GTM-….
	 * @param string $data_layer   dataLayer variable name.
	 * @return string
	 */
	private function build_gtm_snippet_code( $container_id, $data_layer = 'dataLayer' ) {
		$container_id = Tracking_Templates::normalize_gtm_container_id( $container_id );
		if ( '' === $container_id || ! Tracking_Templates::is_gtm_container_id( $container_id ) ) {
			return '';
		}
		$data_layer = Tracking_Templates::sanitize_gtm_data_layer( $data_layer );
		return "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','" . esc_js( $data_layer ) . "','" . esc_js( $container_id ) . "');";
	}

	/**
	 * Loader parts for GTM multi-ID list — GTM- via gtm.js, GT-/G- via gtag.js.
	 *
	 * @param array $containers Normalized container rows.
	 * @return array<int, array{code?:string,src?:string,part_id?:string}>
	 */
	private function build_gtm_loader_parts( array $containers ) {
		$parts    = array();
		$gtag_ids = array();

		foreach ( $containers as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			$cid = Tracking_Templates::normalize_gtm_container_id( $row['id'] );
			if ( '' === $cid ) {
				continue;
			}
			if ( Tracking_Templates::is_gtm_container_id( $cid ) ) {
				$snippet = $this->build_gtm_snippet_code(
					$cid,
					isset( $row['data_layer'] ) ? $row['data_layer'] : 'dataLayer'
				);
				if ( $snippet ) {
					$parts[] = array(
						'code'    => $snippet,
						'part_id' => 'google_tag_manager:' . $cid,
					);
				}
				continue;
			}
			// AW- Ads IDs are Marketing — never load via Analytics GTM/gtag path.
			if ( Tracking_Templates::is_google_ads_id( $cid ) ) {
				continue;
			}
			if ( Tracking_Templates::is_gtag_id( $cid ) && ! in_array( $cid, $gtag_ids, true ) ) {
				$gtag_ids[] = $cid;
			}
		}

		if ( $gtag_ids ) {
			$primary   = $gtag_ids[0];
			$config_js = "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());";
			foreach ( $gtag_ids as $cfg_id ) {
				$config_js .= "gtag('config','" . esc_js( $cfg_id ) . "');";
			}
			$parts[] = array(
				'src'     => 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $primary ),
				'code'    => $config_js,
				'part_id' => 'google_tag_manager:gtag:' . implode( ',', $gtag_ids ),
			);
		}

		return $parts;
	}

	/**
	 * Google Ads (AW-) loader parts — Marketing category only.
	 *
	 * @param string $conversion_id AW-….
	 * @return array<int, array{src?:string,code?:string,part_id?:string}>
	 */
	private function build_google_ads_loader_parts( $conversion_id ) {
		$id = strtoupper( trim( (string) $conversion_id ) );
		if ( ! Tracking_Templates::is_google_ads_id( $id ) ) {
			// Allow pasted URL / loose value containing AW-.
			if ( preg_match( '/AW-[A-Z0-9]+/i', (string) $conversion_id, $m ) ) {
				$id = strtoupper( $m[0] );
			}
		}
		if ( ! Tracking_Templates::is_google_ads_id( $id ) ) {
			return array();
		}
		$config_js = "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" . esc_js( $id ) . "');";
		return array(
			array(
				'src'     => 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $id ),
				'code'    => $config_js,
				'part_id' => 'google_ads:' . $id,
			),
		);
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
			// AW- belongs under Google Ads (Marketing), not GA4 Analytics.
			if ( Tracking_Templates::is_google_ads_id( $raw ) ) {
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
				foreach ( $this->build_gtm_loader_parts( Tracking_Templates::gtm_containers_from_row( $config ) ) as $part ) {
					if ( ! empty( $part['code'] ) ) {
						wp_add_inline_script( 'ucpf-consent', $part['code'], 'after' );
					}
				}
				if ( $code ) {
					wp_add_inline_script( 'ucpf-consent', $code, 'after' );
				}
				break;

			case 'google_ads':
				$parts  = $this->build_google_ads_loader_parts( $id );
				$handle = null;
				foreach ( $parts as $part ) {
					if ( ! empty( $part['src'] ) ) {
						$handle = 'ucpf-google-ads-' . md5( $part['src'] );
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
				// Only allow Closure loaders registered in PHP — never string callables from import/JSON.
				if ( isset( $service['loader'] ) && $service['loader'] instanceof \Closure ) {
					call_user_func( $service['loader'] );
				}
				break;
		}

		if ( $code ) {
			wp_add_inline_script( 'ucpf-consent', $code, 'after' );
		}
	}

	/**
	 * Always park Google / Site Kit gtag handles (runs even when catalog blocker is off).
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Handle.
	 * @param string $src    Source.
	 * @return string
	 */
	public function filter_google_script_tag( $tag, $handle, $src ) {
		if ( is_admin() || empty( $src ) ) {
			return $tag;
		}
		$parked = $this->maybe_park_google_tag( $tag, $handle, $src );
		return $parked ? $parked : $tag;
	}

	/**
	 * Build a parked script tag for Google analytics/gtag/gtm when Analytics is not allowed.
	 *
	 * @param string $tag    Original tag.
	 * @param string $handle Handle.
	 * @param string $src    Source URL.
	 * @return string|null Parked tag or null to leave unchanged.
	 */
	private function maybe_park_google_tag( $tag, $handle, $src ) {
		$src_l    = strtolower( (string) $src );
		$handle_l = strtolower( (string) $handle );
		if (
			false !== strpos( $src_l, 'google-site-kit' ) &&
			false !== strpos( $src_l, 'consent-mode' )
		) {
			return null;
		}
		if (
			0 !== strpos( $handle_l, 'google_gtagjs' ) &&
			false === strpos( $src_l, 'googletagmanager.com/gtag' ) &&
			false === strpos( $src_l, 'googletagmanager.com/gtm.js' ) &&
			false === strpos( $src_l, 'google-analytics.com/analytics.js' ) &&
			false === strpos( $src_l, 'google-analytics.com/ga.js' )
		) {
			return null;
		}
		// CDN-safe: always park in HTML; JS loader restores when the category is allowed.
		// Skipping park when PHP sees a consent cookie poisons shared CF HTML caches.
		// AW- / Google Ads hosts → Marketing; GTM container → Analytics; else GA4 Analytics.
		if ( Tracking_Templates::is_google_ads_src( $src ) ) {
			$category = 'marketing';
			$svc_key  = 'google_ads';
		} elseif ( false !== strpos( $src_l, '/gtm.js' ) || false !== strpos( $src_l, 'googletagmanager.com/gtm' ) ) {
			$category = 'analytics';
			$svc_key  = 'google_tag_manager';
		} else {
			$category = 'analytics';
			$svc_key  = 'google_analytics_4';
		}
		$orig_type = '';
		if ( preg_match( '/\btype\s*=\s*([\'"])(.*?)\1/i', (string) $tag, $tm ) ) {
			$ot = strtolower( trim( (string) $tm[2] ) );
			if ( 'module' === $ot || 'importmap' === $ot ) {
				$orig_type = $ot;
			}
		}
		$out = sprintf(
			'<%1$s type="text/plain" data-ucpf-category="%2$s" data-ucpf-service="%3$s" data-src="%4$s" id="%5$s" data-ucpf-gated="1"',
			'script',
			esc_attr( $category ),
			esc_attr( $svc_key ),
			esc_url( $src ),
			esc_attr( $handle )
		);
		if ( '' !== $orig_type ) {
			$out .= ' data-ucpf-original-type="' . esc_attr( $orig_type ) . '"';
		}
		$out .= '></' . 'script>' . "\n";
		return $out;
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
		// Site Kit consent-mode bridge must stay live so CMP defaults apply.
		$src_l = strtolower( (string) $src );
		if (
			false !== strpos( $src_l, 'google-site-kit' ) &&
			false !== strpos( $src_l, 'consent-mode' )
		) {
			return $tag;
		}

		$parked = $this->maybe_park_google_tag( $tag, $handle, $src );
		if ( $parked ) {
			return $parked;
		}

		$match = $this->match_blocked_asset( $src );
		// Gravity Forms / PayPal Checkout SDK — guarantee park even if catalog match races.
		if ( ! $match && $this->is_paypal_sdk_handle_or_src( $handle, $src ) ) {
			$registry = Script_Registry::instance();
			$service  = $registry->get_service( 'paypal' );
			if ( $service && $registry->should_block_service( $service ) ) {
				$match = array(
					'key'      => 'paypal',
					'category' => ! empty( $service['category'] ) ? (string) $service['category'] : 'functional',
				);
			}
		}
		// reCAPTCHA Woo (invisible) — first-party path often misses catalog race.
		if ( ! $match && $this->is_recaptcha_woo_handle_or_src( $handle, $src ) ) {
			$registry = Script_Registry::instance();
			$service  = $registry->get_service( 'recaptcha' );
			if ( $service && $registry->should_block_service( $service ) ) {
				$match = array(
					'key'      => 'recaptcha',
					'category' => 'security',
				);
			}
		}
		// Mailchimp Woo pixel/SMS — Hummingbird rewrites src to /hummingbird-assets/{hash}.js
		// so URL catalog patterns miss; park by WP handle (still mailchimp-woocommerce-*).
		if ( ! $match && $this->is_mailchimp_tracker_handle_or_src( $handle, $src ) ) {
			$registry = Script_Registry::instance();
			$service  = $registry->get_service( 'mailchimp' );
			if ( $service && $registry->should_block_service( $service ) ) {
				$match = array(
					'key'      => 'mailchimp',
					'category' => ! empty( $service['category'] ) ? (string) $service['category'] : 'marketing',
				);
			} elseif ( ! Consent_Manager::instance()->has_consent( 'marketing' ) ) {
				$match = array(
					'key'      => 'mailchimp',
					'category' => 'marketing',
				);
			}
		}
		// Do NOT park gforms_ppcp_frontend — it defines window.GFPPCP; inline GF init
		// calls `new GFPPCP()` on gform_post_render. Hold that event in JS until paypal exists.
		if ( ! $match ) {
			return $tag;
		}

		$orig_type = '';
		if ( preg_match( '/\btype\s*=\s*([\'"])(.*?)\1/i', (string) $tag, $tm ) ) {
			$ot = strtolower( trim( (string) $tm[2] ) );
			if ( 'module' === $ot || 'importmap' === $ot ) {
				$orig_type = $ot;
			}
		}

		// Consent-gated placeholder; original third-party script was already enqueued.
		// Tag name is split so Plugin Check does not flag NonEnqueuedScript on a literal <script>.
		$out = sprintf(
			'<%1$s type="text/plain" data-ucpf-category="%2$s" data-ucpf-service="%3$s" data-src="%4$s" id="%5$s"',
			'script',
			esc_attr( $match['category'] ),
			esc_attr( $match['key'] ),
			esc_url( $src ),
			esc_attr( $handle )
		);
		if ( '' !== $orig_type ) {
			$out .= ' data-ucpf-original-type="' . esc_attr( $orig_type ) . '"';
		}
		$out .= '></' . 'script>' . "\n";
		return $out;
	}

	/**
	 * Whether a script handle or src is the PayPal JS SDK (GF PPCP / PayPal Checkout).
	 *
	 * @param string $handle Script handle.
	 * @param string $src    Script URL.
	 * @return bool
	 */
	private function is_paypal_sdk_handle_or_src( $handle, $src ) {
		$handle = strtolower( (string) $handle );
		$src    = strtolower( (string) $src );
		if (
			'gform_paypal_sdk' === $handle ||
			0 === strpos( $handle, 'gform_paypal_sdk' ) ||
			false !== strpos( $handle, 'paypal_sdk' )
		) {
			return true;
		}
		return ( false !== strpos( $src, 'paypal.com/sdk' ) || false !== strpos( $src, 'paypalobjects.com' ) );
	}

	/**
	 * Mailchimp for WooCommerce tracker handles (pixel / SMS / public), including
	 * when Asset Optimization rewrites src to a hashed hummingbird-assets URL.
	 *
	 * @param string $handle Script handle.
	 * @param string $src    Script URL.
	 * @return bool
	 */
	private function is_mailchimp_tracker_handle_or_src( $handle, $src ) {
		$handle = strtolower( (string) $handle );
		$src    = strtolower( (string) $src );
		if (
			false !== strpos( $handle, 'mailchimp-woocommerce' ) ||
			false !== strpos( $handle, 'mailchimp_woocommerce' )
		) {
			return true;
		}
		return (
			false !== strpos( $src, 'mailchimp-for-woocommerce' ) ||
			false !== strpos( $src, 'mailchimp-woocommerce' ) ||
			false !== strpos( $src, 'chimpstatic.com' )
		);
	}

	/**
	 * reCAPTCHA for WooCommerce / recaptcha-woo first-party script.
	 *
	 * @param string $handle Script handle.
	 * @param string $src    Script URL.
	 * @return bool
	 */
	private function is_recaptcha_woo_handle_or_src( $handle, $src ) {
		$handle = strtolower( (string) $handle );
		$src    = strtolower( (string) $src );
		if (
			'rcfwc-js' === $handle ||
			0 === strpos( $handle, 'rcfwc' ) ||
			false !== strpos( $handle, 'recaptcha-woo' ) ||
			false !== strpos( $handle, 'recaptcha_woo' )
		) {
			return true;
		}
		return (
			false !== strpos( $src, 'recaptcha-woo' ) ||
			false !== strpos( $src, '/rcfwc.js' ) ||
			false !== strpos( $src, 'recaptcha-for-woocommerce' )
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
		unset( $handle, $href, $media );
		// Never consent-gate stylesheets. Deferring CSS (empty or data: href) unstyles
		// the whole site and triggers browser MIME text/html errors. Gate scripts /
		// iframes / network only — CSS does not set tracking cookies.
		return $html;
	}

	/**
	 * Match a URL against blocked service patterns.
	 *
	 * @param string $url Script or stylesheet URL.
	 * @return array{key:string,category:string}|null
	 */
	private function match_blocked_asset( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return null;
		}

		// Stylesheets are never consent-gated (layout).
		if ( preg_match( '/\.css(\?|#|$)/i', $url ) || false !== stripos( $url, '/elementor/css/' ) ) {
			return null;
		}

		// Theme / Elementor / WP core — never soft-defer (CF + builders must stay untouched).
		if ( \ucpf_is_site_layout_asset( $url ) ) {
			return null;
		}

		// Amelia Booking is a first-party WP form (like Gravity Forms) — never soft-defer.
		if ( false !== stripos( $url, '/ameliabooking/' ) || false !== stripos( $url, 'wpamelia' ) ) {
			return null;
		}

		// GF PayPal Checkout frontend defines window.GFPPCP — never soft-defer (inline init needs it).
		if ( false !== stripos( $url, 'gravityformsppcp' ) ) {
			return null;
		}

		// UserWay accessibility toolbar — never soft-defer (ADA / assistive tech).
		if (
			false !== stripos( $url, 'cdn.userway.org' ) ||
			false !== stripos( $url, 'api.userway.org' ) ||
			false !== stripos( $url, 'userway.org' )
		) {
			return null;
		}

		// Smart Slider 3 / Nextend — never soft-defer (custom elements cannot re-define).
		if (
			false !== stripos( $url, '/smart-slider-3/' ) ||
			false !== stripos( $url, '/smart-slider-3-pro/' ) ||
			false !== stripos( $url, 'n2.min.js' ) ||
			false !== stripos( $url, 'smartslider-frontend' )
		) {
			return null;
		}

		// Path/filename suspicion (pixel-tracking.js, etc.) — fail-closed as marketing.
		$sus = Suspicion::match_needle( $url );
		if ( $sus ) {
			// Already consented — do not rewrite to text/plain (loader would re-activate anyway).
			if ( Consent_Manager::instance()->has_consent( 'marketing' ) ) {
				return null;
			}
			if ( apply_filters( 'ucpf_should_block_script', true, array( 'key' => 'suspicion', 'category' => 'marketing' ), '', $url ) ) {
				return array(
					'key'      => 'suspicion',
					'category' => 'marketing',
				);
			}
		}

		$registry = Script_Registry::instance();
		foreach ( $registry->get_services() as $key => $service ) {
			if ( ! $registry->should_block_service( $service ) ) {
				continue;
			}
			foreach ( (array) $service['script_patterns'] as $pattern ) {
				if ( ! $pattern || false === stripos( $url, (string) $pattern ) ) {
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

		// Skip full preg on huge Elementor pages — but always park known video/map iframes
		// (Vimeo vuid / YouTube) so consent cookies cannot fire before the network gate.
		if ( strlen( $html ) > 750000 ) {
			return self::soft_defer_safe_iframes_only( $html );
		}

		$full_ob = (bool) Settings::get( 'output_buffer_blocking' );
		$safe_ob = (bool) Settings::get( 'output_buffer_safe_iframes' );

		// No full/safe OB opted in — still park CF Web Analytics + YouTube/Vimeo
		// (Smush data: placeholders race the JS gate on Elementor video widgets).
		if ( ! $full_ob && ! $safe_ob ) {
			return self::soft_defer_safe_iframes_only( $html );
		}

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
							$orig_type = '';
							if ( preg_match( '/\btype\s*=\s*([\'"])(.*?)\1/i', $attrs, $tm ) ) {
								$ot = strtolower( trim( (string) $tm[2] ) );
								if ( 'module' === $ot || 'importmap' === $ot ) {
									$orig_type = $ot;
								}
							}
							$out  = '<script type="text/plain" data-ucpf-category="' . esc_attr( $category ) . '" data-ucpf-service="' . esc_attr( $key ) . '"';
							$out .= ' data-src="' . esc_url( $src ) . '"';
							if ( '' !== $orig_type ) {
								$out .= ' data-ucpf-original-type="' . esc_attr( $orig_type ) . '"';
							}
							foreach ( array( 'id', 'async', 'defer', 'crossorigin', 'integrity', 'nonce', 'nomodule', 'referrerpolicy' ) as $keep ) {
								if ( preg_match( '/\b' . preg_quote( $keep, '/' ) . '\s*=\s*([\'"])(.*?)\1/i', $attrs, $km ) ) {
									$out .= ' ' . $keep . '="' . esc_attr( $km[2] ) . '"';
								} elseif ( preg_match( '/\b' . preg_quote( $keep, '/' ) . '\b(?!\s*=)/i', $attrs ) ) {
									// Boolean attributes (async / defer / nomodule).
									$out .= ' ' . $keep;
								}
							}
							// CF Web Analytics (and similar) need data-cf-beacon after consent restore.
							if ( preg_match_all( '/\b(data-cf-beacon|data-cf-[a-z0-9_-]+)\s*=\s*([\'"])(.*?)\2/is', $attrs, $dm, PREG_SET_ORDER ) ) {
								foreach ( $dm as $pair ) {
									$out .= ' ' . $pair[1] . '="' . esc_attr( $pair[3] ) . '"';
								}
							}
							$out .= '>';
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
					// Do not rewrite <link rel="stylesheet"> — CSS is never consent-gated.
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
						$src   = self::extract_iframe_embed_src( $attrs );
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
		// Skip when Marketing+Embeds already granted (Accept reload / returning visitor).
		// Never convert YouTube/Vimeo to placeholders — soft_defer_video_iframes_inplace parks in place.
		if ( $safe_ob ) {
			$embeds_ok = function_exists( 'ucpf_has_consent' )
				&& ucpf_has_consent( 'marketing' )
				&& ucpf_has_consent( 'functional' );
			if ( ! $embeds_ok ) {
				$video_hosts = array( 'youtube.com', 'youtube-nocookie.com', 'youtu.be', 'vimeo.com', 'player.vimeo.com' );
				foreach ( $safe_hosts as $host ) {
					$is_video = false;
					foreach ( $video_hosts as $vh ) {
						if ( false !== stripos( $host, $vh ) || false !== stripos( $vh, $host ) ) {
							$is_video = true;
							break;
						}
					}
					if ( $is_video ) {
						continue;
					}
					$replaced = preg_replace_callback(
						'#<iframe([^>]*' . preg_quote( $host, '#' ) . '[^>]*)>.*?</iframe>#is',
						static function ( $m ) {
							$attrs = $m[1];
							$src   = self::extract_iframe_embed_src( $attrs );
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
		}

		// Elementor HTML / custom-code CF Web Analytics embeds (also when full OB skipped scripts).
		$html = self::soft_defer_video_iframes_inplace( $html );
		$html = self::soft_defer_cloudflare_web_analytics( $html );
		$html = self::soft_defer_google_tags( $html );
		$html = self::soft_defer_optimizer_escaped_trackers( $html );
		return $html;
	}

	/**
	 * Lightweight iframe-only soft-defer for oversized HTML (Elementor etc.).
	 * Parks YouTube/Vimeo/maps iframes so third-party cookies (e.g. vuid) cannot
	 * set before consent when full OB rewriting is skipped for performance.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function soft_defer_safe_iframes_only( $html ) {
		$do_extra = (bool) Settings::get( 'output_buffer_safe_iframes' ) || (bool) Settings::get( 'output_buffer_blocking' );

		// Always soft-defer YouTube/Vimeo in place (keep Elementor open-inline wrappers).
		// Replacing with <div class="ucpf-iframe-placeholder"> collapses the Resources
		// Quick Tip grid and breaks post-consent hydrate.
		$html = self::soft_defer_video_iframes_inplace( $html );

		if ( $do_extra ) {
			// Maps / CTCT: leave live when Marketing+Embeds already granted.
			$embeds_ok = function_exists( 'ucpf_has_consent' )
				&& ucpf_has_consent( 'marketing' )
				&& ucpf_has_consent( 'functional' );
			if ( ! $embeds_ok ) {
				$hosts = array(
					'google.com/maps',
					'maps.google.com',
					'www.google.com/maps',
					'static.ctctcdn.com',
				);
				$start = microtime( true );
				foreach ( $hosts as $host ) {
					if ( ( microtime( true ) - $start ) > 0.4 ) {
						break;
					}
					$cat = ( false !== strpos( $host, 'maps' ) ) ? 'functional' : 'marketing';
					$svc = ( false !== strpos( $host, 'ctct' ) ) ? 'constant_contact' : 'safe_iframe';
					$replaced = preg_replace_callback(
						'#<iframe([^>]*' . preg_quote( $host, '#' ) . '[^>]*)>.*?</iframe>#is',
						static function ( $m ) use ( $cat, $svc ) {
							$attrs = $m[1];
							$src   = self::extract_iframe_embed_src( $attrs );
							return '<div class="ucpf-iframe-placeholder" data-ucpf-category="' . esc_attr( $cat ) . '" data-ucpf-service="' . esc_attr( $svc ) . '" data-src="' . esc_url( $src ) . '"></div>';
						},
						$html,
						40
					);
					if ( is_string( $replaced ) ) {
						$html = $replaced;
					}
				}
			}
		}

		$html = self::soft_defer_cloudflare_web_analytics( $html );
		$html = self::soft_defer_google_tags( $html );
		$html = self::soft_defer_optimizer_escaped_trackers( $html );
		return $html;
	}

	/**
	 * Always park Google gtag/GTM + Site Kit config snippets when Analytics is not allowed.
	 *
	 * Site Kit prints `google_gtagjs` HTML directly (comments in source) so script_loader_tag
	 * alone is not enough — same always-on OB pattern as Cloudflare Web Analytics.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function soft_defer_google_tags( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		$looks_google = (
			false !== stripos( $html, 'googletagmanager.com' ) ||
			false !== stripos( $html, 'google-analytics.com' ) ||
			false !== stripos( $html, 'google_gtagjs' ) ||
			false !== stripos( $html, 'googlesitekit' )
		);
		if ( ! $looks_google ) {
			return $html;
		}
		// Always park in origin HTML (do not skip when PHP sees Analytics consent).
		// Shared Cloudflare HTML cache otherwise freezes a consented render for everyone.

		// External gtag.js / gtm.js / analytics.js.
		$replaced = preg_replace_callback(
			'#<script([^>]*(?:googletagmanager\.com/(?:gtag|gtm\.js)|google-analytics\.com/(?:analytics|ga)\.js)[^>]*)>(.*?)</script>#is',
			static function ( $m ) {
				$attrs = $m[1];
				$body  = $m[2];
				if ( preg_match( '/\bdata-ucpf-gated\s*=/i', $attrs ) || preg_match( '/\btype\s*=\s*[\'"]text\/plain[\'"]/i', $attrs ) ) {
					return $m[0];
				}
				// Never park UCPF / Site Kit consent-mode bootstrap only (no src).
				$id = '';
				if ( preg_match( '/\bid\s*=\s*([\'"])(.*?)\1/i', $attrs, $im ) ) {
					$id = strtolower( (string) $im[2] );
				}
				if ( false !== strpos( $id, 'consent-mode' ) || 0 === strpos( $id, 'ucpf-' ) ) {
					return $m[0];
				}
				$src = '';
				if ( preg_match( '/\bsrc\s*=\s*([\'"])(.*?)\1/i', $attrs, $sm ) ) {
					$src = $sm[2];
				}
				$src_l = strtolower( $src );
				if ( Tracking_Templates::is_google_ads_src( $src ) ) {
					$category = 'marketing';
					$svc_key  = 'google_ads';
				} elseif ( false !== strpos( $src_l, 'gtm.js' ) || false !== strpos( $src_l, '/gtm?' ) ) {
					$category = 'analytics';
					$svc_key  = 'google_tag_manager';
				} else {
					$category = 'analytics';
					$svc_key  = 'google_analytics_4';
				}
				$orig_type = '';
				if ( preg_match( '/\btype\s*=\s*([\'"])(.*?)\1/i', $attrs, $tm ) ) {
					$ot = strtolower( trim( (string) $tm[2] ) );
					if ( 'module' === $ot || 'importmap' === $ot ) {
						$orig_type = $ot;
					}
				}
				$out  = '<script type="text/plain" data-ucpf-category="' . esc_attr( $category ) . '" data-ucpf-service="' . esc_attr( $svc_key ) . '" data-ucpf-gated="1"';
				$out .= ' data-src="' . esc_url( $src ) . '"';
				if ( '' !== $orig_type ) {
					$out .= ' data-ucpf-original-type="' . esc_attr( $orig_type ) . '"';
				}
				foreach ( array( 'id', 'async', 'defer', 'crossorigin', 'integrity', 'nonce', 'nomodule', 'referrerpolicy' ) as $keep ) {
					if ( preg_match( '/\b' . preg_quote( $keep, '/' ) . '\s*=\s*([\'"])(.*?)\1/i', $attrs, $km ) ) {
						$out .= ' ' . $keep . '="' . esc_attr( $km[2] ) . '"';
					} elseif ( preg_match( '/\b' . preg_quote( $keep, '/' ) . '\b(?!\s*=)/i', $attrs ) ) {
						$out .= ' ' . $keep;
					}
				}
				$out .= '>';
				$out .= $src ? '' : $body;
				$out .= '</script>';
				return $out;
			},
			$html,
			40
		);
		if ( is_string( $replaced ) ) {
			$html = $replaced;
		}

		// Site Kit inline config (id=google_gtagjs-*-after, etc.) — park so gtag("config")
		// cannot queue measurement before consent (external file may be disk-cached).
		$replaced = preg_replace_callback(
			'#<script([^>]*\bid\s*=\s*[\'"]google_gtagjs[^\'"]*[\'"][^>]*)>(.*?)</script>#is',
			static function ( $m ) {
				$attrs = $m[1];
				$body  = $m[2];
				if ( preg_match( '/\bdata-ucpf-gated\s*=/i', $attrs ) || preg_match( '/\btype\s*=\s*[\'"]text\/plain[\'"]/i', $attrs ) ) {
					return $m[0];
				}
				if ( preg_match( '/\bsrc\s*=/i', $attrs ) ) {
					return $m[0]; // External tags handled above.
				}
				$id = '';
				if ( preg_match( '/\bid\s*=\s*([\'"])(.*?)\1/i', $attrs, $im ) ) {
					$id = strtolower( (string) $im[2] );
				}
				// Keep Site Kit consent-mode defaults live.
				if ( false !== strpos( $id, 'consent-mode' ) ) {
					return $m[0];
				}
				$out  = '<script type="text/plain" data-ucpf-category="analytics" data-ucpf-service="google_analytics_4" data-ucpf-gated="1"';
				foreach ( array( 'id', 'nonce' ) as $keep ) {
					if ( preg_match( '/\b' . preg_quote( $keep, '/' ) . '\s*=\s*([\'"])(.*?)\1/i', $attrs, $km ) ) {
						$out .= ' ' . $keep . '="' . esc_attr( $km[2] ) . '"';
					}
				}
				$out .= '>' . $body . '</script>';
				return $out;
			},
			$html,
			20
		);
		return is_string( $replaced ) ? $replaced : $html;
	}

	/**
	 * Park known tracker script tags that optimizers rewrote to hashed first-party URLs.
	 * Catalog URL patterns miss /hummingbird-assets/{hash}.js — match WP id/handle instead.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function soft_defer_optimizer_escaped_trackers( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		// Already consented marketing — leave live (Accept reload / remembered choice).
		if ( function_exists( 'ucpf_has_consent' ) && ucpf_has_consent( 'marketing' ) ) {
			return $html;
		}
		if (
			false === stripos( $html, 'mailchimp-woocommerce' ) &&
			false === stripos( $html, 'mailchimp_woocommerce' ) &&
			false === stripos( $html, 'chimpstatic.com' )
		) {
			return $html;
		}
		$replaced = preg_replace_callback(
			'#<script([^>]*(?:id\s*=\s*[\'"][^\'"]*mailchimp[-_]woocommerce[^\'"]*[\'"]|mailchimp-for-woocommerce|chimpstatic\.com)[^>]*)>(.*?)</script>#is',
			static function ( $m ) {
				$attrs = $m[1];
				$body  = $m[2];
				if ( preg_match( '/\bdata-ucpf-(?:gated|service)\s*=/i', $attrs ) || preg_match( '/\btype\s*=\s*[\'"]text\/plain[\'"]/i', $attrs ) ) {
					return $m[0];
				}
				$src = '';
				if ( preg_match( '/\bsrc\s*=\s*([\'"])(.*?)\1/i', $attrs, $sm ) ) {
					$src = $sm[2];
				}
				// Inline wp_localize extras (…-js-extra) have no src — leave alone.
				if ( '' === $src && ( false !== stripos( $attrs, '-js-extra' ) || false !== stripos( $attrs, 'js-extra' ) ) ) {
					return $m[0];
				}
				if ( '' === $src && '' === trim( (string) $body ) ) {
					return $m[0];
				}
				$out  = '<script type="text/plain" data-ucpf-category="marketing" data-ucpf-service="mailchimp" data-ucpf-gated="1"';
				if ( '' !== $src ) {
					$out .= ' data-src="' . esc_url( $src ) . '"';
				}
				foreach ( array( 'id', 'async', 'defer', 'crossorigin', 'integrity', 'nonce', 'nomodule', 'referrerpolicy' ) as $keep ) {
					if ( preg_match( '/\b' . preg_quote( $keep, '/' ) . '\s*=\s*([\'"])(.*?)\1/i', $attrs, $km ) ) {
						$out .= ' ' . $keep . '="' . esc_attr( $km[2] ) . '"';
					} elseif ( preg_match( '/\b' . preg_quote( $keep, '/' ) . '\b(?!\s*=)/i', $attrs ) ) {
						$out .= ' ' . $keep;
					}
				}
				$out .= '>';
				$out .= $src ? '' : $body;
				$out .= '</script>';
				return $out;
			},
			$html,
			40
		);
		return is_string( $replaced ) ? $replaced : $html;
	}

	/**
	 * Soft-defer YouTube/Vimeo iframes without destroying the Elementor widget shell.
	 * Smush uses src=data:svg + data-src=player.vimeo.com — park the real URL and
	 * strip lazyload classes so Smush cannot promote the player before consent.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function soft_defer_video_iframes_inplace( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		// Already consented (Accept reload / remembered choice): leave live src alone.
		// Re-parking empty iframes forces Elementor runReadyTrigger races that re-stick
		// .elementor-invisible on Resources Quick Tips and other fade-in sections.
		if (
			function_exists( 'ucpf_has_consent' ) &&
			ucpf_has_consent( 'marketing' ) &&
			ucpf_has_consent( 'functional' )
		) {
			return $html;
		}
		if (
			false === stripos( $html, 'player.vimeo.com' ) &&
			false === stripos( $html, 'youtube.com/embed' ) &&
			false === stripos( $html, 'youtube-nocookie.com' ) &&
			false === stripos( $html, 'youtu.be/' )
		) {
			return $html;
		}
		$replaced = preg_replace_callback(
			'#<iframe([^>]*(?:player\.vimeo\.com|youtube\.com/embed|youtube-nocookie\.com|youtu\.be/)[^>]*)>.*?</iframe>#is',
			static function ( $m ) {
				$attrs = $m[1];
				if ( preg_match( '/\bdata-ucpf-gated\s*=/i', $attrs ) ) {
					return $m[0];
				}
				$src = self::extract_iframe_embed_src( $attrs );
				$src = html_entity_decode( (string) $src, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( '' === $src || self::is_placeholder_embed_src( $src ) ) {
					return $m[0];
				}
				$is_vimeo = ( false !== stripos( $src, 'vimeo' ) );
				$cat      = $is_vimeo ? 'functional' : 'marketing';
				$svc      = $is_vimeo ? 'vimeo' : 'youtube';

				$class = '';
				if ( preg_match( '/\bclass\s*=\s*([\'"])(.*?)\1/i', $attrs, $cm ) ) {
					$class = preg_replace( '/\b(lazyload|lazyloaded|lazyloading)\b/i', '', $cm[2] );
				}
				$class = trim( preg_replace( '/\s+/', ' ', (string) $class ) );
				if ( ! preg_match( '/\bno-lazyload\b/i', $class ) ) {
					$class = trim( $class . ' no-lazyload skip-lazy' );
				}

				$out  = '<iframe';
				$out .= ' class="' . esc_attr( $class ) . '"';
				$out .= ' data-src="' . esc_attr( $src ) . '"';
				$out .= ' data-ucpf-category="' . esc_attr( $cat ) . '"';
				$out .= ' data-ucpf-service="' . esc_attr( $svc ) . '"';
				$out .= ' data-ucpf-gated="1"';
				$out .= ' data-no-lazyload="1" data-skip-lazy-load="1"';
				foreach ( array( 'id', 'title', 'allow', 'allowfullscreen', 'width', 'height', 'frameborder', 'loading', 'referrerpolicy', 'data-load-mode' ) as $keep ) {
					if ( preg_match( '/\b' . preg_quote( $keep, '/' ) . '\s*=\s*([\'"])(.*?)\1/i', $attrs, $km ) ) {
						$out .= ' ' . $keep . '="' . esc_attr( $km[2] ) . '"';
					} elseif ( preg_match( '/\b' . preg_quote( $keep, '/' ) . '\b(?!\s*=)/i', $attrs ) ) {
						$out .= ' ' . $keep;
					}
				}
				$out .= '></iframe>';
				return $out;
			},
			$html,
			40
		);
		return is_string( $replaced ) ? $replaced : $html;
	}

	/**
	 * Park Cloudflare Web Analytics beacon (Elementor HTML / custom code embeds).
	 * Always applied in safe-iframe mode and as a belt for full OB — CF Insights
	 * is optional analytics, not NS/CDN/challenge infrastructure.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function soft_defer_cloudflare_web_analytics( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		if ( false === stripos( $html, 'cloudflareinsights.com' ) && false === stripos( $html, 'cdn-cgi/rum' ) ) {
			return $html;
		}
		// Already consented analytics — leave beacon live (Accept reload / remembered choice).
		if ( function_exists( 'ucpf_has_consent' ) && ucpf_has_consent( 'analytics' ) ) {
			return $html;
		}
		$replaced = preg_replace_callback(
			'#<script([^>]*(?:static\.cloudflareinsights\.com|cloudflareinsights\.com|/cdn-cgi/rum)[^>]*)>(.*?)</script>#is',
			static function ( $m ) {
				$attrs = $m[1];
				$body  = $m[2];
				// Already parked by catalog OB or prior pass.
				if ( preg_match( '/\bdata-ucpf-gated\s*=/i', $attrs ) || preg_match( '/\btype\s*=\s*[\'"]text\/plain[\'"]/i', $attrs ) ) {
					return $m[0];
				}
				$src = '';
				if ( preg_match( '/\bsrc\s*=\s*([\'"])(.*?)\1/i', $attrs, $sm ) ) {
					$src = $sm[2];
				}
				$orig_type = '';
				if ( preg_match( '/\btype\s*=\s*([\'"])(.*?)\1/i', $attrs, $tm ) ) {
					$ot = strtolower( trim( (string) $tm[2] ) );
					if ( 'module' === $ot || 'importmap' === $ot ) {
						$orig_type = $ot;
					}
				}
				$out  = '<script type="text/plain" data-ucpf-category="analytics" data-ucpf-service="cloudflare_web_analytics" data-ucpf-gated="1"';
				$out .= ' data-src="' . esc_url( $src ) . '"';
				if ( '' !== $orig_type ) {
					$out .= ' data-ucpf-original-type="' . esc_attr( $orig_type ) . '"';
				}
				foreach ( array( 'id', 'async', 'defer', 'crossorigin', 'integrity', 'nonce', 'nomodule', 'referrerpolicy' ) as $keep ) {
					if ( preg_match( '/\b' . preg_quote( $keep, '/' ) . '\s*=\s*([\'"])(.*?)\1/i', $attrs, $km ) ) {
						$out .= ' ' . $keep . '="' . esc_attr( $km[2] ) . '"';
					} elseif ( preg_match( '/\b' . preg_quote( $keep, '/' ) . '\b(?!\s*=)/i', $attrs ) ) {
						$out .= ' ' . $keep;
					}
				}
				if ( preg_match_all( '/\b(data-cf-beacon|data-cf-[a-z0-9_-]+)\s*=\s*([\'"])(.*?)\2/is', $attrs, $dm, PREG_SET_ORDER ) ) {
					foreach ( $dm as $pair ) {
						$out .= ' ' . $pair[1] . '="' . esc_attr( $pair[3] ) . '"';
					}
				}
				$out .= '>';
				$out .= $src ? '' : $body;
				$out .= '</script>';
				return $out;
			},
			$html,
			20
		);
		return is_string( $replaced ) ? $replaced : $html;
	}

	/**
	 * True for lazy-load placeholder iframe src values.
	 *
	 * @param string $url URL or src value.
	 * @return bool
	 */
	private static function is_placeholder_embed_src( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || 'about:blank' === $url ) {
			return true;
		}
		$lower = strtolower( $url );
		return 0 === strpos( $lower, 'data:' ) || 0 === strpos( $lower, 'blob:' );
	}

	/**
	 * Extract real iframe embed URL from attributes (data-src when src is placeholder).
	 *
	 * @param string $attrs Raw iframe attribute string.
	 * @return string
	 */
	private static function extract_iframe_embed_src( $attrs ) {
		$src = '';
		if ( preg_match( '/\bsrc\s*=\s*([\'"])(.*?)\1/i', $attrs, $sm ) ) {
			$src = $sm[2];
		}
		if ( ! self::is_placeholder_embed_src( $src ) ) {
			return $src;
		}
		foreach ( array( 'data-src', 'data-lazy-src' ) as $attr ) {
			if ( preg_match( '/\b' . preg_quote( $attr, '/' ) . '\s*=\s*([\'"])(.*?)\1/i', $attrs, $dm ) ) {
				if ( ! self::is_placeholder_embed_src( $dm[2] ) ) {
					return $dm[2];
				}
			}
		}
		return $src;
	}
}
