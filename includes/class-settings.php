<?php
/**
 * Stored configuration: where it lives and how it is read.
 *
 * Kept apart from the settings screen on purpose. The screen is one consumer
 * of these values; the queue, running with no screen at all, is another.
 *
 * @package PushNotifications
 */

declare( strict_types = 1 );

namespace PushNotifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {

	public const OPTION_TOKEN   = 'push_notify_app_token';
	public const OPTION_ROUTING = 'push_notify_routing';
	public const OPTION_LOG     = 'push_notify_log';

	public const META_USER_KEY    = 'push_notify_user_key';
	public const META_MUTED       = 'push_notify_muted';
	public const META_KEY_INVALID = 'push_notify_key_invalid';

	/** Idempotency marker, stored on the object the event is about. */
	public const META_SENT = '_push_notify_sent';

	/** Constant or environment variable holding the application token. */
	public const TOKEN_SOURCE_NAME = 'PUSH_NOTIFY_APP_TOKEN';

	public const PAGE_SLUG = 'push-notify';

	/**
	 * The application token in use.
	 *
	 * A constant in wp-config.php wins over an environment variable, and both
	 * win over the database. A site that can set either should not have to keep
	 * the secret in a table that ends up in every backup.
	 */
	public static function app_token(): string {
		if ( defined( self::TOKEN_SOURCE_NAME ) ) {
			$value = (string) constant( self::TOKEN_SOURCE_NAME );
			if ( '' !== $value ) {
				return $value;
			}
		}

		$env = getenv( self::TOKEN_SOURCE_NAME );
		if ( is_string( $env ) && '' !== trim( $env ) ) {
			return trim( $env );
		}

		return self::stored_token();
	}

	/** Where the token in use comes from: constant, environment, database or nowhere. */
	public static function token_source(): string {
		if ( defined( self::TOKEN_SOURCE_NAME ) && '' !== (string) constant( self::TOKEN_SOURCE_NAME ) ) {
			return 'constant';
		}

		$env = getenv( self::TOKEN_SOURCE_NAME );
		if ( is_string( $env ) && '' !== trim( $env ) ) {
			return 'environment';
		}

		return '' !== self::stored_token() ? 'database' : 'none';
	}

	public static function stored_token(): string {
		return (string) get_option( self::OPTION_TOKEN, '' );
	}

	/** True when the channel has an application token to send with. */
	public static function is_enabled(): bool {
		return '' !== self::app_token();
	}

	/**
	 * Who may configure notifications.
	 *
	 * `manage_options` is the plain WordPress answer: this screen holds a
	 * secret and decides who gets woken up at night. The WooCommerce
	 * integration widens it to `manage_woocommerce`, so shop managers can run
	 * their own shop without being handed the keys to the whole site.
	 */
	public static function capability(): string {
		return (string) apply_filters( 'push_notify_manage_capability', 'manage_options' );
	}

	/**
	 * Who may be a recipient at all.
	 *
	 * `edit_posts` covers editors, authors and WooCommerce shop managers, and
	 * excludes subscribers and shop customers, which is exactly the line this
	 * plugin cares about: notifications are for the people running the site.
	 */
	public static function recipient_capability(): string {
		return (string) apply_filters( 'push_notify_recipient_capability', 'edit_posts' );
	}

	/**
	 * Pushover keys are 30 characters of letters and digits.
	 *
	 * Anything else is a paste accident, so it is stripped rather than stored
	 * and rejected later by the service.
	 */
	public static function clean_key( string $raw ): string {
		$clean = preg_replace( '/[^A-Za-z0-9]/', '', $raw );

		return substr( (string) $clean, 0, 30 );
	}

	/** Last four characters, enough to tell two keys apart and useless on its own. */
	public static function mask( string $key ): string {
		if ( '' === $key ) {
			return '';
		}

		return str_repeat( '.', 4 ) . substr( $key, -4 );
	}

	/** The user key of a recipient, empty when they have not set one. */
	public static function user_key( int $user_id ): string {
		return self::clean_key( (string) get_user_meta( $user_id, self::META_USER_KEY, true ) );
	}

	public static function is_muted( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, self::META_MUTED, true );
	}

	public static function settings_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}
}
