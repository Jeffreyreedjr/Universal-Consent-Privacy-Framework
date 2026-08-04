<?php
/**
 * Plugin Name:       Universal Consent & Privacy Framework (Alpha)
 * Plugin URI:        https://github.com/Jeffreyreedjr/Universal-Consent-Privacy-Framework
 * Description:       Alpha release. Standardizes privacy, cookie consent, GDPR-style consent handling, script blocking, privacy pages, and a developer API for registering services. Not production-certified.
 * Version:           0.1.15-alpha
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

define( 'UCPF_VERSION', '0.1.15-alpha' );
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

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

require_once UCPF_PLUGIN_DIR . 'includes/helpers.php';

register_activation_hook( __FILE__, array( 'UCPF\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'UCPF\\Deactivator', 'deactivate' ) );

add_action( 'wp_initialize_site', array( 'UCPF\\Activator', 'on_initialize_site' ), 20, 1 );

add_action(
	'plugins_loaded',
	static function () {
		UCPF\Plugin::instance()->init();
	}
);
