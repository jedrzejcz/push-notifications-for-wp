<?php
/**
 * Assertions, fixtures and a stand-in for the network.
 *
 * The suite runs against a live WordPress install through WP-CLI, so every
 * fixture has to clean up after itself; `Cleanup` does that even when a test
 * throws, because the runner calls it from a `finally` block. Only the
 * WooCommerce suite needs a shop; the rest runs on plain WordPress.
 *
 * No test ever reaches the network. `Http::intercept()` answers on the
 * `pre_http_request` filter and records what would have been sent, which is
 * also how the suite inspects the message body.
 *
 * @package PushNotifications\Tests
 */

declare( strict_types = 1 );

namespace PushNotifications\Tests;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** A failed assertion. */
final class Failure extends \RuntimeException {}

/** A case that does not apply to this install. */
final class Skipped extends \RuntimeException {}

/**
 * Declares that this case has nothing to check here.
 *
 * Reported as skipped rather than passed, because a case that quietly returns
 * looks exactly like a case that ran, and a suite that lies about coverage is
 * worse than no suite.
 */
function skip( string $why ): void {
	throw new Skipped( $why );
}

/** Objects and options to restore once a test is done. */
final class Cleanup {

	/** @var int[] */
	private static array $posts = array();

	/** @var int[] */
	private static array $users = array();

	/** @var array<string,mixed> */
	private static array $options = array();

	public static function post( int $id ): void {
		self::$posts[] = $id;
	}

	public static function user( int $id ): void {
		self::$users[] = $id;
	}

	/** Remembers an option so the test can change it freely. */
	public static function option( string $name ): void {
		if ( ! array_key_exists( $name, self::$options ) ) {
			self::$options[ $name ] = get_option( $name, null );
		}
	}

	public static function run(): void {
		foreach ( array_reverse( self::$posts ) as $id ) {
			if ( function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $id );

				if ( $product ) {
					$product->delete( true );
					continue;
				}

				$order = wc_get_order( $id );

				if ( $order ) {
					$order->delete( true );
					continue;
				}
			}

			wp_delete_post( $id, true );
		}

		foreach ( self::$users as $id ) {
			wp_delete_user( $id );
		}

		foreach ( self::$options as $name => $value ) {
			if ( null === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value );
			}
		}

		self::$posts   = array();
		self::$users   = array();
		self::$options = array();

		Http::release();
	}
}

/** Stands in for the Pushover endpoint. */
final class Http {

	/** @var array<int,array<string,mixed>> Requests that would have gone out. */
	public static array $requests = array();

	/** @var array<int,array<string,mixed>> Responses to hand back, in order. */
	private static array $responses = array();

	private static bool $active = false;

	/**
	 * @param array<int,array<string,mixed>> $responses Responses for consecutive calls.
	 */
	public static function intercept( array $responses = array() ): void {
		self::$requests  = array();
		self::$responses = $responses;

		if ( ! self::$active ) {
			add_filter( 'pre_http_request', array( self::class, 'answer' ), 10, 3 );
			self::$active = true;
		}
	}

	public static function release(): void {
		if ( self::$active ) {
			remove_filter( 'pre_http_request', array( self::class, 'answer' ), 10 );
			self::$active = false;
		}

		self::$requests  = array();
		self::$responses = array();
	}

	/**
	 * @param mixed                $preempt
	 * @param array<string,mixed>  $args
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function answer( $preempt, $args, string $url ) {
		self::$requests[] = array(
			'url'  => $url,
			'body' => (array) ( $args['body'] ?? array() ),
		);

		$next = array_shift( self::$responses );

		if ( null === $next ) {
			return self::ok();
		}

		return $next;
	}

	/** A successful Pushover response. */
	public static function ok(): array {
		return array(
			'headers'  => array(),
			'body'     => '{"status":1,"request":"test"}',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
		);
	}

	/** A permanent refusal, such as a bad recipient key. */
	public static function bad_user(): array {
		return array(
			'headers'  => array(),
			'body'     => '{"user":"invalid","errors":["user identifier is not a valid user"],"status":0}',
			'response' => array(
				'code'    => 400,
				'message' => 'Bad Request',
			),
		);
	}

	/** A temporary refusal worth retrying. */
	public static function unavailable(): array {
		return array(
			'headers'  => array(),
			'body'     => '{"status":0,"errors":["service unavailable"]}',
			'response' => array(
				'code'    => 503,
				'message' => 'Service Unavailable',
			),
		);
	}

	/**
	 * The message bodies sent so far.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function bodies(): array {
		return array_column( self::$requests, 'body' );
	}

	public static function count(): int {
		return count( self::$requests );
	}
}

// ---------------------------------------------------------------------------
// Assertions
// ---------------------------------------------------------------------------

/**
 * @param mixed $expected
 * @param mixed $actual
 */
function equals( $expected, $actual, string $what = '' ): void {
	if ( $expected !== $actual ) {
		throw new Failure(
			sprintf(
				'%sexpected %s, got %s',
				'' !== $what ? $what . ': ' : '',
				var_export( $expected, true ),
				var_export( $actual, true )
			)
		);
	}
}

/** @param mixed $value */
function is_true( $value, string $what = '' ): void {
	equals( true, (bool) $value, '' !== $what ? $what : 'expected true' );
}

/** @param mixed $value */
function is_false( $value, string $what = '' ): void {
	equals( false, (bool) $value, '' !== $what ? $what : 'expected false' );
}

function contains( string $needle, string $haystack, string $what = '' ): void {
	if ( false === stripos( $haystack, $needle ) ) {
		throw new Failure(
			sprintf( '%sexpected to find "%s" in "%s"', '' !== $what ? $what . ': ' : '', $needle, $haystack )
		);
	}
}

function does_not_contain( string $needle, string $haystack, string $what = '' ): void {
	if ( false !== stripos( $haystack, $needle ) ) {
		throw new Failure(
			sprintf( '%sdid not expect "%s" in "%s"', '' !== $what ? $what . ': ' : '', $needle, $haystack )
		);
	}
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/** Turns the channel on with a token that never leaves the test. */
function with_token( string $token = 'abcdefghijklmnopqrstuvwxyz1234' ): void {
	Cleanup::option( \PushNotifications\Settings::OPTION_TOKEN );
	update_option( \PushNotifications\Settings::OPTION_TOKEN, $token );
}

/** A member of staff who can receive notifications. */
function recipient( string $key = 'uuuuuuuuuuuuuuuuuuuuuuuuuuuuuu', bool $muted = false, string $role = 'editor' ): int {
	static $seq = 0;
	++$seq;

	$id = wp_insert_user(
		array(
			'user_login' => 'pushover_test_' . $seq . '_' . wp_rand( 1000, 9999 ),
			'user_pass'  => wp_generate_password(),
			'user_email' => sprintf( 'pushover_test_%d_%d@example.test', $seq, wp_rand( 1000, 9999 ) ),
			'role'       => $role,
		)
	);

	if ( is_wp_error( $id ) ) {
		throw new Failure( 'could not create a recipient: ' . $id->get_error_message() );
	}

	$id = (int) $id;
	Cleanup::user( $id );

	if ( '' !== $key ) {
		update_user_meta( $id, \PushNotifications\Settings::META_USER_KEY, $key );
	}

	if ( $muted ) {
		update_user_meta( $id, \PushNotifications\Settings::META_MUTED, 1 );
	}

	return $id;
}

/**
 * Assigns an event to recipients.
 *
 * @param int[] $user_ids
 */
function route( string $event, array $user_ids, string $priority = 'normal' ): void {
	Cleanup::option( \PushNotifications\Settings::OPTION_ROUTING );

	$config = get_option( \PushNotifications\Settings::OPTION_ROUTING, array() );
	$config = is_array( $config ) ? $config : array();

	$config[ $event ] = array(
		'users'    => array_map( 'intval', $user_ids ),
		'priority' => $priority,
	);

	update_option( \PushNotifications\Settings::OPTION_ROUTING, $config );
}

/** A plain post, the generic object an event can be about. */
function post( string $title = 'Test post' ): int {
	$id = wp_insert_post(
		array(
			'post_title'   => $title,
			'post_content' => 'Test content.',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);

	if ( is_wp_error( $id ) || ! $id ) {
		throw new Failure( 'could not create a post' );
	}

	Cleanup::post( (int) $id );

	return (int) $id;
}

/** True when this install has a shop to test the WooCommerce integration against. */
function shop_present(): bool {
	return class_exists( 'WooCommerce' ) && function_exists( 'wc_create_order' );
}

/** An order with one line, enough for every order event. */
function order( float $total = 249.0 ): \WC_Order {
	$order = wc_create_order();
	$order->set_status( 'pending' );
	$order->set_billing_first_name( 'Testowy' );
	$order->set_billing_last_name( 'Klientowski' );
	$order->set_billing_email( 'klient@example.test' );
	$order->set_billing_phone( '600100200' );
	$order->set_total( (string) $total );
	$order->save();

	Cleanup::post( $order->get_id() );

	return $order;
}

function product( string $name = 'Test product', int $stock = 0 ): \WC_Product_Simple {
	$product = new \WC_Product_Simple();
	$product->set_name( $name );
	$product->set_regular_price( '19.99' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( $stock );
	$product->save();

	Cleanup::post( $product->get_id() );

	return $product;
}
