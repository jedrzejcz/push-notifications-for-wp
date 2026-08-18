<?php
/**
 * The event registry, and the handful of events core WordPress provides.
 *
 * An event is a description, not a piece of code: a key, a label, a group, a
 * default urgency, an optional throttling window and a builder that turns the
 * context into a title and a message. Built-in events are added through the
 * same filter that third-party code and the bundled integrations use, so there
 * is exactly one way in.
 *
 * @package PushNotifications
 */

declare( strict_types = 1 );

namespace PushNotifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Events {

	public const GROUP_CONTENT = 'content';
	public const GROUP_USERS   = 'users';
	public const GROUP_OTHER   = 'other';

	public const PRIORITY_NORMAL = 'normal';
	public const PRIORITY_URGENT = 'urgent';

	/** @var array<string,array<string,mixed>>|null Registry cache for the current request. */
	private static ?array $cache = null;

	public function register(): void {
		add_filter( 'push_notify_events', array( self::class, 'core_events' ), 5 );

		add_action( 'comment_post', array( $this, 'on_comment' ), 10, 2 );
		add_action( 'user_register', array( $this, 'on_user' ), 10, 1 );
	}

	// -----------------------------------------------------------------------
	// Registry
	// -----------------------------------------------------------------------

	/**
	 * Every registered event, keyed by event key.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$registered = (array) apply_filters( 'push_notify_events', array() );
		$groups     = self::groups();
		$clean      = array();

		foreach ( $registered as $key => $definition ) {
			$key = (string) $key;

			if ( ! preg_match( '/^[A-Za-z0-9._-]{1,64}$/', $key ) || ! is_array( $definition ) ) {
				continue;
			}

			$label = isset( $definition['label'] ) ? (string) $definition['label'] : '';

			if ( '' === $label ) {
				continue;
			}

			$group    = (string) ( $definition['group'] ?? self::GROUP_OTHER );
			$priority = (string) ( $definition['priority'] ?? self::PRIORITY_NORMAL );

			$clean[ $key ] = array(
				'label'    => $label,
				'group'    => array_key_exists( $group, $groups ) ? $group : self::GROUP_OTHER,
				'priority' => self::PRIORITY_URGENT === $priority ? self::PRIORITY_URGENT : self::PRIORITY_NORMAL,
				'throttle' => max( 0, (int) ( $definition['throttle'] ?? 0 ) ),
				'build'    => isset( $definition['build'] ) && is_callable( $definition['build'] )
					? $definition['build']
					: null,
			);
		}

		self::$cache = $clean;

		return self::$cache;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function get( string $key ): ?array {
		$all = self::all();

		return $all[ $key ] ?? null;
	}

	/** Drops the request cache. Needed after registering an event late, as tests do. */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Group labels, in the order the settings screen shows them.
	 *
	 * Integrations add their own groups through the filter; anything referring
	 * to a group nobody registered falls back to "Other".
	 *
	 * @return array<string,string>
	 */
	public static function groups(): array {
		$groups = array(
			self::GROUP_CONTENT => __( 'Content', 'push-notifications-for-wp' ),
			self::GROUP_USERS   => __( 'Users', 'push-notifications-for-wp' ),
			self::GROUP_OTHER   => __( 'Other', 'push-notifications-for-wp' ),
		);

		$filtered = (array) apply_filters( 'push_notify_event_groups', $groups );

		// "Other" is where unknown groups land, so it has to survive whatever a
		// filter does and has to come last.
		unset( $filtered[ self::GROUP_OTHER ] );
		$filtered[ self::GROUP_OTHER ] = $groups[ self::GROUP_OTHER ];

		return $filtered;
	}

	/**
	 * The events plain WordPress can offer, with no other plugin involved.
	 *
	 * @param array<string,array<string,mixed>> $events
	 * @return array<string,array<string,mixed>>
	 */
	public static function core_events( array $events ): array {
		$events['core.comment_moderation'] = array(
			'label' => __( 'Comment awaiting moderation', 'push-notifications-for-wp' ),
			'group' => self::GROUP_CONTENT,
			'build' => array( self::class, 'build_comment' ),
		);

		$events['core.user_registered'] = array(
			'label' => __( 'New account registered', 'push-notifications-for-wp' ),
			'group' => self::GROUP_USERS,
			'build' => array( self::class, 'build_user' ),
		);

		return $events;
	}

	// -----------------------------------------------------------------------
	// WordPress hooks
	// -----------------------------------------------------------------------

	/**
	 * @param int|string $comment_id
	 * @param int|string $approved
	 */
	public function on_comment( $comment_id, $approved ): void {
		if ( 1 === (int) $approved ) {
			return;
		}

		$comment = get_comment( (int) $comment_id );

		if ( ! $comment ) {
			return;
		}

		push_notify_send(
			'core.comment_moderation',
			array(
				'object_id'   => (int) $comment_id,
				'object_type' => 'comment',
				'post_title'  => get_the_title( (int) $comment->comment_post_ID ),
				'waiting'     => (int) wp_count_comments()->moderated,
				'url'         => admin_url( 'edit-comments.php?comment_status=moderated' ),
			)
		);
	}

	public function on_user( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		push_notify_send(
			'core.user_registered',
			array(
				'object_id'   => $user_id,
				'object_type' => 'user',
				'role'        => (string) ( $user->roles[0] ?? '' ),
				'url'         => admin_url( sprintf( 'user-edit.php?user_id=%d', $user_id ) ),
			)
		);
	}

	// -----------------------------------------------------------------------
	// Message builders
	// -----------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,string>
	 */
	public static function build_comment( array $context ): array {
		$waiting = (int) ( $context['waiting'] ?? 1 );

		return array(
			'title'   => (string) ( $context['post_title'] ?? '' ),
			'message' => sprintf(
				/* translators: %d: number of comments waiting for moderation */
				_n(
					'%d comment is waiting for moderation.',
					'%d comments are waiting for moderation.',
					$waiting,
					'push-notifications-for-wp'
				),
				$waiting
			),
		);
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,string>
	 */
	public static function build_user( array $context ): array {
		$role = (string) ( $context['role'] ?? '' );

		return array(
			'title'   => __( 'New account', 'push-notifications-for-wp' ),
			'message' => '' !== $role
				? sprintf(
					/* translators: %s: role name of the new account */
					__( 'Somebody registered with the role %s.', 'push-notifications-for-wp' ),
					$role
				)
				: __( 'Somebody registered an account.', 'push-notifications-for-wp' ),
		);
	}
}
