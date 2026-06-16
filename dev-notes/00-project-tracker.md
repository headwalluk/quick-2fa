# Project Tracker — Quick 2FA

**Current Version:** 1.1.4
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

## Planned milestone — v1.2.0: cookie-based device trust

**Status:** Implemented on `master`, version bumped to 1.2.0, docs/CHANGELOG/readme updated. **Release tag deliberately withheld** pending real-world verification (Paul testing from varying IPs on a live host). Tag `v1.2.0` only once confirmed working — tagging triggers the release workflow and the client site auto-updates. The "decide during build" item below was resolved by deleting `get_device_fingerprint()` and `normalize_user_agent()` outright.

### Why

A WooCommerce client (warehouse + roaming staff) reported two persistent complaints: users getting *multiple* re-verification emails throughout a single day, and *bursts* of up to three emails for one login attempt. Could not reproduce in-house against a stable NAT IP.

Diagnosis from two real users' event logs (`dev-notes/diagnostics/`, gitignored — contains unredacted client IPs):

- **Root cause is the IP half of the fingerprint, not the UA.** Both users' User-Agents were rock-stable across three months (only genuine browser version bumps). Their **public IP changed constantly** — one user presented **8 distinct IPs across 6 unrelated ranges**, switching IP *three times within a single working day* (e.g. 2026-04-13 and 2026-06-01), each switch triggering a fresh 2FA cycle. The site uses `IP + UA` fingerprints, so every IP change reads as a new, untrusted device. This is ordinary modern UK connectivity (multi-WAN/failover egress, CGNAT, 4G tethering, roaming laptops), not VPN/spoofing tools.
- This means **v1.1.4's UA normalisation, while harmless, addressed a non-problem for this client** — the churn was never in the UA.
- **The email bursts are a separate bug:** `handle_verification_page()` emails a brand-new code on *every* GET load of the verify page, with no idempotency. Reloads, impatient "Resend" clicks, and multiple admin tabs each burn another code+email (rate-limiter caps it at 3 per 15 min — which is exactly the "three emails" the client saw). Confirmed in the logs as human-paced ~20–70s repeat loads, not sub-second prefetch.

This milestone supersedes the v1.1.4 approach to device identity and resolves *Open design question #2* (reverse-proxy / shared-IP fingerprinting).

### Scope

Two independent pieces; the first is a low-risk quick win that helps immediately, the second is the robust fix.

**1. Idempotent code sending on page load** *(ship first; small, low-risk)*
- In the non-POST branch of `handle_verification_page()`, only call `send_via_email()` when there is **no currently-valid, unexpired code** (`META_CODE_HASH` present **and** `! is_expired()`). Otherwise render the page and reuse the existing code.
- The explicit **Resend Code** button continues to force a new code (still rate-limited).
- Net effect: reloading the verify page, opening it in multiple tabs, or being bounced back to it no longer spawns duplicate emails. Kills the burst symptom on its own.

**2. Cookie-token device trust** *(replaces IP+UA fingerprinting)*
- Trust identity becomes a **persistent secure cookie token**, not a hash of network attributes. On successful verification with "Trust this device":
  - generate a cryptographically random token (`random_bytes`),
  - store its **hash** in user meta with an expiry (DB leak must not allow forging a trusted device),
  - set a cookie scoped like WP auth cookies (`COOKIE_DOMAIN` / `COOKIEPATH` / `SITECOOKIEPATH`), `HttpOnly`, `SameSite=Lax`, `Secure` when `is_ssl()`. Cookie is set in the verify-success branch *before* the redirect (no output sent yet).
- `is_device_trusted()` becomes: read the cookie, hash it, `hash_equals` against the user's stored unexpired token hashes. No IP, no UA in the identity.
- Keep the existing two-tier expiry behaviour: ticked = full trusted-device expiry (30d default), unticked = short-term trust (verification period, 3d default) — but carried by the cookie, not a fingerprint entry.
- Consider a richer stored structure (`selector => { validator_hash, expiry, created, last_seen_ip }`) to enable per-device labels / "log out other devices" later, but keep v1.2.0 minimal.
- **Fallback is fail-secure:** no cookie (cleared, disabled, new device) ⇒ verification required, as today.

### Migration & fallout

- Changing the trust mechanism **orphans all existing trusted devices** — every user re-verifies once on first login after upgrade (same one-time cost as v1.1.4; acceptable).
- `normalize_user_agent()` / the UA half of `get_device_fingerprint()` become **dead code for trust** once identity is cookie-based. Decide during the build whether to delete `get_device_fingerprint()` outright or retain the raw IP/UA capture purely for the event log. The UA is no longer security-relevant.
- `uninstall.php` already wipes trusted-device meta; update the meta key(s) it targets if the storage key changes.
- Update `docs/troubleshooting.md` (currently documents the shared-IP limitation) and the `CLAUDE.md` Security Implementation section (device fingerprinting bullet).

### Security notes to honour during build

- Token: ≥32 bytes from a CSPRNG; stored hashed; compared with `hash_equals` (constant-time).
- Cookie: `HttpOnly` (no JS access), `Secure` on HTTPS, `SameSite=Lax`; never store the raw token server-side.
- This also retires the spoofable-header concern in `get_ip_address()` *for trust purposes* (it reads `HTTP_CLIENT_IP` / `X-Forwarded-For` ahead of `REMOTE_ADDR`) — IP no longer gates access, only annotates logs.

---

## Open design questions

Unresolved questions about the plugin's behaviour, not committed tasks. Revisit when the underlying scenario actually arises (a security report, a deployment context, a user request).

1. **Uninstall data retention.** Currently `uninstall.php` only wipes trusted-device fingerprints. Lock status, security event logs, password-reminder timestamps, and plugin settings are all preserved across uninstall/reinstall. The reasoning: a developer temporarily uninstalling for testing should not silently unlock previously-locked accounts or lose their tuned configuration. **To revisit:** whether to offer a separate "delete all plugin data" option (a settings checkbox? a WP-CLI command?) for sites that genuinely want a clean removal. Think through the security implications first — uninstall-as-attack-vector is the concern.

2. **Reverse-proxy device fingerprinting.** ~~Device fingerprints are `IP + User-Agent` hashed...~~ **Being addressed by the v1.2.0 cookie-based device trust milestone above** — moving device identity off network attributes (IP/UA) onto a secure cookie token resolves both the shared-IP undercount and the IP-churn over-prompt described in the original question. Retained here for history until v1.2.0 ships.

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
| v1.1.2 | Updater errors now log unconditionally to `error_log`. Diagnosed the root cause of silent v1.0.0–v1.1.1 update failures: the `headwalluk/quick-2fa` repo was private, so unauthenticated API calls returned 404. Repo flipped to public; existing installs now reach updates. |
| v1.1.3 | WordPress 7.0 compatibility. WP 7.0 moved `--wp-admin-theme-color` under body-class scoped selectors in `admin-schemes.min.css`; the 2FA verification and password-reminder templates were missing the `admin-color-modern` body class that core's `wp-login.php` carries, leaving the "Trust this device" checkbox SVG white-on-white. Fix is a one-token addition to each template's body class. `readme.txt` Tested-up-to bumped to 7.0. |
| **v1.1.4** | **Trusted-device fingerprint now normalises the User-Agent before hashing (`normalize_user_agent()` in `functions-private.php`, wired into `get_device_fingerprint()`). Fixes spurious repeat 2FA prompts when an upstream proxy/middlebox rewrites the UA header — a client saw ~1% of requests arrive with the inter-token whitespace folded into commas. Non-alphanumeric runs collapse to single spaces + lowercase; raw UA kept for logs/device list. One-time cost: fingerprint formula change orphans all existing trusted devices, so every user re-verifies once.** |

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
