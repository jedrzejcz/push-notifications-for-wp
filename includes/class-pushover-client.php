<?php
/**
 * The one place that talks to Pushover.
 *
 * Everything above it works with a verdict, not with an HTTP response: was it
 * delivered, is the failure worth retrying, and is the recipient key at fault.
 *
 * @package PushNotifications
 */

declare( strict_types = 1 );

namespace PushNotifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Pushover_Client {

	public const ENDPOINT = 'https://api.pushover.net/1/messages.json';

	/** Sent from a background job, so nobody is waiting on this. */
	private const TIMEOUT = 10;

	/**
	 * @param array<string,mixed> $message
	 * @return array{ok:bool,permanent:bool,invalid_user:bool,reason:string}
	 */
	public function send( string $token, string $user_key, array $message ): array {
		$body = array(
			'token'    => $token,
			'user'     => $user_key,
			'title'    => (string) ( $message['title'] ?? '' ),
			'message'  => (string) ( $message['message'] ?? '' ),
			'priority' => (int) ( $message['priority'] ?? 0 ),
		);

		if ( ! empty( $message['url'] ) ) {
			$body['url']       = (string) $message['url'];
			$body['url_title'] = (string) ( $message['url_title'] ?? '' );
		}

		if ( ! empty( $message['sound'] ) ) {
			$body['sound'] = (string) $message['sound'];
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => self::TIMEOUT,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			// Network trouble says nothing about the request itself, so it is
			// worth trying again.
			return self::verdict( false, false, false, $response->get_error_message() );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		if ( 200 === $code && 1 === (int) ( $decoded['status'] ?? 0 ) ) {
			return self::verdict( true, false, false, '' );
		}

		$reason = self::reason( $decoded, $code );

		// 429 is the monthly message limit and 5xx is the service having a bad
		// day. Both pass, given time. Every other 4xx is our request being
		// wrong, and repeating a wrong request keeps it wrong.
		if ( 429 === $code || $code >= 500 ) {
			return self::verdict( false, false, false, $reason );
		}

		$invalid_user = isset( $decoded['user'] ) || self::mentions_user( $decoded );

		return self::verdict( false, true, $invalid_user, $reason );
	}

	/**
	 * @param array<string,mixed> $decoded
	 */
	private static function reason( array $decoded, int $code ): string {
		$errors = isset( $decoded['errors'] ) && is_array( $decoded['errors'] )
			? array_map( 'strval', $decoded['errors'] )
			: array();

		if ( $errors ) {
			return implode( '; ', $errors );
		}

		return sprintf(
			/* translators: %d: HTTP status code */
			__( 'HTTP %d without an explanation', 'push-notifications-for-wp' ),
			$code
		);
	}

	/**
	 * @param array<string,mixed> $decoded
	 */
	private static function mentions_user( array $decoded ): bool {
		$errors = isset( $decoded['errors'] ) && is_array( $decoded['errors'] ) ? $decoded['errors'] : array();

		foreach ( $errors as $error ) {
			if ( false !== stripos( (string) $error, 'user' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array{ok:bool,permanent:bool,invalid_user:bool,reason:string}
	 */
	private static function verdict( bool $ok, bool $permanent, bool $invalid_user, string $reason ): array {
		return array(
			'ok'           => $ok,
			'permanent'    => $permanent,
			'invalid_user' => $invalid_user,
			'reason'       => $reason,
		);
	}
}
