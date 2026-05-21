# Changelog

All notable changes to Quick 2FA will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
