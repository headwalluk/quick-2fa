# Configuration

All settings live under **Settings → Quick 2FA** in the WordPress admin. The defaults are deliberately conservative, and most sites can leave them alone. Each setting below is listed under the label the settings page shows, with its `wp_options` name for use with `wp option`.

## 2FA Mode

`quick2fa_mode` controls which users are required to verify:

| Setting page label | Value | Behaviour |
|--------------------|-------|-----------|
| Enabled for all users *(default)* | `all` | Every user who reaches the admin area must verify, including subscribers |
| Enabled for specific roles | `roles` | Only users with one of the **Protected Roles** must verify |
| Disabled | `disabled` | 2FA is off. Useful as a temporary recovery state |

**Protected Roles** (`quick2fa_protected_roles`) defaults to every role with the `install_plugins` or `manage_options` capability. On a standard site that is just Administrator.

While the mode is **Disabled**, every admin screen shows users who can manage options an error notice saying that 2FA is off. To switch to disabled from the command line, use [`wp quick-2fa emergency-disable`](wp-cli.md#emergency-disable---yes).

## Verification

| Setting page label | Option | Default | What it does |
|--------------------|--------|---------|--------------|
| Verification Period | `quick2fa_verification_period` | `3` days | How long a verification trusts the device when "Trust this device" is left unticked, and how long a verified login session stays verified after its device is revoked. Not used when trusted devices are disabled; see [trusted devices](trusted-devices.md) |
| Code Length | `quick2fa_code_length` | `6` digits | Length of the emailed numeric code |
| Code Expiry | `quick2fa_code_expiry` | `15` minutes | How long an emailed code remains valid before the user must request a new one |

## Trusted devices

| Setting page label | Option | Default | What it does |
|--------------------|--------|---------|--------------|
| Trust Device Duration | `quick2fa_trusted_device_expiry` | `30` days | How long a device stays trusted after the user ticks "Trust this device" |

Trusted devices can be switched off entirely, so that every login session verifies. That switch has no field on the settings page; see [trusted devices → disabling the feature](trusted-devices.md#disabling-the-feature).

## Account locking

| Setting page label | Option | Default | What it does |
|--------------------|--------|---------|--------------|
| Auto-Lock Duration | `quick2fa_lockout_duration` | `60` minutes | How long an account stays locked after too many wrong codes |

The threshold itself, 5 wrong codes, is fixed rather than a setting. See [account locking](account-locking.md) for the full mechanics.

## Password reminders

Quick 2FA can periodically nudge users to change their password. This is separate from the 2FA check: the reminder is shown on an admin page load once the user has no verification outstanding.

| Setting page label | Option | Default | What it does |
|--------------------|--------|---------|--------------|
| Password Reminders | `quick2fa_password_reminders_enabled` | On | Turns the reminder feature on or off |
| Reminder Period | `quick2fa_password_reminder_period` | `60` days | Password age at which the reminder is shown |
| Reminder Cooldown | `quick2fa_password_reminder_cooldown` | `1` day | How long to wait before showing the reminder again after the user dismisses it |

## Email Settings

| Setting page label | Option | Default | What it does |
|--------------------|--------|---------|--------------|
| From Name | `quick2fa_email_from_name` | Site title | Sender name on the verification email |
| From Address | `quick2fa_email_from_address` | Site admin email | Sender address |
| Email Subject | `quick2fa_email_subject` | "Your verification code" | Subject line |

Email is sent with `wp_mail()`, so it goes out however your site sends mail: an SMTP plugin, a transactional email service or the server's own mail system. If users aren't receiving codes, check your `wp_mail()` setup first; see [troubleshooting](troubleshooting.md#users-arent-receiving-verification-codes).

The reminder page tells the user how often to change their password, using the Reminder Period.

## Uninstall

| Setting page label | Option | Default | What it does |
|--------------------|--------|---------|--------------|
| Plugin Data: Delete all plugin data when uninstalled | `quick2fa_delete_data_on_uninstall` | Off | When the plugin is deleted from the Plugins screen, also delete all of its data. See [how it works → deactivating and deleting the plugin](how-it-works.md#deactivating-and-deleting-the-plugin) |

## Customising the verification and password reminder text

The intro text on the verification page and the password reminder page can't be edited on the settings page. Change it with the [`quick2fa_verify_intro`](developers/hooks-and-filters.md#quick2fa_verify_intro) and [`quick2fa_password_intro`](developers/hooks-and-filters.md#quick2fa_password_intro) filters, from your theme's `functions.php` or a site-specific plugin. See [extending Quick 2FA](developers/extending.md) for examples to copy.
