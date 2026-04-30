# Troubleshooting

## Users aren't receiving verification codes

99% of the time this is an email delivery problem, not a Quick 2FA problem. Quick 2FA hands the email to `wp_mail()` and trusts WordPress to deliver it.

**Diagnose:**

1. Send a test email from any "test email" plugin or via `wp eval 'wp_mail("you@example.com", "test", "test");'`. If that fails, the issue is your `wp_mail()` setup, not Quick 2FA.
2. Check your spam folder. Verification codes are short transactional emails — some filters flag them.
3. If you use an SMTP plugin, check its log for delivery errors.
4. Check the user's security event log for `code_sent` events with `success: false`. That indicates `wp_mail()` returned a failure.

**Fix:** install a transactional email service (Postmark, SendGrid, AWS SES, Mailgun) and a corresponding WordPress integration. Sending mail straight from PHP via `sendmail` is fragile and frequently lands in spam.

## I'm being asked to verify on every page load

This usually means trusted devices are disabled and your verification period is set very low. Check **Settings → Quick 2FA**:

- Verification period (days): if this is `0`, every page load will trigger verification
- Disable trusted devices: if `true`, every login requires verification (this is by design — see [trusted devices](trusted-devices.md))

## My office is behind a reverse proxy or shared NAT

Quick 2FA fingerprints devices as `SHA-256(client_ip + '|' + user_agent)`. Behind a reverse proxy (Cloudflare, AWS ALB, an office firewall), every user appears to come from the proxy's IP.

**Implications:**

- Two users on the same network with the same browser version will produce the *same* fingerprint
- One user trusting their device may inadvertently extend trust to others on the same network — but **only for the same user account**, since trusted-device lists are stored per-user
- Across different user accounts, there's no cross-contamination

**The takeaway:** Quick 2FA's device trust is *per-user-per-fingerprint*, not *globally-per-fingerprint*. The collision risk on shared infrastructure is account-internal, not cross-account. That said, on highly shared infrastructure you may want to set "Disable trusted devices" to `true` and rely on per-login verification instead.

A future release may add `X-Forwarded-For` parsing or per-device labels — see the project tracker for the open review item.

## I am locked out of my own site

Try these in order:

### 1. Wait it out

If you triggered an *automatic* lockout (failed verification too many times), it lifts after the configured lockout duration (default 60 minutes). Make a coffee, come back.

### 2. WP-CLI emergency disable

If you have shell access:

```bash
wp quick-2fa emergency_disable --yes
```

This sets the plugin to `disabled` mode. Log in normally, then go to **Settings → Quick 2FA** and re-enable 2FA.

### 3. WP-CLI unlock yourself

If your account was manually locked but the plugin is otherwise working:

```bash
wp quick-2fa unlock <your_login>
```

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

Check the user's event log for the source. Either:

- An admin manually locked the account from the Users table or via `wp quick-2fa lock`
- Someone *else* tried to log in as you and tripped the rate limit. Check `verification_failed` events for IP addresses that aren't yours

If you see suspicious lock activity, treat it as a possible credential compromise and rotate the password.

## "Too many verification codes requested" — but I only requested one

This is the per-user code generation rate limit (3 codes per 15 minutes). If you're seeing this without having requested multiple codes, possible causes:

- Multiple browser tabs open on the verification page, each triggering a code request on render
- A previous session left a partially-completed verification, then you started a fresh login
- Someone else attempting to log in as you, eating into your quota

Wait 15 minutes for the rate-limit window to reset, or have an admin clear the transient:

```bash
wp transient delete q2fa_rate_limit_code_gen_<user_id>
```

## I changed the lockout duration but locked-out users aren't unlocked

Lock durations are baked in at the moment of lockout. Existing locked accounts will use the duration that was active when they were locked. Either wait it out or manually unlock with `wp quick-2fa unlock <user>`.

## The plugin updater isn't picking up new releases

Quick 2FA polls GitHub for new releases on a 12-hour cache TTL. To force an immediate check:

```bash
wp transient delete quick_2fa_github_release
wp transient delete update_plugins --network
```

Then visit **Dashboard → Updates** in the WordPress admin to trigger a fresh check.

If you want to disable auto-updates entirely (e.g. on staging or for a specific site), see the [`quick_2fa_updater_enabled` filter](developers/hooks-and-filters.md#quick_2fa_updater_enabled).
