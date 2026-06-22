# Changelog

All notable changes to Quick 2FA will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.2] — 2026-06-22

### Fixed

- **Recompiled the Spanish, Italian and Greek translation binaries to match their source.** v1.2.1 shipped corrected `.po` sources for the "Lock Out" user-row action (a verb) but stale `.mo` binaries compiled from the previous machine translation, which had rendered it as a noun — `Bloqueo` (es_ES), `Blocco` (it_IT) and `Αποκλεισμός` (el_GR, "exclusion/ban"). Since WordPress loads the `.mo` at runtime, those three locales displayed the wrong label. The `.mo` files now carry the intended verb forms `Bloquear`, `Blocca` and `Κλείδωμα`. No source-string or behaviour changes.

## [1.2.1] — 2026-06-22

### Changed

- Raised the default generated-password length range to **12–20 characters** (was 10–16). This only affects the defaults applied to new installs (`DEFAULT_PASSWORD_LENGTH_MIN` / `DEFAULT_PASSWORD_LENGTH_MAX`); sites that have already saved their password settings keep their configured values.

## [1.2.0] — 2026-06-16

### Changed

- **Device trust is now carried by a secure cookie token instead of a hash of network attributes.** Previously a trusted device was identified by `sha256( client_ip | user_agent )`. On modern connections the client IP is simply not stable — multi-WAN/failover routers egress different sessions via different uplinks, mobile tethering and CGNAT rotate the public IP, IPv6 privacy addressing rotates it on a timer — so the fingerprint changed under users who had not changed anything, and they were re-prompted for 2FA repeatedly (in one diagnosed case, a single user presented 8 distinct public IPs across 6 unrelated ranges, switching IP up to three times in a working day). Trust identity is now a cryptographically random 32-byte token (`bin2hex( random_bytes() )`) set as a cookie on the user's browser when they verify; only the token's SHA-256 is stored server-side (in the `_quick2fa_trusted_devices` user meta, keyed as before). The cookie is `HttpOnly`, `SameSite=Lax`, `Secure` over HTTPS, scoped to `SITECOOKIEPATH` and named with `COOKIEHASH`, mirroring core's auth cookies. The client IP and User-Agent no longer participate in trust decisions at all — they are still recorded in the event log for diagnostics. This also retires the spoofable-header concern around `get_ip_address()` for access control (now log-only) and resolves the long-standing shared-NAT/reverse-proxy fingerprint-collision caveat. New API: `Account_Security_Handler::get_current_device_key()`; `trust_device()` now takes an explicit expiry in seconds. Removed: `get_device_fingerprint()` and the v1.1.4 `normalize_user_agent()` helper (its only consumer).

### Fixed

- **A single login no longer sends multiple verification-code emails.** The verification page emailed a brand-new code on every plain GET, so a browser reload, a speculative "preload" request, or two open admin tabs each spawned another code and another email — capped only by the rate limiter at three per fifteen minutes, which is exactly the burst clients reported. Page loads are now idempotent: a new code is sent only when there is no valid, unexpired one already outstanding (new `Verification_Code_Handler::has_valid_code()`), otherwise the existing code is reused. The **Resend Code** button remains the explicit way to force a fresh code.
- Hardened a latent edge in the old `trust_device()` that added to a pre-cleanup snapshot of the trusted-device list, which could resurrect an entry that had just been pruned for expiry.

### Migration

- **All existing trusted devices stop matching after this update** and every user re-verifies once on their next admin login — the same one-time cost as v1.1.4, for the same underlying reason (the stored keys are no longer derived from anything the request still presents). Stale `IP|User-Agent` entries cannot match a cookie token, are inert security-wise, and are pruned on their normal expiry. No re-keying is possible.

## [1.1.4] — 2026-06-09

### Fixed

- **Trusted devices no longer cause a spurious second 2FA prompt when an upstream proxy rewrites the `User-Agent` header.** The device fingerprint is `sha256( ip | user_agent )` (`Account_Security_Handler::get_device_fingerprint()`), so any byte-level change to the UA produces a different fingerprint, the stored trusted-device key stops matching, and the user is re-prompted. A client hit this when ~1% of their requests arrived with a cosmetically mangled UA — the whitespace between top-level UA tokens (outside parentheses) folded into commas (`Mozilla/5.0,(Macintosh...` instead of `Mozilla/5.0 (Macintosh...`), the signature of a middlebox/proxy re-serializing the header. The fix adds `normalize_user_agent()` (`functions-private.php`), which collapses every run of non-alphanumeric characters to a single space and lowercases the result before hashing; the comma-folded and original strings now normalise identically. The raw user agent is still used unchanged for event logging and the trusted-device list, so the original header remains diagnosable.

### Migration

- **All existing trusted devices stop matching after this update.** Because the fingerprint formula changed, every previously-trusted device produces a new hash on its next request and every user re-verifies once. There is no migration path (the stored sha256 keys cannot be reversed to re-key them), and trusted devices expire on their normal TTL regardless, so this is self-healing — but expect a single round of re-verification across the user base immediately after upgrading.

## [1.1.3] — 2026-05-21

### Fixed

- **WordPress 7.0 compatibility — "Trust this device" checkbox no longer renders white-on-white.** WP 7.0 reorganises the admin color schemes: the `--wp-admin-theme-color` CSS variable is now defined only inside body-class scoped selectors (`body.admin-color-modern`, `body.admin-color-light`, etc.) in `wp-includes/css/dist/base-styles/admin-schemes.min.css`. Core's `wp-login.php` hardcodes `admin-color-modern` on the login body so the variable resolves; our `views/verification-page.php` and `views/password-page.php` did not, so the variable resolved to nothing and the checkbox SVG fill (which uses it) ended up white on the white form background. Both templates now include `admin-color-modern` in the body class list, matching core. The underlying `wp-base-styles` style handle was already pulled in via the `login` style's dependency chain — no enqueue change was needed.

### Compatibility

- Declared tested up to WordPress 7.0 in `readme.txt`. No new minimum-version bump — the fix is forward-compatible and does not affect WP 6.x sites (the extra body class is inert when the WP 7.0 CSS is not present).

## [1.1.2] — 2026-04-30

### Fixed

- **GitHub updater no longer fails silently.** API errors (HTTP failures, non-200 responses, malformed JSON, missing `.zip` assets) now write to PHP's `error_log` unconditionally. Previously these went through the same `WP_DEBUG`-gated logger as routine debug messages, so on production sites — where `WP_DEBUG` is typically off — the updater could be entirely broken with no trace in the logs. The new `log_error()` helper sits alongside the existing debug `log()`; cache hits, "up to date" messages, and other routine flow tracing remain debug-gated. Error log lines now include the request URL so the failure mode is diagnosable from `error_log` alone, without grepping the source.

### Operational

- The `headwalluk/quick-2fa` GitHub repository was made public on 2026-04-30. **This is the actual reason the in-plugin updater was not reaching any sites in v1.0.0–v1.1.1**, despite the documentation describing GitHub Releases as the distribution channel. Unauthenticated calls to a private repo's GitHub API return HTTP 404 (not 403, by design — to avoid leaking the existence of private repos), so the updater's "no release found" code path was being hit on every install. Combined with the silent-logging bug above, this hid the issue completely until v1.1.0 was deployed manually to three sites and v1.1.1 was cut as a no-op test release. No code change is required in v1.1.2 to benefit — the visibility flip alone unblocked existing installs running v1.0.0/v1.0.1/v1.1.0/v1.1.1, which will now jump straight to v1.1.2 on their next update check.

### Translations

- Regenerated `languages/quick-2fa.pot` against v1.1.2. No new translatable strings since v1.1.1; the `.po` files retain their original `Project-Id-Version` per gettext convention.

## [1.1.1] — 2026-04-30

Maintenance release. No functional code changes.

### Changed

- Regenerated all translation files in `languages/` against the current source. The POT `Project-Id-Version` is now in sync with the plugin version, the `Author URI` reference matches the v1.0.1 correction (`headwall-hosting.com`), and source line numbers in the `.po` files reflect the current tree. No new translatable strings.

### Internal

- Cut as a real GitHub Release to validate that the in-plugin updater (renamed in v1.1.0 to `Quick_2FA\Github_Updater`) reaches existing installs end-to-end.

## [1.1.0] — 2026-04-30

### ⚠️ Breaking

- **The `headwall_github_updater_enabled` filter has been renamed to `quick_2fa_updater_enabled`.** Any site using the old filter to disable auto-updates (e.g. on staging environments) will silently stop honouring it after upgrade — auto-updates will re-enable. Update your `add_filter()` call to the new name. The new filter takes a single `bool $enabled` argument; the previous `$plugin_slug` and `$github_repo` arguments are gone (they only made sense when the updater was shared across multiple plugins).

### Changed

- The bundled `Headwall_GitHub_Plugin_Updater` class has been integrated into the plugin namespace as `Quick_2FA\Github_Updater` (file: `includes/class-github-updater.php`). The portable single-file design with the `HW_GITHUB_UPDATER_VERSION` collision guard is gone — it was useful when the same file was copy-pasted into multiple plugins, but Quick 2FA is the only plugin shipping it.
- Updater configuration (GitHub repo, cache key, cache TTL) lives in `constants.php` alongside the rest of the plugin's control knobs.
- The release-data transient key has changed from `headwall_ghu_<md5(repo)>` to `quick_2fa_github_release`. Existing cached data will simply expire on its 12-hour TTL after upgrade — no migration needed.

### Documentation

- Hooks reference, troubleshooting guide, and developer extending guide updated for the new filter name.

## [1.0.1] — 2026-04-17

### Fixed

- **Theme/plugin file editor compatibility.** Clicking "Update File" on a PHP file reverted the change with `loopback_request_failed`. WordPress core does a cookie-authenticated loopback request after a PHP edit to check for fatal errors, but the loopback carries WordPress's own User-Agent rather than the admin's browser UA, so the `IP + User-Agent` trusted-device fingerprint didn't match and the loopback was redirected to the 2FA verification page. `should_skip_check()` now recognises legitimate scrape loopbacks by validating `wp_scrape_key` + `wp_scrape_nonce` against the transient that core itself sets in `wp_edit_theme_plugin_file()` — bare query params without a matching transient still trigger 2FA.

### Changed

- Bundled `Headwall_GitHub_Plugin_Updater` class bumped from 1.1.0 to 1.1.2 (docblock clarifies the `HW_GITHUB_UPDATER_VERSION` collision-guard mechanism; no functional change).
- `Author URI` header corrected from `power-plugins.com` to `headwall-hosting.com`.

### Documentation

- `docs/how-it-works.md` — bypass table updated with the new scrape-loopback skip and the (previously-undocumented) `disabled` mode bypass.

## [1.0.0] — 2026-04-09

Initial public release. Distributed via GitHub releases with in-plugin auto-updates from [`headwalluk/quick-2fa`](https://github.com/headwalluk/quick-2fa).

See [`docs/`](https://github.com/headwalluk/quick-2fa/tree/master/docs) for the full feature documentation.
