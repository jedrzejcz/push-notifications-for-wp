<?php
/**
 * What happened to a notification.
 *
 * A fifty entry ring buffer shown on the settings screen: short-lived
 * diagnostics do not deserve a database table of their own. Every entry is
 * also announced on `push_notify_logged`, which is how the WooCommerce
 * integration copies anything about an order into that order's notes, where
 * whoever is investigating the order is already looking.
 *
 * @package PushNotifications
 */

declare( strict_types = 1 );

namespace PushNotifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Log {

	private const LIMIT = 50;

	private const FAILURE_TRANSIENT = 'push_notify_channel_failure';
	private const VOLUME_TRANSIENT  = 'push_notify_volume';

	/** Sends per hour above which the settings screen says something is off. */
	public const VOLUME_THRESHOLD = 50;

	/**
	 * @param array<string,mixed> $context
	 */
	public static function add( string $event, string $result, string $detail = '', int $user_id = 0, array $context = array() ): void {
		$entries = self::entries();

		array_unshift(
			$entries,
			array(
				'time'   => time(),
				'event'  => $event,
				'result' => $result,
				'detail' => $detail,
				'user'   => $user_id,
			)
		);

		$entry = $entries[0];

		update_option( Settings::OPTION_LOG, array_slice( $entries, 0, self::LIMIT ), false );

		do_action( 'push_notify_logged', $entry, $context );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function entries(): array {
		$stored = get_option( Settings::OPTION_LOG, array() );

		return is_array( $stored ) ? $stored : array();
	}

	public static function clear(): void {
		delete_option( Settings::OPTION_LOG );
	}

	// -----------------------------------------------------------------------
	// Channel health
	// -----------------------------------------------------------------------

	/**
	 * Remembers that the channel gave up on a notification.
	 *
	 * Deliberately not sent as a push: the channel that would carry the warning
	 * is the one that just failed.
	 */
	public static function record_failure( string $event, string $reason ): void {
		set_transient(
			self::FAILURE_TRANSIENT,
			array(
				'time'   => time(),
				'event'  => $event,
				'reason' => $reason,
			),
			WEEK_IN_SECONDS
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function failure(): ?array {
		$stored = get_transient( self::FAILURE_TRANSIENT );

		return is_array( $stored ) ? $stored : null;
	}

	public static function clear_failure(): void {
		delete_transient( self::FAILURE_TRANSIENT );
	}

	/** Counts a delivery in the current hour, for the volume warning. */
	public static function count_send(): void {
		$count = (int) get_transient( self::VOLUME_TRANSIENT );

		set_transient( self::VOLUME_TRANSIENT, $count + 1, HOUR_IN_SECONDS );
	}

	public static function volume(): int {
		return (int) get_transient( self::VOLUME_TRANSIENT );
	}
}
