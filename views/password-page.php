<?php
/**
 * Password Reminder Page Template
 *
 * @package Quick_2FA
 * @since 1.0.0
 *
 * Variables available in this template:
 * @var WP_User     $user           Current user object
 * @var WP_Error    $error          Error object (if any)
 * @var string|null $message        Success message (if any)
 * @var int         $days_since     Days since last password change
 * @var string      $new_password   Pre-generated strong password
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die(); ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex, nofollow">
	<title><?php esc_html_e( 'Update Your Password', 'quick-2fa' ); ?> - <?php bloginfo( 'name' ); ?></title>
	<style>
		body {
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
			background: #f0f0f1;
			margin: 0;
			padding: 20px;
		}
		.q2fa-container {
			max-width: 450px;
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
		.q2fa-warning {
			background: #fcf9e8;
			border-left: 4px solid #dba617;
			padding: 12px;
			margin: 16px 0;
		}
		label {
			display: block;
			margin: 16px 0 8px;
			font-weight: 600;
			color: #1d2327;
		}
		input[type="password"],
		input[type="text"] {
			width: 100%;
			padding: 8px;
			font-size: 14px;
			box-sizing: border-box;
			font-family: Consolas, Monaco, monospace;
		}
		.password-wrapper {
			position: relative;
		}
		.toggle-password {
			position: block;
			/* position: absolute;
			right: 8px;
			top: 50%;
			transform: translateY(-50%); */
			background: none;
			border: none;
			color: #2271b1;
			cursor: pointer;
			padding: 4px 8px;
			font-size: 12px;
		}
		.toggle-password:hover {
			color :white;
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
		.button-secondary {
			background: #f6f7f7;
			color: #2c3338;
			border: 1px solid #dcdcde;
			/* margin-left: 8px; */
		}
		.button-secondary:hover {
			background: #f0f0f1;
			border-color: #8c8f94;
		}
		.q2fa-actions {
			margin-top: 20px;
			padding-top: 20px;
			border-top: 1px solid #dcdcde;
		}
		.q2fa-footer {
			margin-top: 20px;
			padding-top: 20px;
			border-top: 1px solid #dcdcde;
			text-align: center;
			font-size: 13px;
		}
		.password-strength {
			margin-top: 8px;
			font-size: 13px;
		}
	</style>
</head>
<body>
	<div class="q2fa-container">
		<h1><?php esc_html_e( 'Update Your Password', 'quick-2fa' ); ?></h1>
		
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

		<div class="q2fa-warning">
			<strong><?php esc_html_e( 'Password Security Reminder', 'quick-2fa' ); ?></strong>
			<p>
				<?php
				printf(
				/* translators: %d: number of days since last password change */
					esc_html__( "It's been %d days since you last changed your password. For your security, we recommend updating it regularly.", 'quick-2fa' ),
					(int) $days_since
				);
				?>
			</p>
		</div>

		<form method="post" action="" autocomplete="on">
			<?php wp_nonce_field( 'quick2fa_password' ); ?>
			
			<!-- Hidden username field for password manager compatibility -->
			<input type="hidden" name="username" value="<?php echo esc_attr( $user->user_email ); ?>" autocomplete="username">
			
			<label for="q2fa_new_password"><?php esc_html_e( 'New Password:', 'quick-2fa' ); ?></label>
			<div class="password-wrapper">
				<input 
					type="password" 
					name="q2fa_new_password" 
					id="q2fa_new_password" 
					value="<?php echo esc_attr( $new_password ); ?>" 
					required 
					autocomplete="new-password"
				>
				<button type="button" class="toggle-password" onclick="togglePassword()">
					<?php esc_html_e( 'Show password', 'quick-2fa' ); ?>
				</button>
			</div>
			<p class="description">
				<?php esc_html_e( 'A strong password has been generated for you. You can use it as-is or change it to your own.', 'quick-2fa' ); ?>
			</p>

			<div style="margin-top: 20px;">
				<button type="submit" name="q2fa_update_password">
					<?php esc_html_e( 'Update Password', 'quick-2fa' ); ?>
				</button>
			</div>
		</form>

		<div class="q2fa-actions">
			<form method="post" action="" style="display: inline;">
				<?php wp_nonce_field( 'quick2fa_remind_later' ); ?>
				<button type="submit" name="q2fa_remind_later" class="button-secondary">
					<?php esc_html_e( 'Remind Me Later', 'quick-2fa' ); ?>
				</button>
			</form>
		</div>

		<div class="q2fa-footer">
			<p>&larr; <a href="<?php echo esc_url( wp_logout_url() ); ?>"><?php esc_html_e( 'Log out', 'quick-2fa' ); ?></a></p>
		</div>
	</div>

	<script>
		function togglePassword() {
			const input = document.getElementById('q2fa_new_password');
			const button = event.target;
			
			if (input.type === 'password') {
				input.type = 'text';
				button.textContent = '<?php echo esc_js( __( 'Hide', 'quick-2fa' ) ); ?>';
			} else {
				input.type = 'password';
				button.textContent = '<?php echo esc_js( __( 'Show', 'quick-2fa' ) ); ?>';
			}
		}
	</script>
</body>
</html>
