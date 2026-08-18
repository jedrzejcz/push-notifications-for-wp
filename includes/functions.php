<?php
/**
 * The public surface of this plugin.
 *
 * Two functions and one filter, and that is the whole contract other code may
 * rely on. Everything else is internal and may change between releases.
 *
 * Callers outside this plugin should guard on `function_exists()`, so that a
 * site with the plugin switched off keeps working:
 *
 *     if ( function_exists( 'push_notify_send' ) ) {
 *         push_notify_send( 'my_plugin.thing_happened', array( 'order_id' => $id ) );
 *     }
 *
 * @package PushNotifications
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'push_notify_send' ) ) {
	/**
	 * Reports that an event happened.
	 *
	 * Returns immediately: recipients are picked here, the sending happens in a
	 * background job. An unknown event key is recorded in the log and ignored,
	 * never raised, because a notification is not worth breaking a page for.
	 *
	 * The context carries plain values only and must not contain personal data.
	 * `object_id` together with `object_type` (and the shorthands `order_id`
	 * and `product_id`) are understood: they give the notification its link in
	 * the admin and the delivery its once-only marker.
	 *
	 * @param string              $event   Event key, for example `order.paid`.
	 * @param array<string,mixed> $context Plain values describing what happened.
	 */
	function push_notify_send( string $event, array $context = array() ): void {
		if ( ! class_exists( \PushNotifications\Queue::class ) ) {
			return;
		}

		\PushNotifications\Queue::dispatch( $event, $context );
	}
}

if ( ! function_exists( 'push_notify_register_event' ) ) {
	/**
	 * Adds an event to the registry.
	 *
	 * Shorthand for the `push_notify_events` filter, which stays available for
	 * anyone who wants to inspect or reorder the whole registry.
	 *
	 * Definition keys:
	 * - `label`    (string, required) what the settings screen calls it
	 * - `group`    (string) a key from `push_notify_event_groups`, `other` by default
	 * - `priority` (string) `normal` or `urgent`, the default urgency
	 * - `throttle` (int) seconds; at most one notification per window
	 * - `build`    (callable) fn( array $context ): array{title,message,url}
	 *
	 * @param array<string,mixed> $definition
	 */
	function push_notify_register_event( string $key, array $definition ): void {
		add_filter(
			'push_notify_events',
			static function ( array $events ) use ( $key, $definition ): array {
				$events[ $key ] = $definition;

				return $events;
			}
		);

		if ( class_exists( \PushNotifications\Events::class ) ) {
			\PushNotifications\Events::flush();
		}
	}
}
