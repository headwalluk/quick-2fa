# Changelog

All notable changes to Quick 2FA will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
