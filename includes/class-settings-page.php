<?php
/**
 * The settings screen: the application token, the matrix, a test send and a log.
 *
 * It lives under the WooCommerce menu rather than as a WooCommerce settings
 * tab, because the matrix is a table that the settings tab API has no field
 * type for, and a plain submenu page keeps this plugin off WooCommerce
 * internals.
 *
 * @package PushNotifications
 */

declare( strict_types = 1 );

namespace PushNotifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings_Page {

	private const GROUP = 'push_notify';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_action( 'admin_post_push_notify_test', array( $this, 'handle_test' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * A menu entry of its own, not a page tucked inside somebody else's.
	 *
	 * The plugin does not belong to any one plugin's world: it carries events
	 * from WordPress itself, from WooCommerce when it is there, and from
	 * whatever else registers its own. Position 58 puts it below the content
	 * and shop entries and above Appearance.
	 */
	public function menu(): void {
		add_menu_page(
			__( 'Push notifications', 'push-notifications-for-wp' ),
			__( 'Push notifications', 'push-notifications-for-wp' ),
			Settings::capability(),
			Settings::PAGE_SLUG,
			array( $this, 'render' ),
			'dashicons-megaphone',
			58
		);
	}

	public function settings(): void {
		// Created explicitly rather than through register_setting() defaults,
		// so that both options stay out of the autoloaded set: they are read on
		// this screen and in the queue, never on the front end.
		if ( false === get_option( Settings::OPTION_TOKEN, false ) ) {
			add_option( Settings::OPTION_TOKEN, '', '', false );
		}

		if ( false === get_option( Settings::OPTION_ROUTING, false ) ) {
			add_option( Settings::OPTION_ROUTING, array(), '', false );
		}

		register_setting(
			self::GROUP,
			Settings::OPTION_TOKEN,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_token' ),
			)
		);

		register_setting(
			self::GROUP,
			Settings::OPTION_ROUTING,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Routing::class, 'sanitize' ),
			)
		);
	}

	public function assets( string $hook ): void {
		if ( ! str_contains( $hook, Settings::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'push-notify-admin',
			PUSH_NOTIFY_URL . 'assets/admin.css',
			array(),
			VERSION
		);
	}

	/**
	 * Keeps the stored token when the field comes back empty.
	 *
	 * The screen never prints the token in full, so an empty field means
	 * "unchanged", not "delete". Deleting has its own checkbox.
	 *
	 * @param mixed $input
	 */
	public function sanitize_token( $input ): string {
		// Nonce and capability are checked by options.php before this runs.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['push_notify_remove_token'] ) ) {
			return '';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$clean = Settings::clean_key( (string) $input );

		return '' !== $clean ? $clean : Settings::stored_token();
	}

	// -----------------------------------------------------------------------
	// Screen
	// -----------------------------------------------------------------------

	public function render(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to configure notifications for this site.', 'push-notifications-for-wp' ) );
		}

		$users = Routing::eligible_users();

		?>
		<div class="wrap push-notify">
			<h1><?php esc_html_e( 'Push notifications', 'push-notifications-for-wp' ); ?></h1>

			<?php $this->status_box(); ?>
			<?php $this->test_result(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php esc_html_e( 'Application token', 'push-notifications-for-wp' ); ?></h2>
				<?php $this->token_field(); ?>

				<h2><?php esc_html_e( 'Who gets what', 'push-notifications-for-wp' ); ?></h2>
				<?php $this->matrix( $users ); ?>

				<?php submit_button(); ?>
			</form>

			<?php $this->test_form( $users ); ?>
			<?php $this->log_table(); ?>
		</div>
		<?php
	}

	private function status_box(): void {
		$source  = Settings::token_source();
		$enabled = Settings::is_enabled();

		$labels = array(
			'constant'    => __( 'from a constant in wp-config.php', 'push-notifications-for-wp' ),
			'environment' => __( 'from an environment variable', 'push-notifications-for-wp' ),
			'database'    => __( 'from the site database', 'push-notifications-for-wp' ),
			'none'        => __( 'not set anywhere', 'push-notifications-for-wp' ),
		);

		printf(
			'<div class="notice notice-%s inline"><p><strong>%s</strong> %s</p></div>',
			$enabled ? 'success' : 'warning',
			$enabled
				? esc_html__( 'The channel is on.', 'push-notifications-for-wp' )
				: esc_html__( 'The channel is off: nothing is sent until an application token is set.', 'push-notifications-for-wp' ),
			esc_html(
				sprintf(
					/* translators: %s: where the application token comes from */
					__( 'Application token %s.', 'push-notifications-for-wp' ),
					$labels[ $source ]
				)
			)
		);

		$volume = Log::volume();

		if ( $volume > Log::VOLUME_THRESHOLD ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of notifications sent in the last hour */
						__( '%d notifications went out in the last hour. That is usually a sign of something looping rather than of a busy site.', 'push-notifications-for-wp' ),
						$volume
					)
				)
			);
		}
	}

	private function token_field(): void {
		$source = Settings::token_source();
		$stored = Settings::stored_token();
		$fixed  = in_array( $source, array( 'constant', 'environment' ), true );

		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="push_notify_app_token"><?php esc_html_e( 'Application token', 'push-notifications-for-wp' ); ?></label>
				</th>
				<td>
					<input type="text" class="regular-text" id="push_notify_app_token"
						name="<?php echo esc_attr( Settings::OPTION_TOKEN ); ?>" value=""
						autocomplete="off" spellcheck="false"
						placeholder="<?php echo esc_attr( '' !== $stored ? Settings::mask( $stored ) : '' ); ?>"
						<?php disabled( $fixed ); ?> />

					<?php if ( $fixed ) : ?>
						<p class="description">
							<?php
							esc_html_e(
								'The token comes from the site configuration, so the value stored in the database is not used. Remove it there to edit the token here.',
								'push-notifications-for-wp'
							);
							?>
						</p>
					<?php else : ?>
						<p class="description">
							<?php
							esc_html_e(
								'Create an application at pushover.net/apps and paste its API token here. Leave the field empty to keep the current token.',
								'push-notifications-for-wp'
							);
							?>
						</p>
						<?php if ( '' !== $stored ) : ?>
							<p>
								<label>
									<input type="checkbox" name="push_notify_remove_token" value="1" />
									<?php
									printf(
										/* translators: %s: masked token */
										esc_html__( 'Remove the stored token (%s)', 'push-notifications-for-wp' ),
										esc_html( Settings::mask( $stored ) )
									);
									?>
								</label>
							</p>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * @param \WP_User[] $users
	 */
	private function matrix( array $users ): void {
		if ( ! $users ) {
			printf(
				'<p>%s</p>',
				esc_html__(
					'Nobody can receive notifications yet. Everyone who should get them has to open their own profile and paste their Pushover user key there.',
					'push-notifications-for-wp'
				)
			);

			return;
		}

		$events = Events::all();
		$config = Routing::config();

		?>
		<div class="push-notify-matrix-wrap">
			<table class="widefat striped push-notify-matrix">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Event', 'push-notifications-for-wp' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Urgency', 'push-notifications-for-wp' ); ?></th>
						<?php foreach ( $users as $user ) : ?>
							<th scope="col" class="push-notify-person">
								<?php echo esc_html( $user->display_name ); ?>
								<?php if ( Settings::is_muted( (int) $user->ID ) ) : ?>
									<span class="push-notify-muted"><?php esc_html_e( '(muted)', 'push-notifications-for-wp' ); ?></span>
								<?php endif; ?>
							</th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( Events::groups() as $group_key => $group_label ) : ?>
					<?php
					$in_group = array_filter(
						$events,
						static fn( array $event ): bool => $group_key === $event['group']
					);

					if ( ! $in_group ) {
						continue;
					}
					?>
					<tr class="push-notify-group">
						<th colspan="<?php echo esc_attr( (string) ( count( $users ) + 2 ) ); ?>" scope="colgroup">
							<?php echo esc_html( $group_label ); ?>
						</th>
					</tr>
					<?php foreach ( $in_group as $key => $event ) : ?>
						<?php
						$assigned = (array) ( $config[ $key ]['users'] ?? array() );
						$priority = Routing::priority( (string) $key );
						$field    = Settings::OPTION_ROUTING . '[' . $key . ']';
						?>
						<tr>
							<th scope="row">
								<?php echo esc_html( $event['label'] ); ?>
								<code><?php echo esc_html( (string) $key ); ?></code>
							</th>
							<td>
								<select name="<?php echo esc_attr( $field . '[priority]' ); ?>">
									<option value="normal" <?php selected( Events::PRIORITY_NORMAL, $priority ); ?>>
										<?php esc_html_e( 'Normal', 'push-notifications-for-wp' ); ?>
									</option>
									<option value="urgent" <?php selected( Events::PRIORITY_URGENT, $priority ); ?>>
										<?php esc_html_e( 'Urgent (sound, ignores quiet hours)', 'push-notifications-for-wp' ); ?>
									</option>
								</select>
							</td>
							<?php foreach ( $users as $user ) : ?>
								<td class="push-notify-cell">
									<label>
										<span class="screen-reader-text">
											<?php
											printf(
												/* translators: 1: event name, 2: person */
												esc_html__( '%1$s to %2$s', 'push-notifications-for-wp' ),
												esc_html( $event['label'] ),
												esc_html( $user->display_name )
											);
											?>
										</span>
										<input type="checkbox"
											name="<?php echo esc_attr( $field . '[users][]' ); ?>"
											value="<?php echo esc_attr( (string) $user->ID ); ?>"
											<?php checked( in_array( (int) $user->ID, array_map( 'intval', $assigned ), true ) ); ?> />
									</label>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * @param \WP_User[] $users
	 */
	private function test_form( array $users ): void {
		if ( ! $users ) {
			return;
		}

		?>
		<h2><?php esc_html_e( 'Test notification', 'push-notifications-for-wp' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="push_notify_test" />
			<?php wp_nonce_field( 'push_notify_test' ); ?>
			<label for="push_notify_test_user" class="screen-reader-text">
				<?php esc_html_e( 'Recipient', 'push-notifications-for-wp' ); ?>
			</label>
			<select id="push_notify_test_user" name="user">
				<?php foreach ( $users as $user ) : ?>
					<option value="<?php echo esc_attr( (string) $user->ID ); ?>">
						<?php echo esc_html( $user->display_name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Send a test notification', 'push-notifications-for-wp' ), 'secondary', 'submit', false ); ?>
			<p class="description">
				<?php
				esc_html_e(
					'Sends one notification marked as a test. It is not recorded as a site event.',
					'push-notifications-for-wp'
				);
				?>
			</p>
		</form>
		<?php
	}

	private function log_table(): void {
		$entries = Log::entries();

		?>
		<h2><?php esc_html_e( 'Recent deliveries', 'push-notifications-for-wp' ); ?></h2>
		<?php if ( ! $entries ) : ?>
			<p><?php esc_html_e( 'Nothing has been sent yet.', 'push-notifications-for-wp' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'When', 'push-notifications-for-wp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Event', 'push-notifications-for-wp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Recipient', 'push-notifications-for-wp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'push-notifications-for-wp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'push-notifications-for-wp' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $entries as $entry ) : ?>
				<?php $user = ! empty( $entry['user'] ) ? get_userdata( (int) $entry['user'] ) : null; ?>
				<tr>
					<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $entry['time'] ) ); ?></td>
					<td><code><?php echo esc_html( (string) $entry['event'] ); ?></code></td>
					<td><?php echo esc_html( $user ? $user->display_name : '-' ); ?></td>
					<td><?php echo esc_html( (string) $entry['result'] ); ?></td>
					<td><?php echo esc_html( (string) $entry['detail'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// -----------------------------------------------------------------------
	// Test send
	// -----------------------------------------------------------------------

	public function handle_test(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to send notifications from this site.', 'push-notifications-for-wp' ) );
		}

		check_admin_referer( 'push_notify_test' );

		$user_id = isset( $_POST['user'] ) ? absint( wp_unslash( $_POST['user'] ) ) : 0;
		$token   = Settings::app_token();

		if ( '' === $token ) {
			$this->remember_test(
				false,
				__( 'No application token is set, so there is nothing to send with.', 'push-notifications-for-wp' )
			);
			$this->back();
		}

		if ( ! Routing::is_eligible( $user_id ) ) {
			$this->remember_test(
				false,
				__( 'That account cannot receive notifications: it either has no Pushover key or is not allowed to edit content.', 'push-notifications-for-wp' )
			);
			$this->back();
		}

		$client  = new Pushover_Client();
		$verdict = $client->send( $token, Settings::user_key( $user_id ), Message::test() );

		if ( $verdict['ok'] ) {
			delete_user_meta( $user_id, Settings::META_KEY_INVALID );
			$this->remember_test( true, __( 'Delivered. The phone should have it.', 'push-notifications-for-wp' ) );
			$this->back();
		}

		if ( $verdict['invalid_user'] ) {
			update_user_meta( $user_id, Settings::META_KEY_INVALID, $verdict['reason'] );

			$this->remember_test(
				false,
				sprintf(
					/* translators: %s: reason returned by Pushover */
					__( 'Pushover refused the recipient key: %s. Correct it on that person profile.', 'push-notifications-for-wp' ),
					$verdict['reason']
				)
			);
			$this->back();
		}

		$this->remember_test(
			false,
			sprintf(
				/* translators: %s: reason returned by Pushover */
				__( 'Pushover refused the request: %s. Check the application token.', 'push-notifications-for-wp' ),
				$verdict['reason']
			)
		);
		$this->back();
	}

	private function remember_test( bool $ok, string $message ): void {
		set_transient(
			'push_notify_test_' . get_current_user_id(),
			array(
				'ok'      => $ok,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	private function test_result(): void {
		$key    = 'push_notify_test_' . get_current_user_id();
		$result = get_transient( $key );

		if ( ! is_array( $result ) ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-%s inline"><p>%s</p></div>',
			! empty( $result['ok'] ) ? 'success' : 'error',
			esc_html( (string) $result['message'] )
		);
	}

	private function back(): void {
		wp_safe_redirect( Settings::settings_url() );
		exit;
	}

	// -----------------------------------------------------------------------
	// Admin notice about a channel that stopped working
	// -----------------------------------------------------------------------

	public function notices(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			return;
		}

		$screen = get_current_screen();
		$where  = $screen ? $screen->id : '';

		$loud_enough = 'dashboard' === $where
			|| 'plugins' === $where
			|| str_contains( $where, Settings::PAGE_SLUG )
			|| str_contains( $where, 'woocommerce' );

		if ( ! $loud_enough ) {
			return;
		}

		$failure = Log::failure();

		if ( ! $failure ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: 1: event key, 2: reason returned by Pushover */
					__( 'Push notifications are not being delivered. The last failure was on %1$s: %2$s', 'push-notifications-for-wp' ),
					(string) $failure['event'],
					(string) $failure['reason']
				)
			),
			esc_url( Settings::settings_url() ),
			esc_html__( 'Open the notification settings', 'push-notifications-for-wp' )
		);
	}
}
