<?php
/**
 * Profile Section - Trusted Devices
 *
 * @package Quick_2FA
 * @since 0.6.1
 *
 * Variables available in this template:
 * @var WP_User $user                User object
 * @var array   $trusted_devices     Array of trusted devices (fingerprint => expiry timestamp)
 * @var string  $current_fingerprint Current device fingerprint
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die(); ?>
<h2><?php esc_html_e( 'Two-Factor Authentication', 'quick-2fa' ); ?></h2>
<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><?php esc_html_e( 'Trusted Devices', 'quick-2fa' ); ?></th>
		<td>
			<?php if ( empty( $trusted_devices ) ) : ?>
				<p><?php esc_html_e( 'No trusted devices.', 'quick-2fa' ); ?></p>
			<?php else : ?>
				<p>
					<?php printf( esc_html( _n( 'You have %d trusted device.', 'You have %d trusted devices.', count( $trusted_devices ), 'quick-2fa' ) ), count( $trusted_devices ) ); ?>
				</p>
				<table class="widefat" style="max-width: 600px; margin-top: 10px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Device', 'quick-2fa' ); ?></th>
							<th><?php esc_html_e( 'Expires', 'quick-2fa' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'quick-2fa' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$device_num = 1;
						foreach ( $trusted_devices as $fingerprint => $expiry ) :

							$expires_in_days = ceil( ( $expiry - time() ) / DAY_IN_SECONDS );
							$revoke_url      = wp_nonce_url(
								add_query_arg(
									array(
										'action'      => 'quick2fa_revoke_device',
										'user_id'     => $user->ID,
										'fingerprint' => $fingerprint,
									),
									admin_url( 'admin.php' )
								),
								'quick2fa_revoke_device_' . $user->ID . '_' . $fingerprint
							);
							?>
							<tr>
								<td>
									<?php
									printf( esc_html__( 'Device #%d', 'quick-2fa' ), $device_num++ );
									if ( $fingerprint === $current_fingerprint ) {
										echo ' <strong>(' . esc_html__( 'This Device', 'quick-2fa' ) . ')</strong>';
									}
									?>
								</td>
								<td>
									<?php
									if ( $expires_in_days > 0 ) {
										printf( esc_html( _n( 'In %d day', 'In %d days', $expires_in_days, 'quick-2fa' ) ), $expires_in_days );
									} else {
										esc_html_e( 'Today', 'quick-2fa' );
									}
									?>
								</td>
								<td>
									<a href="<?php echo esc_url( $revoke_url ); ?>" class="button button-secondary button-small">
										<?php esc_html_e( 'Revoke', 'quick-2fa' ); ?>
									</a>
								</td>
							</tr>
							<?php
		endforeach;
						?>
					</tbody>
				</table>
				<p style="margin-top: 10px;">
					<?php
					$revoke_all_url = wp_nonce_url(
						add_query_arg(
							array(
								'action'  => 'quick2fa_revoke_all_devices',
								'user_id' => $user->ID,
							),
							admin_url( 'admin.php' )
						),
						'quick2fa_revoke_all_devices_' . $user->ID
					);
					?>
					<a href="<?php echo esc_url( $revoke_all_url ); ?>" class="button button-secondary">
						<?php esc_html_e( 'Revoke All Devices', 'quick-2fa' ); ?>
					</a>
				</p>
				<p class="description">
					<?php esc_html_e( 'Revoking devices will require re-verification from those devices on the next login.', 'quick-2fa' ); ?>
				</p>
			<?php endif; ?>
		</td>
	</tr>
</table>
