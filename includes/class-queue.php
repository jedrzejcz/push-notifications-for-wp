<?php
/**
 * From "something happened" to "the phone buzzed".
 *
 * Nothing here runs inside the request the event came from. Reporting an event
 * only picks the recipients and queues one job per recipient; the call to
 * Pushover happens later, in the background, where a slow or broken service
 * costs nobody a page load.
 *
 * One job per recipient rather than one per event, because a retry after a
 * failure for one person must not send the notification to everybody else a
 * second time.
 *
 * @package PushNotifications
 */

declare( strict_types = 1 );

namespace PushNotifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Queue {

	public const HOOK  = 'push_notify_send';
	public const GROUP = 'push-notify';

	/** Delay before the second, third and fourth attempt, in seconds. */
	private const DELAYS = array( 60, 300, 1800 );

	public function register(): void {
		add_action( self::HOOK, array( $this, 'work' ), 10, 1 );
	}

	// -----------------------------------------------------------------------
	// Reporting an event
	// -----------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $context
	 */
	public static function dispatch( string $event, array $context ): void {
		if ( ! Settings::is_enabled() ) {
			return;
		}

		$definition = Events::get( $event );

		if ( null === $definition ) {
			Log::add( $event, 'unknown', __( 'Event is not registered.', 'push-notifications-for-wp' ) );
			return;
		}

		$recipients = Routing::recipients( $event );

		if ( ! $recipients ) {
			return;
		}

		$context  = self::plain( $context );
		$throttle = (int) $definition['throttle'];

		foreach ( $recipients as $user_id ) {
			if ( self::already_sent( $event, $user_id, $context ) ) {
				continue;
			}

			$payload = array(
				'event'   => $event,
				'user'    => $user_id,
				'context' => $context,
				'attempt' => 1,
			);

			if ( $throttle > 0 ) {
				$gate = self::gate( $event, $user_id, $throttle );

				if ( ! $gate['allowed'] ) {
					continue;
				}

				$payload['context']['suppressed'] = $gate['suppressed'];
			}

			self::enqueue( $payload );
		}
	}

	/**
	 * Hands the job to whichever scheduler this site has.
	 *
	 * Action Scheduler when it is present, because it survives fatal errors,
	 * shows its history in the admin and has a place to put a retry. WP-Cron
	 * otherwise, which is slower to start but is always there. Both are only
	 * ever asked to run one job for one recipient.
	 *
	 * @param array<string,mixed> $payload
	 */
	private static function enqueue( array $payload, int $delay = 0 ): void {
		$async = (bool) apply_filters( 'push_notify_async', true );

		if ( ! $async ) {
			// Tests and WP-CLI runs want the send to happen where they can see it.
			( new self() )->work( $payload );
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( $delay > 0 ) {
				as_schedule_single_action( time() + $delay, self::HOOK, array( $payload ), self::GROUP );
				return;
			}

			as_enqueue_async_action( self::HOOK, array( $payload ), self::GROUP );
			return;
		}

		wp_schedule_single_event( time() + max( 1, $delay ), self::HOOK, array( $payload ) );
	}

	// -----------------------------------------------------------------------
	// Sending
	// -----------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $payload
	 */
	public function work( array $payload ): void {
		$event   = (string) ( $payload['event'] ?? '' );
		$user_id = (int) ( $payload['user'] ?? 0 );
		$context = (array) ( $payload['context'] ?? array() );
		$attempt = max( 1, (int) ( $payload['attempt'] ?? 1 ) );

		$definition = Events::get( $event );
		$token      = Settings::app_token();

		if ( null === $definition || '' === $token || $user_id <= 0 ) {
			return;
		}

		if ( Settings::is_muted( $user_id ) ) {
			return;
		}

		$user_key = Settings::user_key( $user_id );

		if ( '' === $user_key ) {
			return;
		}

		if ( self::already_sent( $event, $user_id, $context ) ) {
			return;
		}

		$message = Message::build( $event, $definition, $context );
		$client  = new Pushover_Client();
		$verdict = $client->send( $token, $user_key, $message );

		if ( $verdict['ok'] ) {
			self::mark_sent( $event, $user_id, $context );
			delete_user_meta( $user_id, Settings::META_KEY_INVALID );
			Log::clear_failure();
			Log::count_send();
			Log::add( $event, 'sent', '', $user_id, $context );
			return;
		}

		if ( $verdict['invalid_user'] ) {
			update_user_meta( $user_id, Settings::META_KEY_INVALID, $verdict['reason'] );
		}

		if ( $verdict['permanent'] ) {
			Log::add( $event, 'rejected', $verdict['reason'], $user_id, $context );
			Log::record_failure( $event, $verdict['reason'] );
			return;
		}

		if ( $attempt <= count( self::DELAYS ) ) {
			$payload['attempt'] = $attempt + 1;

			Log::add(
				$event,
				'retrying',
				sprintf(
					/* translators: 1: attempt number, 2: reason */
					__( 'attempt %1$d failed: %2$s', 'push-notifications-for-wp' ),
					$attempt,
					$verdict['reason']
				),
				$user_id
			);

			self::enqueue( $payload, self::DELAYS[ $attempt - 1 ] );
			return;
		}

		Log::add( $event, 'failed', $verdict['reason'], $user_id, $context );
		Log::record_failure( $event, $verdict['reason'] );
	}

	// -----------------------------------------------------------------------
	// Sent once, and only once
	// -----------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $context
	 */
	private static function already_sent( string $event, int $user_id, array $context ): bool {
		$object = self::object( $context );

		return $object ? Markers::has( $object['type'], $object['id'], $event, $user_id ) : false;
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private static function mark_sent( string $event, int $user_id, array $context ): void {
		$object = self::object( $context );

		if ( $object ) {
			Markers::add( $object['type'], $object['id'], $event, $user_id );
		}
	}

	/**
	 * Which object this event is about, if any.
	 *
	 * `order_id` and `product_id` are understood as well as the generic
	 * `object_id`, because they are what integrations and other plugins
	 * naturally reach for.
	 *
	 * @param array<string,mixed> $context
	 * @return array{type:string,id:int}|null
	 */
	private static function object( array $context ): ?array {
		$id = (int) ( $context['object_id'] ?? 0 );

		if ( $id > 0 ) {
			return array(
				'type' => (string) ( $context['object_type'] ?? 'post' ),
				'id'   => $id,
			);
		}

		foreach ( array( 'order' => 'order_id', 'product' => 'product_id' ) as $type => $key ) {
			$id = (int) ( $context[ $key ] ?? 0 );

			if ( $id > 0 ) {
				return array(
					'type' => $type,
					'id'   => $id,
				);
			}
		}

		return null;
	}

	// -----------------------------------------------------------------------
	// Throttling window
	// -----------------------------------------------------------------------

	/**
	 * Lets one notification through per window and counts the rest.
	 *
	 * Events without an object to hang a marker on (a rejected gateway callback,
	 * say) repeat exactly when something is wrong, which is when a phone would
	 * otherwise ring all night.
	 *
	 * @return array{allowed:bool,suppressed:int}
	 */
	private static function gate( string $event, int $user_id, int $window ): array {
		$key   = 'push_notify_gate_' . md5( $event . '|' . $user_id );
		$count = 'push_notify_missed_' . md5( $event . '|' . $user_id );

		if ( get_transient( $key ) ) {
			set_transient( $count, ( (int) get_transient( $count ) ) + 1, 2 * $window );

			return array(
				'allowed'    => false,
				'suppressed' => 0,
			);
		}

		$suppressed = (int) get_transient( $count );

		delete_transient( $count );
		set_transient( $key, 1, $window );

		return array(
			'allowed'    => true,
			'suppressed' => $suppressed,
		);
	}

	// -----------------------------------------------------------------------
	// Context hygiene
	// -----------------------------------------------------------------------

	/**
	 * Scheduled job arguments end up in the database, so the context is
	 * flattened to plain values. Objects would not survive the round trip
	 * anyway.
	 *
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private static function plain( array $context ): array {
		$plain = array();

		foreach ( $context as $key => $value ) {
			$key = (string) $key;

			if ( is_scalar( $value ) || null === $value ) {
				$plain[ $key ] = $value;
				continue;
			}

			if ( is_array( $value ) ) {
				$plain[ $key ] = self::plain( $value );
			}
		}

		return $plain;
	}
}
