<?php
/**
 * Plugin Name:       Push Notifications for WP
 * Plugin URI:        https://github.com/jedrzejcz/push-notifications-for-wp
 * Description:       Sends events from your site to the phones of the people who run it, one row per event and one column per person. Delivered through Pushover.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Jędrzej Czerwiński
 * Author URI:        https://github.com/jedrzejcz
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       push-notifications-for-wp
 * Domain Path:       /languages
 *
 * The plugin knows nothing about any particular site. Everything a site may
 * want to add lives behind two public entry points: the `push_notify_events`
 * filter, which registers an event, and `push_notify_send()`, which reports
 * that one happened.
 *
 * WooCommerce is not required. When it is active, the integration in
 * includes/integrations/ adds shop events on top of the core ones, through
 * exactly the same public API a third-party plugin would use.
 *
 * @package PushNotifications
 */

declare( strict_types = 1 );

namespace PushNotifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '0.1.0';
const SLUG    = 'push-notifications-for-wp';

define( 'PUSH_NOTIFY_FILE', __FILE__ );
define( 'PUSH_NOTIFY_DIR', plugin_dir_path( __FILE__ ) );
define( 'PUSH_NOTIFY_URL', plugin_dir_url( __FILE__ ) );

/**
 * Class autoloader.
 *
 * Maps PushNotifications\Some_Class to includes/class-some-class.php and
 * PushNotifications\Integrations\WooCommerce to
 * includes/integrations/class-woocommerce.php.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		if ( ! str_starts_with( $class_name, __NAMESPACE__ . '\\' ) ) {
			return;
		}

		$parts = explode( '\\', substr( $class_name, strlen( __NAMESPACE__ ) + 1 ) );
		$class = array_pop( $parts );

		$directory = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
		$file      = PUSH_NOTIFY_DIR . 'includes/' . $directory
			. 'class-' . str_replace( '_', '-', strtolower( (string) $class ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

require_once PUSH_NOTIFY_DIR . 'includes/functions.php';

/**
 * High-Performance Order Storage compatibility.
 *
 * Declared here rather than in the WooCommerce integration because
 * `before_woocommerce_init` fires before the plugin has decided which
 * integrations to load, and a missing declaration puts the shop into
 * compatibility mode.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				PUSH_NOTIFY_FILE,
				true
			);
		}
	}
);

/**
 * Translations.
 *
 * Source strings are English; the plugin ships a Polish translation and takes
 * whatever else is installed for its text domain.
 */
add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( SLUG, false, dirname( plugin_basename( PUSH_NOTIFY_FILE ) ) . '/languages' );
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->init();
	}
);

/**
 * Deactivation leaves nothing scheduled behind.
 *
 * Queued sends belong to this plugin alone; left in the queue they would fire
 * against a handler that no longer exists. Both backends are cleared, because
 * a site can switch between them by installing or removing WooCommerce.
 */
register_deactivation_hook(
	__FILE__,
	static function (): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Queue::HOOK );
		}

		wp_unschedule_hook( Queue::HOOK );
	}
);
