<?php
/**
 * The registry: core events, events added from outside, urgency and throttling.
 *
 * @package PushNotifications\Tests
 */

declare( strict_types = 1 );

namespace PushNotifications\Tests;

use PushNotifications\Events;
use PushNotifications\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(

	'ships the plain WordPress events' => static function (): void {
		Events::flush();
		$keys = array_keys( Events::all() );

		foreach ( array( 'core.comment_moderation', 'core.user_registered' ) as $expected ) {
			is_true( in_array( $expected, $keys, true ), 'registry has ' . $expected );
		}
	},

	'an event from outside behaves like a built-in one' => static function (): void {
		with_token();
		Http::intercept();

		push_notify_register_event(
			'tests.thing',
			array(
				'label' => 'Test thing',
				'group' => Events::GROUP_OTHER,
				'build' => static fn( array $context ): array => array(
					'title'   => 'Case ' . ( $context['case'] ?? '' ),
					'message' => 'Something happened',
				),
			)
		);

		$user = recipient();
		route( 'tests.thing', array( $user ) );

		push_notify_send( 'tests.thing', array( 'case' => 'A12' ) );

		equals( 1, Http::count(), 'it was delivered' );

		$body = Http::bodies()[0];
		contains( 'Test thing', (string) $body['title'], 'the label leads the title' );
		contains( 'A12', (string) $body['title'], 'the builder had its say' );
	},

	'an event in an unknown group lands in Other' => static function (): void {
		push_notify_register_event(
			'tests.homeless',
			array(
				'label' => 'Homeless event',
				'group' => 'no-such-group',
			)
		);

		$event = Events::get( 'tests.homeless' );

		is_true( is_array( $event ), 'the event survived registration' );
		equals( Events::GROUP_OTHER, (string) $event['group'], 'unknown groups fall back' );
	},

	'an unknown event is ignored, not raised' => static function (): void {
		with_token();
		Http::intercept();
		Cleanup::option( Settings::OPTION_LOG );

		$user = recipient();
		route( 'tests.thing', array( $user ) );

		push_notify_send( 'nothing.like.this', array() );

		equals( 0, Http::count(), 'nothing goes out' );
	},

	'urgency from the matrix reaches the request' => static function (): void {
		with_token();
		Http::intercept();

		push_notify_register_event(
			'tests.urgent',
			array(
				'label' => 'Test urgent',
				'group' => Events::GROUP_OTHER,
			)
		);

		$user = recipient();
		route( 'tests.urgent', array( $user ), 'urgent' );

		push_notify_send( 'tests.urgent', array( 'message' => 'wake up' ) );

		$body = Http::bodies()[0];
		equals( 1, (int) $body['priority'], 'high priority' );
		equals( 'persistent', (string) $body['sound'], 'and a sound to go with it' );
	},

	'a throttled event sends once per window' => static function (): void {
		with_token();
		Http::intercept();

		push_notify_register_event(
			'tests.flood',
			array(
				'label'    => 'Test flood',
				'group'    => Events::GROUP_OTHER,
				'throttle' => 900,
			)
		);

		$user = recipient();
		route( 'tests.flood', array( $user ) );

		push_notify_send( 'tests.flood', array( 'message' => 'first' ) );
		push_notify_send( 'tests.flood', array( 'message' => 'second' ) );
		push_notify_send( 'tests.flood', array( 'message' => 'third' ) );

		equals( 1, Http::count(), 'the window holds the rest back' );

		delete_transient( 'push_notify_gate_' . md5( 'tests.flood|' . $user ) );
		delete_transient( 'push_notify_missed_' . md5( 'tests.flood|' . $user ) );
	},

	'a comment awaiting moderation is reported without quoting anybody' => static function (): void {
		with_token();
		Http::intercept();

		$user    = recipient();
		$post_id = post( 'Post with comments' );
		route( 'core.comment_moderation', array( $user ) );

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => 'Anna Nowak',
				'comment_author_email' => 'anna@example.test',
				'comment_content'      => 'Buy my pills at example.test',
				'comment_approved'     => 0,
			)
		);

		is_true( $comment_id > 0, 'the comment was created' );

		push_notify_send(
			'core.comment_moderation',
			array(
				'object_id'   => (int) $comment_id,
				'object_type' => 'comment',
				'post_title'  => get_the_title( $post_id ),
				'waiting'     => 3,
			)
		);

		equals( 1, Http::count(), 'the moderator hears about it' );

		$body = Http::bodies()[0];
		$sent = (string) $body['title'] . ' ' . (string) $body['message'];

		contains( 'Post with comments', $sent, 'which post it is about' );
		does_not_contain( 'Anna Nowak', $sent, 'comment author' );
		does_not_contain( 'anna@example.test', $sent, 'author email' );
		does_not_contain( 'pills', $sent, 'comment content' );

		wp_delete_comment( (int) $comment_id, true );
	},
);
