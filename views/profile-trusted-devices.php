<?php
/**
 * Profile Section - Trusted Devices
 *
 * @package Quick_2FA
 * @since 0.6.1
 *
 * Variables available in this template:
 * @var WP_User $user                User object
 * @var array   $trusted_devices     Array of trusted devices (device key => expiry timestamp)
 * @var string  $current_fingerprint Device key for the current browser, or '' if none
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

printf( '<h2>%s</h2>', esc_html__( 'Two-Factor Authentication', 'quick-2fa' ) );
echo '<table class="form-table" role="presentation"><tr>';
printf( '<th scope="row">%s</th>', esc_html__( 'Trusted Devices', 'quick-2fa' ) );
echo '<td>';

if ( empty( $trusted_devices ) ) {
	printf( '<p>%s</p>', esc_html__( 'No trusted devices.', 'quick-2fa' ) );
} else {
	/* translators: %d: number of trusted devices */
	$quick_2fa_device_count_text = _n( 'You have %d trusted device.', 'You have %d trusted devices.', count( $trusted_devices ), 'quick-2fa' );
	printf( '<p>%s</p>', sprintf( esc_html( $quick_2fa_device_count_text ), count( $trusted_devices ) ) );

	echo '<table class="widefat" style="max-width: 600px; margin-top: 10px;"><thead><tr>';
	printf( '<th>%s</th>', esc_html__( 'Device', 'quick-2fa' ) );
	printf( '<th>%s</th>', esc_html__( 'Expires', 'quick-2fa' ) );
	printf( '<th>%s</th>', esc_html__( 'Actions', 'quick-2fa' ) );
	echo '</tr></thead><tbody>';

	$quick_2fa_device_num = 1;
	foreach ( $trusted_devices as $quick_2fa_fingerprint => $quick_2fa_expiry ) {
		$quick_2fa_expires_in_days = ceil( ( $quick_2fa_expiry - time() ) / DAY_IN_SECONDS );
		$quick_2fa_revoke_url      = wp_nonce_url(
			add_query_arg(
				array(
					'action'      => 'quick2fa_revoke_device',
					'user_id'     => $user->ID,
					'fingerprint' => $quick_2fa_fingerprint,
				),
				admin_url( 'admin.php' )
			),
			'quick2fa_revoke_device_' . $user->ID . '_' . $quick_2fa_fingerprint
		);

		echo '<tr><td>';

		/* translators: %d: device number */
		printf( esc_html__( 'Device #%d', 'quick-2fa' ), (int) $quick_2fa_device_num );
		if ( $quick_2fa_fingerprint === $current_fingerprint ) {
			printf( ' <strong>(%s)</strong>', esc_html__( 'This Device', 'quick-2fa' ) );
		}
		++$quick_2fa_device_num;

		echo '</td><td>';

		if ( $quick_2fa_expires_in_days > 0 ) {
			/* translators: %d: number of days until expiry */
			$quick_2fa_expiry_text = _n( 'In %d day', 'In %d days', $quick_2fa_expires_in_days, 'quick-2fa' );
			printf( esc_html( $quick_2fa_expiry_text ), (int) $quick_2fa_expires_in_days );
		} else {
			echo esc_html__( 'Today', 'quick-2fa' );
		}

		echo '</td><td>';

		printf(
			'<a href="%s" class="button button-secondary button-small">%s</a>',
			esc_url( $quick_2fa_revoke_url ),
			esc_html__( 'Revoke', 'quick-2fa' )
		);

		echo '</td></tr>';
	}

	echo '</tbody></table>';

	$quick_2fa_revoke_all_url = wp_nonce_url(
		add_query_arg(
			array(
				'action'  => 'quick2fa_revoke_all_devices',
				'user_id' => $user->ID,
			),
			admin_url( 'admin.php' )
		),
		'quick2fa_revoke_all_devices_' . $user->ID
	);

	printf( '<p style="margin-top: 10px;">' );
	printf(
		'<a href="%s" class="button button-secondary">%s</a>',
		esc_url( $quick_2fa_revoke_all_url ),
		esc_html__( 'Revoke All Devices', 'quick-2fa' )
	);
	echo '</p>';

	printf(
		'<p class="description">%s</p>',
		esc_html__( 'Revoking devices will require re-verification from those devices on the next login.', 'quick-2fa' )
	);
}

echo '</td></tr></table>';
