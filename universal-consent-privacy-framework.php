<?php
/**
 * Plugin Name:       Universal Consent & Privacy Framework (Alpha)
 * Plugin URI:        https://github.com/Jeffreyreedjr/Universal-Consent-Privacy-Framework
 * Description:       Alpha release. Standardizes privacy, cookie consent, GDPR-style consent handling, script blocking, privacy pages, and a developer API for registering services. Not production-certified.
 * Version:           0.1.29-alpha
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Jeffrey Reed Jr.
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       universal-consent-privacy-framework
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the plugin directory looks fully extracted.
 *
 * During zip/FTP replace WordPress still lists UCPF as active while files are
 * half-written. Requiring a truncated helpers.php fatals every front-end request
 * (theme/Elementor never render) and Cloudflare can cache that error HTML.
 * Prefer a brief "no banner" over a white-screen of the whole site.
 *
 * @return bool
 */
function ucpf_install_is_complete() {
	$dir  = plugin_dir_path( __FILE__ );
	$need = array(
		'includes/helpers.php',
		'includes/class-plugin.php',
		'includes/class-activator.php',
		'includes/class-deactivator.php',
		'includes/class-settings.php',
		'includes/class-consent-manager.php',
		'public/js/network-gate.js',
		'public/js/consent.js',
		'public/js/loader.js',
	);
	foreach ( $need as $rel ) {
		$path = $dir . $rel;
		if ( ! is_readable( $path ) ) {
			return false;
		}
		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $size || $size < 64 ) {
			return false;
		}
	}
	return true;
}

if ( ! ucpf_install_is_complete() ) {
	return;
}

define( 'UCPF_VERSION', '0.1.29-alpha' );
define( 'UCPF_PLUGIN_FILE', __FILE__ );
define( 'UCPF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'UCPF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'UCPF_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * PSR-4 style autoloader for UCPF namespace.
 *
 * @param string $class Class name.
 */
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'UCPF\\' ) ) {
			return;
		}

		$relative = strtolower( str_replace( '\\', '/', substr( $class, 5 ) ) );
		$relative = str_replace( '_', '-', $relative );
		$file     = UCPF_PLUGIN_DIR . 'includes/' . $relative . '.php';

		if ( strpos( $relative, 'integrations/' ) === 0 ) {
			$parts    = explode( '/', $relative );
			$filename = 'class-' . array_pop( $parts ) . '.php';
			$file     = UCPF_PLUGIN_DIR . 'includes/integrations/' . $filename;
		} elseif ( false === strpos( $relative, '/' ) ) {
			$file = UCPF_PLUGIN_DIR . 'includes/class-' . $relative . '.php';
		}

		if ( ! is_readable( $file ) ) {
			return;
		}
		$size = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $size || $size < 32 ) {
			return;
		}

		try {
			require_once $file;
		} catch ( \Throwable $e ) {
			// Incomplete/truncated PHP during deploy — do not take down the site.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'UCPF autoload skipped: ' . $e->getMessage() );
			}
		}
	}
);

try {
	// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingCustomConstant -- plugin bootstrap.
	require_once UCPF_PLUGIN_DIR . 'includes/helpers.php';
} catch ( \Throwable $e ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'UCPF helpers load failed: ' . $e->getMessage() );
	}
	return;
}

register_activation_hook( __FILE__, array( 'UCPF\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'UCPF\\Deactivator', 'deactivate' ) );

add_action( 'wp_initialize_site', array( 'UCPF\\Activator', 'on_initialize_site' ), 20, 1 );

add_action(
	'plugins_loaded',
	static function () {
		// Soft-fail: never white-screen the theme because UCPF failed mid-boot.
		try {
			if ( ! function_exists( 'ucpf_install_is_complete' ) || ! ucpf_install_is_complete() ) {
				return;
			}
			if ( ! class_exists( 'UCPF\\Plugin', false ) && ! class_exists( 'UCPF\\Plugin' ) ) {
				return;
			}
			UCPF\Plugin::instance()->init();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'UCPF init failed: ' . $e->getMessage() );
			}
		}
	}
);
