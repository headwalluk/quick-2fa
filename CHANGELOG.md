# Changelog

All notable changes to Quick 2FA will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.5.0] — 2026-09-25

### Added

- **The `quick2fa_updater_enabled` filter** replaces `quick_2fa_updater_enabled`, so every Quick 2FA filter now uses the same `quick2fa_` prefix.

### Deprecated

- **`quick_2fa_updater_enabled`.** It still works: its result is passed on to `quick2fa_updater_enabled`, and WordPress raises a deprecation notice when `WP_DEBUG` is on. Rename `add_filter()` calls to the new name. The old name will be removed no earlier than 2.0.0.

### Fixed

- **Another plugin returning the wrong type from a WordPress hook could crash the Users screen or the login.** Quick 2FA's callbacks declared strict parameter types, so when a plugin's `manage_users_custom_column` callback returned nothing for a column it didn't own, Quick 2FA received `null` and PHP threw a `TypeError`. The users-table column, sorting, view and row-action callbacks, the profile section, the settings-page script loader and the lockout check at login had the same weakness. Each now accepts any value, acts only on a value it can use, and passes anything else through unchanged. Introduced in 0.7.0.
- **WP-CLI often missed new releases, and could hide them from the admin too.** The GitHub updater loaded only in admin and cron requests, so when `wp plugin list` or `wp plugin update` refreshed the update list, Quick 2FA's release was left out, and the refreshed list replaced the one the admin screen had built. It now loads under WP-CLI too. Introduced in 1.0.0.
- **The password-reminder warning showed a blue border instead of amber in right-to-left languages.** Its colour was set inline with `border-left-color`, but WordPress draws the login message border on the right in RTL locales. It now uses the logical `border-inline-start-color` from the plugin's login stylesheet. Introduced in 0.10.0.

### Changed

- The `quick2fa_record_last_login` and `quick2fa_updater_enabled` results are read as booleans the way the plugin reads boolean options, so a callback returning `'no'`, `'off'` or `'false'` now counts as false.
- The WP-CLI reference is corrected: `emergency-disable` was headed with its old underscore name, the CLI does not stop you locking your own account, and the lockdown example excluded the shell user instead of a WordPress login. It also gains a section on plugin updates, and the updater troubleshooting steps now clear the failed-lookup cache.
- `CLAUDE.md` gains rules on public contracts, hook callback types, filter results, CSS and JavaScript, comments and phpcs suppressions.

## [1.4.0] — 2026-09-13

### Added

- **With trusted devices disabled, every login session must now verify.** A successful verification is recorded in that WordPress login session's own data, and the check runs against it. Each new login, on any device, asks for a code, while pages within a verified session do not, and logging out ends the session and its record. Previously, disabling trusted devices fell back to the verification period: a user who had verified within the last 3 days (by default) could log in again from any device without a challenge, although the documentation said every login required verification. Changing password from the password-reminder page now reissues the auth cookie for the current session, as WordPress's own profile page does, so it keeps its verification. **On sites with trusted devices disabled, every existing session is challenged once after upgrading.** Sites using trusted devices, the default, see no change.

### Fixed

- **Storing `false` in `quick2fa_disable_trusted_devices` disabled trusted devices.** The option was read as plain truthy, so `wp option update quick2fa_disable_trusted_devices false` stored the string `"false"` and turned device trust off. It is now read as a boolean: `1`, `true`, `yes` and `on` disable device trust, and anything else leaves it on.
- **A failed verification email left a code behind and logged nothing.** The code was stored before sending, so after a failure a reload found a valid code, sent nothing and showed no error. The undelivered code is now discarded so the next page load tries again, and every failed send is written to the PHP error log with the reason WordPress reported, whether or not `WP_DEBUG` is on.
- Option and user-meta values are cast to their expected type where they are read. A malformed protected-roles option could previously throw a `TypeError` in the role check.

### Changed

- The Verification Period setting's help text now says what the setting does: how long a verification trusts the device when "Trust this device" is left unticked. Translated in all eight locales.
- Removed three unused lockout constants describing a 10-attempts-per-hour policy the plugin never enforced. Lockout is unchanged: the 5th failed attempt against a code locks the account for the Lockout duration setting.
- Documentation corrected for trusted-device behaviour, email-failure troubleshooting and `CLAUDE.md`.

## [1.3.2] — 2026-09-13

### Fixed

- **A password set from the password-reminder page could be saved differently from what was typed.** The submitted password went through `sanitize_text_field()`, which strips anything that looks like an HTML tag, removes `%XX` octets, collapses repeated whitespace and trims the ends. `Abc<def>Ghi123!` was saved as `AbcGhi123!` and `Pass%41word99` as `Password99`, so the user and their password manager held a password that no longer worked, and only a password reset got them back in. Nearly 2% of the passwords the page generates were affected at the default settings (a `%` followed by two hex characters), and around a quarter with the extra special characters enabled through `quick2fa_password_parameters`. The password is now only unslashed, as WordPress core's own reset form does.

### Changed

- Tested up to WordPress 7.1.

## [1.3.1] — 2026-09-13

### Changed

- **No behaviour changes.** This release tidies code structure and comments only. Site owners and integrations won't notice anything, and no translatable strings changed.
- Every `if`/`elseif` chain now ends in an explicit `else`, so each remaining case is spelled out instead of falling through. To allow this, `phpcs.xml` accepts `if`/`elseif`/`else` branches that contain only a comment. Empty `catch`, loop and `switch` bodies are still errors.
- Five docblocks that told the history behind a change now say what the function does, and point to `docs/` or this changelog for the reasoning.
- The request-flow diagram in `docs/how-it-works.md` now shows the theme/plugin editor loopback bypass, which the bypass table already listed.

## [1.3.0] — 2026-09-12

### Added

- **Quick 2FA now records a last-login timestamp for every user.** WordPress core keeps no record of when a user last logged in, and `_quick2fa_last_verified` is not a substitute — it is only written on the login paths 2FA actually guards, so a WooCommerce customer signing in at `/my-account/` never gets one. A new `wp_login` listener writes `_quick2fa_last_login` (Unix timestamp, user meta) on every successful authentication, independent of the verification flow, the configured mode and the protected-roles list. There is no setting and no admin UI; the new `quick2fa_record_last_login` filter turns it off per-user or site-wide.
- **A recording epoch, so absence of a timestamp is interpretable.** The first login recorded on a site also stamps `quick2fa_last_login_since` (site option, Unix timestamp). Without it, "never logged in" and "last logged in before this site started recording" are indistinguishable, which makes the data unsafe for anything destructive until it has a year or more behind it. With it, an account registered after the epoch and carrying no timestamp provably never logged in — useful from the first day rather than the first year. Consumers must still treat absence as *unknown* rather than *inactive*: `wp_login` does not fire for Application Password / REST authentication, nor for SSO plugins that call `wp_set_auth_cookie()` directly. Documented in [`docs/developers/extending.md`](docs/developers/extending.md).
- Last-login data is retained on uninstall. It cannot be backfilled, delete-and-reinstall is a routine troubleshooting step, and a timestamp is less sensitive than the event log that `uninstall.php` already keeps.

### Changed

- **Breaking: four WP-CLI subcommands have been renamed to the hyphenated forms the documentation already used.** `lock_all`, `unlock_all`, `list_locked`, `clear_devices` and `emergency_disable` are now `lock-all`, `unlock-all`, `list-locked`, `clear-devices` and `emergency-disable`. The documented hyphenated names never worked — WP-CLI registered the method names verbatim — so `docs/wp-cli.md`, `docs/account-locking.md` and `docs/troubleshooting.md` were all instructing people to run commands that did not exist, including in the lockout-recovery procedure. The underscore forms are gone rather than kept as aliases; update any scripts or runbooks that use them.
- **Raised the minimum PHP version to 8.2.** The plugin declared `Requires PHP: 8.0`, but four handlers return `true|\WP_Error` and the `true` type was only added in PHP 8.2. On 8.0 or 8.1 those files fail to compile, and because they are required unconditionally the result is a fatal on every request rather than a graceful failure. The declared floor has been wrong since roughly v0.5.0, so no site can have been running the plugin below 8.2 — this makes the header honest, and lets WordPress block installation on incompatible hosts instead of permitting a configuration that breaks.

### Removed

- `Account_Security_Handler::get_client_ip()` and `::get_client_user_agent()`. Both were one-line static wrappers around the namespaced `get_ip_address()` / `get_user_agent()` helpers, and nothing in the plugin called either. The class API is internal — see `docs/developers/hooks-and-filters.md` — so this is not a supported-surface change.

### Fixed

- **Plural strings were untranslated in every locale.** Ten count-bearing messages — remaining verification attempts, trusted-device counts, code expiry, password age — fell back to English in all eight languages, because the `.po` files carried empty plural slots and no `Plural-Forms` headers. All eight locales are regenerated with plural forms populated and correct headers.
- **Polish would have rendered blank rather than English for common counts.** Polish needs three plural forms and machine translation supplies two, so the third was left empty — and WordPress returns an empty translation rather than falling back to the source. With the default settings (30-day device trust, 10-minute code expiry) that meant blank labels. The third form now carries the English source, so those counts read as they did before rather than disappearing.
- **The plugin name and author were being translated.** "Quick 2FA" appeared as "Schnelle 2FA", "Γρήγορη 2FA" and six other variants, and the Greek catalogue transliterated the author's name. Both are proper nouns and are now pinned to the source text in every locale. The plugin description stays translated.

- **The locked-user count badge on the users list went stale for up to five minutes.** The cached count was never invalidated when an account was locked or unlocked, so the "Locked (N)" filter showed the old number — including after an automatic lockout, which never touches the admin UI at all. The cache is now cleared wherever lock state changes.
- **The Lock Status column disappeared if another plugin removed the email column.** It was only inserted while iterating past `email`, so with no email column there was no insertion point and the column vanished silently. It now appends when its preferred position is absent.
- **A manual lock is no longer stored in scientific notation.** Locking indefinitely used `PHP_INT_MAX` as the duration, and `time() + PHP_INT_MAX` overflows to a float, so the value was written as `9.223372038644E+18` and every reader had to cope with that. It now uses a large but finite `PERMANENT_LOCK_DURATION`. Locks already stored in the old form are still read correctly.
- **The protected-roles setting could be stored as a sparse array.** `array_intersect()` preserves the original keys, so selecting roles that straddled an invalid entry saved something like `{1: 'editor', 3: 'author'}` rather than a plain list. Harmless in practice — the values were still compared correctly — but it serialised oddly and would have surprised anything reading the option. Now re-indexed.
- **A failed GitHub release lookup is no longer retried on every update check.** Failures were not cached at all, so while the API was unreachable or rate-limiting, each check made the request again and blocked the admin page for the full ten-second timeout. A failed lookup is now remembered for an hour — short enough not to delay a real release, long enough to stop an outage being felt on every page load. A successful lookup, or completing an update, clears the back-off.
- **The updater could offer an unrelated release asset as the plugin package.** Any `.zip` whose name merely started with the plugin slug was accepted as a fallback, so an asset such as `quick-2fa-docs.zip` would have been handed to WordPress to install. The fallback now matches only a versioned package name (`quick-2fa-1.2.3.zip`).
- **The password-reminder rule existed twice, in two slightly different forms.** `Plugin::user_needs_password_reminder()` reimplemented the whole policy inline — the registration-date fallback, the period comparison and the cooldown — while `Password_Reminder_Handler::needs_reminder()` sat unused. The two disagreed at the boundary: one compared elapsed seconds, the other whole days, so a reminder could fire up to a day apart depending on which was consulted. There is now one implementation, in the handler, and `Plugin` delegates to it.
- **`update_password()` measured password length in bytes, not characters.** `strlen()` accepted a seven-character multibyte password as eight-plus characters, against a message promising "at least 8 characters". Now uses `mb_strlen()`, and the minimum is stated once as `PASSWORD_MIN_LENGTH`.
- **A corrupt lock timestamp no longer locks a user out permanently.** `Account_Security_Handler::is_locked()` tested the stored value with `empty()` and compared it untyped, so an unreadable `_quick2fa_locked_until` could report the account as locked with no expiry that would ever pass. It now casts on read and treats anything unreadable as not locked, which is also the self-healing behaviour — an elapsed lock already clears itself.
- **A corrupt rate-limit transient no longer fatals the verification page.** `check_rate_limit()` tested only for `false` before indexing the cached value as an array, so a transient holding anything else raised an uncaught `TypeError` — on the one page a locked-out administrator has to reach. It now requires the expected array shape and starts a fresh window otherwise.
- **A verification code with an unreadable timestamp is now treated as expired** rather than as newly issued.
- **A verification code is no longer stored when the user record is missing.** `send_via_email()` generated and stored a fresh code before checking that the user existed, leaving orphaned state behind when it then bailed out.
- **The updater no longer reports an update that is already installed.** v1.2.2 bumped the `Version:` header in `quick-2fa.php` but left `QUICK_2FA_VERSION` at `1.2.1`. The updater compared that constant against the latest GitHub release tag, so every site running v1.2.2 saw a permanent "1.2.2 available" notice — and installing it could never clear the notice, because the reinstalled files carried the same stale constant. The constant now matches the header again, and the updater compares against the version WordPress itself records for the plugin (read from the file header into the `update_plugins` transient) rather than the constant, so a future drift cannot resurface the phantom update. If the two ever disagree, the updater now logs an error naming both values.

### Changed

- The release workflow verifies that the git tag, the `Version:` header, `QUICK_2FA_VERSION` and the `readme.txt` stable tag all agree before building, and fails the build if they do not.

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
