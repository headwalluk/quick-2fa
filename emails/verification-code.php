<?php
/**
 * Email Template: Verification Code
 *
 * Available variables:
 * - {name}      : User display name
 * - {code}      : Verification code
 * - {site_name} : Site name
 * - {site_url}  : Site URL
 *
 * @package Quick_2FA
 * @since 0.4.0
 */

// Block direct access.
defined( 'ABSPATH' ) || die();

// Translators: Email template with placeholders that will be replaced by actual values.
// {name} = user display name, {code} = verification code, {site_name} = site name, {site_url} = site URL.
// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.Security.EscapeOutput.OutputNotEscaped
echo __(
	"Hello {name},\n\n" .
	"Your verification code is: {code}\n\n" .
	"This code will expire in 15 minutes.\n\n" .
	"If you did not request this code, please contact your site administrator immediately.\n\n" .
	"---\n" .
	"{site_name}\n" .
	'{site_url}',
	'quick-2fa'
);
// phpcs:enable WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.Security.EscapeOutput.OutputNotEscaped
