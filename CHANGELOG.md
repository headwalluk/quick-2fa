# Changelog

All notable changes to Quick 2FA will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
