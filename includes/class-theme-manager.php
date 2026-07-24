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
			UCPF_VERSION
		);

		$preset = $this->resolve_preset();
		$file   = $this->presets[ $preset ];

		wp_enqueue_style(
			'ucpf-theme',
			UCPF_PLUGIN_URL . 'public/css/themes/' . $file,
			array( 'ucpf-tokens' ),
			UCPF_VERSION
		);

		wp_enqueue_style(
			'ucpf-banner',
			UCPF_PLUGIN_URL . 'public/css/banner.css',
			array( 'ucpf-theme' ),
			UCPF_VERSION
		);

		wp_enqueue_style(
			'ucpf-legal',
			UCPF_PLUGIN_URL . 'public/css/legal.css',
			array( 'ucpf-tokens' ),
			UCPF_VERSION
		);

		$inline = $this->get_inline_overrides();
		if ( $inline ) {
			wp_add_inline_style( 'ucpf-banner', $inline );
		}

		$custom = Settings::get( 'custom_css' );
		if ( $custom ) {
			wp_add_inline_style( 'ucpf-banner', '.ucpf-custom { ' . wp_strip_all_tags( $custom ) . ' }' );
		}
	}

	/**
	 * Admin preview styles only.
	 */
	public function enqueue_admin_preview_styles() {
		$this->enqueue_styles();
	}

	/**
	 * Build inline CSS variable overrides.
	 *
	 * @return string
	 */
	private function get_inline_overrides() {
		$vars = apply_filters(
			'ucpf_theme_tokens',
			array(
				'accent'   => Settings::get( 'accent_color' ),
				'accent_2' => Settings::get( 'accent_2_color' ),
				'surface'  => Settings::get( 'surface_color' ),
			)
		);

		$parts = array();
		$map   = array(
			'accent'   => '--ucpf-accent',
			'accent_2' => '--ucpf-accent-2',
			'surface'  => '--ucpf-surface',
		);
		foreach ( $map as $key => $css_var ) {
			if ( empty( $vars[ $key ] ) ) {
				continue;
			}
			$val = sanitize_hex_color( (string) $vars[ $key ] );
			if ( ! $val ) {
				$val = sanitize_text_field( (string) $vars[ $key ] );
			}
			if ( $val ) {
				$parts[] = $css_var . ':' . $val;
			}
		}
		if ( ! $parts ) {
			return '';
		}
		return '#ucpf-root{' . implode( ';', $parts ) . '}';
	}
}
