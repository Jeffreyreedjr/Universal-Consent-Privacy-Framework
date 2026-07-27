<?php
/**
 * Cookie scanner — multi-context detection.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Cookie scanner.
 *
 * Keep scans small: self HTTP requests compete for PHP workers with the
 * admin REST request and can 504 or hang the whole site if unbounded.
 */
class Cookie_Scanner {

	/**
	 * Hard max URLs fetched per server scan (guest).
	 * Keep bounded so self-HTTP doesn't exhaust PHP workers, but high enough
	 * for multi-location landing pages.
	 */
	const MAX_SERVER_URLS = 40;

	/**
	 * Max pages offered in the scanner picker UI (full catalog — not depth-gated).
	 */
	const MAX_PICKER_URLS = 200;

	/**
	 * Max URLs loaded in the guest browser crawl (iframe harvest).
	 * Aligned with Playwright deep maxPages so selected pages are not silently dropped.
	 */
	const MAX_BROWSER_URLS = 80;

	/**
	 * Depth preset limits (FAZ-inspired).
	 */
	const DEPTH_QUICK    = 10;
	const DEPTH_STANDARD = 40;
	const DEPTH_DEEP     = 100;

	/**
	 * Discover-mode token transient key.
	 */
	const DISCOVER_TOKEN_KEY = 'ucpf_discover_token';

	/**
	 * Discover token lifetime (seconds).
	 */
	const DISCOVER_TOKEN_TTL = 300;

	/**
	 * Per-request HTTP timeout (seconds).
	 */
	const FETCH_TIMEOUT = 4;

	/**
	 * Instance.
	 *
	 * @var Cookie_Scanner|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Cookie_Scanner
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init.
	 */
	public function init() {
		// Scanner runs via REST / admin.
	}

	/**
	 * Whether WooCommerce is active on this site.
	 *
	 * @return bool
	 */
	public function is_woo_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Build guest scan URL set: home, priority pages (contact/newsletter), Woo only if active.
	 *
	 * @param int $limit Max URLs.
	 * @return array
	 */
	public function get_default_scan_urls( $limit = 30 ) {
		$limit = max( 3, min( self::MAX_SERVER_URLS, (int) $limit ) );
		$urls  = array(
			array(
				'url'     => home_url( '/' ),
				'context' => 'guest',
				'label'   => __( 'Homepage', 'universal-consent-privacy-framework' ),
				'chip'    => 'home',
			),
		);

		if ( $this->is_woo_active() ) {
			$woo_pages = array(
				'shop'      => __( 'Shop', 'universal-consent-privacy-framework' ),
				'cart'      => __( 'Cart', 'universal-consent-privacy-framework' ),
				'checkout'  => __( 'Checkout', 'universal-consent-privacy-framework' ),
				'myaccount' => __( 'My Account', 'universal-consent-privacy-framework' ),
			);
			foreach ( $woo_pages as $id => $label ) {
				$page_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( $id ) : 0;
				if ( $page_id && $page_id > 0 ) {
					$urls[] = array(
						'url'     => get_permalink( $page_id ),
						'context' => ( 'checkout' === $id || 'cart' === $id ) ? 'checkout' : 'guest',
						'label'   => $label,
						'chip'    => $id,
					);
				}
			}
		}

		$priority = $this->find_priority_content_urls( $limit );
		foreach ( $priority as $item ) {
			$urls[] = $item;
		}

		$posts = get_posts(
			array(
				'post_type'              => array( 'page', 'post' ),
				'post_status'            => 'publish',
				'posts_per_page'         => max( 4, $limit ),
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $posts as $page ) {
			$permalink = get_permalink( $page );
			if ( ! $permalink ) {
				continue;
			}
			$urls[] = array(
				'url'     => $permalink,
				'context' => 'guest',
				'label'   => $page->post_title ? $page->post_title : $permalink,
			);
		}

		return $this->dedupe_url_defs( $urls, $limit );
	}

	/**
	 * Pages/posts available for the scanner multi-select (larger than crawl cap).
	 *
	 * @param int $limit Max items.
	 * @return array
	 */
	public function get_selectable_scan_urls( $limit = 40 ) {
		$limit = max( 8, min( self::MAX_PICKER_URLS, (int) $limit ) );
		$urls  = $this->get_default_scan_urls( self::MAX_SERVER_URLS );

		$posts = get_posts(
			array(
				'post_type'              => array( 'page', 'post' ),
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $posts as $page ) {
			$permalink = get_permalink( $page );
			if ( ! $permalink ) {
				continue;
			}
			$urls[] = array(
				'url'       => $permalink,
				'context'   => 'guest',
				'label'     => $page->post_title ? $page->post_title : $permalink,
				'post_type' => $page->post_type,
				'id'        => (int) $page->ID,
			);
		}

		return $this->dedupe_url_defs( $urls, $limit );
	}

	/**
	 * Resolve a scan depth preset to a URL limit.
	 *
	 * @param string $depth quick|standard|deep.
	 * @return int
	 */
	public function depth_limit( $depth ) {
		$depth = sanitize_key( (string) $depth );
		if ( 'quick' === $depth ) {
			return self::DEPTH_QUICK;
		}
		if ( 'deep' === $depth ) {
			return min( self::DEPTH_DEEP, self::MAX_PICKER_URLS );
		}
		return self::DEPTH_STANDARD;
	}

	/**
	 * Discover same-origin paths from sitemap + homepage links + WP / Woo content.
	 * Always returns the full picker catalog (up to MAX_PICKER_URLS). Depth only
	 * affects Playwright session profile + crawl caps, not which URLs are listed.
	 *
	 * @param string $depth Ignored for catalog size (kept for call-site BC).
	 * @return array URL defs with url, label, context, source, group.
	 */
	public function discover_site_paths( $depth = 'standard' ) {
		unset( $depth ); // Catalog is not depth-gated.
		$limit = self::MAX_PICKER_URLS;
		$urls  = array();

		$urls[] = array(
			'url'      => home_url( '/' ),
			'context'  => 'guest',
			'label'    => __( 'Homepage', 'universal-consent-privacy-framework' ),
			'source'   => 'home',
			'chip'     => 'home',
			'group'    => 'home',
			'priority' => 0,
		);

		if ( $this->is_woo_active() ) {
			$woo_pages = array(
				'shop'      => __( 'Shop', 'universal-consent-privacy-framework' ),
				'cart'      => __( 'Cart', 'universal-consent-privacy-framework' ),
				'checkout'  => __( 'Checkout', 'universal-consent-privacy-framework' ),
				'myaccount' => __( 'My Account', 'universal-consent-privacy-framework' ),
			);
			foreach ( $woo_pages as $id => $label ) {
				$page_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( $id ) : 0;
				if ( $page_id && $page_id > 0 ) {
					$permalink = get_permalink( $page_id );
					if ( $permalink ) {
						$urls[] = array(
							'url'      => $permalink,
							'context'  => ( 'checkout' === $id || 'cart' === $id ) ? 'checkout' : 'guest',
							'label'    => $label,
							'source'   => 'woocommerce',
							'chip'     => $id,
							'group'    => 'woocommerce',
							'priority' => 1,
						);
					}
				}
			}

			$products = get_posts(
				array(
					'post_type'              => 'product',
					'post_status'            => 'publish',
					'posts_per_page'         => min( 80, $limit ),
					'orderby'                => 'modified',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			foreach ( $products as $product ) {
				$permalink = get_permalink( $product );
				if ( ! $permalink ) {
					continue;
				}
				$urls[] = array(
					'url'       => $permalink,
					'context'   => 'guest',
					'label'     => $product->post_title ? $product->post_title : $permalink,
					'source'    => 'woocommerce_product',
					'post_type' => 'product',
					'id'        => (int) $product->ID,
					'group'     => 'products',
					'priority'  => 3,
				);
			}

			if ( taxonomy_exists( 'product_cat' ) ) {
				$terms = get_terms(
					array(
						'taxonomy'   => 'product_cat',
						'hide_empty' => true,
						'number'     => 40,
					)
				);
				if ( ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term ) {
						$link = get_term_link( $term );
						if ( is_wp_error( $link ) || ! $link ) {
							continue;
						}
						$urls[] = array(
							'url'      => $link,
							'context'  => 'guest',
							'label'    => $term->name,
							'source'   => 'product_cat',
							'group'    => 'product_categories',
							'priority' => 3,
						);
					}
				}
			}
		}

		foreach ( $this->find_priority_content_urls( $limit ) as $item ) {
			$item['source']   = isset( $item['source'] ) ? $item['source'] : 'priority';
			$item['priority'] = 2;
			$item['group']    = isset( $item['group'] ) ? $item['group'] : 'pages';
			$urls[]           = $item;
		}

		foreach ( $this->discover_paths_from_sitemap( $limit ) as $item ) {
			$item['group'] = isset( $item['group'] ) ? $item['group'] : $this->infer_url_group( $item );
			$urls[]        = $item;
		}

		foreach ( $this->discover_paths_from_homepage_links( $limit ) as $item ) {
			$item['group'] = isset( $item['group'] ) ? $item['group'] : $this->infer_url_group( $item );
			$urls[]        = $item;
		}

		$posts = get_posts(
			array(
				'post_type'              => array( 'page', 'post' ),
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		foreach ( $posts as $page ) {
			$permalink = get_permalink( $page );
			if ( ! $permalink ) {
				continue;
			}
			$urls[] = array(
				'url'       => $permalink,
				'context'   => 'guest',
				'label'     => $page->post_title ? $page->post_title : $permalink,
				'source'    => 'wp_content',
				'post_type' => $page->post_type,
				'id'        => (int) $page->ID,
				'group'     => ( 'post' === $page->post_type ) ? 'posts' : 'pages',
				'priority'  => 5,
			);
		}

		$blog_cats = get_categories(
			array(
				'hide_empty' => true,
				'number'     => 30,
			)
		);
		foreach ( $blog_cats as $cat ) {
			$link = get_category_link( $cat->term_id );
			if ( ! $link ) {
				continue;
			}
			$urls[] = array(
				'url'      => $link,
				'context'  => 'guest',
				'label'    => $cat->name,
				'source'   => 'category',
				'group'    => 'categories',
				'priority' => 6,
			);
		}

		usort(
			$urls,
			static function ( $a, $b ) {
				$pa = isset( $a['priority'] ) ? (int) $a['priority'] : 9;
				$pb = isset( $b['priority'] ) ? (int) $b['priority'] : 9;
				if ( $pa !== $pb ) {
					return $pa - $pb;
				}
				return strcasecmp( (string) ( $a['label'] ?? '' ), (string) ( $b['label'] ?? '' ) );
			}
		);

		$deduped = $this->dedupe_url_defs( $urls, $limit );
		foreach ( $deduped as &$row ) {
			if ( empty( $row['group'] ) ) {
				$row['group'] = $this->infer_url_group( $row );
			}
		}
		unset( $row );

		return $deduped;
	}

	/**
	 * Infer picker group from a URL def.
	 *
	 * @param array $item URL def.
	 * @return string
	 */
	private function infer_url_group( array $item ) {
		if ( ! empty( $item['group'] ) ) {
			return sanitize_key( (string) $item['group'] );
		}
		$source = isset( $item['source'] ) ? (string) $item['source'] : '';
		if ( 'home' === $source ) {
			return 'home';
		}
		if ( 'woocommerce' === $source ) {
			return 'woocommerce';
		}
		if ( 'woocommerce_product' === $source || 'product' === ( $item['post_type'] ?? '' ) ) {
			return 'products';
		}
		if ( 'product_cat' === $source ) {
			return 'product_categories';
		}
		if ( 'post' === ( $item['post_type'] ?? '' ) ) {
			return 'posts';
		}
		if ( 'page' === ( $item['post_type'] ?? '' ) || 'wp_content' === $source || 'priority' === $source ) {
			return 'pages';
		}
		if ( 'category' === $source ) {
			return 'categories';
		}
		$path = '';
		if ( ! empty( $item['url'] ) ) {
			$path = (string) wp_parse_url( $item['url'], PHP_URL_PATH );
		}
		$path = strtolower( $path );
		if ( preg_match( '#/(product|products)/#', $path ) ) {
			return 'products';
		}
		if ( preg_match( '#/(product-category|shop)/#', $path ) ) {
			return 'product_categories';
		}
		if ( preg_match( '#/(cart|checkout|my-account)/?#', $path ) ) {
			return 'woocommerce';
		}
		if ( preg_match( '#/(category|tag)/#', $path ) ) {
			return 'categories';
		}
		return 'other';
	}

	/**
	 * Paths from sitemap.xml / sitemap index (same-origin only).
	 *
	 * @param int $limit Cap.
	 * @return array
	 */
	public function discover_paths_from_sitemap( $limit = 40 ) {
		$limit = max( 1, min( self::MAX_PICKER_URLS, (int) $limit ) );
		$home  = home_url( '/' );
		$host  = wp_parse_url( $home, PHP_URL_HOST );
		$out   = array();

		$candidates = array(
			home_url( '/sitemap.xml' ),
			home_url( '/sitemap_index.xml' ),
			home_url( '/wp-sitemap.xml' ),
		);
		$sitemap_url = apply_filters( 'ucpf_scanner_sitemap_url', '' );
		if ( $sitemap_url ) {
			array_unshift( $candidates, $sitemap_url );
		}

		$loc_urls = array();
		foreach ( $candidates as $candidate ) {
			$locs = $this->fetch_sitemap_locs( $candidate, $host, 0 );
			if ( $locs ) {
				$loc_urls = $locs;
				break;
			}
		}

		foreach ( $loc_urls as $loc ) {
			$path = wp_parse_url( $loc, PHP_URL_PATH );
			$parts = wp_parse_url( $loc );
			$clean = $loc;
			if ( is_array( $parts ) && ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
				$path_part = isset( $parts['path'] ) ? $parts['path'] : '/';
				$clean     = $parts['scheme'] . '://' . $parts['host'] . ( $path_part ? $path_part : '/' );
			}
			$out[] = array(
				'url'      => $clean,
				'context'  => 'guest',
				'label'    => $path ? $path : $clean,
				'source'   => 'sitemap',
				'priority' => $this->path_priority_score( $path ? $path : '' ),
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Fetch <loc> URLs from a sitemap (follows sitemap indexes one level).
	 *
	 * @param string $url   Sitemap URL.
	 * @param string $host  Allowed host.
	 * @param int    $depth Recursion depth.
	 * @return string[]
	 */
	private function fetch_sitemap_locs( $url, $host, $depth = 0 ) {
		if ( $depth > 1 || ! $url ) {
			return array();
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 4,
				'redirection' => 2,
				'user-agent'  => 'UCPF-Scanner/1.0 (+sitemap discovery)',
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body || false === stripos( $body, '<loc' ) ) {
			return array();
		}

		$locs = array();
		if ( preg_match_all( '/<loc>\s*([^<\s]+)\s*<\/loc>/i', $body, $m ) ) {
			foreach ( $m[1] as $loc ) {
				$loc = esc_url_raw( html_entity_decode( trim( $loc ), ENT_QUOTES, 'UTF-8' ) );
				if ( ! $loc ) {
					continue;
				}
				$loc_host = wp_parse_url( $loc, PHP_URL_HOST );
				if ( $host && $loc_host && strtolower( $loc_host ) !== strtolower( $host ) ) {
					continue;
				}
				// Nested sitemap index.
				if ( $depth < 1 && ( false !== stripos( $loc, 'sitemap' ) && false !== stripos( $loc, '.xml' ) ) ) {
					$nested = $this->fetch_sitemap_locs( $loc, $host, $depth + 1 );
					foreach ( $nested as $n ) {
						$locs[] = $n;
					}
					continue;
				}
				$locs[] = $loc;
			}
		}
		return array_values( array_unique( $locs ) );
	}

	/**
	 * Same-origin links from the homepage HTML.
	 *
	 * @param int $limit Cap.
	 * @return array
	 */
	public function discover_paths_from_homepage_links( $limit = 40 ) {
		$limit    = max( 1, min( self::MAX_PICKER_URLS, (int) $limit ) );
		$home     = home_url( '/' );
		$host     = wp_parse_url( $home, PHP_URL_HOST );
		$response = wp_remote_get(
			$home,
			array(
				'timeout'     => 5,
				'redirection' => 2,
				'user-agent'  => 'UCPF-Scanner/1.0 (+link discovery)',
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		$html = (string) wp_remote_retrieve_body( $response );
		if ( '' === $html ) {
			return array();
		}

		$out = array();
		if ( ! preg_match_all( '/<a\s[^>]*href\s*=\s*["\']([^"\']+)["\']/i', $html, $m ) ) {
			return array();
		}

		foreach ( $m[1] as $href ) {
			$href = trim( html_entity_decode( $href, ENT_QUOTES, 'UTF-8' ) );
			if ( '' === $href || '#' === $href[0] || 0 === stripos( $href, 'mailto:' ) || 0 === stripos( $href, 'tel:' ) || 0 === stripos( $href, 'javascript:' ) ) {
				continue;
			}
			if ( 0 === strpos( $href, '/' ) ) {
				$href = home_url( $href );
			}
			if ( ! preg_match( '#^https?://#i', $href ) ) {
				continue;
			}
			$href_host = wp_parse_url( $href, PHP_URL_HOST );
			if ( $host && $href_host && strtolower( $href_host ) !== strtolower( $host ) ) {
				continue;
			}
			$path = wp_parse_url( $href, PHP_URL_PATH );
			// Strip query/fragment so ?utm=… homepage links do not flood the picker.
			$clean = $href;
			$parts = wp_parse_url( $href );
			if ( is_array( $parts ) && ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
				$path_part = isset( $parts['path'] ) ? $parts['path'] : '/';
				$clean     = $parts['scheme'] . '://' . $parts['host'] . ( $path_part ? $path_part : '/' );
			}
			$out[] = array(
				'url'      => $clean,
				'context'  => 'guest',
				'label'    => $path ? $path : $clean,
				'source'   => 'homepage_link',
				'priority' => $this->path_priority_score( $path ? $path : '' ),
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Lower number = higher priority for discovery sort.
	 *
	 * @param string $path URL path.
	 * @return int
	 */
	private function path_priority_score( $path ) {
		$p = strtolower( (string) $path );
		if ( preg_match( '/(checkout|cart|payment)/', $p ) ) {
			return 1;
		}
		if ( preg_match( '/(contact|form|enquiry|inquiry|support)/', $p ) ) {
			return 2;
		}
		if ( preg_match( '/(shop|product|store)/', $p ) ) {
			return 3;
		}
		if ( preg_match( '/(about|privacy|cookie|terms)/', $p ) ) {
			return 4;
		}
		return 6;
	}

	/**
	 * Quick-select chips for the scanner UI.
	 *
	 * @return array
	 */
	public function get_scan_chips() {
		$chips = array(
			array(
				'id'    => 'home',
				'label' => __( 'Home', 'universal-consent-privacy-framework' ),
				'url'   => untrailingslashit( home_url( '/' ) ),
			),
		);

		$priority_map = array(
			'contact'    => __( 'Contact', 'universal-consent-privacy-framework' ),
			'newsletter' => __( 'Newsletter', 'universal-consent-privacy-framework' ),
			'subscribe'  => __( 'Subscribe', 'universal-consent-privacy-framework' ),
			'privacy'    => __( 'Privacy', 'universal-consent-privacy-framework' ),
			'blog'       => __( 'Blog', 'universal-consent-privacy-framework' ),
		);

		foreach ( $this->find_priority_content_urls( 12 ) as $item ) {
			$chip_id = isset( $item['chip'] ) ? $item['chip'] : '';
			if ( ! $chip_id || ! isset( $priority_map[ $chip_id ] ) ) {
				continue;
			}
			$chips[] = array(
				'id'    => $chip_id,
				'label' => $priority_map[ $chip_id ],
				'url'   => $item['url'],
			);
		}

		if ( $this->is_woo_active() ) {
			foreach ( array( 'shop', 'cart', 'checkout' ) as $id ) {
				$page_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( $id ) : 0;
				if ( $page_id && $page_id > 0 ) {
					$permalink = get_permalink( $page_id );
					if ( $permalink ) {
						$labels  = array(
							'shop'     => __( 'Shop', 'universal-consent-privacy-framework' ),
							'cart'     => __( 'Cart', 'universal-consent-privacy-framework' ),
							'checkout' => __( 'Checkout', 'universal-consent-privacy-framework' ),
						);
						$chips[] = array(
							'id'    => $id,
							'label' => $labels[ $id ],
							'url'   => untrailingslashit( $permalink ),
						);
					}
				}
			}
		}

		return $this->dedupe_url_defs( $chips, 16 );
	}

	/**
	 * Find pages matching contact / newsletter / privacy / blog heuristics.
	 *
	 * @param int $limit Max.
	 * @return array
	 */
	private function find_priority_content_urls( $limit = 8 ) {
		$needles = array(
			'contact'    => array( 'contact', 'kontakt', 'get-in-touch' ),
			'newsletter' => array( 'newsletter', 'mailing', 'mailchimp' ),
			'subscribe'  => array( 'subscribe', 'signup', 'sign-up', 'join' ),
			'privacy'    => array( 'privacy', 'cookie-policy', 'cookies' ),
			'blog'       => array( 'blog', 'news', 'articles' ),
		);

		$posts = get_posts(
			array(
				'post_type'              => array( 'page', 'post' ),
				'post_status'            => 'publish',
				'posts_per_page'         => 60,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$found = array();
		$seen  = array();
		foreach ( $needles as $chip => $terms ) {
			foreach ( $posts as $page ) {
				$hay = strtolower( $page->post_name . ' ' . $page->post_title );
				$hit = false;
				foreach ( $terms as $term ) {
					if ( false !== strpos( $hay, $term ) ) {
						$hit = true;
						break;
					}
				}
				if ( ! $hit ) {
					continue;
				}
				$permalink = get_permalink( $page );
				if ( ! $permalink ) {
					continue;
				}
				$key = $this->canonicalize_scan_url( $permalink );
				if ( ! $key || isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$found[]      = array(
					'url'     => $key,
					'context' => 'guest',
					'label'   => $page->post_title ? $page->post_title : $permalink,
					'chip'    => $chip,
				);
				break;
			}
			if ( count( $found ) >= $limit ) {
				break;
			}
		}

		return $found;
	}

	/**
	 * Canonical same-site URL for picker dedupe (host + path only; no query/fragment).
	 *
	 * @param string $url Raw URL or path.
	 * @return string Empty if invalid / off-site.
	 */
	public function canonicalize_scan_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		}
		$url = esc_url_raw( $url );
		if ( ! $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( $home_host && strtolower( (string) $parts['host'] ) !== strtolower( (string) $home_host ) ) {
			return '';
		}

		$scheme = ! empty( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}
		$host = strtolower( (string) $parts['host'] );
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		if ( '' === $path ) {
			$path = '/';
		}
		// Collapse /index.php homepage variants.
		if ( preg_match( '#^/index\.php/?$#i', $path ) ) {
			$path = '/';
		}
		$path = untrailingslashit( $path );
		// Homepage becomes scheme://host (no trailing slash).
		return $scheme . '://' . $host . $path;
	}

	/**
	 * Prefer human titles over bare paths when merging duplicate URL rows.
	 *
	 * @param string $candidate New label.
	 * @param string $current   Existing label.
	 * @return bool
	 */
	private function is_better_scan_label( $candidate, $current ) {
		$candidate = trim( (string) $candidate );
		$current   = trim( (string) $current );
		if ( '' === $candidate ) {
			return false;
		}
		if ( '' === $current || '/' === $current ) {
			return true;
		}
		$bare = ( '/' === $candidate || 0 === strpos( $candidate, '/' ) || 0 === stripos( $candidate, 'http' ) );
		$cur_bare = ( '/' === $current || 0 === strpos( $current, '/' ) || 0 === stripos( $current, 'http' ) );
		if ( $cur_bare && ! $bare ) {
			return true;
		}
		return false;
	}

	/**
	 * Human label for a canonical scan URL.
	 *
	 * @param string $canonical Canonical URL.
	 * @param string $fallback  Existing label.
	 * @return string
	 */
	private function label_for_scan_url( $canonical, $fallback = '' ) {
		$fallback = trim( (string) $fallback );
		$path     = wp_parse_url( $canonical, PHP_URL_PATH );
		$path     = ( null === $path || '' === $path ) ? '/' : $path;
		if ( '/' === $path || '' === $path ) {
			return __( 'Homepage', 'universal-consent-privacy-framework' );
		}
		if ( '' === $fallback || '/' === $fallback || $fallback === $canonical || $fallback === $path ) {
			return $path;
		}
		return $fallback;
	}

	/**
	 * Dedupe URL definition arrays and apply scanner filter.
	 *
	 * @param array $urls  URL defs.
	 * @param int   $limit Max.
	 * @return array
	 */
	private function dedupe_url_defs( array $urls, $limit ) {
		$seen = array();
		$out  = array();
		foreach ( $urls as $item ) {
			if ( empty( $item['url'] ) ) {
				continue;
			}
			$key = $this->canonicalize_scan_url( $item['url'] );
			if ( ! $key ) {
				continue;
			}
			$label = $this->label_for_scan_url( $key, isset( $item['label'] ) ? (string) $item['label'] : '' );
			if ( isset( $seen[ $key ] ) ) {
				$idx = $seen[ $key ];
				if ( $this->is_better_scan_label( $label, isset( $out[ $idx ]['label'] ) ? (string) $out[ $idx ]['label'] : '' ) ) {
					$out[ $idx ]['label'] = $label;
				}
				// Keep the earliest (usually highest-priority) entry.
				continue;
			}
			$seen[ $key ] = count( $out );
			$item['url']  = $key;
			$item['label'] = $label;
			$out[]        = $item;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		/**
		 * Filter scanner URLs.
		 *
		 * @param array $out URL definitions.
		 */
		return apply_filters( 'ucpf_scanner_urls', $out );
	}

	/**
	 * Create a short-lived discover token for guest crawl (admin only).
	 *
	 * @return array{token:string,expires_in:int}
	 */
	public function create_discover_token() {
		$token = wp_generate_password( 32, false, false );
		set_transient( self::DISCOVER_TOKEN_KEY, $token, self::DISCOVER_TOKEN_TTL );
		return array(
			'token'      => $token,
			'expires_in' => self::DISCOVER_TOKEN_TTL,
		);
	}

	/**
	 * Clear discover token after crawl.
	 */
	public function clear_discover_token() {
		delete_transient( self::DISCOVER_TOKEN_KEY );
	}

	/**
	 * Hard-stop a running scan: clear busy lock + discover token.
	 *
	 * @return array
	 */
	public function cancel_scan() {
		delete_transient( 'ucpf_scan_running' );
		$this->clear_discover_token();
		/**
		 * Fires when an admin cancels a scan mid-flight.
		 */
		do_action( 'ucpf_scan_cancelled' );
		return array(
			'success' => true,
			'message' => __( 'Scan stopped. You can refresh or start again.', 'universal-consent-privacy-framework' ),
		);
	}

	/**
	 * Whether a scan lock is currently held.
	 *
	 * @return bool
	 */
	public function is_scan_running() {
		return (bool) get_transient( 'ucpf_scan_running' );
	}

	/**
	 * Validate discover token from request.
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	public function validate_discover_token( $token ) {
		$token = (string) $token;
		if ( '' === $token ) {
			return false;
		}
		$stored = get_transient( self::DISCOVER_TOKEN_KEY );
		return is_string( $stored ) && '' !== $stored && hash_equals( $stored, $token );
	}

	/**
	 * Built-in + agency private cookie default patterns.
	 *
	 * @return array
	 */
	public function get_private_cookie_defaults() {
		$seed = array(
			array(
				'name'         => 'wp_consent_*',
				'pattern'      => 'wp_consent_*',
				'purpose'      => __( 'WP Consent API category choice cookie.', 'universal-consent-privacy-framework' ),
				'retention'    => __( 'Session / short-lived', 'universal-consent-privacy-framework' ),
				'category'     => 'preferences',
				'treatment'    => 'necessary',
				'service'      => 'wp_consent_api',
				'service_name' => __( 'WP Consent API', 'universal-consent-privacy-framework' ),
				'provider'     => 'WordPress',
			),
			array(
				'name'         => 'MCPopupClosed',
				'pattern'      => 'MCPopupClosed',
				'purpose'      => __( 'Mailchimp popup closed state.', 'universal-consent-privacy-framework' ),
				'retention'    => __( '1 year', 'universal-consent-privacy-framework' ),
				'category'     => 'marketing',
				'treatment'    => 'consent',
				'service'      => 'mailchimp',
				'service_name' => 'Mailchimp',
				'provider'     => 'Mailchimp',
			),
			array(
				'name'         => 'MCPopupSubscribed',
				'pattern'      => 'MCPopupSubscribed',
				'purpose'      => __( 'Mailchimp popup subscribed state.', 'universal-consent-privacy-framework' ),
				'retention'    => __( '1 year', 'universal-consent-privacy-framework' ),
				'category'     => 'marketing',
				'treatment'    => 'consent',
				'service'      => 'mailchimp',
				'service_name' => 'Mailchimp',
				'provider'     => 'Mailchimp',
			),
			array(
				'name'         => 'mailchimp_landing_site',
				'pattern'      => 'mailchimp_landing_site',
				'purpose'      => __( 'Mailchimp landing attribution.', 'universal-consent-privacy-framework' ),
				'retention'    => __( '1 month', 'universal-consent-privacy-framework' ),
				'category'     => 'marketing',
				'treatment'    => 'consent',
				'service'      => 'mailchimp',
				'service_name' => 'Mailchimp',
				'provider'     => 'Mailchimp',
			),
			array(
				'name'         => '_mcid',
				'pattern'      => '_mcid',
				'purpose'      => __( 'Mailchimp visitor id.', 'universal-consent-privacy-framework' ),
				'retention'    => __( '1 year', 'universal-consent-privacy-framework' ),
				'category'     => 'marketing',
				'treatment'    => 'consent',
				'service'      => 'mailchimp',
				'service_name' => 'Mailchimp',
				'provider'     => 'Mailchimp',
			),
		);

		$custom = get_option( 'ucpf_private_cookie_defaults', array() );
		if ( is_array( $custom ) && $custom ) {
			$seed = array_merge( $seed, $custom );
		}

		/**
		 * Filter private cookie defaults used before marking a cookie unknown.
		 *
		 * @param array $seed Defaults.
		 */
		return apply_filters( 'ucpf_private_cookie_defaults', $seed );
	}

	/**
	 * Normalize and validate same-site scan URLs from the admin picker.
	 *
	 * @param array $urls Raw URL defs or strings.
	 * @param int   $limit Max.
	 * @return array
	 */
	public function normalize_scan_urls( array $urls, $limit = 30 ) {
		$limit = max( 1, min( self::MAX_SERVER_URLS, (int) $limit ) );
		$home     = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$out      = array();
		$seen     = array();

		foreach ( $urls as $def ) {
			if ( is_string( $def ) ) {
				$def = array(
					'url'     => $def,
					'context' => 'guest',
					'label'   => $def,
				);
			}
			if ( empty( $def['url'] ) ) {
				continue;
			}
			$url = esc_url_raw( $def['url'] );
			if ( ! $url ) {
				// Allow relative paths.
				$path = '/' . ltrim( (string) $def['url'], '/' );
				$url  = home_url( $path );
				$url  = esc_url_raw( $url );
			}
			if ( ! $url ) {
				continue;
			}
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( $home && $host && strtolower( (string) $host ) !== strtolower( (string) $home ) ) {
				continue;
			}
			$key = $this->canonicalize_scan_url( $url );
			if ( ! $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'url'     => $key,
				'context' => 'guest',
				'label'   => $this->label_for_scan_url( $key, ! empty( $def['label'] ) ? (string) $def['label'] : '' ),
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Scan URLs for known patterns and cookies.
	 *
	 * @param array $args {
	 *   @type array $urls            Optional URL defs or string URLs.
	 *   @type bool  $include_auth    Also scan homepage as logged-in admin (once).
	 *   @type array $live_cookies    Cookie names from live capture.
	 * }
	 * @return array|\WP_Error
	 */
	public function scan( array $args = array() ) {
		if ( isset( $args[0] ) && is_string( $args[0] ) ) {
			$urls = array();
			foreach ( $args as $url ) {
				$urls[] = array(
					'url'     => $url,
					'context' => 'guest',
					'label'   => $url,
				);
			}
			$args = array( 'urls' => $urls );
		}

		if ( get_transient( 'ucpf_scan_running' ) ) {
			return new \WP_Error(
				'ucpf_scan_busy',
				__( 'A scan is already running. Wait a moment and try again.', 'universal-consent-privacy-framework' ),
				array( 'status' => 409 )
			);
		}

		set_transient( 'ucpf_scan_running', 1, 180 );

		try {
			return $this->run_scan( $args );
		} finally {
			delete_transient( 'ucpf_scan_running' );
		}
	}

	/**
	 * Internal scan runner.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	private function run_scan( array $args ) {
		if ( function_exists( 'set_time_limit' ) ) {
			// Long cookie scans may exceed default max_execution_time on shared hosts.
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit, WordPress.PHP.NoSilencedErrors.Discouraged -- scan needs extended runtime; @ suppresses when disabled by host.
			@set_time_limit( 60 );
		}

		$url_defs = array();
		if ( ! empty( $args['urls'] ) ) {
			$url_defs = $args['urls'];
		} elseif ( empty( $args['live_only'] ) ) {
			$url_defs = $this->get_default_scan_urls( isset( $args['limit'] ) ? (int) $args['limit'] : self::MAX_SERVER_URLS );
		}

		$include_auth = ! empty( $args['include_auth'] );
		$live_cookies = isset( $args['live_cookies'] ) && is_array( $args['live_cookies'] ) ? $args['live_cookies'] : array();

		$results       = array();
		$cookies_found = array();
		$unknown       = array();
		$seen_services = array();
		$patterns      = Script_Registry::instance()->get_all_patterns();
		$cf_challenged = false;

		$max_urls   = isset( $args['max_urls'] ) ? max( 1, min( self::MAX_SERVER_URLS, (int) $args['max_urls'] ) ) : self::MAX_SERVER_URLS;
		$normalized = array();
		$auth_added = false;

		foreach ( array_slice( $url_defs, 0, $max_urls ) as $def ) {
			if ( is_string( $def ) ) {
				$def = array(
					'url'     => $def,
					'context' => 'guest',
					'label'   => $def,
				);
			}
			$normalized[] = $def;

			// Auth pass once on homepage only — never double every URL.
			if ( $include_auth && ! $auth_added ) {
				$auth_def            = $def;
				$auth_def['context'] = 'logged_in';
				$auth_def['label']   = sprintf(
					/* translators: %s: page label */
					__( '%s (logged in)', 'universal-consent-privacy-framework' ),
					isset( $def['label'] ) ? $def['label'] : $def['url']
				);
				$normalized[] = $auth_def;
				$auth_added   = true;
			}
		}

		foreach ( $normalized as $def ) {
			$url     = esc_url_raw( $def['url'] );
			$context = isset( $def['context'] ) ? sanitize_key( $def['context'] ) : 'guest';
			$fetched = $this->fetch_page( $url, 'logged_in' === $context );

			if ( is_wp_error( $fetched ) ) {
				continue;
			}

			if ( ! empty( $fetched['cf_challenged'] ) ) {
				$cf_challenged = true;
			}

			$html = $fetched['body'];
			// Skip script pattern matching on Cloudflare interstitials (false positives).
			if ( ! empty( $fetched['cf_challenged'] ) ) {
				foreach ( $fetched['cookies'] as $cookie_name ) {
					$this->classify_cookie_name( $cookie_name, $url, $context, 'header', $cookies_found, $unknown );
				}
				continue;
			}

			foreach ( $patterns as $entry ) {
				if ( false === stripos( $html, $entry['pattern'] ) ) {
					continue;
				}
				$service_key = $entry['service'];
				// Skip vague HTML substring hits unless the matching plugin is active.
				if ( $this->is_ambiguous_script_pattern( $entry['pattern'] ) && ! $this->service_has_active_plugin( $service_key ) ) {
					continue;
				}
				if ( isset( $seen_services[ $service_key . '|' . $context . '|' . $entry['pattern'] ] ) ) {
					continue;
				}
				$seen_services[ $service_key . '|' . $context . '|' . $entry['pattern'] ] = true;

				$service   = Script_Registry::instance()->get_service( $service_key );
				$results[] = array(
					'type'               => 'script',
					'service'            => $service_key,
					'service_name'       => $service ? $service['name'] : $service_key,
					'pattern'            => $entry['pattern'],
					'suggested_category' => $entry['category'],
					'treatment'          => isset( $entry['treatment'] ) ? $entry['treatment'] : 'consent',
					'confidence'         => $entry['confidence'],
					'blocking_status'    => Script_Registry::instance()->should_block_service( $service_key ) ? 'blocked' : 'allowed',
					'suggested_action'   => __( 'Register and apply treatment until consent where required.', 'universal-consent-privacy-framework' ),
					'page_url'           => $url,
					'context'            => $context,
				);
			}

			foreach ( $fetched['cookies'] as $cookie_name ) {
				$this->classify_cookie_name( $cookie_name, $url, $context, 'header', $cookies_found, $unknown );
			}
		}

		if ( empty( $args['live_only'] ) ) {
			foreach ( $this->scan_options_for_ids() as $finding ) {
				$results[] = $finding;
			}

			foreach ( $this->scan_active_plugins() as $finding ) {
				$results[] = $finding;
			}
		}

		foreach ( $live_cookies as $cookie_name ) {
			$cookie_name = sanitize_text_field( $cookie_name );
			if ( '' === $cookie_name ) {
				continue;
			}
			$live_context = ! empty( $args['live_context'] ) ? sanitize_key( $args['live_context'] ) : 'guest';
			$this->classify_cookie_name( $cookie_name, home_url( '/' ), $live_context, 'guest_crawl' === $live_context || 'guest' === $live_context ? 'guest_crawl' : 'live', $cookies_found, $unknown );
		}

		$cookies_found = $this->dedupe_cookies( $cookies_found );
		$unknown       = $this->dedupe_unknown( $unknown );
		$detected_keys = array();
		foreach ( $results as $row ) {
			if ( ! empty( $row['service'] ) ) {
				$detected_keys[ $row['service'] ] = true;
			}
		}
		foreach ( $cookies_found as $row ) {
			if ( ! empty( $row['service'] ) ) {
				$detected_keys[ $row['service'] ] = true;
			}
		}

		$payload = array(
			'date'              => current_time( 'mysql' ),
			'results'           => $results,
			'cookies'           => $cookies_found,
			'unknown_cookies'   => $unknown,
			'detected_services' => array_keys( $detected_keys ),
			'scanned_urls'      => count( $normalized ),
			'cf_challenged'     => $cf_challenged,
		);

		if ( empty( $args['skip_persist'] ) ) {
			$payload = $this->persist_scan_payload( $payload );
		}

		$notice = __( 'Scanner is a helper tool only and does not guarantee legal compliance.', 'universal-consent-privacy-framework' );
		if ( $cf_challenged ) {
			$notice = __( 'Cloudflare challenge page detected on a server fetch. Guest browser crawl still runs in your browser (usually allowed). If cookies look incomplete, allowlist this origin IP in Cloudflare or rely on the browser crawl.', 'universal-consent-privacy-framework' );
		}

		return array(
			'scanned_at'        => $payload['date'],
			'results'           => $results,
			'cookies'           => $cookies_found,
			'unknown_cookies'   => $unknown,
			'detected_services' => array_keys( $detected_keys ),
			'scanned_urls'      => $payload['scanned_urls'],
			'cf_challenged'     => $cf_challenged,
			'notice'            => $notice,
			'pages_refreshed'   => ! empty( $payload['_pages_refreshed'] ),
		);
	}

	/**
	 * Classify a cookie name into known or unknown.
	 *
	 * @param string $cookie_name Name.
	 * @param string $url         Source URL.
	 * @param string $context     Context.
	 * @param string $source      Source type.
	 * @param array  $cookies     Known list (by ref).
	 * @param array  $unknown     Unknown list (by ref).
	 */
	private function classify_cookie_name( $cookie_name, $url, $context, $source, array &$cookies, array &$unknown ) {
		$cookie_name = (string) $cookie_name;
		if ( '' === $cookie_name || Scan_Noise_Filter::should_omit_cookie( $cookie_name ) ) {
			return;
		}
		$match = Script_Registry::instance()->match_cookie_name( $cookie_name );
		if ( ! $match ) {
			$match = $this->match_private_cookie_default( $cookie_name );
		}
		if ( $match ) {
			$service   = Script_Registry::instance()->get_service( $match['service'] );
			$cookies[] = $this->format_cookie_finding( $match, $service ? $service : array( 'name' => isset( $match['service_name'] ) ? $match['service_name'] : $match['service'] ), $url, $context, $source, $cookie_name );
			return;
		}
		$unknown[] = array(
			'name'      => $cookie_name,
			'page_url'  => $url,
			'context'   => $context,
			'source'    => $source,
			'status'    => 'needs_review',
			'treatment' => 'consent',
			'category'  => 'analytics',
		);
	}

	/**
	 * Match cookie against private/agency defaults.
	 *
	 * @param string $cookie_name Name.
	 * @return array|null
	 */
	private function match_private_cookie_default( $cookie_name ) {
		$registry = Script_Registry::instance();
		foreach ( $this->get_private_cookie_defaults() as $row ) {
			$pattern = isset( $row['pattern'] ) ? $row['pattern'] : ( isset( $row['name'] ) ? $row['name'] : '' );
			if ( ! $pattern || ! $registry->cookie_name_matches( $cookie_name, $pattern ) ) {
				continue;
			}
			return $row;
		}
		return null;
	}

	/**
	 * Format cookie finding row.
	 *
	 * @param array       $cookie  Cookie def.
	 * @param array       $service Service.
	 * @param string      $url     URL.
	 * @param string      $context Context.
	 * @param string      $source  Source.
	 * @param string|null $actual  Actual name if different from pattern.
	 * @return array
	 */
	private function format_cookie_finding( array $cookie, array $service, $url, $context, $source, $actual = null ) {
		return array(
			'name'         => $actual ? $actual : $cookie['name'],
			'pattern'      => isset( $cookie['pattern'] ) ? $cookie['pattern'] : $cookie['name'],
			'purpose'      => isset( $cookie['purpose'] ) ? $cookie['purpose'] : '',
			'retention'    => isset( $cookie['retention'] ) ? $cookie['retention'] : '',
			'category'     => isset( $cookie['category'] ) ? $cookie['category'] : ( isset( $service['category'] ) ? $service['category'] : 'analytics' ),
			'treatment'    => isset( $cookie['treatment'] ) ? $cookie['treatment'] : ( isset( $service['treatment'] ) ? $service['treatment'] : 'consent' ),
			'service'      => isset( $cookie['service'] ) ? $cookie['service'] : ( isset( $service['key'] ) ? $service['key'] : '' ),
			'service_name' => isset( $service['name'] ) ? $service['name'] : '',
			'provider'     => isset( $service['provider'] ) ? $service['provider'] : '',
			'page_url'     => $url,
			'context'      => $context,
			'source'       => $source,
		);
	}

	/**
	 * Dedupe cookie findings by name+service.
	 *
	 * @param array $cookies Cookies.
	 * @return array
	 */
	private function dedupe_cookies( array $cookies ) {
		$out  = array();
		$seen = array();
		foreach ( $cookies as $cookie ) {
			$key = strtolower( $cookie['name'] . '|' . $cookie['service'] );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $cookie;
		}
		return $out;
	}

	/**
	 * Dedupe unknown cookies by name.
	 *
	 * @param array $unknown Unknown.
	 * @return array
	 */
	private function dedupe_unknown( array $unknown ) {
		$out  = array();
		$seen = array();
		foreach ( $unknown as $row ) {
			$key = strtolower( $row['name'] );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $row;
		}
		return $out;
	}

	/**
	 * Fetch page HTML + Set-Cookie names in one request.
	 *
	 * Uses a browser-like UA: agency sites behind Cloudflare often challenge
	 * custom bots. Origin/server IP is usually allowed, but public-URL loopback
	 * still hits CF — stay polite and detect challenge pages.
	 *
	 * @param string $url          URL.
	 * @param bool   $as_logged_in Use current admin cookies.
	 * @return array|\WP_Error { body: string, cookies: string[], cf_challenged?: bool }
	 */
	private function fetch_page( $url, $as_logged_in = false ) {
		$args = array(
			'timeout'     => self::FETCH_TIMEOUT,
			'redirection' => 2,
			'sslverify'   => true,
			'headers'     => array(
				// Browser-like UA reduces Cloudflare Bot Fight false positives on loopback.
				'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 UCPF-Scanner/' . UCPF_VERSION,
				'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language' => 'en-US,en;q=0.9',
			),
		);

		if ( $as_logged_in && is_user_logged_in() ) {
			$cookies = array();
			foreach ( $_COOKIE as $name => $value ) {
				// Forward WP session + Cloudflare clearance so challenge pages resolve when scanning as logged-in.
				if (
					0 === strpos( $name, 'wordpress_' )
					|| 0 === strpos( $name, 'wp-' )
					|| 0 === strpos( $name, 'woocommerce' )
					|| 0 === strpos( $name, 'wp_woocommerce' )
					|| 0 === strpos( $name, '__cf' )
					|| 0 === strpos( $name, 'cf_' )
					|| '_cfuvid' === $name
				) {
					$cookies[] = new \WP_Http_Cookie(
						array(
							'name'  => $name,
							'value' => $value,
						)
					);
				}
			}
			if ( $cookies ) {
				$args['cookies'] = $cookies;
			}
		}

		/**
		 * Filter HTTP args for scanner page fetches (Cloudflare / WAF tuning).
		 *
		 * @param array  $args Args for wp_remote_get.
		 * @param string $url  URL.
		 * @param bool   $as_logged_in Logged-in pass.
		 */
		$args = apply_filters( 'ucpf_scanner_fetch_args', $args, $url, $as_logged_in );

		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 500 ) {
			return new \WP_Error( 'ucpf_scan_http', 'HTTP ' . $code );
		}

		$names   = array();
		$headers = wp_remote_retrieve_headers( $response );
		$raw     = array();
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$all = $headers->getAll();
			if ( ! empty( $all['set-cookie'] ) ) {
				$raw = (array) $all['set-cookie'];
			}
		} elseif ( is_array( $headers ) && ! empty( $headers['set-cookie'] ) ) {
			$raw = (array) $headers['set-cookie'];
		}

		foreach ( $raw as $header ) {
			if ( is_array( $header ) ) {
				foreach ( $header as $line ) {
					if ( preg_match( '/^([^=;\s]+)=/', (string) $line, $m ) ) {
						$names[] = $m[1];
					}
				}
			} elseif ( preg_match( '/^([^=;\s]+)=/', (string) $header, $m ) ) {
				$names[] = $m[1];
			}
		}

		$body         = (string) wp_remote_retrieve_body( $response );
		$cf_challenged = $this->html_looks_like_cloudflare_challenge( $body, $code );

		return array(
			'body'          => $body,
			'cookies'       => array_values( array_unique( $names ) ),
			'cf_challenged' => $cf_challenged,
			'http_code'     => $code,
		);
	}

	/**
	 * Detect Cloudflare challenge / block interstitial HTML.
	 *
	 * @param string $html HTML body.
	 * @param int    $code HTTP status.
	 * @return bool
	 */
	private function html_looks_like_cloudflare_challenge( $html, $code ) {
		if ( 403 === (int) $code || 503 === (int) $code ) {
			if ( false !== stripos( $html, 'cloudflare' ) || false !== stripos( $html, 'cf-ray' ) ) {
				return true;
			}
		}
		$needles = array(
			'cf-browser-verification',
			'cf-challenge',
			'challenge-platform',
			'cdn-cgi/challenge',
			'just a moment',
			'attention required! | cloudflare',
			'enable javascript and cookies to continue',
		);
		$html_l = strtolower( (string) $html );
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $html_l, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Detect known services from installed/active plugins via plugin-map.json.
	 *
	 * @return array
	 */
	private function scan_active_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$meta    = $this->get_plugin_map_meta();
		$map     = isset( $meta['map'] ) && is_array( $meta['map'] ) ? $meta['map'] : array();
		$hints   = isset( $meta['slug_hints'] ) && is_array( $meta['slug_hints'] ) ? $meta['slug_hints'] : array();
		$exclude = isset( $meta['exclude_slugs'] ) && is_array( $meta['exclude_slugs'] ) ? $meta['exclude_slugs'] : array();

		$findings = array();
		$seen     = array();

		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$active = array_fill_keys( array_map( 'strval', $active ), true );

		$plugins = get_plugins();
		foreach ( $plugins as $plugin_file => $plugin_data ) {
			$plugin_file = (string) $plugin_file;
			$slug        = dirname( $plugin_file );
			if ( '.' === $slug ) {
				$slug = preg_replace( '/\.php$/', '', $plugin_file );
			}

			if ( $this->is_excluded_plugin_slug( $slug, $exclude ) ) {
				// Rival CMPs — do not surface in scanner inventory.
				continue;
			}

			$key = null;
			if ( ! empty( $map[ $plugin_file ] ) ) {
				$key = $map[ $plugin_file ];
			} else {
				foreach ( $hints as $needle => $service_key ) {
					if ( false !== stripos( $slug, (string) $needle ) || false !== stripos( $plugin_file, (string) $needle ) ) {
						$key = $service_key;
						break;
					}
				}
			}

			if ( ! $key || isset( $seen[ $key . '|' . $plugin_file ] ) ) {
				continue;
			}

			// Active plugins only for inventory confidence — skip inactive noise (e.g. Woo).
			$is_active = isset( $active[ $plugin_file ] );
			if ( ! $is_active ) {
				continue;
			}

			$seen[ $key . '|' . $plugin_file ] = true;

			$service    = Script_Registry::instance()->get_service( $key );
			$findings[] = array(
				'type'               => 'plugin',
				'service'            => $key,
				'service_name'       => $service ? $service['name'] : ( isset( $plugin_data['Name'] ) ? $plugin_data['Name'] : $key ),
				'pattern'            => $plugin_file,
				'suggested_category' => $service ? $service['category'] : 'functional',
				'treatment'          => $service && isset( $service['treatment'] ) ? $service['treatment'] : 'consent',
				'confidence'         => 'high',
				'blocking_status'    => Script_Registry::instance()->should_block_service( $key ) ? 'blocked' : 'allowed',
				'suggested_action'   => __( 'Active plugin detected on this site.', 'universal-consent-privacy-framework' ),
				'page_url'           => 'installed_plugins',
				'context'            => 'active',
			);
		}

		return $findings;
	}

	/**
	 * Load plugin-map.json metadata.
	 *
	 * @return array
	 */
	private function get_plugin_map_meta() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		$path = UCPF_PLUGIN_DIR . 'assets/vendor-catalog/plugin-map.json';
		$cached = array(
			'map'           => array(),
			'slug_hints'    => array(),
			'exclude_slugs' => array(),
			'option_keys'   => array(),
		);
		if ( ! is_readable( $path ) ) {
			return $cached;
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			return $cached;
		}
		foreach ( array( 'map', 'slug_hints', 'exclude_slugs', 'option_keys' ) as $field ) {
			if ( ! empty( $data[ $field ] ) && is_array( $data[ $field ] ) ) {
				$cached[ $field ] = $data[ $field ];
			}
		}
		return $cached;
	}

	/**
	 * Whether a script pattern is too ambiguous for HTML substring matching alone.
	 *
	 * @param string $pattern Pattern.
	 * @return bool
	 */
	private function is_ambiguous_script_pattern( $pattern ) {
		$pattern = strtolower( (string) $pattern );
		$ambiguous = array(
			'woocommerce',
			'jetpack',
			'wpforms',
			'forminator',
			'themepunch',
			'pixelyoursite',
			'tawk.to',
			'elementor',
		);
		if ( in_array( $pattern, $ambiguous, true ) ) {
			return true;
		}
		// Short tokens without a path or domain separator are noisy.
		if ( strlen( $pattern ) < 10 && false === strpos( $pattern, '/' ) && false === strpos( $pattern, '.' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Whether any active plugin maps to this service key.
	 *
	 * @param string $service_key Service key.
	 * @return bool
	 */
	private function service_has_active_plugin( $service_key ) {
		$service_key = sanitize_key( $service_key );
		if ( '' === $service_key ) {
			return false;
		}
		static $active_services = null;
		if ( null === $active_services ) {
			$active_services = array();
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$meta    = $this->get_plugin_map_meta();
			$map     = isset( $meta['map'] ) && is_array( $meta['map'] ) ? $meta['map'] : array();
			$hints   = isset( $meta['slug_hints'] ) && is_array( $meta['slug_hints'] ) ? $meta['slug_hints'] : array();
			$active  = (array) get_option( 'active_plugins', array() );
			if ( is_multisite() ) {
				$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
			}
			foreach ( $active as $plugin_file ) {
				$plugin_file = (string) $plugin_file;
				if ( ! empty( $map[ $plugin_file ] ) ) {
					$active_services[ $map[ $plugin_file ] ] = true;
					continue;
				}
				$slug = dirname( $plugin_file );
				if ( '.' === $slug ) {
					$slug = preg_replace( '/\.php$/', '', $plugin_file );
				}
				foreach ( $hints as $needle => $key ) {
					if ( false !== stripos( $slug, (string) $needle ) || false !== stripos( $plugin_file, (string) $needle ) ) {
						$active_services[ $key ] = true;
						break;
					}
				}
			}
		}
		return ! empty( $active_services[ $service_key ] );
	}

	/**
	 * Whether a plugin slug is a rival consent tool to ignore.
	 *
	 * @param string $slug    Plugin folder slug.
	 * @param array  $exclude Exclude needles.
	 * @return bool
	 */
	private function is_excluded_plugin_slug( $slug, array $exclude ) {
		$slug = strtolower( (string) $slug );
		foreach ( $exclude as $needle ) {
			$needle = strtolower( (string) $needle );
			if ( '' !== $needle && false !== strpos( $slug, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Scan known option keys for tracking IDs (avoid wp_load_alloptions on large sites).
	 *
	 * @return array
	 */
	private function scan_options_for_ids() {
		$findings = array();
		$meta     = $this->get_plugin_map_meta();
		$keys     = ! empty( $meta['option_keys'] ) ? $meta['option_keys'] : array(
			'ucpf_settings',
			'google_analytics_4',
			'gtm4wp-options',
		);

		/**
		 * Option names to inspect for tracking IDs.
		 *
		 * @param array $keys Option keys.
		 */
		$keys = apply_filters( 'ucpf_scan_option_keys', $keys );

		$needles = array(
			'G-'              => array( 'service' => 'google_analytics_4', 'category' => 'analytics' ),
			'GT-'             => array( 'service' => 'google_analytics_4', 'category' => 'analytics' ),
			'GTM-'            => array( 'service' => 'google_tag_manager', 'category' => 'analytics' ),
			'UA-'             => array( 'service' => 'google_analytics_4', 'category' => 'analytics' ),
			'fbq'             => array( 'service' => 'meta_pixel', 'category' => 'marketing' ),
			'facebook.com'    => array( 'service' => 'meta_pixel', 'category' => 'marketing' ),
			'clarity.ms'      => array( 'service' => 'microsoft_clarity', 'category' => 'analytics' ),
			'hotjar'          => array( 'service' => 'hotjar', 'category' => 'analytics' ),
			'klaviyo'         => array( 'service' => 'klaviyo', 'category' => 'marketing' ),
			'mailchimp'       => array( 'service' => 'mailchimp', 'category' => 'marketing' ),
			'tawk.to'         => array( 'service' => 'tawkto', 'category' => 'functional' ),
			'googletagmanager'=> array( 'service' => 'google_tag_manager', 'category' => 'analytics' ),
		);

		$seen = array();
		foreach ( $keys as $name ) {
			$value = get_option( $name );
			if ( is_array( $value ) || is_object( $value ) ) {
				$value = wp_json_encode( $value );
			}
			if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 100000 ) {
				continue;
			}
			foreach ( $needles as $needle => $meta_row ) {
				$dedupe = $meta_row['service'] . '|' . $needle;
				if ( isset( $seen[ $dedupe ] ) ) {
					continue;
				}
				if ( false === stripos( $value, $needle ) ) {
					continue;
				}
				// Skip vague option hits when the related plugin is not active.
				$specific = in_array( $needle, array( 'G-', 'GT-', 'GTM-', 'UA-' ), true );
				if ( ! $specific && ! $this->service_has_active_plugin( $meta_row['service'] ) ) {
					continue;
				}
				$seen[ $dedupe ] = true;
				$findings[]      = array(
					'type'               => 'option',
					'service'            => $meta_row['service'],
					'service_name'       => $meta_row['service'],
					'pattern'            => $needle,
					'suggested_category' => $meta_row['category'],
					'treatment'          => 'consent',
					'confidence'         => $specific ? 'medium' : 'low',
					'blocking_status'    => 'unknown',
					'suggested_action'   => sprintf(
						/* translators: %s: option name */
						__( 'Found in option: %s', 'universal-consent-privacy-framework' ),
						$name
					),
					'page_url'           => 'wp_options',
					'context'            => 'admin',
				);
			}
		}

		return $findings;
	}

	/**
	 * Persist scan results (transient + durable option) and refresh Cookie Policy when enabled.
	 *
	 * @param array $payload Scan payload.
	 * @return array Payload (may include _pages_refreshed).
	 */
	public function persist_scan_payload( array $payload ) {
		unset( $payload['_pages_refreshed'] );

		set_transient( 'ucpf_last_scan', $payload, WEEK_IN_SECONDS );
		update_option( 'ucpf_last_scan', $payload, false );

		if ( isset( $payload['unknown_cookies'] ) ) {
			update_option( 'ucpf_unknown_cookies', $payload['unknown_cookies'], false );
		}
		if ( isset( $payload['detected_services'] ) ) {
			update_option( 'ucpf_detected_services', $payload['detected_services'], false );
		}

		/**
		 * Fires after a scan (or live capture merge) is stored.
		 *
		 * @param array $payload Scan payload.
		 */
		do_action( 'ucpf_scan_completed', $payload );

		$refreshed = false;
		if ( Settings::get( 'auto_refresh_cookie_policy_after_scan', true ) ) {
			$refreshed = Page_Generator::instance()->refresh_cookie_policy_page();
			Page_Generator::instance()->refresh_privacy_policy_page();
		}
		if ( $refreshed ) {
			$payload['_pages_refreshed'] = true;
		}

		return $payload;
	}

	/**
	 * Build export payload for catalog merge (agency workflow).
	 *
	 * @return array
	 */
	public function get_catalog_export() {
		$scan = $this->get_last_scan();
		$plugins = array();
		$excluded = array();
		if ( ! empty( $scan['results'] ) && is_array( $scan['results'] ) ) {
			foreach ( $scan['results'] as $row ) {
				if ( empty( $row['type'] ) ) {
					continue;
				}
				if ( 'plugin_excluded' === $row['type'] ) {
					$excluded[] = $row;
				} elseif ( 'plugin' === $row['type'] || 'option' === $row['type'] ) {
					$plugins[] = $row;
				}
			}
		}

		return array(
			'exported_at'        => current_time( 'mysql' ),
			'site_url'           => home_url( '/' ),
			'plugin_version'     => defined( 'UCPF_VERSION' ) ? UCPF_VERSION : '',
			'scan_date'          => isset( $scan['date'] ) ? $scan['date'] : '',
			'cookies'            => isset( $scan['cookies'] ) ? $scan['cookies'] : array(),
			'unknown_cookies'    => isset( $scan['unknown_cookies'] ) ? $scan['unknown_cookies'] : array(),
			'detected_services'  => isset( $scan['detected_services'] ) ? $scan['detected_services'] : array(),
			'detected_plugins'   => $plugins,
			'excluded_plugins'   => $excluded,
			'script_matches'     => isset( $scan['results'] ) ? $scan['results'] : array(),
			'suggested_services' => Catalog_Suggestions::compute(),
			'note'               => 'Merge unknowns into assets/vendor-catalog after review. Do not scrape CookieDatabase.org.',
		);
	}

	/**
	 * Get last scan results (durable option fallback when transient expires).
	 *
	 * @return array
	 */
	public function get_last_scan() {
		$data = get_transient( 'ucpf_last_scan' );
		if ( ! is_array( $data ) || empty( $data ) ) {
			$data = get_option( 'ucpf_last_scan', array() );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Get site cookie display overrides (keyed by lowercase cookie name).
	 * Merges legacy `cookie_overrides` into `cookie_display_overrides`.
	 *
	 * @return array
	 */
	public static function get_display_overrides() {
		$primary = Settings::get( 'cookie_display_overrides', array() );
		$legacy  = Settings::get( 'cookie_overrides', array() );
		if ( ! is_array( $primary ) ) {
			$primary = array();
		}
		if ( ! is_array( $legacy ) ) {
			$legacy = array();
		}
		$out = array();
		foreach ( array_merge( $legacy, $primary ) as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$k = strtolower( sanitize_text_field( is_string( $key ) ? $key : ( isset( $row['name'] ) ? $row['name'] : '' ) ) );
			if ( '' === $k ) {
				continue;
			}
			$out[ $k ] = self::sanitize_display_override( $row );
		}
		return $out;
	}

	/**
	 * Sanitize one display override row.
	 *
	 * @param array $row Raw.
	 * @return array
	 */
	public static function sanitize_display_override( array $row ) {
		$vis = isset( $row['visibility'] ) ? sanitize_key( $row['visibility'] ) : 'show';
		if ( ! in_array( $vis, array( 'show', 'hide', 'document_only' ), true ) ) {
			$vis = 'show';
		}
		$treatment = isset( $row['treatment'] ) ? sanitize_key( $row['treatment'] ) : '';
		if ( $treatment && ! in_array( $treatment, array( 'necessary', 'consent', 'ignore' ), true ) ) {
			$treatment = '';
		}
		return array(
			'label'      => isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '',
			'purpose'    => isset( $row['purpose'] ) ? sanitize_textarea_field( $row['purpose'] ) : '',
			'visibility' => $vis,
			'category'   => isset( $row['category'] ) ? sanitize_key( $row['category'] ) : '',
			'treatment'  => $treatment,
		);
	}

	/**
	 * Save one cookie display override.
	 *
	 * @param string $name Cookie name.
	 * @param array  $fields Fields.
	 * @return bool
	 */
	public static function save_display_override( $name, array $fields ) {
		$name = strtolower( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			return false;
		}
		$all = self::get_display_overrides();
		$all[ $name ] = self::sanitize_display_override( $fields );
		// Drop empty overrides that only have defaults.
		$o = $all[ $name ];
		if ( '' === $o['label'] && '' === $o['purpose'] && 'show' === $o['visibility'] && '' === $o['category'] && '' === $o['treatment'] ) {
			unset( $all[ $name ] );
		}
		Settings::update(
			array(
				'cookie_display_overrides' => $all,
				'cookie_overrides'         => $all, // keep legacy key in sync
			)
		);
		return true;
	}

	/**
	 * Batch save display overrides.
	 *
	 * @param array $map name => fields.
	 * @return int Count saved.
	 */
	public static function save_display_overrides_batch( array $map ) {
		$all = self::get_display_overrides();
		$n   = 0;
		foreach ( $map as $name => $fields ) {
			if ( ! is_array( $fields ) ) {
				continue;
			}
			$key = strtolower( sanitize_text_field( is_string( $name ) ? $name : ( isset( $fields['name'] ) ? $fields['name'] : '' ) ) );
			if ( '' === $key ) {
				continue;
			}
			$all[ $key ] = self::sanitize_display_override( $fields );
			$o           = $all[ $key ];
			if ( '' === $o['label'] && '' === $o['purpose'] && 'show' === $o['visibility'] && '' === $o['category'] && '' === $o['treatment'] ) {
				unset( $all[ $key ] );
			}
			++$n;
		}
		Settings::update(
			array(
				'cookie_display_overrides' => $all,
				'cookie_overrides'         => $all,
			)
		);
		return $n;
	}

	/**
	 * Refresh Cookie + Privacy Policy pages after review (when auto-refresh enabled).
	 *
	 * @return void
	 */
	public static function refresh_policy_pages_after_review() {
		if ( ! Settings::get( 'auto_refresh_cookie_policy_after_scan', true ) ) {
			return;
		}
		Page_Generator::instance()->refresh_cookie_policy_page();
		Page_Generator::instance()->refresh_privacy_policy_page();
	}

	/**
	 * Human consent column label for public tables.
	 *
	 * @param string $treatment Treatment.
	 * @param string $category  Category.
	 * @param string $visibility Visibility.
	 * @return string
	 */
	public static function consent_column_label( $treatment, $category, $visibility = 'show' ) {
		$treatment  = sanitize_key( (string) $treatment );
		$category   = sanitize_key( (string) $category );
		$visibility = sanitize_key( (string) $visibility );
		if ( 'hide' === $visibility ) {
			return __( 'Hidden', 'universal-consent-privacy-framework' );
		}
		if ( 'document_only' === $visibility || 'ignore' === $treatment ) {
			return __( 'Documented only (not gated)', 'universal-consent-privacy-framework' );
		}
		if ( 'necessary' === $treatment || 'necessary' === $category ) {
			return __( 'Essential', 'universal-consent-privacy-framework' );
		}
		return __( 'Optional (consent)', 'universal-consent-privacy-framework' );
	}

	/**
	 * Build a visitor-facing Cookie Policy inventory from the latest scan (deduped + enriched).
	 *
	 * @return array{date:string,cookies:array,storage:array,technologies:array,categories:array}
	 */
	public function get_policy_inventory() {
		$scan       = $this->get_last_scan();
		$categories = Consent_Manager::instance()->get_categories();
		$registry   = Script_Registry::instance();
		$overrides  = self::get_display_overrides();

		$by_name = array();
		$rows    = array();
		if ( ! empty( $scan['cookies'] ) && is_array( $scan['cookies'] ) ) {
			$rows = array_merge( $rows, $scan['cookies'] );
		}
		if ( ! empty( $scan['unknown_cookies'] ) && is_array( $scan['unknown_cookies'] ) ) {
			foreach ( $scan['unknown_cookies'] as $unknown ) {
				if ( ! is_array( $unknown ) || empty( $unknown['name'] ) ) {
					continue;
				}
				$rows[] = array(
					'name'         => $unknown['name'],
					'service_name' => ! empty( $unknown['provider'] ) ? $unknown['provider'] : __( 'Pending review', 'universal-consent-privacy-framework' ),
					'provider'     => isset( $unknown['provider'] ) ? $unknown['provider'] : '',
					'category'     => ! empty( $unknown['category'] ) ? $unknown['category'] : '',
					'purpose'      => __( 'Detected on this site; category assignment pending site review.', 'universal-consent-privacy-framework' ),
					'retention'    => '',
					'treatment'    => isset( $unknown['treatment'] ) ? $unknown['treatment'] : 'consent',
					'context'      => isset( $unknown['context'] ) ? $unknown['context'] : '',
				);
			}
		}

		foreach ( $rows as $cookie ) {
			if ( ! is_array( $cookie ) || empty( $cookie['name'] ) ) {
				continue;
			}
			$name = (string) $cookie['name'];
			// Keep Cookie Policy / public inventory free of lockout & logged-in noise.
			if ( Scan_Noise_Filter::should_omit_cookie( $name ) ) {
				continue;
			}
			$key  = strtolower( $name );
			$ov   = isset( $overrides[ $key ] ) ? $overrides[ $key ] : array();

			$match   = $registry->match_cookie_name( $name );
			$service = ( $match && ! empty( $match['service'] ) ) ? $registry->get_service( $match['service'] ) : null;

			$category = isset( $cookie['category'] ) ? sanitize_key( $cookie['category'] ) : '';
			if ( ! $category && $match && ! empty( $match['category'] ) ) {
				$category = sanitize_key( $match['category'] );
			}
			// Live service override wins over scan snapshot.
			if ( $service && ! empty( $service['category'] ) ) {
				$category = sanitize_key( (string) $service['category'] );
			}
			if ( ! empty( $ov['category'] ) ) {
				$category = sanitize_key( $ov['category'] );
			}
			if ( ! $category ) {
				$category = 'analytics';
			}

			$treatment = isset( $cookie['treatment'] ) ? sanitize_key( $cookie['treatment'] ) : '';
			if ( $service && ! empty( $service['treatment'] ) ) {
				$treatment = sanitize_key( (string) $service['treatment'] );
			}
			if ( ! empty( $ov['treatment'] ) ) {
				$treatment = sanitize_key( $ov['treatment'] );
			}
			if ( ! $treatment ) {
				$treatment = ( 'necessary' === $category ) ? 'necessary' : 'consent';
			}

			$visibility = ! empty( $ov['visibility'] ) ? $ov['visibility'] : 'show';
			if ( 'hide' === $visibility ) {
				continue;
			}

			$purpose = isset( $cookie['purpose'] ) ? (string) $cookie['purpose'] : '';
			if ( '' === $purpose && $match && ! empty( $match['purpose'] ) ) {
				$purpose = (string) $match['purpose'];
			}
			if ( '' === $purpose && $service && ! empty( $service['description'] ) ) {
				$purpose = (string) $service['description'];
			}
			if ( '' === $purpose ) {
				$purpose = isset( $categories[ $category ]['description'] )
					? (string) $categories[ $category ]['description']
					: __( 'Used for site functionality or optional features subject to your consent choices.', 'universal-consent-privacy-framework' );
			}
			if ( ! empty( $ov['purpose'] ) ) {
				$purpose = (string) $ov['purpose'];
			}

			$retention = isset( $cookie['retention'] ) ? (string) $cookie['retention'] : '';
			if ( '' === $retention && $match && ! empty( $match['retention'] ) ) {
				$retention = (string) $match['retention'];
			}
			if ( '' === $retention ) {
				$retention = __( 'See provider documentation / session or persistent', 'universal-consent-privacy-framework' );
			}

			$service_name = isset( $cookie['service_name'] ) ? (string) $cookie['service_name'] : '';
			if ( '' === $service_name && $service && ! empty( $service['name'] ) ) {
				$service_name = (string) $service['name'];
			}
			if ( '' === $service_name ) {
				$service_name = isset( $cookie['provider'] ) ? (string) $cookie['provider'] : $name;
			}
			$display_label = ! empty( $ov['label'] ) ? (string) $ov['label'] : $service_name;

			$provider = isset( $cookie['provider'] ) ? (string) $cookie['provider'] : '';
			if ( '' === $provider && $service && ! empty( $service['provider'] ) ) {
				$provider = (string) $service['provider'];
			}

			$context = isset( $cookie['context'] ) ? (string) $cookie['context'] : '';
			$cat_label = isset( $categories[ $category ]['label'] ) ? $categories[ $category ]['label'] : $category;

			$document_only = ( 'document_only' === $visibility || 'ignore' === $treatment );
			$consent_required = ! $document_only && ( 'necessary' !== $category && 'necessary' !== $treatment );

			if ( isset( $by_name[ $key ] ) ) {
				// Merge duplicate rows (e.g. same cookie seen in multiple consent sessions).
				$prev = $by_name[ $key ];
				$ctxs = array_filter( array_unique( array_merge(
					$prev['contexts'] ? explode( ',', $prev['contexts'] ) : array(),
					$context ? explode( ',', $context ) : array()
				) ) );
				$by_name[ $key ]['contexts'] = implode( ', ', $ctxs );
				if ( strlen( $purpose ) > strlen( (string) $prev['purpose'] ) && empty( $ov['purpose'] ) ) {
					$by_name[ $key ]['purpose'] = $purpose;
				}
				if ( '' === $prev['provider'] && $provider ) {
					$by_name[ $key ]['provider'] = $provider;
				}
				continue;
			}

			$by_name[ $key ] = array(
				'name'               => $name,
				'display_label'      => $display_label,
				'service_name'       => $service_name,
				'provider'           => $provider,
				'category'           => $category,
				'category_label'     => $cat_label,
				'purpose'            => $purpose,
				'retention'          => $retention,
				'treatment'          => $treatment,
				'visibility'         => $visibility,
				'consent_required'   => $consent_required,
				'consent_label'      => self::consent_column_label( $treatment, $category, $visibility ),
				'document_only'      => $document_only,
				'contexts'           => $context,
				'description_source' => ( $match && ! empty( $match['description_source'] ) ) ? (string) $match['description_source'] : ( isset( $cookie['description_source'] ) ? (string) $cookie['description_source'] : '' ),
				'domain'             => isset( $cookie['domain'] ) ? (string) $cookie['domain'] : '',
				'path'               => isset( $cookie['path'] ) ? (string) $cookie['path'] : '',
				'httpOnly'           => ! empty( $cookie['httpOnly'] ),
				'service_key'        => ( $match && ! empty( $match['service'] ) ) ? (string) $match['service'] : '',
			);
		}

		$cookies = array_values( $by_name );
		usort(
			$cookies,
			static function ( $a, $b ) {
				$order = array(
					'necessary'   => 0,
					'preferences' => 1,
					'functional'  => 2,
					'analytics'   => 3,
					'marketing'   => 4,
					'security'    => 5,
				);
				$ac = isset( $order[ $a['category'] ] ) ? $order[ $a['category'] ] : 9;
				$bc = isset( $order[ $b['category'] ] ) ? $order[ $b['category'] ] : 9;
				if ( $ac !== $bc ) {
					return $ac - $bc;
				}
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		// Browser storage keys from scan.
		$storage = array();
		if ( ! empty( $scan['storage'] ) && is_array( $scan['storage'] ) ) {
			$seen_store = array();
			foreach ( $scan['storage'] as $row ) {
				if ( ! is_array( $row ) || empty( $row['key'] ) ) {
					continue;
				}
				$sk = strtolower( ( isset( $row['kind'] ) ? $row['kind'] : '' ) . '|' . $row['key'] );
				if ( isset( $seen_store[ $sk ] ) ) {
					continue;
				}
				$seen_store[ $sk ] = true;
				$storage[]         = array(
					'kind'     => isset( $row['kind'] ) ? (string) $row['kind'] : 'storage',
					'key'      => (string) $row['key'],
					'contexts' => isset( $row['contexts'] ) && is_array( $row['contexts'] ) ? implode( ', ', $row['contexts'] ) : '',
				);
			}
		}

		// Third-party / plugin technologies from privacy signals (not always cookies).
		$technologies = array();
		$signals      = isset( $scan['privacy_signals'] ) && is_array( $scan['privacy_signals'] ) ? $scan['privacy_signals'] : array();
		$seen_tech    = array();
		foreach ( array( 'scripts', 'beacons', 'pixels', 'iframes', 'requests' ) as $group ) {
			if ( empty( $signals[ $group ] ) || ! is_array( $signals[ $group ] ) ) {
				continue;
			}
			foreach ( $signals[ $group ] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$provider = isset( $row['provider'] ) ? trim( (string) $row['provider'] ) : '';
				$host     = isset( $row['host'] ) ? (string) $row['host'] : ( isset( $row['url'] ) ? (string) $row['url'] : '' );
				if ( '' === $provider && '' === $host ) {
					continue;
				}
				$cat = isset( $row['category'] ) ? sanitize_key( $row['category'] ) : '';
				// Skip pure first-party WordPress/UCPF chrome noise for the public policy.
				if ( in_array( $provider, array( 'WordPress Core', 'UCPF', 'First-party site', 'Hello Elementor theme' ), true ) ) {
					continue;
				}
				if ( 'necessary' === $cat && ( false !== stripos( $provider, 'Elementor' ) || false !== stripos( $provider, 'WordPress' ) ) ) {
					continue;
				}
				$dedupe = strtolower( ( $provider ? $provider : $host ) . '|' . $cat );
				if ( isset( $seen_tech[ $dedupe ] ) ) {
					continue;
				}
				$seen_tech[ $dedupe ] = true;
				$technologies[]       = array(
					'name'           => $provider ? $provider : $host,
					'category'       => $cat,
					'category_label' => ( $cat && isset( $categories[ $cat ]['label'] ) ) ? $categories[ $cat ]['label'] : ( $cat ? $cat : __( 'Unclassified', 'universal-consent-privacy-framework' ) ),
					'type'           => $group,
					'host'           => $host,
					'consent_required' => ( 'necessary' !== $cat && '' !== $cat ),
				);
			}
		}

		usort(
			$technologies,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		$cat_list = array();
		foreach ( $categories as $slug => $cat ) {
			$cat_list[] = array(
				'slug'        => $slug,
				'label'       => isset( $cat['label'] ) ? $cat['label'] : $slug,
				'description' => isset( $cat['description'] ) ? $cat['description'] : '',
				'required'    => ! empty( $cat['required'] ),
			);
		}

		$plugins       = $this->build_policy_plugins( $scan, $registry );
		$destinations  = $this->build_policy_destinations( $technologies, $plugins, $registry );
		$service_keys  = array();
		foreach ( array_merge( $technologies, $plugins ) as $row ) {
			if ( ! empty( $row['service_key'] ) ) {
				$service_keys[ $row['service_key'] ] = true;
			}
		}
		foreach ( $cookies as $c ) {
			if ( ! empty( $c['service_key'] ) ) {
				$service_keys[ $c['service_key'] ] = true;
			}
		}

		return array(
			'date'         => ! empty( $scan['date'] ) ? (string) $scan['date'] : '',
			'cookies'      => $cookies,
			'storage'      => $storage,
			'technologies' => $technologies,
			'categories'   => $cat_list,
			'plugins'      => $plugins,
			'destinations' => $destinations,
			'service_keys' => array_keys( $service_keys ),
		);
	}

	/**
	 * Active / scan-detected plugins for Privacy Policy disclosures.
	 *
	 * @param array           $scan     Last scan.
	 * @param Script_Registry $registry Registry.
	 * @return array
	 */
	private function build_policy_plugins( array $scan, $registry ) {
		$out  = array();
		$seen = array();

		if ( ! empty( $scan['results'] ) && is_array( $scan['results'] ) ) {
			foreach ( $scan['results'] as $row ) {
				if ( ! is_array( $row ) || empty( $row['type'] ) ) {
					continue;
				}
				if ( 'plugin' !== $row['type'] && 'option' !== $row['type'] ) {
					continue;
				}
				$name = isset( $row['service_name'] ) ? (string) $row['service_name'] : '';
				if ( '' === $name ) {
					$name = isset( $row['name'] ) ? (string) $row['name'] : ( isset( $row['plugin'] ) ? (string) $row['plugin'] : '' );
				}
				if ( '' === $name && isset( $row['pattern'] ) ) {
					$name = (string) $row['pattern'];
				}
				if ( '' === $name ) {
					continue;
				}
				$key = strtolower( $name );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$svc_key      = isset( $row['service'] ) ? sanitize_key( (string) $row['service'] ) : '';
				$service      = $svc_key ? $registry->get_service( $svc_key ) : null;
				$out[]        = array(
					'name'           => $name,
					'service_key'    => $svc_key,
					'service_name'   => $service && ! empty( $service['name'] ) ? (string) $service['name'] : $name,
					'provider'       => $service && ! empty( $service['provider'] ) ? (string) $service['provider'] : '',
					'category'       => $service && ! empty( $service['category'] ) ? (string) $service['category'] : ( isset( $row['suggested_category'] ) ? sanitize_key( (string) $row['suggested_category'] ) : ( isset( $row['category'] ) ? sanitize_key( (string) $row['category'] ) : '' ) ),
					'privacy_url'    => $service && ! empty( $service['privacy_url'] ) ? (string) $service['privacy_url'] : '',
					'description'    => $service && ! empty( $service['description'] ) ? (string) $service['description'] : __( 'WordPress plugin that may process visitor or form data as part of site functionality.', 'universal-consent-privacy-framework' ),
				);
			}
		}

		// Always include mapped active plugins even if last scan is stale.
		foreach ( $this->scan_active_plugins() as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}
			$name = isset( $finding['service_name'] ) ? (string) $finding['service_name'] : '';
			if ( '' === $name && isset( $finding['pattern'] ) ) {
				$name = (string) $finding['pattern'];
			}
			if ( '' === $name ) {
				continue;
			}
			$key = strtolower( $name );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$svc_key      = isset( $finding['service'] ) ? sanitize_key( (string) $finding['service'] ) : '';
			$service      = $svc_key ? $registry->get_service( $svc_key ) : null;
			$out[]        = array(
				'name'         => $name,
				'service_key'  => $svc_key,
				'service_name' => $service && ! empty( $service['name'] ) ? (string) $service['name'] : $name,
				'provider'     => $service && ! empty( $service['provider'] ) ? (string) $service['provider'] : '',
				'category'     => $service && ! empty( $service['category'] ) ? (string) $service['category'] : ( isset( $finding['suggested_category'] ) ? sanitize_key( (string) $finding['suggested_category'] ) : '' ),
				'privacy_url'  => $service && ! empty( $service['privacy_url'] ) ? (string) $service['privacy_url'] : '',
				'description'  => $service && ! empty( $service['description'] ) ? (string) $service['description'] : __( 'Active WordPress plugin that may process visitor data.', 'universal-consent-privacy-framework' ),
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $out;
	}

	/**
	 * Third-party destinations (where data may go) for Privacy Policy.
	 *
	 * @param array           $technologies Tech rows.
	 * @param array           $plugins      Plugin rows.
	 * @param Script_Registry $registry     Registry.
	 * @return array
	 */
	private function build_policy_destinations( array $technologies, array $plugins, $registry ) {
		$out  = array();
		$seen = array();

		$push = static function ( $name, $provider, $host, $category, $privacy_url, $purpose ) use ( &$out, &$seen ) {
			$dedupe = strtolower( ( $provider ? $provider : $name ) . '|' . ( $host ? $host : '' ) );
			if ( isset( $seen[ $dedupe ] ) ) {
				return;
			}
			$seen[ $dedupe ] = true;
			$out[]           = array(
				'name'        => $name,
				'provider'    => $provider,
				'host'        => $host,
				'category'    => $category,
				'privacy_url' => $privacy_url,
				'purpose'     => $purpose,
			);
		};

		foreach ( $technologies as $row ) {
			$push(
				isset( $row['name'] ) ? $row['name'] : '',
				isset( $row['name'] ) ? $row['name'] : '',
				isset( $row['host'] ) ? $row['host'] : '',
				isset( $row['category'] ) ? $row['category'] : '',
				'',
				__( 'Scripts, pixels, beacons, or embeds observed during a privacy scan of this website.', 'universal-consent-privacy-framework' )
			);
		}

		foreach ( $plugins as $row ) {
			$push(
				isset( $row['service_name'] ) ? $row['service_name'] : $row['name'],
				isset( $row['provider'] ) ? $row['provider'] : '',
				'',
				isset( $row['category'] ) ? $row['category'] : '',
				isset( $row['privacy_url'] ) ? $row['privacy_url'] : '',
				isset( $row['description'] ) ? $row['description'] : ''
			);
		}

		foreach ( $registry->get_services() as $svc ) {
			if ( empty( $svc['privacy_url'] ) && empty( $svc['script_patterns'] ) ) {
				continue;
			}
			// Only include services that appear blocked-by-default / consent-gated or were detected.
			if ( empty( $svc['default_blocking'] ) && ( empty( $svc['treatment'] ) || 'necessary' === $svc['treatment'] ) ) {
				continue;
			}
			$source = isset( $svc['source'] ) ? $svc['source'] : '';
			if ( ! in_array( $source, array( 'core', 'site_local', 'imported', 'admin', 'remote_metadata' ), true ) ) {
				continue;
			}
			// Prefer services that match observed tech/plugin names to avoid dumping entire catalog.
			$matched = false;
			$lname   = strtolower( isset( $svc['name'] ) ? $svc['name'] : '' );
			$lprov   = strtolower( isset( $svc['provider'] ) ? $svc['provider'] : '' );
			foreach ( $technologies as $t ) {
				$tn = strtolower( isset( $t['name'] ) ? $t['name'] : '' );
				$th = strtolower( isset( $t['host'] ) ? $t['host'] : '' );
				if ( ( $lname && false !== strpos( $tn, $lname ) ) || ( $lprov && false !== strpos( $tn, $lprov ) ) ) {
					$matched = true;
					break;
				}
				foreach ( (array) ( $svc['script_patterns'] ?? array() ) as $pat ) {
					if ( $pat && $th && false !== strpos( $th, strtolower( (string) $pat ) ) ) {
						$matched = true;
						break 2;
					}
				}
			}
			foreach ( $plugins as $p ) {
				if ( ! empty( $p['service_key'] ) && ! empty( $svc['key'] ) && $p['service_key'] === $svc['key'] ) {
					$matched = true;
					break;
				}
			}
			if ( ! $matched && 'site_local' !== $source ) {
				continue;
			}
			$host = '';
			if ( ! empty( $svc['script_patterns'][0] ) ) {
				$host = (string) $svc['script_patterns'][0];
			}
			$push(
				isset( $svc['name'] ) ? $svc['name'] : $svc['key'],
				isset( $svc['provider'] ) ? $svc['provider'] : '',
				$host,
				isset( $svc['category'] ) ? $svc['category'] : '',
				isset( $svc['privacy_url'] ) ? $svc['privacy_url'] : '',
				isset( $svc['description'] ) ? $svc['description'] : ''
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return array_slice( $out, 0, 80 );
	}

	/**
	 * Get unknown cookies pending review.
	 *
	 * @return array
	 */
	public function get_unknown_cookies() {
		$data = get_option( 'ucpf_unknown_cookies', array() );
		if ( ! is_array( $data ) ) {
			return array();
		}
		return Scan_Noise_Filter::filter_cookie_rows( $data );
	}

	/**
	 * Update an unknown cookie treatment after review.
	 * Assigns a real category (never unclassified) and moves it into the known inventory.
	 *
	 * @param string $name   Cookie name.
	 * @param array  $fields Fields.
	 * @return bool
	 */
	public function review_unknown_cookie( $name, array $fields ) {
		$name     = sanitize_text_field( $name );
		$category = isset( $fields['category'] ) ? sanitize_key( $fields['category'] ) : '';
		$allowed  = Privacy_Scan_Importer::assignable_categories();
		if ( ! $name || ! in_array( $category, $allowed, true ) ) {
			return false;
		}

		$treatment = isset( $fields['treatment'] ) ? sanitize_key( $fields['treatment'] ) : 'consent';
		if ( ! in_array( $treatment, array( 'necessary', 'consent', 'ignore' ), true ) ) {
			$treatment = 'necessary' === $category ? 'necessary' : 'consent';
		}

		$unknown  = $this->get_unknown_cookies();
		$promoted = null;
		$remaining = array();
		foreach ( $unknown as $row ) {
			if ( ! is_array( $row ) || ( isset( $row['name'] ) && $row['name'] === $name ) ) {
				if ( is_array( $row ) && isset( $row['name'] ) && $row['name'] === $name ) {
					$promoted = $row;
				}
				continue;
			}
			$remaining[] = $row;
		}

		if ( ! $promoted ) {
			// Also check last scan unknown list.
			$scan = $this->get_last_scan();
			if ( ! empty( $scan['unknown_cookies'] ) && is_array( $scan['unknown_cookies'] ) ) {
				foreach ( $scan['unknown_cookies'] as $row ) {
					if ( is_array( $row ) && isset( $row['name'] ) && $row['name'] === $name ) {
						$promoted = $row;
						break;
					}
				}
			}
		}

		if ( ! $promoted ) {
			$promoted = array( 'name' => $name );
		}

		$match   = Script_Registry::instance()->match_cookie_name( $name );
		$service = $match ? Script_Registry::instance()->get_service( $match['service'] ) : null;
		$known   = array(
			'name'         => $name,
			'pattern'      => $match && ! empty( $match['pattern'] ) ? $match['pattern'] : $name,
			'purpose'      => $match && ! empty( $match['purpose'] ) ? $match['purpose'] : '',
			'retention'    => $match && ! empty( $match['retention'] ) ? $match['retention'] : '',
			'category'     => $category,
			'treatment'    => $treatment,
			'importance'   => 'necessary' === $category ? 'required' : 'non_essential',
			'service'      => $match && ! empty( $match['service'] ) ? $match['service'] : sanitize_key( ! empty( $promoted['provider'] ) ? $promoted['provider'] : $name ),
			'service_name' => $service ? $service['name'] : ( ! empty( $promoted['provider'] ) ? $promoted['provider'] : $name ),
			'provider'     => $service && ! empty( $service['provider'] ) ? $service['provider'] : ( ! empty( $promoted['provider'] ) ? $promoted['provider'] : '' ),
			'page_url'     => ! empty( $promoted['page_url'] ) ? $promoted['page_url'] : home_url( '/' ),
			'context'      => ! empty( $promoted['context'] ) ? $promoted['context'] : 'reviewed',
			'source'       => ! empty( $promoted['source'] ) ? $promoted['source'] : 'manual_review',
			'status'       => 'reviewed',
			'selected'     => true,
			'httpOnly'     => ! empty( $promoted['httpOnly'] ),
			'pre_consent'  => ! empty( $promoted['pre_consent'] ),
			'post_accept'  => ! empty( $promoted['post_accept'] ),
		);

		$scan = $this->get_last_scan();
		if ( ! is_array( $scan ) ) {
			$scan = array();
		}
		$cookies = ! empty( $scan['cookies'] ) && is_array( $scan['cookies'] ) ? $scan['cookies'] : array();
		$replaced = false;
		foreach ( $cookies as $i => $row ) {
			if ( isset( $row['name'] ) && $row['name'] === $name ) {
				$cookies[ $i ] = $known;
				$replaced      = true;
				break;
			}
		}
		if ( ! $replaced ) {
			$cookies[] = $known;
		}

		$scan_unknown = ! empty( $scan['unknown_cookies'] ) && is_array( $scan['unknown_cookies'] ) ? $scan['unknown_cookies'] : array();
		$scan_unknown = array_values(
			array_filter(
				$scan_unknown,
				static function ( $row ) use ( $name ) {
					return ! is_array( $row ) || ! isset( $row['name'] ) || $row['name'] !== $name;
				}
			)
		);

		$scan['cookies']         = $cookies;
		$scan['unknown_cookies'] = ! empty( $remaining ) ? $remaining : $scan_unknown;
		$scan['date']            = current_time( 'mysql' );

		update_option( 'ucpf_unknown_cookies', $scan['unknown_cookies'], false );
		$this->persist_scan_payload( $scan );

		if ( ! empty( $known['service'] ) ) {
			Privacy_Scan_Importer::select_detected_services( array( $known['service'] ) );
		}

		// Persist optional display overrides from review.
		$disp = array(
			'label'      => isset( $fields['label'] ) ? $fields['label'] : '',
			'purpose'    => isset( $fields['purpose'] ) ? $fields['purpose'] : ( isset( $fields['display_purpose'] ) ? $fields['display_purpose'] : '' ),
			'visibility' => isset( $fields['visibility'] ) ? $fields['visibility'] : 'show',
			'category'   => $category,
			'treatment'  => $treatment,
		);
		self::save_display_override( $name, $disp );

		return true;
	}
}
