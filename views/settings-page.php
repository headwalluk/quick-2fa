<?php
/**
 * Settings Page Template
 *
 * @package Quick_2FA
 * @since 1.0.0
 *
 * Variables available in this template:
 * @var string $mode                               Current 2FA mode
 * @var array  $protected_roles                    Array of protected role slugs
 * @var int    $verification_period                Verification period in days
 * @var int    $code_length                        Verification code length
 * @var int    $code_expiry                        Code expiry in minutes
 * @var string $email_from_name                    Email from name
 * @var string $email_from_address                 Email from address
 * @var string $email_subject                      Email subject
 * @var bool   $password_reminders_enabled         Whether password reminders are enabled
 * @var int    $password_reminder_period           Password reminder period in days
 * @var int    $password_reminder_cooldown         Cooldown between reminders in days
 * @var bool   $trusted_devices_enabled            Whether trusted devices feature is enabled
 * @var int    $trusted_device_expiry              Trusted device expiry in days
 * @var int    $lockout_duration                   Account lockout duration in minutes
 * @var array  $all_roles                          All WordPress roles
 * @var string $const_option_mode                  $const_option_mode constant value
 * @var string $const_option_protected_roles       $const_option_protected_roles constant value
 * @var string $const_option_verification_period   $const_option_verification_period constant value
 * @var string $const_option_code_length           $const_option_code_length constant value
 * @var string $const_option_code_expiry           $const_option_code_expiry constant value
 * @var string $const_option_email_from_name       $const_option_email_from_name constant value
 * @var string $const_option_email_from_address    $const_option_email_from_address constant value
 * @var string $const_option_email_subject         $const_option_email_subject constant value
 * @var string $const_option_password_reminders_enabled    $const_option_password_reminders_enabled constant value
 * @var string $const_option_password_reminder_period      $const_option_password_reminder_period constant value
 * @var string $const_option_password_reminder_cooldown    $const_option_password_reminder_cooldown constant value
 * @var string $const_option_enable_trusted_devices        $const_option_enable_trusted_devices constant value
 * @var string $const_option_trusted_device_expiry         $const_option_trusted_device_expiry constant value
 * @var string $const_option_lockout_duration              $const_option_lockout_duration constant value
 * @var string $const_mode_all                     $const_mode_all constant value
 * @var string $const_mode_roles                   $const_mode_roles constant value
 * @var string $const_mode_disabled                $const_mode_disabled constant value
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();
?>
<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<form method="post" action="options.php">
		<?php settings_fields( 'quick2fa_settings' ); ?>

		<table class="form-table" role="presentation">
			<tbody>
				<!-- 2FA Mode -->
				<tr>
					<th scope="row">
						<label><?php esc_html_e( '2FA Mode', 'quick-2fa' ); ?></label>
					</th>
					<td>
						<fieldset>
							<legend class="screen-reader-text">
								<span><?php esc_html_e( '2FA Mode', 'quick-2fa' ); ?></span>
							</legend>
							
							<label>
								<input type="radio" 
										name="<?php echo esc_attr( $const_option_mode ); ?>" 
										value="<?php echo esc_attr( $const_mode_disabled ); ?>" 
										<?php checked( $mode, $const_mode_disabled ); ?>>
								<strong><?php esc_html_e( 'Disabled', 'quick-2fa' ); ?></strong>
								<p class="description">
									<?php esc_html_e( 'Two-factor authentication is disabled. Not recommended for production sites.', 'quick-2fa' ); ?>
								</p>
							</label>
							<br>
							
							<label>
								<input type="radio" 
										name="<?php echo esc_attr( $const_option_mode ); ?>" 
										value="<?php echo esc_attr( $const_mode_all ); ?>" 
										<?php checked( $mode, $const_mode_all ); ?>>
								<strong><?php esc_html_e( 'Enabled for all users', 'quick-2fa' ); ?></strong>
								<p class="description">
									<?php esc_html_e( 'All users, including subscribers, will be required to verify their identity.', 'quick-2fa' ); ?>
								</p>
							</label>
							<br>
							
							<label>
								<input type="radio" 
										name="<?php echo esc_attr( $const_option_mode ); ?>" 
										value="<?php echo esc_attr( $const_mode_roles ); ?>" 
										<?php checked( $mode, $const_mode_roles ); ?>>
								<strong><?php esc_html_e( 'Enabled for specific roles', 'quick-2fa' ); ?></strong>
								<p class="description">
									<?php esc_html_e( 'Only users with selected roles will be required to verify their identity.', 'quick-2fa' ); ?>
								</p>
							</label>
						</fieldset>
					</td>
				</tr>

				<!-- Protected Roles -->
				<tr id="protected-roles-row" style="<?php echo $const_mode_roles !== $mode ? 'display:none;' : ''; ?>">
					<th scope="row">
						<label for="quick2fa_protected_roles"><?php esc_html_e( 'Protected Roles', 'quick-2fa' ); ?></label>
					</th>
					<td>
						<select name="<?php echo esc_attr( $const_option_protected_roles ); ?>[]" 
								id="quick2fa_protected_roles" 
								multiple="multiple" 
								style="width: 25em;">
							<?php foreach ( $all_roles as $role_slug => $role_name ) : ?>
								<option value="<?php echo esc_attr( $role_slug ); ?>" 
										<?php echo in_array( $role_slug, $protected_roles, true ) ? 'selected' : ''; ?>>
									<?php echo esc_html( $role_name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Select which user roles require two-factor authentication.', 'quick-2fa' ); ?>
						</p>
					</td>
				</tr>

				<!-- Verification Period -->
				<tr>
					<th scope="row">
						<label for="quick2fa_verification_period"><?php esc_html_e( 'Verification Period', 'quick-2fa' ); ?></label>
					</th>
					<td>
						<input type="number" 
								name="<?php echo esc_attr( $const_option_verification_period ); ?>" 
								id="quick2fa_verification_period" 
								value="<?php echo esc_attr( $verification_period ); ?>" 
								min="1" 
								max="365" 
								class="small-text">
						<?php esc_html_e( 'days', 'quick-2fa' ); ?>
						<p class="description">
							<?php esc_html_e( 'How often users need to re-verify their identity (1-365 days).', 'quick-2fa' ); ?>
						</p>
					</td>
				</tr>

				<!-- Code Length -->
				<tr>
					<th scope="row">
						<label for="quick2fa_code_length"><?php esc_html_e( 'Code Length', 'quick-2fa' ); ?></label>
					</th>
					<td>
						<input type="number" 
								name="<?php echo esc_attr( $const_option_code_length ); ?>" 
								id="quick2fa_code_length" 
								value="<?php echo esc_attr( $code_length ); ?>" 
								min="4" 
								max="8" 
								class="small-text">
						<?php esc_html_e( 'digits', 'quick-2fa' ); ?>
						<p class="description">
							<?php esc_html_e( 'Length of the verification code (4-8 digits).', 'quick-2fa' ); ?>
						</p>
					</td>
				</tr>

				<!-- Code Expiry -->
				<tr>
					<th scope="row">
						<label for="quick2fa_code_expiry"><?php esc_html_e( 'Code Expiry', 'quick-2fa' ); ?></label>
					</th>
					<td>
						<input type="number" 
								name="<?php echo esc_attr( $const_option_code_expiry ); ?>" 
								id="quick2fa_code_expiry" 
								value="<?php echo esc_attr( $code_expiry ); ?>" 
								min="5" 
								max="60" 
								class="small-text">
						<?php esc_html_e( 'minutes', 'quick-2fa' ); ?>
						<p class="description">
							<?php esc_html_e( 'How long verification codes remain valid (5-60 minutes).', 'quick-2fa' ); ?>
						</p>
					</td>
				</tr>

				<!-- Trusted Devices -->
				<tr>
					<th scope="row">
						<label for="quick2fa_enable_trusted_devices"><?php esc_html_e( 'Trusted Devices', 'quick-2fa' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" 
									name="<?php echo esc_attr( $const_option_enable_trusted_devices ); ?>" 
									id="quick2fa_enable_trusted_devices" 
									value="1" 
									<?php checked( $trusted_devices_enabled, 1 ); ?>>
							<?php esc_html_e( 'Allow users to trust devices', 'quick-2fa' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When enabled, users can choose to trust a device, skipping 2FA on that device.', 'quick-2fa' ); ?>
						</p>
					</td>
				</tr>

				<!-- Trusted Device Expiry -->
				<tr>
					<th scope="row">
						<label for="quick2fa_trusted_device_expiry"><?php esc_html_e( 'Trust Device Duration', 'quick-2fa' ); ?></label>
					</th>
					<td>
						<input type="number" 
								name="<?php echo esc_attr( $const_option_trusted_device_expiry ); ?>" 
								id="quick2fa_trusted_device_expiry" 
								value="<?php echo esc_attr( $trusted_device_expiry ); ?>" 
								min="1" 
								max="365" 
								class="small-text">
						<?php esc_html_e( 'days', 'quick-2fa' ); ?>
						<p class="description">
							<?php esc_html_e( 'How long a trusted device remains trusted before requiring 2FA again (1-365 days).', 'quick-2fa' ); ?>
						</p>
					</td>
				</tr>

				<!-- Account Lockout Duration -->
				<tr>
					<th scope="row">
						<label for="quick2fa_lockout_duration"><?php esc_html_e( 'Auto-Lock Duration', 'quick-2fa' ); ?></label>
					</th>
					<td>
						<input type="number" 
								name="<?php echo esc_attr( $const_option_lockout_duration ); ?>" 
								id="quick2fa_lockout_duration" 
								value="<?php echo esc_attr( $lockout_duration ); ?>" 
								min="1" 
								max="1440" 
								class="small-text">
						<?php esc_html_e( 'minutes', 'quick-2fa' ); ?>
						<p class="description">
							<?php esc_html_e( 'Duration to lock accounts after too many failed verification attempts (1-1440 minutes = 1 minute to 24 hours).', 'quick-2fa' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Email Settings', 'quick-2fa' ); ?></h2>
		
		<table class="form-table" role="presentation">
			<tbody>
				<!-- Email From Name -->
				<tr>
					<th scope="row">
						<label for="quick2fa_email_from_name"><?php esc_html_e( 'From Name', 'quick-2fa' ); ?></label>
					</th>
					<td>
					<input type="text" 
							name="<?php echo esc_attr( $const_option_email_from_name ); ?>" 
							id="quick2fa_email_from_name" 
							value="<?php echo esc_attr( $email_from_name ); ?>" 
							class="widefat">
						<p class="description">
							<?php esc_html_e( 'The name that appears in the "From" field of verification emails.', 'quick-2fa' ); ?>
						</p>
					</td>
				</tr>

				<!-- Email From Address -->
				<tr>
					<th scope="row">
						<label for="quick2fa_email_from_address"><?php esc_html_e( 'From Address', 'quick-2fa' ); ?></label>
					</th>
					<td>
					<input type="email" 
							name="<?php echo esc_attr( $const_option_email_from_address ); ?>" 
							id="quick2fa_email_from_address" 
							value="<?php echo esc_attr( $email_from_address ); ?>" 
							class="widefat">
						<p class="description">
							<?php esc_html_e( 'The email address that appears in the "From" field of verification emails.', 'quick-2fa' ); ?>
						</p>
					</td>
				</tr>

				<!-- Email Subject -->
				<tr>
					<th scope="row">
						<label for="quick2fa_email_subject"><?php esc_html_e( 'Email Subject', 'quick-2fa' ); ?></label>
					</th>
					<td>
					<input type="text" 
							name="<?php echo esc_attr( $const_option_email_subject ); ?>" 
							id="quick2fa_email_subject" 
							value="<?php echo esc_attr( $email_subject ); ?>" 
							class="widefat">
						<p class="description">
							<?php esc_html_e( 'Subject line for verification emails.', 'quick-2fa' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Password Reminder Settings', 'quick-2fa' ); ?></h2>
		
		<table class="form-table" role="presentation">
			<tbody>
				<!-- Password Reminders Enabled -->
				<tr>
					<th scope="row">
						<label for="quick2fa_password_reminders_enabled"><?php esc_html_e( 'Password Reminders', 'quick-2fa' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" 
									name="<?php echo esc_attr( $const_option_password_reminders_enabled ); ?>" 
									id="quick2fa_password_reminders_enabled" 
									value="1" 
									<?php checked( $password_reminders_enabled, 1 ); ?>>
							<?php esc_html_e( 'Enable password change reminders', 'quick-2fa' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Periodically remind users to update their passwords.', 'quick-2fa' ); ?>
						</p>
					</td>
				</tr>

				<!-- Password Reminder Period -->
				<tr>
					<th scope="row">
						<label for="quick2fa_password_reminder_period"><?php esc_html_e( 'Reminder Period', 'quick-2fa' ); ?></label>
					</th>
					<td>
						<input type="number" 
								name="<?php echo esc_attr( $const_option_password_reminder_period ); ?>" 
								id="quick2fa_password_reminder_period" 
								value="<?php echo esc_attr( $password_reminder_period ); ?>" 
								min="30" 
								max="365" 
								class="small-text">
						<?php esc_html_e( 'days', 'quick-2fa' ); ?>
						<p class="description">
							<?php esc_html_e( 'Remind users to change their password after this many days (30-365 days).', 'quick-2fa' ); ?>
						</p>
					</td>
				</tr>

				<!-- Password Reminder Cooldown -->
				<tr>
					<th scope="row">
						<label for="quick2fa_password_reminder_cooldown"><?php esc_html_e( 'Reminder Cooldown', 'quick-2fa' ); ?></label>
					</th>
					<td>
						<input type="number" 
								name="<?php echo esc_attr( $const_option_password_reminder_cooldown ); ?>" 
								id="quick2fa_password_reminder_cooldown" 
								value="<?php echo esc_attr( $password_reminder_cooldown ); ?>" 
								min="1" 
								max="90" 
								class="small-text">
						<?php esc_html_e( 'days', 'quick-2fa' ); ?>
						<p class="description">
							<?php esc_html_e( 'Wait this many days before showing the reminder again if dismissed (1-90 days).', 'quick-2fa' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<?php submit_button(); ?>
	</form>
</div>

<script>
	// Show/hide protected roles field based on selected mode
	jQuery(document).ready(function($) {
		$('input[name="<?php echo esc_js( $const_option_mode ); ?>"]').on('change', function() {
			if ($(this).val() === '<?php echo esc_js( $const_mode_roles ); ?>') {
				$('#protected-roles-row').show();
			} else {
				$('#protected-roles-row').hide();
			}
		});
	});
</script>
