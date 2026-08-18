<?php
/**
 * Remembers that a notification about a given object already went out.
 *
 * The marker hangs on the object the event is about, so "one notification per
 * event and recipient" survives repeated callbacks, double saves and retries.
 * Posts of any kind are handled here; anything stored elsewhere (WooCommerce
 * orders under High-Performance Order Storage, for one) is handed to whoever
 * filters `push_notify_read_markers` and `push_notify_write_markers`.
 *
 * @package PushNotifications
 */

declare( strict_types = 1 );

namespace PushNotifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Markers {

	/**
	 * @return string[]
	 */
	public static function read( string $type, int $id ): array {
		$handled = apply_filters( 'push_notify_read_markers', null, $type, $id );

		if ( is_array( $handled ) ) {
			return array_map( 'strval', $handled );
		}

		$stored = get_post_meta( $id, Settings::META_SENT, true );

		return is_array( $stored ) ? array_map( 'strval', $stored ) : array();
	}

	/**
	 * @param string[] $markers
	 */
	public static function write( string $type, int $id, array $markers ): void {
		$handled = apply_filters( 'push_notify_write_markers', false, $type, $id, $markers );

		if ( true === $handled ) {
			return;
		}

		update_post_meta( $id, Settings::META_SENT, $markers );
	}

	public static function stamp( string $event, int $user_id ): string {
		return $event . ':' . $user_id;
	}

	public static function has( string $type, int $id, string $event, int $user_id ): bool {
		return in_array( self::stamp( $event, $user_id ), self::read( $type, $id ), true );
	}

	public static function add( string $type, int $id, string $event, int $user_id ): void {
		$markers   = self::read( $type, $id );
		$markers[] = self::stamp( $event, $user_id );

		self::write( $type, $id, array_values( array_unique( $markers ) ) );
	}

	/**
	 * Forgets markers for the given events, so the next occurrence is reported.
	 *
	 * @param string[] $events
	 */
	public static function forget( string $type, int $id, array $events ): void {
		$kept = array_values(
			array_filter(
				self::read( $type, $id ),
				static function ( string $entry ) use ( $events ): bool {
					foreach ( $events as $event ) {
						if ( str_starts_with( $entry, $event . ':' ) ) {
							return false;
						}
					}

					return true;
				}
			)
		);

		self::write( $type, $id, $kept );
	}
}
