# Troubleshooting

## Users aren't receiving verification codes

This is almost always an email delivery problem rather than a Quick 2FA one. Quick 2FA hands the email to `wp_mail()` and relies on WordPress to deliver it.

**Diagnose:**

1. Send a test email from any "test email" plugin or via `wp eval 'wp_mail("you@example.com", "test", "test");'`. If that fails, the issue is your `wp_mail()` setup, not Quick 2FA.
2. Check your spam folder. Verification codes are short transactional emails — some filters flag them.
3. If you use an SMTP plugin, check its log for delivery errors.
4. Check the PHP error log for lines starting `Quick_2FA Email_Handler [error]:`. Quick 2FA logs every failed send there, whether or not `WP_DEBUG` is on, with the reason WordPress reported when it gave one.
5. Check the user's [event log](account-locking.md#event-logging) for `code_sent` events with `success: false`. That means `wp_mail()` reported a failure.

A code whose email failed is discarded, so reloading the verification page tries to send a fresh one. Each attempt counts towards the limit of 3 codes per 15 minutes.

**Fix:** install a transactional email service (Postmark, SendGrid, AWS SES, Mailgun) and a corresponding WordPress integration. Sending mail straight from PHP via `sendmail` is fragile and frequently lands in spam.

## I'm being asked to verify on every page load

First, check whether trusted devices have been turned off. There is no field for this on the settings page; it is the `quick2fa_disable_trusted_devices` option:

```bash
wp option get quick2fa_disable_trusted_devices
```

If it holds a true value (`1`, `true`, `yes` or `on`), device trust is off and users verify once per login session: a code on every login, but not on every page load within a session. See [trusted devices](trusted-devices.md).

If trusted devices are *enabled* and you're still being challenged constantly, the cause is almost always that the **device-trust cookie isn't being kept or sent back**:

- The browser is in a private/incognito window, or is set to clear cookies on close
- A privacy extension or aggressive cookie policy is stripping the `quick2fa_device_…` cookie
- You verified on HTTP but are now browsing on HTTPS (or vice versa) — the cookie is marked `Secure` on HTTPS and won't travel to an HTTP request
- You're switching between different browsers or machines — each is, correctly, a separate device

To confirm: after verifying with "Trust this device" ticked, check your browser's cookies for the current site and look for a `quick2fa_device_…` entry. If it isn't there after a successful verification, something on the client side is discarding it.

## My office is behind a reverse proxy or shared NAT

That makes no difference. Device trust is carried by a per-browser [secure cookie token](trusted-devices.md#what-identifies-a-device), not the client IP, so it doesn't matter whether everyone shares one external IP or the IP changes during the day.

## I am locked out of my own site

Try these in order:

### 1. Wait it out

An *automatic* lock, from entering too many wrong codes, lifts after the **Auto-Lock Duration** (60 minutes by default). A manual lock doesn't lift by itself.

### 2. Unlock yourself with WP-CLI

If you have shell access, this works for automatic and manual locks alike:

```bash
wp quick-2fa unlock <your_login>
```

### 3. Disable 2FA with WP-CLI

If you are not locked but can't complete verification, for example because codes aren't arriving:

```bash
wp quick-2fa emergency-disable --yes
```

This sets the plugin to `disabled` mode. Log in normally, fix the underlying problem, then go to **Settings → Quick 2FA** and turn 2FA back on.

### 4. Direct database edit

If you have no shell access at all but have database access (phpMyAdmin, your hosting control panel):

```sql
-- Disable Quick 2FA entirely
UPDATE wp_options SET option_value = 'disabled' WHERE option_name = 'quick2fa_mode';

-- Or just unlock your own account (replace 1 with your user ID)
DELETE FROM wp_usermeta WHERE user_id = 1 AND meta_key = '_quick2fa_locked_until';
```

Adjust the table prefix (`wp_`) to match your installation.

### 5. SFTP / file system access

Move or rename the plugin folder. WordPress will automatically deactivate it.

```
mv wp-content/plugins/quick-2fa wp-content/plugins/quick-2fa.disabled
```

Once you're back in, rename it back and reconfigure. Your settings, lock status, and event logs are all preserved across deactivation.

## I see "Account Locked" but I never failed any attempts

Check the account's [event log](account-locking.md#event-logging) to see where the lock came from. Either:

- Someone locked the account by hand, from the Users screen or with `wp quick-2fa lock`. The `account_locked` event's data says which
- Someone else entered 5 wrong codes. Check the `verification_failed` events for IP addresses that aren't yours

Only someone who knows the password can reach the verification page, so the second case means the password is compromised. Change it, which also [revokes the account's trusted devices](trusted-devices.md#revoking-devices).

## "Too many verification codes requested" — but I only requested one

This is the per-user limit on sending codes: 3 per 15-minute window, counted from the first code sent. The message says how long is left. Reloading the verification page, or opening it in several tabs, reuses the current code and doesn't count. Possible causes:

- Clicking **Resend Code** several times, which always sends a fresh code
- Codes sent in an earlier login attempt within the same window
- Someone else logging in as you. That needs your password, so if you rule out the first two, change it

Wait for the window to end, or have an admin clear it:

```bash
wp transient delete q2fa_rate_limit_code_gen_<user_id>
```

## I changed the Auto-Lock Duration but locked accounts aren't unlocked

A lock's end time is fixed when the lock starts, so changing the setting only affects new locks. Wait for the existing lock to end, or unlock the account with `wp quick-2fa unlock <user>`.

## The plugin updater isn't picking up new releases

Quick 2FA polls GitHub for new releases on a 12-hour cache TTL. To force an immediate check:

```bash
wp transient delete quick_2fa_github_release
wp transient delete quick_2fa_github_failed
wp transient delete update_plugins --network
wp plugin list --name=quick-2fa --fields=name,version,update,update_version
```

`quick_2fa_github_failed` is set for an hour after a failed lookup, and the updater makes no new request while it exists. The last command runs the fresh check; visiting **Dashboard → Updates** in the WordPress admin does the same.

If you want to disable auto-updates entirely (e.g. on staging or for a specific site), see the [`quick2fa_updater_enabled` filter](developers/hooks-and-filters.md#quick2fa_updater_enabled).
