<?php
/**
 * The recipient key, on the profile of the person it belongs to.
 *
 * A Pushover user key is not a site secret, but it does identify somebody's
 * phone, so only its owner and whoever administers the site ever see it. The
 * fields are shown for accounts that can edit content, which leaves out
 * subscribers and shop customers.
 *
 * @package PushNotifications
 */

declare( strict_types = 1 );

namespace PushNotifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Profile_Fields {

	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'fields' ) );
		add_action( 'edit_user_profile', array( $this, 'fields' ) );
		add_action( 'personal_options_update', array( $this, 'save' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save' ) );
	}

	public function fields( \WP_User $user ): void {
		if ( ! user_can( $user, Settings::recipient_capability() ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$key     = Settings::user_key( (int) $user->ID );
		$muted   = Settings::is_muted( (int) $user->ID );
		$invalid = (string) get_user_meta( $user->ID, Settings::META_KEY_INVALID, true );

		?>
		<h2><?php esc_html_e( 'Push notifications', 'push-notifications-for-wp' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="push_notify_user_key"><?php esc_html_e( 'Pushover user key', 'push-notifications-for-wp' ); ?></label>
				</th>
				<td>
					<input type="text" class="regular-text" id="push_notify_user_key"
						name="push_notify_user_key" value="<?php echo esc_attr( $key ); ?>"
						autocomplete="off" spellcheck="false" />
					<p class="description">
						<?php
						esc_html_e(
							'Install the Pushover app, open it and copy the user key shown on its main screen. Leave empty to stop being a possible recipient.',
							'push-notifications-for-wp'
						);
						?>
					</p>
					<?php if ( '' !== $invalid ) : ?>
						<p class="description" style="color:#b32d2e">
							<?php
							printf(
								/* translators: %s: reason returned by Pushover */
								esc_html__( 'Pushover rejected this key: %s', 'push-notifications-for-wp' ),
								esc_html( $invalid )
							);
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Delivery', 'push-notifications-for-wp' ); ?></th>
				<td>
					<label for="push_notify_muted">
						<input type="checkbox" id="push_notify_muted" name="push_notify_muted" value="1"
							<?php checked( $muted ); ?> />
						<?php esc_html_e( 'Do not send me notifications', 'push-notifications-for-wp' ); ?>
					</label>
					<p class="description">
						<?php
						esc_html_e(
							'Silences every notification for this account without touching the assignments made in the site settings. Useful while away.',
							'push-notifications-for-wp'
						);
						?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		check_admin_referer( 'update-user_' . $user_id );

		$user = get_userdata( $user_id );

		if ( ! $user || ! user_can( $user, Settings::recipient_capability() ) ) {
			return;
		}

		$submitted = isset( $_POST['push_notify_user_key'] )
			? Settings::clean_key( sanitize_text_field( wp_unslash( (string) $_POST['push_notify_user_key'] ) ) )
			: '';

		if ( $submitted !== Settings::user_key( $user_id ) ) {
			// A new key deserves a fresh chance; the old rejection said nothing
			// about this one.
			delete_user_meta( $user_id, Settings::META_KEY_INVALID );
		}

		if ( '' === $submitted ) {
			delete_user_meta( $user_id, Settings::META_USER_KEY );
		} else {
			update_user_meta( $user_id, Settings::META_USER_KEY, $submitted );
		}

		if ( isset( $_POST['push_notify_muted'] ) ) {
			update_user_meta( $user_id, Settings::META_MUTED, 1 );
		} else {
			delete_user_meta( $user_id, Settings::META_MUTED );
		}
	}
}
