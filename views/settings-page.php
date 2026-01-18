<?php
/**
 * Settings Page Template
 *
 * @package Quick_2FA
 * @since 1.0.0
 *
 * Variables available in this template:
 * @var string $mode                                Current 2FA mode
 * @var array  $protected_roles                     Array of protected role slugs
 * @var int    $verification_period                 Verification period in days
 * @var int    $code_length                         Verification code length
 * @var int    $code_expiry                         Code expiry in minutes
 * @var string $email_from_name                     Email from name
 * @var string $email_from_address                  Email from address
 * @var string $email_subject                       Email subject
 * @var bool   $password_reminders_enabled          Whether password reminders are enabled
 * @var int    $password_reminder_period            Password reminder period in days
 * @var int    $password_reminder_cooldown          Cooldown between reminders in days
 * @var bool   $trusted_devices_enabled             Whether trusted devices feature is enabled
 * @var int    $trusted_device_expiry               Trusted device expiry in days
 * @var int    $lockout_duration                    Account lockout duration in minutes
 * @var array  $all_roles                           All WordPress roles
 * @var string $const_option_mode                   OPTION_MODE constant value
 * @var string $const_option_protected_roles        OPTION_PROTECTED_ROLES constant value
 * @var string $const_option_verification_period    OPTION_VERIFICATION_PERIOD constant value
 * @var string $const_option_code_length            OPTION_CODE_LENGTH constant value
 * @var string $const_option_code_expiry            OPTION_CODE_EXPIRY constant value
 * @var string $const_option_email_from_name        OPTION_EMAIL_FROM_NAME constant value
 * @var string $const_option_email_from_address     OPTION_EMAIL_FROM_ADDRESS constant value
 * @var string $const_option_email_subject          OPTION_EMAIL_SUBJECT constant value
 * @var string $const_option_password_reminders_enabled    OPTION_PASSWORD_REMINDERS_ENABLED constant value
 * @var string $const_option_password_reminder_period      OPTION_PASSWORD_REMINDER_PERIOD constant value
 * @var string $const_option_password_reminder_cooldown    OPTION_PASSWORD_REMINDER_COOLDOWN constant value
 * @var string $const_option_trusted_device_expiry         OPTION_TRUSTED_DEVICE_EXPIRY constant value
 * @var string $const_option_lockout_duration              OPTION_LOCKOUT_DURATION constant value
 * @var string $const_mode_all                      MODE_ALL constant value
 * @var string $const_mode_roles                    MODE_ROLES constant value
 * @var string $const_mode_disabled                 MODE_DISABLED constant value
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

echo '<div class="wrap">';
printf( '<h1>%s</h1>', esc_html( get_admin_page_title() ) );

echo '<form method="post" action="options.php">';
settings_fields( 'quick2fa_settings' );

// Main Settings Table.
echo '<table class="form-table" role="presentation"><tbody>';

// 2FA Mode Section.
echo '<tr><th scope="row"><label>' . esc_html__( '2FA Mode', 'quick-2fa' ) . '</label></th><td><fieldset>';
printf( '<legend class="screen-reader-text"><span>%s</span></legend>', esc_html__( '2FA Mode', 'quick-2fa' ) );

// Disabled option.
printf(
	'<label><input type="radio" name="%s" value="%s" %s><strong>%s</strong><p class="description">%s</p></label><br>',
	esc_attr( $const_option_mode ),
	esc_attr( $const_mode_disabled ),
	checked( $mode, $const_mode_disabled, false ),
	esc_html__( 'Disabled', 'quick-2fa' ),
	esc_html__( 'Two-factor authentication is disabled. Not recommended for production sites.', 'quick-2fa' )
);

// All users option.
printf(
	'<label><input type="radio" name="%s" value="%s" %s><strong>%s</strong><p class="description">%s</p></label><br>',
	esc_attr( $const_option_mode ),
	esc_attr( $const_mode_all ),
	checked( $mode, $const_mode_all, false ),
	esc_html__( 'Enabled for all users', 'quick-2fa' ),
	esc_html__( 'All users, including subscribers, will be required to verify their identity.', 'quick-2fa' )
);

// Specific roles option.
printf(
	'<label><input type="radio" name="%s" value="%s" %s><strong>%s</strong><p class="description">%s</p></label>',
	esc_attr( $const_option_mode ),
	esc_attr( $const_mode_roles ),
	checked( $mode, $const_mode_roles, false ),
	esc_html__( 'Enabled for specific roles', 'quick-2fa' ),
	esc_html__( 'Only users with selected roles will be required to verify their identity.', 'quick-2fa' )
);

echo '</fieldset></td></tr>';

// Protected Roles Section.
printf(
	'<tr id="protected-roles-row" style="%s"><th scope="row"><label for="quick2fa_protected_roles">%s</label></th><td>',
	$const_mode_roles !== $mode ? 'display:none;' : '',
	esc_html__( 'Protected Roles', 'quick-2fa' )
);

printf(
	'<select name="%s[]" id="quick2fa_protected_roles" multiple="multiple" style="width: 25em;">',
	esc_attr( $const_option_protected_roles )
);

foreach ( $all_roles as $role_slug => $role_name ) {
	printf(
		'<option value="%s" %s>%s</option>',
		esc_attr( $role_slug ),
		in_array( $role_slug, $protected_roles, true ) ? 'selected' : '',
		esc_html( $role_name )
	);
}

printf(
	'</select><p class="description">%s</p></td></tr>',
	esc_html__( 'Select which user roles require two-factor authentication.', 'quick-2fa' )
);

// Verification Period.
printf(
	'<tr><th scope="row"><label for="quick2fa_verification_period">%s</label></th><td><input type="number" name="%s" id="quick2fa_verification_period" value="%s" min="1" max="365" class="small-text"> %s<p class="description">%s</p></td></tr>',
	esc_html__( 'Verification Period', 'quick-2fa' ),
	esc_attr( $const_option_verification_period ),
	esc_attr( $verification_period ),
	esc_html__( 'days', 'quick-2fa' ),
	esc_html__( 'How often users need to re-verify their identity (1-365 days).', 'quick-2fa' )
);

// Code Length.
printf(
	'<tr><th scope="row"><label for="quick2fa_code_length">%s</label></th><td><input type="number" name="%s" id="quick2fa_code_length" value="%s" min="4" max="8" class="small-text"> %s<p class="description">%s</p></td></tr>',
	esc_html__( 'Code Length', 'quick-2fa' ),
	esc_attr( $const_option_code_length ),
	esc_attr( $code_length ),
	esc_html__( 'digits', 'quick-2fa' ),
	esc_html__( 'Length of the verification code (4-8 digits).', 'quick-2fa' )
);

// Code Expiry.
printf(
	'<tr><th scope="row"><label for="quick2fa_code_expiry">%s</label></th><td><input type="number" name="%s" id="quick2fa_code_expiry" value="%s" min="5" max="60" class="small-text"> %s<p class="description">%s</p></td></tr>',
	esc_html__( 'Code Expiry', 'quick-2fa' ),
	esc_attr( $const_option_code_expiry ),
	esc_attr( $code_expiry ),
	esc_html__( 'minutes', 'quick-2fa' ),
	esc_html__( 'How long verification codes remain valid (5-60 minutes).', 'quick-2fa' )
);

// Trusted Device Expiry.
printf(
	'<tr><th scope="row"><label for="quick2fa_trusted_device_expiry">%s</label></th><td><input type="number" name="%s" id="quick2fa_trusted_device_expiry" value="%s" min="1" max="365" class="small-text"> %s<p class="description">%s</p></td></tr>',
	esc_html__( 'Trust Device Duration', 'quick-2fa' ),
	esc_attr( $const_option_trusted_device_expiry ),
	esc_attr( $trusted_device_expiry ),
	esc_html__( 'days', 'quick-2fa' ),
	esc_html__( 'How long a trusted device remains trusted before requiring 2FA again (1-365 days).', 'quick-2fa' )
);

// Account Lockout Duration.
printf(
	'<tr><th scope="row"><label for="quick2fa_lockout_duration">%s</label></th><td><input type="number" name="%s" id="quick2fa_lockout_duration" value="%s" min="1" max="1440" class="small-text"> %s<p class="description">%s</p></td></tr>',
	esc_html__( 'Auto-Lock Duration', 'quick-2fa' ),
	esc_attr( $const_option_lockout_duration ),
	esc_attr( $lockout_duration ),
	esc_html__( 'minutes', 'quick-2fa' ),
	esc_html__( 'Duration to lock accounts after too many failed verification attempts (1-1440 minutes = 1 minute to 24 hours).', 'quick-2fa' )
);

echo '</tbody></table>';

// Email Settings Section.
printf( '<h2>%s</h2>', esc_html__( 'Email Settings', 'quick-2fa' ) );
echo '<table class="form-table" role="presentation"><tbody>';

// Email From Name.
printf(
	'<tr><th scope="row"><label for="quick2fa_email_from_name">%s</label></th><td><input type="text" name="%s" id="quick2fa_email_from_name" value="%s" class="widefat"><p class="description">%s</p></td></tr>',
	esc_html__( 'From Name', 'quick-2fa' ),
	esc_attr( $const_option_email_from_name ),
	esc_attr( $email_from_name ),
	esc_html__( 'The name that appears in the "From" field of verification emails.', 'quick-2fa' )
);

// Email From Address.
printf(
	'<tr><th scope="row"><label for="quick2fa_email_from_address">%s</label></th><td><input type="email" name="%s" id="quick2fa_email_from_address" value="%s" class="widefat"><p class="description">%s</p></td></tr>',
	esc_html__( 'From Address', 'quick-2fa' ),
	esc_attr( $const_option_email_from_address ),
	esc_attr( $email_from_address ),
	esc_html__( 'The email address that appears in the "From" field of verification emails.', 'quick-2fa' )
);

// Email Subject.
printf(
	'<tr><th scope="row"><label for="quick2fa_email_subject">%s</label></th><td><input type="text" name="%s" id="quick2fa_email_subject" value="%s" class="widefat"><p class="description">%s</p></td></tr>',
	esc_html__( 'Email Subject', 'quick-2fa' ),
	esc_attr( $const_option_email_subject ),
	esc_attr( $email_subject ),
	esc_html__( 'Subject line for verification emails.', 'quick-2fa' )
);

echo '</tbody></table>';

// Password Reminder Settings Section.
printf( '<h2>%s</h2>', esc_html__( 'Password Reminder Settings', 'quick-2fa' ) );
echo '<table class="form-table" role="presentation"><tbody>';

// Password Reminders Enabled.
printf(
	'<tr><th scope="row"><label for="quick2fa_password_reminders_enabled">%s</label></th><td><label><input type="checkbox" name="%s" id="quick2fa_password_reminders_enabled" value="1" %s> %s</label><p class="description">%s</p></td></tr>',
	esc_html__( 'Password Reminders', 'quick-2fa' ),
	esc_attr( $const_option_password_reminders_enabled ),
	checked( $password_reminders_enabled, 1, false ),
	esc_html__( 'Enable password change reminders', 'quick-2fa' ),
	esc_html__( 'Periodically remind users to update their passwords.', 'quick-2fa' )
);

// Password Reminder Period.
printf(
	'<tr><th scope="row"><label for="quick2fa_password_reminder_period">%s</label></th><td><input type="number" name="%s" id="quick2fa_password_reminder_period" value="%s" min="30" max="365" class="small-text"> %s<p class="description">%s</p></td></tr>',
	esc_html__( 'Reminder Period', 'quick-2fa' ),
	esc_attr( $const_option_password_reminder_period ),
	esc_attr( $password_reminder_period ),
	esc_html__( 'days', 'quick-2fa' ),
	esc_html__( 'Remind users to change their password after this many days (30-365 days).', 'quick-2fa' )
);

// Password Reminder Cooldown.
printf(
	'<tr><th scope="row"><label for="quick2fa_password_reminder_cooldown">%s</label></th><td><input type="number" name="%s" id="quick2fa_password_reminder_cooldown" value="%s" min="1" max="90" class="small-text"> %s<p class="description">%s</p></td></tr>',
	esc_html__( 'Reminder Cooldown', 'quick-2fa' ),
	esc_attr( $const_option_password_reminder_cooldown ),
	esc_attr( $password_reminder_cooldown ),
	esc_html__( 'days', 'quick-2fa' ),
	esc_html__( 'Wait this many days before showing the reminder again if dismissed (1-90 days).', 'quick-2fa' )
);

echo '</tbody></table>';

submit_button();

echo '</form></div>';
