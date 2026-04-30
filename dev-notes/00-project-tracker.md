# Project Tracker — Quick 2FA

**Current Version:** 1.1.2
**Distribution:** GitHub releases via in-plugin auto-updater (`headwalluk/quick-2fa`)
**Status:** Maintenance — feature-complete for the email-only 2FA flow

> **Internal notes only.** Anything user-facing belongs in `docs/` (for site administrators and integrators) or `readme.txt` / `README.md` (for the GitHub landing page). This file is for development planning and historical context.

---

## Design philosophy

Quick 2FA does one thing — email-based 2FA on admin login — and tries to do it well. Keeping the plugin small and robust is an explicit goal, not an accident. New features that would expand scope (additional verification backends, broader workflows, front-end user interactions outside `wp-login.php`) carry real architectural cost and should clear a high bar before being adopted. See *Considered but deferred* below.

---

## Distribution model

Quick 2FA is distributed via GitHub releases, **not** the wordpress.org plugin directory. Sites receive updates automatically through the in-plugin `Quick_2FA\Github_Updater` (see `includes/class-github-updater.php`), which polls `api.github.com/repos/headwalluk/quick-2fa/releases/latest` and serves the `quick-2fa.zip` asset attached to each release.

A future wordpress.org submission is possible but not currently planned. If we revisit it, the in-plugin updater would need to be removed (wp.org plugins cannot use third-party update servers).

---

## Active TODO — housekeeping

A rolling catch-all for code-cleanliness items that are too small to justify their own milestone but shouldn't be forgotten. Pick these off opportunistically — ideally bundled into the next release that's already touching nearby code.

- [ ] **Remove dead lockout constants.** `RATE_LIMIT_ACCOUNT_LOCK_THRESHOLD` (10), `RATE_LIMIT_ACCOUNT_LOCK_WINDOW` (3600), and `RATE_LIMIT_ACCOUNT_LOCK_DURATION` (3600) are defined in `constants.php` but never referenced. The active lockout threshold is actually `RATE_LIMIT_VERIFICATION_MAX` (5), used in `class-verification-code-handler.php`. The actual lockout *duration* comes from the `OPTION_LOCKOUT_DURATION` setting (60 minutes default), not a constant. Delete the three dead constants — they mislead anyone reading `constants.php` into thinking lockout policy is 10/hour.
- [ ] **Correct `CLAUDE.md` rate-limit claim.** `CLAUDE.md` currently states *"10 failed attempts in 1 hour triggers 1-hour lockout (all configurable via constants)"* under the Security Implementation section. That matches the dead constants but not the live behaviour — actual behaviour is **5 failed verification attempts per session triggers lockout, duration controlled by the `Lockout duration` setting (60 min default)**. Fix the sentence to match reality, ideally after the dead constants above are removed so there's a single source of truth.

---

## Open design questions

Unresolved questions about the plugin's behaviour, not committed tasks. Revisit when the underlying scenario actually arises (a security report, a deployment context, a user request).

1. **Uninstall data retention.** Currently `uninstall.php` only wipes trusted-device fingerprints. Lock status, security event logs, password-reminder timestamps, and plugin settings are all preserved across uninstall/reinstall. The reasoning: a developer temporarily uninstalling for testing should not silently unlock previously-locked accounts or lose their tuned configuration. **To revisit:** whether to offer a separate "delete all plugin data" option (a settings checkbox? a WP-CLI command?) for sites that genuinely want a clean removal. Think through the security implications first — uninstall-as-attack-vector is the concern.

2. **Reverse-proxy device fingerprinting.** Device fingerprints are `IP + User-Agent` hashed. Behind a shared reverse proxy (Cloudflare, ALB, office NAT) all users present the same external IP, so trust TTLs can be undermined on shared infrastructure. **To revisit:** possibly an optional setting to incorporate `X-Forwarded-For` parsing or to require a per-device label. Security review needed before changing the fingerprint algorithm. Documentation already covers the limitation in `docs/troubleshooting.md`.

3. **Rate-limit scope.** Code-generation rate limits are per-user, not per-session. An attacker with a hijacked session could exhaust a legitimate user's code-generation budget, creating a self-DoS on the account. Acceptable for current releases, but worth considering whether per-session limits would be a meaningful improvement.

---

## Pending — external security audit

A full external security audit is planned, scoped outside of Claude Code so the review is genuinely independent. Findings, when they arrive, may surface as patches or scope-tightening tasks that take priority over other housekeeping items.

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
| v1.0.0 | First public GitHub release with in-plugin auto-updater |
| v1.0.1 | Theme/plugin file editor loopback fix |
| v1.1.0 | GitHub updater integrated into the plugin namespace as `Quick_2FA\Github_Updater`; public filter renamed `headwall_github_updater_enabled` → `quick_2fa_updater_enabled` (breaking) |
| v1.1.1 | Maintenance: refreshed translations; cut as a no-op test release for the updater pipeline |
| **v1.1.2** | **Updater errors now log unconditionally to `error_log`. Diagnosed the root cause of silent v1.0.0–v1.1.1 update failures: the `headwalluk/quick-2fa` repo was private, so unauthenticated API calls returned 404. Repo flipped to public; existing installs now reach updates.** |

---

## Considered but deferred

The plugin's intentional scope is email-based 2FA on admin login. The items below would expand that scope significantly — each carries non-trivial cost (multi-workflow state, front-end user interactions outside the `wp-login.php` login context where themes don't load, theme-clash risk) and is deferred until a strong case emerges.

- **Multiple verification backends** — TOTP / Google Authenticator, SMS. The hard problem is the user-facing setup and recovery UX, not the backend abstraction itself. Backup/one-time recovery codes would land alongside this work, not standalone.
- **Per-role reminder periods.**
- **Optional SHA256 verification of release ZIPs in the GitHub updater.**
- **Native-speaker review of bundled translations.**

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
6. The `.github/workflows/release.yml` workflow builds `quick-2fa.zip` and `quick-2fa-X.Y.Z.zip`, attaches them to a GitHub Release, and the in-plugin updater on existing sites picks up the new version automatically within ~12 hours (cache TTL). Verified end-to-end across three sites at v1.1.1 → v1.1.2.

### Emergency contacts

- Security issues: webmaster@headwall-hosting.com
