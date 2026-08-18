<?php
/**
 * Removing the plugin removes what it stored.
 *
 * Settings, the matrix, the log, recipient keys and anything still queued.
 * Content, orders and accounts are left exactly as they were; the once-only
 * markers sit in meta of objects this plugin does not own, so they stay too.
 *
 * @package PushNotifications
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'push_notify_app_token' );
delete_option( 'push_notify_routing' );
delete_option( 'push_notify_log' );

delete_transient( 'push_notify_channel_failure' );
delete_transient( 'push_notify_volume' );

delete_metadata( 'user', 0, 'push_notify_user_key', '', true );
delete_metadata( 'user', 0, 'push_notify_muted', '', true );
delete_metadata( 'user', 0, 'push_notify_key_invalid', '', true );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'push_notify_send' );
}

wp_unschedule_hook( 'push_notify_send' );
