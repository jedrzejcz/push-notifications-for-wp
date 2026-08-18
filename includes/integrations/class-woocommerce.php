<?php
/**
 * WooCommerce events.
 *
 * Loaded only when WooCommerce is active, and built entirely on the public API
 * of this plugin: it registers events through the `push_notify_events` filter
 * and reports them with `push_notify_send()`, exactly as a third-party plugin
 * would. If anything here needed more than that, the API would be too narrow
 * and the API would be what needs fixing.
 *
 * What is genuinely specific to WooCommerce and cannot live in the core:
 * orders are not posts under High-Performance Order Storage, so their
 * idempotency markers and their notes go through the filters the core exposes
 * for exactly this case.
 *
 * @package PushNotifications
 */

declare( strict_types = 1 );

namespace PushNotifications\Integrations;

use PushNotifications\Events;
use PushNotifications\Markers;
use PushNotifications\Message;
use PushNotifications\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WooCommerce {

	public const GROUP_ORDERS = 'orders';
	public const GROUP_STOCK  = 'stock';

	public function register(): void {
		add_filter( 'push_notify_event_groups', array( $this, 'groups' ) );
		add_filter( 'push_notify_events', array( $this, 'events' ), 5 );

		// Shop managers run the shop without being site administrators, and the
		// notification matrix is part of running the shop.
		add_filter( 'push_notify_manage_capability', static fn(): string => 'manage_woocommerce' );

		// Orders live outside the posts table under HPOS, so their markers and
		// their log entries need the shop's own storage.
		add_filter( 'push_notify_read_markers', array( $this, 'read_markers' ), 10, 3 );
		add_filter( 'push_notify_write_markers', array( $this, 'write_markers' ), 10, 4 );
		add_filter( 'push_notify_object_url', array( $this, 'object_url' ), 10, 3 );
		add_action( 'push_notify_logged', array( $this, 'order_note' ), 10, 2 );

		// Order placed. Classic checkout and the Store API checkout each have
		// their own moment; `woocommerce_new_order` is deliberately not used,
		// because block checkout creates draft orders long before anyone buys.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order_placed' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_order_placed' ), 10, 1 );

		// Paid. Gateways call `payment_complete`, offline methods only move the
		// order to processing. Both are reported; the idempotency marker keeps
		// the recipient from hearing about it twice.
		add_action( 'woocommerce_payment_complete', array( $this, 'on_paid' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'on_paid' ), 10, 1 );

		add_action( 'woocommerce_order_status_failed', array( $this, 'on_failed' ), 10, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'on_cancelled' ), 10, 1 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refunded' ), 10, 2 );

		add_action( 'woocommerce_low_stock', array( $this, 'on_low_stock' ), 10, 1 );
		add_action( 'woocommerce_no_stock', array( $this, 'on_no_stock' ), 10, 1 );

		// Restocking clears the marker, so the next sell-out is reported again.
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_stock_set' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_stock_set' ), 10, 1 );
	}

	/**
	 * @param array<string,string> $groups
	 * @return array<string,string>
	 */
	public function groups( array $groups ): array {
		return array_merge(
			array(
				self::GROUP_ORDERS => __( 'Orders and payments', 'push-notifications-for-wp' ),
				self::GROUP_STOCK  => __( 'Stock', 'push-notifications-for-wp' ),
			),
			$groups
		);
	}

	/**
	 * @param array<string,array<string,mixed>> $events
	 * @return array<string,array<string,mixed>>
	 */
	public function events( array $events ): array {
		$events['order.new'] = array(
			'label' => __( 'New order', 'push-notifications-for-wp' ),
			'group' => self::GROUP_ORDERS,
			'build' => array( self::class, 'build_order' ),
		);

		$events['order.paid'] = array(
			'label' => __( 'Order paid', 'push-notifications-for-wp' ),
			'group' => self::GROUP_ORDERS,
			'build' => array( self::class, 'build_order' ),
		);

		$events['order.failed'] = array(
			'label'    => __( 'Payment failed', 'push-notifications-for-wp' ),
			'group'    => self::GROUP_ORDERS,
			'priority' => Events::PRIORITY_URGENT,
			'build'    => array( self::class, 'build_order' ),
		);

		$events['order.cancelled'] = array(
			'label' => __( 'Order cancelled', 'push-notifications-for-wp' ),
			'group' => self::GROUP_ORDERS,
			'build' => array( self::class, 'build_order' ),
		);

		$events['order.refunded'] = array(
			'label' => __( 'Refund issued', 'push-notifications-for-wp' ),
			'group' => self::GROUP_ORDERS,
			'build' => array( self::class, 'build_refund' ),
		);

		$events['stock.low'] = array(
			'label' => __( 'Low stock', 'push-notifications-for-wp' ),
			'group' => self::GROUP_STOCK,
			'build' => array( self::class, 'build_stock' ),
		);

		$events['stock.out'] = array(
			'label'    => __( 'Out of stock', 'push-notifications-for-wp' ),
			'group'    => self::GROUP_STOCK,
			'priority' => Events::PRIORITY_URGENT,
			'build'    => array( self::class, 'build_stock' ),
		);

		return $events;
	}

	// -----------------------------------------------------------------------
	// Order storage: markers, links and notes
	// -----------------------------------------------------------------------

	/**
	 * @param array<int,string>|null $markers
	 * @return array<int,string>|null
	 */
	public function read_markers( $markers, string $type, int $id ) {
		if ( 'order' !== $type ) {
			return $markers;
		}

		$order  = wc_get_order( $id );
		$stored = $order instanceof \WC_Order ? $order->get_meta( Settings::META_SENT ) : array();

		return is_array( $stored ) ? array_map( 'strval', $stored ) : array();
	}

	/**
	 * @param bool     $handled
	 * @param string[] $markers
	 */
	public function write_markers( bool $handled, string $type, int $id, array $markers ): bool {
		if ( 'order' !== $type ) {
			return $handled;
		}

		$order = wc_get_order( $id );

		if ( $order instanceof \WC_Order ) {
			$order->update_meta_data( Settings::META_SENT, $markers );
			$order->save();
		}

		return true;
	}

	public function object_url( string $url, string $type, int $id ): string {
		if ( 'order' !== $type ) {
			return $url;
		}

		$order = wc_get_order( $id );

		return $order instanceof \WC_Order ? (string) $order->get_edit_order_url() : $url;
	}

	/**
	 * Anything about an order is also written into that order's notes, where
	 * whoever is investigating the order is already looking.
	 *
	 * @param array<string,mixed> $entry
	 * @param array<string,mixed> $context
	 */
	public function order_note( array $entry, array $context ): void {
		$order_id = (int) ( $context['order_id'] ?? 0 );

		if ( $order_id <= 0 && 'order' === (string) ( $context['object_type'] ?? '' ) ) {
			$order_id = (int) ( $context['object_id'] ?? 0 );
		}

		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$user = ! empty( $entry['user'] ) ? get_userdata( (int) $entry['user'] ) : null;

		$order->add_order_note(
			sprintf(
				/* translators: 1: event key, 2: recipient name, 3: result, 4: details */
				__( '[push] %1$s to %2$s: %3$s %4$s', 'push-notifications-for-wp' ),
				(string) $entry['event'],
				$user ? $user->display_name : __( 'unknown recipient', 'push-notifications-for-wp' ),
				(string) $entry['result'],
				(string) $entry['detail']
			)
		);
	}

	// -----------------------------------------------------------------------
	// WooCommerce hooks
	// -----------------------------------------------------------------------

	/**
	 * @param int|\WC_Order $order Order id (classic checkout) or object (Store API).
	 */
	public function on_order_placed( $order ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Draft orders are the block checkout filling a cart, not a purchase.
		if ( in_array( $order->get_status(), array( 'checkout-draft', 'draft', 'auto-draft', 'trash' ), true ) ) {
			return;
		}

		push_notify_send( 'order.new', self::order_context( $order ) );
	}

	public function on_paid( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( $order instanceof \WC_Order ) {
			push_notify_send( 'order.paid', self::order_context( $order ) );
		}
	}

	public function on_failed( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( $order instanceof \WC_Order ) {
			push_notify_send( 'order.failed', self::order_context( $order ) );
		}
	}

	public function on_cancelled( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( $order instanceof \WC_Order ) {
			push_notify_send( 'order.cancelled', self::order_context( $order ) );
		}
	}

	public function on_refunded( int $order_id, int $refund_id ): void {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$context                  = self::order_context( $order );
		$context['refund_id']     = $refund_id;
		$context['refund_amount'] = $refund ? abs( (float) $refund->get_total() ) : 0.0;
		$context['refund_full']   = ! $order->get_remaining_refund_amount();

		push_notify_send( 'order.refunded', $context );
	}

	/** @param \WC_Product $product */
	public function on_low_stock( $product ): void {
		if ( $product instanceof \WC_Product ) {
			push_notify_send( 'stock.low', self::stock_context( $product ) );
		}
	}

	/** @param \WC_Product $product */
	public function on_no_stock( $product ): void {
		if ( $product instanceof \WC_Product ) {
			push_notify_send( 'stock.out', self::stock_context( $product ) );
		}
	}

	/**
	 * Restocking above the low threshold forgets that we ever warned.
	 *
	 * Without this the second sell-out of the same product would be silent,
	 * which is the one thing a stock warning must not be.
	 *
	 * @param \WC_Product $product
	 */
	public function on_stock_set( $product ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$stock = $product->get_stock_quantity();

		if ( null === $stock ) {
			return;
		}

		$threshold = function_exists( 'wc_get_low_stock_amount' )
			? (int) wc_get_low_stock_amount( $product )
			: 0;

		if ( $stock > $threshold ) {
			Markers::forget( 'product', (int) $product->get_id(), array( 'stock.low', 'stock.out' ) );
		}
	}

	// -----------------------------------------------------------------------
	// Context
	// -----------------------------------------------------------------------

	/**
	 * What travels with an order event.
	 *
	 * Plain values only: the context is stored as queue arguments, and it
	 * deliberately carries nothing that identifies the customer.
	 *
	 * @return array<string,mixed>
	 */
	public static function order_context( \WC_Order $order ): array {
		return array(
			'object_id'   => $order->get_id(),
			'object_type' => 'order',
			'order_id'    => $order->get_id(),
			'number'      => (string) $order->get_order_number(),
			'total'       => (float) $order->get_total(),
			'currency'    => $order->get_currency(),
			'items'       => (int) $order->get_item_count(),
			'status'      => $order->get_status(),
			'method'      => (string) $order->get_payment_method_title(),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function stock_context( \WC_Product $product ): array {
		return array(
			'object_id'   => $product->get_id(),
			'object_type' => 'product',
			'product_id'  => $product->get_id(),
			'name'        => $product->get_name(),
			'sku'         => (string) $product->get_sku(),
			'stock'       => (int) $product->get_stock_quantity(),
		);
	}

	// -----------------------------------------------------------------------
	// Message builders
	// -----------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,string>
	 */
	public static function build_order( array $context ): array {
		$number = (string) ( $context['number'] ?? $context['order_id'] ?? '' );

		return array(
			'title'   => sprintf(
				/* translators: %s: order number */
				__( 'Order %s', 'push-notifications-for-wp' ),
				$number
			),
			'message' => sprintf(
				/* translators: 1: order total, 2: number of items, 3: payment method */
				__( '%1$s, %2$d item(s), %3$s', 'push-notifications-for-wp' ),
				Message::money( $context ),
				(int) ( $context['items'] ?? 0 ),
				'' !== (string) ( $context['method'] ?? '' )
					? (string) $context['method']
					: __( 'no payment method', 'push-notifications-for-wp' )
			),
		);
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,string>
	 */
	public static function build_refund( array $context ): array {
		$number = (string) ( $context['number'] ?? $context['order_id'] ?? '' );
		$amount = Message::money(
			array(
				'total'    => $context['refund_amount'] ?? 0,
				'currency' => $context['currency'] ?? '',
			)
		);

		return array(
			'title'   => sprintf(
				/* translators: %s: order number */
				__( 'Refund on order %s', 'push-notifications-for-wp' ),
				$number
			),
			'message' => ! empty( $context['refund_full'] )
				? sprintf(
					/* translators: %s: refunded amount */
					__( 'Full refund of %s', 'push-notifications-for-wp' ),
					$amount
				)
				: sprintf(
					/* translators: 1: refunded amount, 2: order total */
					__( 'Partial refund of %1$s, order total %2$s', 'push-notifications-for-wp' ),
					$amount,
					Message::money( $context )
				),
		);
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,string>
	 */
	public static function build_stock( array $context ): array {
		$name  = (string) ( $context['name'] ?? '' );
		$sku   = (string) ( $context['sku'] ?? '' );
		$stock = (int) ( $context['stock'] ?? 0 );

		return array(
			'title'   => $stock > 0
				? __( 'Low stock', 'push-notifications-for-wp' )
				: __( 'Out of stock', 'push-notifications-for-wp' ),
			'message' => sprintf(
				/* translators: 1: product name, 2: sku or a dash, 3: units left */
				__( '%1$s (%2$s): %3$d left', 'push-notifications-for-wp' ),
				$name,
				'' !== $sku ? $sku : '-',
				$stock
			),
		);
	}
}
