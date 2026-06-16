# Trusted devices

## The security model

When trusted devices are **enabled** (default), the request flow is:

1. User logs in
2. Quick 2FA looks for a valid device-trust **cookie** on the request
3. If the cookie's token matches an entry in the user's trusted-devices list **and** that entry hasn't expired → skip verification
4. Otherwise → require verification

After a successful verification, the user can tick "trust this device". Quick 2FA then mints a random token, sends it to the browser as a secure cookie, and records the token's hash against an expiry (by default 30 days). Leaving the box unticked still grants *short-term* trust — a cookie that lasts for the verification period (3 days by default) — so the user isn't challenged on every single login within that window.

When trusted devices are **disabled**, every login requires verification, full stop. Use this on high-risk sites where convenience is not a priority.

## Why device-based, not just time-based?

Without trusted devices, the alternative is "verify once every N days regardless of where the login is coming from". That's a meaningful security hole: anyone with the password can log in from a new device for `N - 1` days without ever seeing a verification challenge.

Device-based trust closes that hole. A device that doesn't present a valid trust cookie always requires verification, even if the user verified yesterday on a different device.

## What identifies a device

Since v1.2.0, a trusted device is identified by a **secure cookie token**, not by network attributes:

- When a device is trusted, Quick 2FA generates a cryptographically random token (`bin2hex( random_bytes( 32 ) )`) and sets it as a cookie. The cookie is `HttpOnly` (not readable from JavaScript), `SameSite=Lax`, `Secure` whenever the request is over HTTPS, scoped to `SITECOOKIEPATH`, and named with `COOKIEHASH` so it's tied to this specific install — the same hardening WordPress applies to its own auth cookies.
- The **raw token never leaves the browser**. Server-side we store only its SHA-256 hash, keyed into the `_quick2fa_trusted_devices` user meta. A database leak therefore can't be used to forge a trusted device.

**Why not IP + User-Agent?** Earlier versions hashed `IP + User-Agent`. On real-world connections the client IP is not stable — multi-WAN/failover routers send different sessions out via different uplinks, mobile tethering and CGNAT rotate the public IP, and IPv6 privacy addressing rotates it on a timer. Every IP change looked like a brand-new device, so users were re-challenged for 2FA repeatedly throughout the day even though nothing about their machine had changed. Binding trust to a cookie the device carries makes it independent of the network path, and as a bonus removes the old shared-NAT fingerprint-collision caveat entirely. The IP and User-Agent are still recorded in the [event log](account-locking.md) for incident investigation — they just no longer gate access.

A consequence of cookie-based trust: if the user clears their cookies, uses a different browser, or browses in a private/incognito window, that counts as an untrusted device and they'll be asked to verify.

## Expiry

Each trusted-device entry stores its own expiry timestamp, set when the device was trusted. On each login:

- If the cookie token matches an entry **and** it hasn't expired → trusted, skip verification
- If it matches **but** has expired → the entry is silently removed and verification is required

There's no separate "verification period" check overlaid on top of trusted devices — the per-device expiry is the only timer. (When trusted devices are *disabled*, the verification period becomes the active timer instead.)

## Revoking devices

Users can manage their own trusted devices on their **Profile** page. Each device is listed with its expiry, and the browser viewing the page is highlighted as "This Device" (detected via its own trust cookie).

Site administrators can revoke devices for any user from the same profile page (when editing another user). They can also clear *all* trusted devices for a user via WP-CLI:

```bash
wp quick-2fa clear-devices <user>
```

A device list is also wiped automatically when:

- The user changes their password (via the built-in reminder flow)
- The plugin is uninstalled (see `uninstall.php`)

Lock-outs **do not** wipe trusted devices automatically — when an admin unlocks the account, the original trusted devices come back. If you want a clean slate after a lock-out, run `clear-devices` after `unlock`.

## Disabling the feature entirely

The disable-trusted-devices toggle is **CLI-only** for now — there's no settings UI checkbox. To force every login to require verification:

```bash
wp option update quick2fa_disable_trusted_devices 1
```

Re-enable with `wp option update quick2fa_disable_trusted_devices 0`.

Existing trusted-device entries in user meta will be ignored but not deleted. Re-enabling the feature restores the previous trust list (subject to per-device expiry). See [WP-CLI → configuration via CLI](wp-cli.md#configuration-via-cli) for the full CLI-only settings list.
