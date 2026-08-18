<?php
/**
 * Who receives which event, and how urgent it is.
 *
 * The stored matrix is only a list of user ids; whether those users still
 * exist, still handle orders and still have a key is decided on every read.
 * Saved rows for events whose plugin is currently inactive are kept, so
 * turning that plugin back on does not lose the assignment.
 *
 * @package PushNotifications
 */

declare( strict_types = 1 );

namespace PushNotifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Routing {

	/**
	 * The stored matrix, as saved.
	 *
	 * @return array<string,array{users:int[],priority:string}>
	 */
	public static function config(): array {
		$stored = get_option( Settings::OPTION_ROUTING, array() );
		$clean  = array();

		foreach ( (array) $stored as $event => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$clean[ (string) $event ] = array(
				'users'    => array_values( array_unique( array_map( 'absint', (array) ( $row['users'] ?? array() ) ) ) ),
				'priority' => Events::PRIORITY_URGENT === ( $row['priority'] ?? '' )
					? Events::PRIORITY_URGENT
					: Events::PRIORITY_NORMAL,
			);
		}

		return $clean;
	}

	/**
	 * Users who may receive this event right now.
	 *
	 * @return int[]
	 */
	public static function recipients( string $event ): array {
		$config = self::config();

		if ( empty( $config[ $event ]['users'] ) ) {
			return array();
		}

		$recipients = array();

		foreach ( $config[ $event ]['users'] as $user_id ) {
			if ( ! self::is_eligible( $user_id ) ) {
				continue;
			}

			if ( Settings::is_muted( $user_id ) ) {
				continue;
			}

			$recipients[] = $user_id;
		}

		return $recipients;
	}

	/** Urgency of an event: what the matrix says, or what the event asked for. */
	public static function priority( string $event ): string {
		$config = self::config();

		if ( isset( $config[ $event ]['priority'] ) ) {
			return $config[ $event ]['priority'];
		}

		$definition = Events::get( $event );

		return (string) ( $definition['priority'] ?? Events::PRIORITY_NORMAL );
	}

	/**
	 * A user who can be a recipient: runs part of the site and has a key.
	 *
	 * Muted users stay eligible; muting hides them from delivery, not from the
	 * matrix, so nobody has to rebuild the assignment after a holiday.
	 */
	public static function is_eligible( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$user = get_userdata( $user_id );

		if ( ! $user || ! user_can( $user, Settings::recipient_capability() ) ) {
			return false;
		}

		return '' !== Settings::user_key( $user_id );
	}

	/**
	 * Everyone who can be picked in the matrix.
	 *
	 * @return \WP_User[]
	 */
	public static function eligible_users(): array {
		$users = get_users(
			array(
				'capability' => Settings::recipient_capability(),
				'orderby'    => 'display_name',
				'order'      => 'ASC',
			)
		);

		return array_values(
			array_filter(
				$users,
				static fn( \WP_User $user ): bool => '' !== Settings::user_key( (int) $user->ID )
			)
		);
	}

	/**
	 * Sanitising callback for the settings screen.
	 *
	 * Rows absent from the submitted form are kept as they were: the form only
	 * renders events that are registered right now.
	 *
	 * @param mixed $input
	 * @return array<string,array{users:int[],priority:string}>
	 */
	public static function sanitize( $input ): array {
		$result = self::config();

		foreach ( (array) $input as $event => $row ) {
			$event = (string) $event;

			if ( ! preg_match( '/^[A-Za-z0-9._-]{1,64}$/', $event ) ) {
				continue;
			}

			$users = array();

			foreach ( (array) ( $row['users'] ?? array() ) as $user_id ) {
				$user_id = absint( $user_id );

				if ( self::is_eligible( $user_id ) ) {
					$users[] = $user_id;
				}
			}

			$result[ $event ] = array(
				'users'    => array_values( array_unique( $users ) ),
				'priority' => Events::PRIORITY_URGENT === ( $row['priority'] ?? '' )
					? Events::PRIORITY_URGENT
					: Events::PRIORITY_NORMAL,
			);
		}

		return $result;
	}
}
