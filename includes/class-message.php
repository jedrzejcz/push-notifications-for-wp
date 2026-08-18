<?php
/**
 * Turns an event and its context into what Pushover receives.
 *
 * The rule that shapes this file: no customer data. A push notification sits
 * on a lock screen that other people see, and Pushover is a third party
 * outside the EEA. An order number is enough to know what happened and to
 * open the rest in the admin.
 *
 * @package PushNotifications
 */

declare( strict_types = 1 );

namespace PushNotifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Message {

	/** Service limits. Going over them is a permanent error, so we trim first. */
	public const LIMIT_TITLE   = 250;
	public const LIMIT_MESSAGE = 1024;
	public const LIMIT_URL     = 512;

	/**
	 * @param array<string,mixed> $definition
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	public static function build( string $event, array $definition, array $context ): array {
		$parts = array();

		if ( isset( $definition['build'] ) && is_callable( $definition['build'] ) ) {
			$parts = (array) call_user_func( $definition['build'], $context );
		}

		$title   = (string) ( $parts['title'] ?? $context['title'] ?? $definition['label'] ?? $event );
		$body    = (string) ( $parts['message'] ?? $context['message'] ?? '' );
		$label   = (string) ( $definition['label'] ?? $event );
		$urgent  = Events::PRIORITY_URGENT === Routing::priority( $event );
		$dropped = (int) ( $context['suppressed'] ?? 0 );

		// The event name leads, so the phone shows what happened before the detail.
		$title = '' !== $title ? $label . ': ' . $title : $label;

		if ( $dropped > 0 ) {
			$body .= ' ' . sprintf(
				/* translators: %d: number of further events suppressed by the throttling window */
				_n(
					'(%d more since the last notification)',
					'(%d more since the last notification)',
					$dropped,
					'push-notifications-for-wp'
				),
				$dropped
			);
		}

		$url = self::url( $parts, $context );

		return array(
			'title'     => self::trim_to( $title, self::LIMIT_TITLE ),
			'message'   => self::trim_to( trim( $body ), self::LIMIT_MESSAGE ),
			'url'       => self::trim_to( $url, self::LIMIT_URL ),
			'url_title' => '' !== $url ? __( 'Open in the admin', 'push-notifications-for-wp' ) : '',
			'priority'  => $urgent ? 1 : 0,
			'sound'     => $urgent ? 'persistent' : 'pushover',
		);
	}

	/**
	 * Where the notification leads.
	 *
	 * @param array<string,mixed> $parts
	 * @param array<string,mixed> $context
	 */
	private static function url( array $parts, array $context ): string {
		if ( ! empty( $parts['url'] ) ) {
			return (string) $parts['url'];
		}

		if ( ! empty( $context['url'] ) ) {
			return (string) $context['url'];
		}

		$id = (int) ( $context['object_id'] ?? $context['order_id'] ?? $context['product_id'] ?? 0 );

		if ( $id <= 0 ) {
			return '';
		}

		$type = (string) ( $context['object_type'] ?? ( isset( $context['order_id'] ) ? 'order' : 'post' ) );

		// Objects that do not live in the posts table (WooCommerce orders under
		// High-Performance Order Storage, say) have their link supplied by
		// whoever knows where they live.
		$url = (string) apply_filters( 'push_notify_object_url', '', $type, $id );

		if ( '' !== $url ) {
			return $url;
		}

		if ( 'user' === $type ) {
			return admin_url( sprintf( 'user-edit.php?user_id=%d', $id ) );
		}

		return get_post( $id ) ? admin_url( sprintf( 'post.php?post=%d&action=edit', $id ) ) : '';
	}

	/**
	 * Money as the shop writes it, without the markup wc_price() wraps it in.
	 *
	 * @param array<string,mixed> $context
	 */
	public static function money( array $context ): string {
		$amount   = (float) ( $context['total'] ?? 0 );
		$currency = (string) ( $context['currency'] ?? '' );

		if ( ! function_exists( 'wc_price' ) ) {
			return number_format( $amount, 2 ) . ( '' !== $currency ? ' ' . $currency : '' );
		}

		$args = '' !== $currency ? array( 'currency' => $currency ) : array();

		return html_entity_decode(
			wp_strip_all_tags( wc_price( $amount, $args ) ),
			ENT_QUOTES,
			'UTF-8'
		);
	}

	/** Test message sent from the settings screen. */
	public static function test(): array {
		return array(
			'title'     => __( 'Test notification', 'push-notifications-for-wp' ),
			'message'   => sprintf(
				/* translators: %s: shop name */
				__( 'This is a test notification from %s. If you can read it, the channel works.', 'push-notifications-for-wp' ),
				get_bloginfo( 'name' )
			),
			'url'       => Settings::settings_url(),
			'url_title' => __( 'Open the settings screen', 'push-notifications-for-wp' ),
			'priority'  => 0,
			'sound'     => 'pushover',
		);
	}

	private static function trim_to( string $text, int $limit ): string {
		if ( '' === $text ) {
			return '';
		}

		return mb_strlen( $text ) > $limit ? mb_substr( $text, 0, $limit ) : $text;
	}
}
