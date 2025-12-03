<?php
/**
 * Verification Page Template
 *
 * @package Quick_2FA
 * @since 1.0.0
 *
 * Variables available in this template:
 * @var WP_User     $user                     Current user object
 * @var WP_Error    $error                    Error object (if any)
 * @var string|null $message                  Success message (if any)
 * @var bool        $trusted_devices_enabled  Whether trusted devices feature is enabled
 * @var int         $trusted_device_expiry    Number of days to trust a device
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die(); ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex, nofollow">
	<title><?php esc_html_e( 'Account Verification Required', 'quick-2fa' ); ?> - <?php bloginfo( 'name' ); ?></title>
	<style>
		body {
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
			background: #f0f0f1;
			margin: 0;
			padding: 20px;
		}
		.q2fa-container {
			max-width: 400px;
			margin: 50px auto;
			background: #fff;
			padding: 30px;
			border-radius: 4px;
			box-shadow: 0 1px 3px rgba(0,0,0,0.13);
		}
		h1 {
			margin-top: 0;
			font-size: 24px;
			color: #1d2327;
		}
		p {
			color: #50575e;
			line-height: 1.5;
		}
		.q2fa-error {
			background: #fcf0f1;
			border-left: 4px solid #d63638;
			padding: 12px;
			margin: 16px 0;
		}
		.q2fa-message {
			background: #edfaff;
			border-left: 4px solid #00a0d2;
			padding: 12px;
			margin: 16px 0;
		}
		label {
			display: block;
			margin: 16px 0 8px;
			font-weight: 600;
			color: #1d2327;
		}
		input[type="text"] {
			width: 100%;
			padding: 8px;
			font-size: 18px;
			letter-spacing: 0.2em;
			text-align: center;
			box-sizing: border-box;
		}
		button {
			background: #2271b1;
			color: #fff;
			border: none;
			padding: 10px 20px;
			font-size: 14px;
			cursor: pointer;
			border-radius: 3px;
			margin-top: 16px;
		}
		button:hover {
			background: #135e96;
		}
		.q2fa-resend {
			margin-top: 20px;
			padding-top: 20px;
			border-top: 1px solid #dcdcde;
		}
		.q2fa-resend button {
			background: #dcdcde;
			color: #2c3338;
		}
		.q2fa-resend button:hover {
			background: #c3c4c7;
		}
		.q2fa-footer {
			margin-top: 20px;
			padding-top: 20px;
			border-top: 1px solid #dcdcde;
			text-align: center;
			font-size: 13px;
		}
	</style>
</head>
<body>
	<div class="q2fa-container">
		<h1><?php esc_html_e( 'Account Verification Required', 'quick-2fa' ); ?></h1>
		
		<?php if ( $error ) : ?>
			<div class="q2fa-error">
				<?php echo esc_html( $error->get_error_message() ); ?>
			</div>
		<?php endif; ?>

		<?php if ( $message ) : ?>
			<div class="q2fa-message">
				<?php echo esc_html( $message ); ?>
			</div>
		<?php endif; ?>

		<?php if ( ! $error || 'rate_limited' !== $error->get_error_code() ) : ?>
			<p><?php esc_html_e( 'For your security, we need to verify your identity before you can access the admin area.', 'quick-2fa' ); ?></p>
			
			<p>
			<?php
			printf(
			/* translators: %s: user email address */
				esc_html__( 'A verification code has been sent to %s. Please enter it below.', 'quick-2fa' ),
				'<strong>' . esc_html( $user->user_email ) . '</strong>'
			);
			?>
			</p>

			<form method="post" action="">
				<?php wp_nonce_field( 'quick2fa_verify' ); ?>
				<label for="q2fa_code"><?php esc_html_e( 'Verification Code:', 'quick-2fa' ); ?></label>
				<input 
					type="text" 
					name="q2fa_code" 
					id="q2fa_code" 
					pattern="[0-9]{6}" 
					maxlength="6" 
					required 
					autocomplete="one-time-code" 
					inputmode="numeric"
					autofocus
				>
				<?php if ( $trusted_devices_enabled ) : ?>
					<label style="font-weight: normal; margin-top: 12px; display: flex; align-items: center; cursor: pointer;">
						<input type="checkbox" name="q2fa_trust_device" id="q2fa_trust_device" value="1" style="margin: 0 8px 0 0; width: auto;">
						<span>
							<?php
							printf(
							/* translators: %d: number of days */
								esc_html( _n( 'Trust this device for %d day', 'Trust this device for %d days', $trusted_device_expiry, 'quick-2fa' ) ),
								(int) $trusted_device_expiry
							);
							?>
						</span>
					</label>
				<?php endif; ?>
				<button type="submit" name="q2fa_verify"><?php esc_html_e( 'Verify', 'quick-2fa' ); ?></button>
			</form>

			<div class="q2fa-resend">
				<p><?php esc_html_e( "Didn't receive the code?", 'quick-2fa' ); ?></p>
				<form method="post" action="">
					<?php wp_nonce_field( 'quick2fa_resend' ); ?>
					<button type="submit" name="q2fa_resend"><?php esc_html_e( 'Resend Code', 'quick-2fa' ); ?></button>
				</form>
			</div>
		<?php endif; ?>

		<div class="q2fa-footer">
			<p>&larr; <a href="<?php echo esc_url( wp_logout_url() ); ?>"><?php esc_html_e( 'Log out', 'quick-2fa' ); ?></a></p>
		</div>
	</div>
</body>
</html>
