# Configuration

All settings live under **Settings → Quick 2FA** in the WordPress admin. Defaults are deliberately conservative — most sites can leave them alone.

## Mode

Three options control which users are required to verify:

| Mode | Behaviour |
|------|-----------|
| `all` *(default)* | Every user with admin access must verify |
| `roles` | Only users in the configured "protected roles" list must verify |
| `disabled` | 2FA is off — useful as a temporary recovery state |

When **roles** mode is selected, the default protected roles are any role with the `install_plugins` or `manage_options` capability (typically `administrator` and `editor` if your site has elevated editor permissions). You can edit the list in the settings page.

When **disabled** mode is selected, an admin notice is shown on every admin screen warning that 2FA is off. Use the WP-CLI command `wp quick-2fa emergency_disable` if you need to flip to disabled from the command line.

## Verification

| Setting | Default | What it does |
|---------|---------|--------------|
| Verification period | `3` days | How long a successful verification stays valid (only used when trusted devices are disabled — see [trusted devices](trusted-devices.md)) |
| Code length | `6` digits | Length of the emailed numeric code |
| Code expiry | `15` minutes | How long an emailed code remains valid before the user must request a new one |

## Password reminders

Quick 2FA can periodically nudge users to change their password. This is independent from the 2FA flow and runs after a successful verification.

| Setting | Default | What it does |
|---------|---------|--------------|
| Password reminders enabled | `true` | Master switch for the reminder feature |
| Password reminder period | `60` days | Maximum allowed password age before a reminder is shown |
| Password reminder cooldown | `1` day | Minimum time between consecutive reminders for the same user (so they aren't nagged on every page load if they dismiss) |

## Trusted devices

| Setting | Default | What it does |
|---------|---------|--------------|
| Trusted device expiry | `30` days | How long a "trust this device" tick survives before re-verification is required |

The **disable trusted devices** master switch is currently CLI/database-only — there is no checkbox in the settings UI. To force verification on every login regardless of device, run:

```bash
wp option update quick2fa_disable_trusted_devices 1
```

Set it back to `0` to re-enable the feature. See [trusted devices](trusted-devices.md) for the full security model and [WP-CLI → configuration via CLI](wp-cli.md#configuration-via-cli) for other CLI-only toggles.

## Account locking

| Setting | Default | What it does |
|---------|---------|--------------|
| Lockout duration | `60` minutes | How long an account stays locked after exceeding the failed-attempt threshold |

The threshold itself (5 verification attempts before lockout) is currently a constant rather than a setting. See [account locking](account-locking.md) for the full mechanics.

## Email

| Setting | Default | What it does |
|---------|---------|--------------|
| From name | Site name | Sender name on the verification email |
| From address | Site admin email | Sender address |
| Subject | "Your verification code" | Email subject line |

Email delivery uses `wp_mail()`, so whatever your site is configured to use (SMTP plugin, transactional service, system mail) is what gets used. If users aren't receiving codes, troubleshoot your `wp_mail()` setup first — see [troubleshooting](troubleshooting.md).

## Customising the verification and password reminder text

The intro text shown on the verification page and the password reminder page is **not** editable from the settings page. It's customisable via the [`quick2fa_verify_intro`](developers/hooks-and-filters.md#quick2fa_verify_intro) and [`quick2fa_password_intro`](developers/hooks-and-filters.md#quick2fa_password_intro) filters — drop a snippet into your theme's `functions.php` or a site-specific plugin. See [extending Quick 2FA](developers/extending.md) for ready-to-paste examples.
