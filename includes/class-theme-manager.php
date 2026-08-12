<?php
/**
 * Theme manager — banner presets.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Theme manager.
 */
class Theme_Manager {

	/**
	 * Instance.
	 *
	 * @var Theme_Manager|null
	 */
	private static $instance = null;

	/**
	 * Available presets (key => stylesheet).
	 *
	 * @var array
	 */
	private $presets = array(
		'classic'      => 'classic.css',
		'studio_neon'  => 'studio-neon.css',
		'studio_ocean' => 'studio-ocean.css',
		'studio_light' => 'studio-light.css',
	);

	/**
	 * Get instance.
	 *
	 * @return Theme_Manager
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
		// Styles enqueued from Plugin class.
	}

	/**
	 * Resolve theme preset key (unknown / legacy keys → classic).
	 *
	 * @param string|null $preset Preset.
	 * @return string
	 */
	public function resolve_preset( $preset = null ) {
		if ( null === $preset ) {
			$preset = Settings::get( 'banner_theme' );
		}
		$preset = sanitize_key( (string) $preset );
		if ( ! isset( $this->presets[ $preset ] ) ) {
			return 'classic';
		}
		return $preset;
	}

	/**
	 * Public theme keys (for settings sanitization).
	 *
	 * @return string[]
	 */
	public function get_preset_keys() {
		return array_keys( $this->presets );
	}

	/**
	 * Preset key => label map for admin selects.
	 *
	 * @return array<string, string>
	 */
	public function get_preset_options() {
		return array(
			'classic'      => __( 'Classic', 'universal-consent-privacy-framework' ),
			'studio_neon'  => __( 'Studio Neon', 'universal-consent-privacy-framework' ),
			'studio_ocean' => __( 'Studio Ocean', 'universal-consent-privacy-framework' ),
			'studio_light' => __( 'Studio Light', 'universal-consent-privacy-framework' ),
		);
	}

	/**
	 * Enqueue frontend styles.
	 */
	public function enqueue_styles() {
		wp_enqueue_style(
			'ucpf-tokens',
			UCPF_PLUGIN_URL . 'public/css/tokens.css',
			array(),
			ucpf_asset_version( 'public/css/tokens.css' )
		);

		// Load every preset so admin preview + cached markup can switch class without a missing stylesheet.
		$theme_deps = array( 'ucpf-tokens' );
		$last_handle = 'ucpf-tokens';
		foreach ( $this->presets as $key => $file ) {
			$handle = 'ucpf-theme-' . $key;
			wp_enqueue_style(
				$handle,
				UCPF_PLUGIN_URL . 'public/css/themes/' . $file,
				array( $last_handle ),
				ucpf_asset_version( 'public/css/themes/' . $file )
			);
			$last_handle = $handle;
			$theme_deps[] = $handle;
		}

		wp_enqueue_style(
			'ucpf-banner',
			UCPF_PLUGIN_URL . 'public/css/banner.css',
			$theme_deps,
			ucpf_asset_version( 'public/css/banner.css' )
		);

		wp_enqueue_style(
			'ucpf-legal',
			UCPF_PLUGIN_URL . 'public/css/legal.css',
			array( 'ucpf-tokens' ),
			ucpf_asset_version( 'public/css/legal.css' )
		);

		$inline = $this->get_inline_overrides();
		if ( $inline ) {
			wp_add_inline_style( 'ucpf-banner', $inline );
			wp_add_inline_style( 'ucpf-legal', $inline );
		}

		$custom = Settings::get( 'custom_css' );
		if ( $custom ) {
			$safe = $this->sanitize_custom_css( $custom );
			if ( $safe ) {
				wp_add_inline_style( 'ucpf-banner', '.ucpf-custom { ' . $safe . ' }' );
			}
		}
	}

	/**
	 * Admin preview styles only.
	 */
	public function enqueue_admin_preview_styles() {
		$this->enqueue_styles();
	}

	/**
	 * Theme pack schema id.
	 */
	const PACK_SCHEMA = 'ucpf-theme/1.0';

	/**
	 * Setting keys included in a shareable theme pack.
	 *
	 * @return string[]
	 */
	public function get_pack_keys() {
		return array(
			'banner_theme',
			'banner_layout',
			'banner_position',
			'accent_color',
			'accent_2_color',
			'surface_color',
			'custom_css',
			'show_reject_all',
			'show_accept_all',
			'show_customize',
			'floating_prefs_button',
			'logo_url',
		);
	}

	/**
	 * Current resolved theme tokens (after filters).
	 *
	 * @return array{accent:mixed,accent_2:mixed,surface:mixed}
	 */
	public function get_tokens() {
		$tokens = apply_filters(
			'ucpf_theme_tokens',
			array(
				'accent'   => Settings::get( 'accent_color' ),
				'accent_2' => Settings::get( 'accent_2_color' ),
				'surface'  => Settings::get( 'surface_color' ),
			)
		);
		return is_array( $tokens ) ? $tokens : array();
	}

	/**
	 * Resolved CSS custom properties for the active banner theme + admin color overrides.
	 * Used by consent surface guards (forms/maps) outside #ucpf-root.
	 *
	 * @return array<string, string> Map of "--ucpf-*" => value.
	 */
	public function get_resolved_css_variables() {
		$preset = $this->resolve_preset();
		$presets = array(
			'classic'      => array(
				'--ucpf-black'         => '#000000',
				'--ucpf-surface'       => '#111111',
				'--ucpf-cream'         => '#ffffff',
				'--ucpf-muted'         => '#d4d4d4',
				'--ucpf-border'        => 'rgba(255, 255, 255, 0.35)',
				'--ucpf-accent'        => '#0b5cad',
				'--ucpf-accent-hover'  => '#094a8c',
				'--ucpf-accent-active' => '#073a6e',
				'--ucpf-accent-2'      => '#094a8c',
				'--ucpf-on-accent'     => '#ffffff',
				'--ucpf-label'         => '#ffffff',
				'--ucpf-focus'         => '#ffffff',
				'--ucpf-focus-ring'    => '#b45309',
				'--ucpf-shadow-panel'  => '0 12px 40px rgba(0, 0, 0, 0.65)',
			),
			'studio_neon'  => array(
				'--ucpf-black'         => '#0a0a0b',
				'--ucpf-surface'       => '#0f0f10',
				'--ucpf-cream'         => '#ffffff',
				'--ucpf-muted'         => '#d4d4d4',
				'--ucpf-border'        => 'rgba(255, 255, 255, 0.35)',
				'--ucpf-accent'        => '#00c777',
				'--ucpf-accent-hover'  => '#00965a',
				'--ucpf-accent-active' => '#007a49',
				'--ucpf-accent-2'      => 'rgba(255, 255, 255, 0.2)',
				'--ucpf-on-accent'     => '#000000',
				'--ucpf-label'         => '#ffffff',
				'--ucpf-focus'         => '#ffffff',
				'--ucpf-focus-ring'    => '#b45309',
				'--ucpf-shadow-panel'  => '0 12px 40px rgba(0, 0, 0, 0.65)',
			),
			'studio_ocean' => array(
				'--ucpf-black'         => '#051923',
				'--ucpf-surface'       => '#0a2433',
				'--ucpf-cream'         => '#f5fafc',
				'--ucpf-muted'         => '#c5d8e0',
				'--ucpf-border'        => 'rgba(245, 250, 252, 0.35)',
				'--ucpf-accent'        => '#c2410c',
				'--ucpf-accent-hover'  => '#9a3412',
				'--ucpf-accent-active' => '#7c2d12',
				'--ucpf-accent-2'      => '#2ec4b6',
				'--ucpf-on-accent'     => '#ffffff',
				'--ucpf-label'         => '#ffffff',
				'--ucpf-focus'         => '#ffffff',
				'--ucpf-focus-ring'    => '#b45309',
				'--ucpf-shadow-panel'  => '0 12px 40px rgba(0, 0, 0, 0.65)',
			),
			'studio_light' => array(
				'--ucpf-black'         => '#fafafa',
				'--ucpf-surface'       => '#ffffff',
				'--ucpf-cream'         => '#1a1a1a',
				'--ucpf-muted'         => '#4a4a4a',
				'--ucpf-border'        => 'rgba(26, 26, 26, 0.35)',
				'--ucpf-accent'        => '#0b5cad',
				'--ucpf-accent-hover'  => '#094a8c',
				'--ucpf-accent-active' => '#073a6e',
				'--ucpf-accent-2'      => '#094a8c',
				'--ucpf-on-accent'     => '#ffffff',
				'--ucpf-label'         => '#1a1a1a',
				'--ucpf-focus'         => '#1a1a1a',
				'--ucpf-focus-ring'    => '#b45309',
				'--ucpf-shadow-panel'  => '0 -8px 40px rgba(0, 0, 0, 0.12)',
			),
		);

		$vars = isset( $presets[ $preset ] ) ? $presets[ $preset ] : $presets['classic'];
		$vars['--ucpf-font-sans']    = '"Plus Jakarta Sans", system-ui, -apple-system, "Segoe UI", sans-serif';
		$vars['--ucpf-radius-soft']  = '1rem';

		$custom = $this->get_tokens();
		$accent = $this->sanitize_token_color( $custom['accent'] ?? '' );
		if ( $accent ) {
			$vars['--ucpf-accent'] = $accent;
			if ( 0 === strcasecmp( $accent, '#0b5cad' ) ) {
				$vars['--ucpf-accent-hover']  = '#094a8c';
				$vars['--ucpf-accent-active'] = '#073a6e';
			} else {
				$vars['--ucpf-accent-hover']  = 'color-mix(in srgb,' . $accent . ' 82%,#000)';
				$vars['--ucpf-accent-active'] = 'color-mix(in srgb,' . $accent . ' 68%,#000)';
			}
			$vars['--ucpf-focus-ring'] = '#b45309';
		}
		$accent_2 = $this->sanitize_token_color( $custom['accent_2'] ?? '' );
		if ( $accent_2 ) {
			$vars['--ucpf-accent-2'] = $accent_2;
		}
		$surface = $this->sanitize_token_color( $custom['surface'] ?? '' );
		if ( $surface ) {
			$vars['--ucpf-surface'] = $surface;
		}

		/**
		 * Filter resolved CSS variables for banner + consent surface guards.
		 *
		 * @param array<string, string> $vars   Property map.
		 * @param string                $preset Active preset key.
		 */
		$filtered = apply_filters( 'ucpf_theme_css_variables', $vars, $preset );
		return is_array( $filtered ) ? $filtered : $vars;
	}

	/**
	 * Build a portable theme pack for export.
	 *
	 * @param string $name Optional pack label.
	 * @return array
	 */
	public function export_pack( $name = '' ) {
		$theme = array();
		foreach ( $this->get_pack_keys() as $key ) {
			$theme[ $key ] = Settings::get( $key );
		}

		$name = sanitize_text_field( (string) $name );
		if ( '' === $name ) {
			$biz = Settings::get( 'business_name' );
			$name = $biz ? sprintf(
				/* translators: %s: business name */
				__( '%s banner theme', 'universal-consent-privacy-framework' ),
				sanitize_text_field( (string) $biz )
			) : __( 'UCPF banner theme', 'universal-consent-privacy-framework' );
		}

		return array(
			'schema'      => self::PACK_SCHEMA,
			'name'        => $name,
			'exported_at' => gmdate( 'c' ),
			'plugin'      => 'universal-consent-privacy-framework',
			'version'     => defined( 'UCPF_VERSION' ) ? UCPF_VERSION : '',
			'theme'       => $theme,
			'tokens'      => $this->get_tokens(),
			'note'        => __( 'Import on Banner & Branding. Does not change privacy/legal settings. Not a compliance claim.', 'universal-consent-privacy-framework' ),
		);
	}

	/**
	 * Sanitize an imported theme pack into settings keys.
	 *
	 * @param array $pack Raw pack.
	 * @return array|\WP_Error Sanitized settings subset or error.
	 */
	public function sanitize_pack( array $pack ) {
		$schema = isset( $pack['schema'] ) ? (string) $pack['schema'] : '';
		if ( 0 !== strpos( $schema, 'ucpf-theme/' ) ) {
			return new \WP_Error(
				'ucpf_theme_schema',
				__( 'This JSON is not a UCPF theme pack (missing schema ucpf-theme/…).', 'universal-consent-privacy-framework' ),
				array( 'status' => 400 )
			);
		}

		$theme = array();
		if ( isset( $pack['theme'] ) && is_array( $pack['theme'] ) ) {
			$theme = $pack['theme'];
		} else {
			// Allow a flat pack that is just the theme fields.
			$theme = $pack;
		}

		$clean = array();

		if ( isset( $theme['banner_theme'] ) ) {
			$key = sanitize_key( (string) $theme['banner_theme'] );
			$clean['banner_theme'] = in_array( $key, $this->get_preset_keys(), true ) ? $key : 'classic';
		}

		if ( isset( $theme['banner_layout'] ) ) {
			$layout = sanitize_key( (string) $theme['banner_layout'] );
			$clean['banner_layout'] = in_array( $layout, array( 'bar', 'modal', 'corner' ), true ) ? $layout : 'bar';
		}

		if ( isset( $theme['banner_position'] ) ) {
			$pos = sanitize_key( (string) $theme['banner_position'] );
			$clean['banner_position'] = in_array( $pos, array( 'left', 'center', 'right' ), true ) ? $pos : 'left';
		}

		foreach ( array( 'accent_color', 'accent_2_color', 'surface_color' ) as $color_key ) {
			if ( ! array_key_exists( $color_key, $theme ) ) {
				continue;
			}
			$raw = is_string( $theme[ $color_key ] ) ? trim( $theme[ $color_key ] ) : '';
			if ( '' === $raw ) {
				$clean[ $color_key ] = '';
				continue;
			}
			$hex = sanitize_hex_color( $raw );
			$clean[ $color_key ] = $hex ? $hex : '';
		}

		if ( array_key_exists( 'custom_css', $theme ) ) {
			$clean['custom_css'] = $this->sanitize_custom_css( $theme['custom_css'] );
		}

		foreach ( array( 'show_reject_all', 'show_accept_all', 'show_customize', 'floating_prefs_button' ) as $bool_key ) {
			if ( array_key_exists( $bool_key, $theme ) ) {
				$clean[ $bool_key ] = ! empty( $theme[ $bool_key ] );
			}
		}

		if ( array_key_exists( 'logo_url', $theme ) ) {
			$url = esc_url_raw( (string) $theme['logo_url'] );
			$clean['logo_url'] = $url ? $url : '';
		}

		// Optional tokens block overlays color fields when present.
		if ( isset( $pack['tokens'] ) && is_array( $pack['tokens'] ) ) {
			$map = array(
				'accent'   => 'accent_color',
				'accent_2' => 'accent_2_color',
				'surface'  => 'surface_color',
			);
			foreach ( $map as $token_key => $setting_key ) {
				if ( empty( $pack['tokens'][ $token_key ] ) || isset( $clean[ $setting_key ] ) ) {
					continue;
				}
				$raw = (string) $pack['tokens'][ $token_key ];
				$hex = sanitize_hex_color( $raw );
				$clean[ $setting_key ] = $hex ? $hex : '';
			}
		}

		if ( ! $clean ) {
			return new \WP_Error(
				'ucpf_theme_empty',
				__( 'Theme pack contained no recognizable banner fields.', 'universal-consent-privacy-framework' ),
				array( 'status' => 400 )
			);
		}

		return $clean;
	}

	/**
	 * Import a theme pack into settings.
	 *
	 * @param array $pack Raw pack.
	 * @return array|\WP_Error Result with applied keys.
	 */
	public function import_pack( array $pack ) {
		$clean = $this->sanitize_pack( $pack );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		Settings::update( $clean );

		/**
		 * Fires after a theme pack is imported.
		 *
		 * @param array $clean Applied settings.
		 * @param array $pack  Original pack.
		 */
		do_action( 'ucpf_theme_imported', $clean, $pack );

		return array(
			'success' => true,
			'applied' => array_keys( $clean ),
			'theme'   => $clean,
			'message' => __( 'Theme imported. Preview updates after reload; hard-refresh the front end to see the banner.', 'universal-consent-privacy-framework' ),
		);
	}

	/**
	 * Sanitize custom CSS for safe embedding inside `.ucpf-custom { ... }`.
	 *
	 * Strips tags, style-tag breakouts, and braces that escape the wrapper.
	 *
	 * @param mixed $css Raw CSS.
	 * @return string
	 */
	public function sanitize_custom_css( $css ) {
		$css = wp_strip_all_tags( (string) $css );
		// Neutralize </style> / <style> breakouts (any casing / whitespace).
		$css = preg_replace( '/<\/?\s*style\b[^>]*>/i', '', $css );
		// Prevent leaving the `.ucpf-custom { ... }` wrapper.
		$css = str_replace( array( '{', '}' ), '', $css );
		return trim( (string) $css );
	}

	/**
	 * Normalize a color setting to a safe hex CSS value.
	 *
	 * @param mixed $raw Raw color.
	 * @return string Empty if not a valid hex color.
	 */
	private function sanitize_token_color( $raw ) {
		if ( ! is_string( $raw ) && ! is_numeric( $raw ) ) {
			return '';
		}
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		$hex = sanitize_hex_color( $raw );
		return $hex ? $hex : '';
	}

	/**
	 * Build inline CSS variable overrides for banner + legal pages.
	 * Derives hover/active so custom accents cannot leave green preset states.
	 *
	 * @return string
	 */
	private function get_inline_overrides() {
		$vars   = $this->get_tokens();
		$root   = array();
		$legal  = array();

		$accent   = $this->sanitize_token_color( $vars['accent'] ?? '' );
		$accent_2 = $this->sanitize_token_color( $vars['accent_2'] ?? '' );
		$surface  = $this->sanitize_token_color( $vars['surface'] ?? '' );

		if ( $accent ) {
			$root[] = '--ucpf-accent:' . $accent;
			// Known WCAG blue scale — keep exact hover/active when matching defaults.
			if ( 0 === strcasecmp( $accent, '#0b5cad' ) ) {
				$root[] = '--ucpf-accent-hover:#094a8c';
				$root[] = '--ucpf-accent-active:#073a6e';
				$legal[] = '--ucpf-legal-accent:#0b5cad';
				$legal[] = '--ucpf-legal-accent-hover:#094a8c';
			} else {
				$root[]  = '--ucpf-accent-hover:color-mix(in srgb,' . $accent . ' 82%,#000)';
				$root[]  = '--ucpf-accent-active:color-mix(in srgb,' . $accent . ' 68%,#000)';
				$legal[] = '--ucpf-legal-accent:' . $accent;
				$legal[] = '--ucpf-legal-accent-hover:color-mix(in srgb,' . $accent . ' 82%,#000)';
			}
			$legal[] = '--ucpf-legal-focus:#b45309';
			$root[]  = '--ucpf-focus-ring:#b45309';
		}

		if ( $accent_2 ) {
			$root[] = '--ucpf-accent-2:' . $accent_2;
		} elseif ( $accent && 0 === strcasecmp( $accent, '#0b5cad' ) ) {
			$root[] = '--ucpf-accent-2:#094a8c';
		}

		if ( $surface ) {
			$root[] = '--ucpf-surface:' . $surface;
		}

		$css = '';
		if ( $root ) {
			// Match theme-class specificity so explicit custom colors override presets.
			// Include consent surface guards (forms/maps) — they live outside #ucpf-root.
			$selectors = array( '#ucpf-root', '.ucpf-consent-guard', '.ucpf-captcha-guard' );
			foreach ( $this->get_preset_keys() as $key ) {
				$key = sanitize_key( $key );
				$selectors[] = '#ucpf-root.ucpf-theme-' . $key;
				$selectors[] = '.ucpf-consent-guard.ucpf-theme-' . $key;
				$selectors[] = '.ucpf-captcha-guard.ucpf-theme-' . $key;
			}
			$css .= implode( ',', $selectors ) . '{' . implode( ';', $root ) . '}';
		}
		if ( $legal ) {
			$css .= '.ucpf-legal-page,.ucpf-legal-shell,#ucpf-legal-shell{' . implode( ';', $legal ) . '}';
		}
		return $css;
	}
}
