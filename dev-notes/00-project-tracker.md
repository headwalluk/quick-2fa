# Project Tracker — Quick 2FA

**Current Version:** 1.0.0
**Distribution:** GitHub releases via in-plugin auto-updater (`headwalluk/quick-2fa`)
**Status:** v1.0.0 released — first public GitHub distribution

> **Internal notes only.** Anything user-facing belongs in `docs/` (for site administrators and integrators) or `readme.txt` / `README.md` (for the GitHub landing page). This file is for development planning and historical context.

---

## Distribution model

Quick 2FA is distributed via GitHub releases, **not** the wordpress.org plugin directory. Sites receive updates automatically through the bundled `Headwall_GitHub_Plugin_Updater` (see `includes/class-headwall-github-plugin-updater.php`), which polls `api.github.com/repos/headwalluk/quick-2fa/releases/latest` and serves the `quick-2fa.zip` asset attached to each release.

A future wordpress.org submission is possible but not currently planned. If we revisit it, the in-plugin updater would need to be removed (wp.org plugins cannot use third-party update servers).

---

## Active TODO — v1.0.0 release

The v1.0.0 milestone is the first GitHub release with the auto-updater wired up. All items below must be resolved before tagging.

### Code review (in progress)

- [x] Belt-and-braces security audit (no critical/high findings)
- [x] Belt-and-braces code-style review against `CLAUDE.md` conventions
- [x] Belt-and-braces WordPress best-practices review
- [x] SESE re-scan with corrected rule (10 functions refactored)
- [x] Repo references updated `create-element/quick-2fa` → `headwalluk/quick-2fa`
- [x] `functions.php` → `functions-private.php` rename
- [x] Runtime intro filters wired (`quick2fa_verify_intro`, `quick2fa_password_intro`); dead `OPTION_VERIFY_INTRO` / `OPTION_PASSWORD_INTRO` / `OPTION_LOGO_URL` deleted
- [x] Inline JS in `views/password-page.php` externalized to `assets/js/password-page.js`
- [x] Magic string `'quick2fa_version'` swapped for `OPTION_VERSION` constant
- [x] `uninstall.php` written (wipes trusted devices only)
- [ ] `docs/` directory written (user + developer documentation)
- [ ] `README.md` slimmed down to a hub linking to `docs/`
- [ ] Final phpcs + manual review pass
- [ ] Version bump 0.12.2 → 1.0.0 in header, constant, `readme.txt`, `CHANGELOG.md`
- [ ] Tag `v1.0.0` and push to GitHub (release workflow builds the zip automatically)
- [ ] Regenerate `languages/*` translations (handled by user's tooling)

### Open review items (post-v1.0.0)

1. **Uninstall data retention.** Currently `uninstall.php` only wipes trusted-device fingerprints. Lock status, security event logs, password-reminder timestamps, and plugin settings are all preserved across uninstall/reinstall. The reasoning: a developer temporarily uninstalling for testing should not silently unlock previously-locked accounts or lose their tuned configuration. **To revisit:** whether to offer a separate "delete all plugin data" option (a settings checkbox? a WP-CLI command?) for sites that genuinely want a clean removal. Think through the security implications first — uninstall-as-attack-vector is the concern.

2. **Reverse-proxy device fingerprinting.** Device fingerprints are `IP + User-Agent` hashed. Behind a shared reverse proxy (Cloudflare, ALB, office NAT) all users present the same external IP, so trust TTLs can be undermined on shared infrastructure. **To revisit:** documentation in `docs/troubleshooting.md` (covered for v1.0.0), plus possibly an optional setting to incorporate `X-Forwarded-For` parsing or to require a per-device label. Security review needed before changing the fingerprint algorithm.

3. **Rate-limit scope.** Code-generation rate limits are per-user, not per-session. An attacker with a hijacked session could exhaust a legitimate user's code-generation budget, creating a self-DoS on the account. Acceptable for v1.0.0, but worth considering whether per-session limits would be a meaningful improvement.

---

## Milestone history

| Version | Notes |
|---------|-------|
| v0.4.0 | Class-based architecture refactor |
| v0.6.0 | Account locking, security event logging, MU-plugin support |
| v0.6.1 | Trusted devices, profile section UI |
| v0.9.0 | Emergency disable, PHP 8.0+ requirement |
| v0.9.3 | WordPress login page compliance, Query Monitor suppression |
| v0.10.0 | Code-first template migration, JS externalization, settings page refactor |
| v0.11.0 | Trusted device expiry fix, full phpcs compliance |
| v0.11.2 | Default mode changed to "all users" |
| v0.12.0 | Translation scaffolding |
| v0.12.2 | Plugin Check conformance fixes, template variable rename `$q2fa_*` → `$quick_2fa_*` |
| **v1.0.0** | **GitHub-distribution milestone (in progress)** |

---

## Future enhancement ideas (post-v1.0.0)

- TOTP / Google Authenticator support
- SMS verification option
- Backup recovery codes
- Per-role reminder periods
- Optional SHA256 verification of release ZIPs in the GitHub updater
- Native-speaker review of bundled translations

---

## Notes for development

### Code standards workflow

```bash
phpcs        # Check standards
phpcbf       # Auto-fix violations
phpcs        # Verify clean
```

### Release workflow

1. Update version in `quick-2fa.php` header **and** `QUICK_2FA_VERSION` constant
2. Update `readme.txt` stable tag
3. Add `CHANGELOG.md` entry (with rationale, not just file lists)
4. Run `phpcs` to verify compliance
5. Commit, tag `vX.Y.Z`, push tag to GitHub
6. The `.github/workflows/release.yml` workflow builds `quick-2fa.zip` and `quick-2fa-X.Y.Z.zip`, attaches them to a GitHub Release, and the in-plugin updater on existing sites picks up the new version automatically within ~12 hours (cache TTL)

### Emergency contacts

- Security issues: security@power-plugins.com
