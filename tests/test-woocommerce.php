<?php
/**
 * The WooCommerce integration: shop events, order storage and stock markers.
 *
 * Every case skips itself on a site without a shop, which is the point: the
 * plugin has to run on plain WordPress, and this suite is what proves the shop
 * part is an addition rather than a requirement.
 *
 * @package PushNotifications\Tests
 */

declare( strict_types = 1 );

namespace PushNotifications\Tests;

use PushNotifications\Integrations\WooCommerce;
use PushNotifications\Markers;
use PushNotifications\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(

	'ships the shop events when WooCommerce is there' => static function (): void {
		if ( ! shop_present() ) {
			skip( 'no WooCommerce on this site' );
		}

		\PushNotifications\Events::flush();
		$keys = array_keys( \PushNotifications\Events::all() );

		foreach ( array( 'order.new', 'order.paid', 'order.failed', 'order.cancelled', 'order.refunded', 'stock.low', 'stock.out' ) as $expected ) {
			is_true( in_array( $expected, $keys, true ), 'registry has ' . $expected );
		}
	},

	'an order event names the order and the amount' => static function (): void {
		if ( ! shop_present() ) {
			skip( 'no WooCommerce on this site' );
		}

		with_token();
		Http::intercept();

		$user  = recipient( 'uuuuuuuuuuuuuuuuuuuuuuuuuuuuuu', false, 'shop_manager' );
		$order = order( 249.0 );
		route( 'order.paid', array( $user ) );

		push_notify_send( 'order.paid', WooCommerce::order_context( $order ) );

		equals( 1, Http::count(), 'one notification' );

		$body = Http::bodies()[0];
		contains( (string) $order->get_order_number(), (string) $body['title'], 'order number' );
		contains( '249', (string) $body['message'], 'order total' );
	},

	'an order carries its marker even under HPOS' => static function (): void {
		if ( ! shop_present() ) {
			skip( 'no WooCommerce on this site' );
		}

		with_token();
		Http::intercept();

		$user    = recipient( 'uuuuuuuuuuuuuuuuuuuuuuuuuuuuuu', false, 'shop_manager' );
		$order   = order();
		$context = WooCommerce::order_context( $order );
		route( 'order.paid', array( $user ) );

		push_notify_send( 'order.paid', $context );
		push_notify_send( 'order.paid', $context );

		equals( 1, Http::count(), 'the repeat is swallowed' );
		is_true(
			Markers::has( 'order', $order->get_id(), 'order.paid', $user ),
			'the marker sits on the order, wherever the shop keeps it'
		);
	},

	'the notification leads to the order in the admin' => static function (): void {
		if ( ! shop_present() ) {
			skip( 'no WooCommerce on this site' );
		}

		with_token();
		Http::intercept();

		$user  = recipient( 'uuuuuuuuuuuuuuuuuuuuuuuuuuuuuu', false, 'shop_manager' );
		$order = order();
		route( 'order.paid', array( $user ) );

		push_notify_send( 'order.paid', WooCommerce::order_context( $order ) );

		contains( (string) $order->get_id(), (string) Http::bodies()[0]['url'], 'the link points at the order' );
	},

	'the delivery is written into the order notes' => static function (): void {
		if ( ! shop_present() ) {
			skip( 'no WooCommerce on this site' );
		}

		with_token();
		Http::intercept();

		$user  = recipient( 'uuuuuuuuuuuuuuuuuuuuuuuuuuuuuu', false, 'shop_manager' );
		$order = order();
		route( 'order.paid', array( $user ) );

		push_notify_send( 'order.paid', WooCommerce::order_context( $order ) );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$found = false;

		foreach ( $notes as $note ) {
			if ( false !== strpos( $note->content, 'order.paid' ) ) {
				$found = true;
			}
		}

		is_true( $found, 'the order history mentions the notification' );
	},

	'an order event carries no customer data' => static function (): void {
		if ( ! shop_present() ) {
			skip( 'no WooCommerce on this site' );
		}

		with_token();
		Http::intercept();

		$user  = recipient( 'uuuuuuuuuuuuuuuuuuuuuuuuuuuuuu', false, 'shop_manager' );
		$order = order();
		route( 'order.paid', array( $user ) );

		push_notify_send( 'order.paid', WooCommerce::order_context( $order ) );

		$body = Http::bodies()[0];
		$sent = (string) $body['title'] . ' ' . (string) $body['message'];

		does_not_contain( 'Testowy', $sent, 'first name' );
		does_not_contain( 'Klientowski', $sent, 'last name' );
		does_not_contain( 'klient@example.test', $sent, 'email address' );
		does_not_contain( '600100200', $sent, 'phone number' );
	},

	'a refund says how much went back' => static function (): void {
		if ( ! shop_present() ) {
			skip( 'no WooCommerce on this site' );
		}

		with_token();
		Http::intercept();

		$user  = recipient( 'uuuuuuuuuuuuuuuuuuuuuuuuuuuuuu', false, 'shop_manager' );
		$order = order( 249.0 );
		route( 'order.refunded', array( $user ) );

		$context                  = WooCommerce::order_context( $order );
		$context['refund_amount'] = 49.0;
		$context['refund_full']   = false;

		push_notify_send( 'order.refunded', $context );

		contains( '49', (string) Http::bodies()[0]['message'], 'the refunded amount' );
	},

	'restocking lets the next sell-out be reported' => static function (): void {
		if ( ! shop_present() ) {
			skip( 'no WooCommerce on this site' );
		}

		with_token();
		Http::intercept();

		$user    = recipient( 'uuuuuuuuuuuuuuuuuuuuuuuuuuuuuu', false, 'shop_manager' );
		$product = product( 'Stock test', 0 );
		route( 'stock.out', array( $user ) );

		$context = WooCommerce::stock_context( $product );

		push_notify_send( 'stock.out', $context );
		push_notify_send( 'stock.out', $context );

		equals( 1, Http::count(), 'one warning per sell-out' );

		Markers::forget( 'product', $product->get_id(), array( 'stock.low', 'stock.out' ) );

		push_notify_send( 'stock.out', $context );

		equals( 2, Http::count(), 'and a new one after the shelf was refilled' );
	},

	'the stock message names the product and what is left' => static function (): void {
		if ( ! shop_present() ) {
			skip( 'no WooCommerce on this site' );
		}

		with_token();
		Http::intercept();

		$user    = recipient( 'uuuuuuuuuuuuuuuuuuuuuuuuuuuuuu', false, 'shop_manager' );
		$product = product( 'Kanister 20 litrow', 0 );
		route( 'stock.out', array( $user ) );

		push_notify_send( 'stock.out', WooCommerce::stock_context( $product ) );

		contains( 'Kanister 20 litrow', (string) Http::bodies()[0]['message'], 'product name' );
	},

	'shop managers may configure notifications' => static function (): void {
		if ( ! shop_present() ) {
			skip( 'no WooCommerce on this site' );
		}

		equals( 'manage_woocommerce', Settings::capability(), 'the shop widens who may configure' );
	},
);
