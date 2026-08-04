<?php
/**
 * Open Cookie Database lookup (bundled offline snapshot).
 *
 * Source: https://github.com/jkwakman/Open-Cookie-Database
 * Attribution only — not a legal compliance determination.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Cookie description database (OCD).
 */
class Cookie_Database {

	/**
	 * Instance.
	 *
	 * @var Cookie_Database|null
	 */
	private static $instance = null;

	/**
	 * Exact name → entry (lowercase keys).
	 *
	 * @var array<string,array>|null
	 */
	private $exact = null;

	/**
	 * Wildcard prefix entries (longest prefix first).
	 *
	 * @var array<int,array>|null
	 */
	private $wildcards = null;

	/**
	 * @return Cookie_Database
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Map OCD category label → UCPF category slug.
	 *
	 * @param string $ocd_category OCD category.
	 * @return string
	 */
	public static function map_category( $ocd_category ) {
		$raw = strtolower( trim( (string) $ocd_category ) );
		$map = array(
			'necessary'       => 'necessary',
			'functional'      => 'preferences',
			'personalization' => 'preferences',
			'analytics'       => 'analytics',
			'marketing'       => 'marketing',
			'security'        => 'security',
		);
		$slug = isset( $map[ $raw ] ) ? $map[ $raw ] : '';
		/**
		 * Filter OCD → UCPF category mapping result.
		 *
		 * @param string $slug         UCPF slug or empty.
		 * @param string $ocd_category Original OCD category.
		 */
		return (string) apply_filters( 'ucpf_ocd_category_map', $slug, $ocd_category );
	}

	/**
	 * Match a cookie name against the bundled Open Cookie Database.
	 *
	 * @param string $cookie_name Cookie name.
	 * @return array|null Synthetic catalog-shaped row or null.
	 */
	public function match( $cookie_name ) {
		$cookie_name = (string) $cookie_name;
		if ( '' === $cookie_name ) {
			return null;
		}

		// Single-/two-char names are too ambiguous (e.g. Magnite "c" vs random `c`).
		$min_len = (int) apply_filters( 'ucpf_ocd_min_cookie_name_length', 3 );
		if ( strlen( $cookie_name ) < max( 2, $min_len ) ) {
			return null;
		}

		$this->ensure_loaded();

		$key = strtolower( $cookie_name );
		$row = isset( $this->exact[ $key ] ) ? $this->exact[ $key ] : null;

		if ( ! $row ) {
			foreach ( $this->wildcards as $wild ) {
				$prefix = isset( $wild['n'] ) ? (string) $wild['n'] : '';
				if ( '' === $prefix ) {
					continue;
				}
				// Ignore ultra-short wildcard prefixes (same ambiguity problem).
				if ( strlen( $prefix ) < max( 2, $min_len ) ) {
					continue;
				}
				if ( 0 === stripos( $cookie_name, $prefix ) ) {
					$row = $wild;
					break;
				}
			}
		}

		if ( ! $row ) {
			return null;
		}

		$ucpf_cat = self::map_category( isset( $row['c'] ) ? $row['c'] : '' );
		$platform = isset( $row['p'] ) ? (string) $row['p'] : '';
		$controller = isset( $row['o'] ) ? (string) $row['o'] : '';

		$result = array(
			'name'         => isset( $row['n'] ) ? (string) $row['n'] : $cookie_name,
			'pattern'      => ! empty( $row['w'] ) ? ( (string) $row['n'] . '*' ) : ( isset( $row['n'] ) ? (string) $row['n'] : $cookie_name ),
			'purpose'      => isset( $row['d'] ) ? (string) $row['d'] : '',
			'retention'    => isset( $row['r'] ) ? (string) $row['r'] : '',
			'category'     => $ucpf_cat,
			'treatment'    => ( 'necessary' === $ucpf_cat ) ? 'necessary' : 'consent',
			'service'      => '',
			'service_name' => $platform ? $platform : ( $controller ? $controller : __( 'Open Cookie Database', 'universal-consent-privacy-framework' ) ),
			'provider'     => $controller ? $controller : $platform,
			'source'       => 'open_cookie_database',
			'description_source' => 'open_cookie_database',
			'ocd_platform' => $platform,
			'ocd_category' => isset( $row['c'] ) ? (string) $row['c'] : '',
		);

		/**
		 * Filter an Open Cookie Database match before use.
		 *
		 * @param array  $result      Match row.
		 * @param string $cookie_name Cookie name queried.
		 * @param array  $row         Raw compact OCD entry.
		 */
		return apply_filters( 'ucpf_open_cookie_database_match', $result, $cookie_name, $row );
	}

	/**
	 * Search OCD by name substring / prefix (offline).
	 *
	 * @param string $query Query (min 2 chars).
	 * @param int    $limit Max results.
	 * @return array[] Catalog-shaped rows.
	 */
	public function search( $query, $limit = 25 ) {
		$query = strtolower( trim( (string) $query ) );
		$limit = max( 1, min( 50, (int) $limit ) );
		if ( strlen( $query ) < 2 ) {
			return array();
		}

		$this->ensure_loaded();
		$hits = array();

		foreach ( $this->exact as $key => $row ) {
			if ( false === strpos( $key, $query ) ) {
				continue;
			}
			$name = isset( $row['n'] ) ? (string) $row['n'] : $key;
			$match = $this->match( $name );
			if ( $match ) {
				$hits[] = $match;
			}
			if ( count( $hits ) >= $limit ) {
				return $hits;
			}
		}

		foreach ( $this->wildcards as $row ) {
			$prefix = isset( $row['n'] ) ? strtolower( (string) $row['n'] ) : '';
			if ( '' === $prefix ) {
				continue;
			}
			if ( false === strpos( $prefix, $query ) && 0 !== strpos( $query, $prefix ) ) {
				continue;
			}
			$match = $this->match( (string) $row['n'] . 'X' );
			if ( $match ) {
				$hits[] = $match;
			}
			if ( count( $hits ) >= $limit ) {
				break;
			}
		}

		return $hits;
	}

	/**
	 * Whether the bundled file is present.
	 *
	 * @return bool
	 */
	public function is_available() {
		return file_exists( $this->data_path() );
	}

	/**
	 * Path to bundled JSON.
	 *
	 * @return string
	 */
	private function data_path() {
		return UCPF_PLUGIN_DIR . 'data/open-cookie-database.min.json';
	}

	/**
	 * Lazy-load indexes.
	 */
	private function ensure_loaded() {
		if ( null !== $this->exact && null !== $this->wildcards ) {
			return;
		}

		$this->exact     = array();
		$this->wildcards = array();

		$path = $this->data_path();
		if ( ! file_exists( $path ) ) {
			return;
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw || '' === $raw ) {
			return;
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['cookies'] ) || ! is_array( $data['cookies'] ) ) {
			return;
		}

		$wild = array();
		foreach ( $data['cookies'] as $row ) {
			if ( ! is_array( $row ) || empty( $row['n'] ) ) {
				continue;
			}
			$name = (string) $row['n'];
			if ( ! empty( $row['w'] ) ) {
				$wild[] = $row;
			} else {
				$this->exact[ strtolower( $name ) ] = $row;
			}
		}

		usort(
			$wild,
			static function ( $a, $b ) {
				return strlen( (string) $b['n'] ) - strlen( (string) $a['n'] );
			}
		);
		$this->wildcards = $wild;
	}
}
