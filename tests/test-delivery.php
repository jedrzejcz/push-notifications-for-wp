<?php
/**
 * Who receives a notification, how often, and what happens when the service
 * refuses it. Plain WordPress only: no shop involved.
 *
 * @package PushNotifications\Tests
 */

declare( strict_types = 1 );

namespace PushNotifications\Tests;

use PushNotifications\Events;
use PushNotifications\Queue;
use PushNotifications\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers the event these cases send, and returns a context pointing at a post. */
function delivery_event( int $post_id ): array {
	push_notify_register_event(
		'tests.delivery',
		array(
			'label' => 'Test delivery',
			'group' => Events::GROUP_OTHER,
			'build' => static fn( array $context ): array => array(
				'title'   => (string) ( $context['subject'] ?? '' ),
				'message' => (string) ( $context['detail'] ?? '' ),
			),
		)
	);

	return array(
		'object_id'   => $post_id,
		'object_type' => 'post',
		'subject'     => 'Something happened',
		'detail'      => 'And here is what.',
	);
}

return array(

	'sends to the assigned recipient' => static function (): void {
		with_token();
		Http::intercept();

		$user    = recipient();
		$context = delivery_event( post() );
		route( 'tests.delivery', array( $user ) );

		push_notify_send( 'tests.delivery', $context );

		equals( 1, Http::count(), 'one notification' );

		$body = Http::bodies()[0];
		equals( 'uuuuuuuuuuuuuuuuuuuuuuuuuuuuuu', $body['user'], 'recipient key' );
		contains( 'Something happened', (string) $body['title'], 'the builder had its say' );
	},

	'sends to everyone assigned' => static function (): void {
		with_token();
		Http::intercept();

		$first   = recipient( 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' );
		$second  = recipient( 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' );
		$context = delivery_event( post() );
		route( 'tests.delivery', array( $first, $second ) );

		push_notify_send( 'tests.delivery', $context );

		equals( 2, Http::count(), 'one notification per recipient' );
	},

	'skips a muted recipient but not the rest' => static function (): void {
		with_token();
		Http::intercept();

		$muted   = recipient( 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', true );
		$active  = recipient( 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' );
		$context = delivery_event( post() );
		route( 'tests.delivery', array( $muted, $active ) );

		push_notify_send( 'tests.delivery', $context );

		equals( 1, Http::count(), 'only the recipient who is not muted' );
		equals( 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', Http::bodies()[0]['user'] );
	},

	'skips an account without a key' => static function (): void {
		with_token();
		Http::intercept();

		$keyless = recipient( '' );
		$context = delivery_event( post() );
		route( 'tests.delivery', array( $keyless ) );

		push_notify_send( 'tests.delivery', $context );

		equals( 0, Http::count(), 'nothing to send to' );
	},

	'skips an account that cannot edit content' => static function (): void {
		with_token();
		Http::intercept();

		$reader  = recipient( 'cccccccccccccccccccccccccccccc', false, 'subscriber' );
		$context = delivery_event( post() );
		route( 'tests.delivery', array( $reader ) );

		push_notify_send( 'tests.delivery', $context );

		equals( 0, Http::count(), 'subscribers and customers are never recipients' );
	},

	'stays silent without an application token' => static function (): void {
		Cleanup::option( Settings::OPTION_TOKEN );
		delete_option( Settings::OPTION_TOKEN );
		Http::intercept();

		$user    = recipient();
		$context = delivery_event( post() );
		route( 'tests.delivery', array( $user ) );

		push_notify_send( 'tests.delivery', $context );

		equals( 0, Http::count(), 'the channel is off' );
	},

	'stays silent when nobody is assigned' => static function (): void {
		with_token();
		Http::intercept();

		recipient();
		$context = delivery_event( post() );
		route( 'tests.delivery', array() );

		push_notify_send( 'tests.delivery', $context );

		equals( 0, Http::count(), 'an empty row sends nothing' );
	},

	'sends once per event and recipient' => static function (): void {
		with_token();
		Http::intercept();

		$user    = recipient();
		$context = delivery_event( post() );
		route( 'tests.delivery', array( $user ) );

		push_notify_send( 'tests.delivery', $context );
		push_notify_send( 'tests.delivery', $context );
		push_notify_send( 'tests.delivery', $context );

		equals( 1, Http::count(), 'a repeated event is still one notification' );
	},

	'a different event about the same object still goes out' => static function (): void {
		with_token();
		Http::intercept();

		$user    = recipient();
		$context = delivery_event( post() );

		push_notify_register_event(
			'tests.delivery_other',
			array(
				'label' => 'Another test delivery',
				'group' => Events::GROUP_OTHER,
			)
		);

		route( 'tests.delivery', array( $user ) );
		route( 'tests.delivery_other', array( $user ) );

		push_notify_send( 'tests.delivery', $context );
		push_notify_send( 'tests.delivery_other', $context );

		equals( 2, Http::count(), 'the marker is per event, not per object' );
	},

	'retries a temporary refusal' => static function (): void {
		with_token();
		Http::intercept( array( Http::unavailable(), Http::unavailable(), Http::ok() ) );

		$user    = recipient();
		$context = delivery_event( post() );
		route( 'tests.delivery', array( $user ) );

		push_notify_send( 'tests.delivery', $context );

		equals( 3, Http::count(), 'two failures and one delivery' );
	},

	'gives up after the last attempt and says so' => static function (): void {
		with_token();
		Http::intercept(
			array( Http::unavailable(), Http::unavailable(), Http::unavailable(), Http::unavailable() )
		);
		Cleanup::option( Settings::OPTION_LOG );

		$user    = recipient();
		$context = delivery_event( post() );
		route( 'tests.delivery', array( $user ) );

		push_notify_send( 'tests.delivery', $context );

		equals( 4, Http::count(), 'the first attempt and three retries' );
		is_true( \PushNotifications\Log::failure(), 'the admin gets a warning' );

		\PushNotifications\Log::clear_failure();
	},

	'does not retry a refused recipient key' => static function (): void {
		with_token();
		Http::intercept( array( Http::bad_user() ) );
		Cleanup::option( Settings::OPTION_LOG );

		$user    = recipient();
		$context = delivery_event( post() );
		route( 'tests.delivery', array( $user ) );

		push_notify_send( 'tests.delivery', $context );

		equals( 1, Http::count(), 'a wrong key stays wrong' );
		contains(
			'user',
			(string) get_user_meta( $user, Settings::META_KEY_INVALID, true ),
			'the key is flagged for correction'
		);

		\PushNotifications\Log::clear_failure();
	},

	'links to the object in the admin' => static function (): void {
		with_token();
		Http::intercept();

		$user    = recipient();
		$post_id = post();
		$context = delivery_event( $post_id );
		route( 'tests.delivery', array( $user ) );

		push_notify_send( 'tests.delivery', $context );

		contains( (string) $post_id, (string) Http::bodies()[0]['url'], 'the link points at the post' );
	},

	'queued jobs carry no keys' => static function (): void {
		with_token( 'zzzzzzzzzzzzzzzzzzzzzzzzzzzzzz' );

		$user    = recipient( 'yyyyyyyyyyyyyyyyyyyyyyyyyyyyyy' );
		$context = delivery_event( post() );
		route( 'tests.delivery', array( $user ) );

		// This one case wants the real queue, so the synchronous shortcut the
		// runner installs is lifted for the length of the dispatch.
		remove_filter( 'push_notify_async', '__return_false' );
		push_notify_send( 'tests.delivery', $context );
		add_filter( 'push_notify_async', '__return_false' );

		$arguments = array();

		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			foreach ( as_get_scheduled_actions( array( 'hook' => Queue::HOOK, 'per_page' => 20 ) ) as $action ) {
				$arguments[] = $action->get_args();
			}

			as_unschedule_all_actions( Queue::HOOK );
		} else {
			foreach ( _get_cron_array() as $timestamp => $hooks ) {
				foreach ( $hooks[ Queue::HOOK ] ?? array() as $event ) {
					$arguments[] = $event['args'];
				}
			}

			wp_unschedule_hook( Queue::HOOK );
		}

		is_true( count( $arguments ) > 0, 'the job was queued' );

		$encoded = (string) wp_json_encode( $arguments );

		does_not_contain( 'zzzzzzzzzzzzzzzzzzzzzzzzzzzzzz', $encoded, 'application token' );
		does_not_contain( 'yyyyyyyyyyyyyyyyyyyyyyyyyyyyyy', $encoded, 'recipient key' );
		contains( (string) $user, $encoded, 'the recipient is referred to by id' );
	},
);
