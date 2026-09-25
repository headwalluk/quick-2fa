# Trusted devices

## The security model

When trusted devices are **enabled** (default), the request flow is:

1. User logs in
2. If this login session already verified within the **Verification Period** → no challenge
3. Otherwise Quick 2FA looks for a valid device-trust **cookie** on the request
4. If the cookie's token matches an entry in the user's trusted-devices list **and** that entry hasn't expired → skip verification
5. Otherwise → require verification

After a successful verification, the user can tick "trust this device". Quick 2FA then mints a random token, sends it to the browser as a secure cookie, and records the token's hash against an expiry (by default 30 days). Leaving the box unticked still grants *short-term* trust — a cookie that lasts for the verification period (3 days by default) — so the user isn't challenged on every single login within that window.

When trusted devices are **disabled**, every login session requires verification. The result is recorded against that WordPress login session, so logging in again — on any device, or after the session expires — asks for a new code, while pages within a verified session do not. Use this on high-risk sites where convenience is not a priority.

## Why device-based, not just time-based?

Without trusted devices, the alternative is "verify once every N days regardless of where the login is coming from". That's a meaningful security hole: anyone with the password can log in from a new device for `N - 1` days without ever seeing a verification challenge.

Device-based trust closes that hole. A device that doesn't present a valid trust cookie always requires verification, even if the user verified yesterday on a different device.

## What identifies a device

A trusted device is identified by a **secure cookie token**, not by network attributes:

- When a device is trusted, Quick 2FA generates a cryptographically random token (`bin2hex( random_bytes( 32 ) )`) and sets it as a cookie. The cookie is `HttpOnly` (not readable from JavaScript), `SameSite=Lax`, `Secure` whenever the request is over HTTPS, scoped to `SITECOOKIEPATH`, and named with `COOKIEHASH` so it's tied to this specific install — the same hardening WordPress applies to its own auth cookies.
- The **raw token never leaves the browser**. Server-side we store only its SHA-256 hash, keyed into the `_quick2fa_trusted_devices` user meta. A database leak therefore can't be used to forge a trusted device.

**Why not IP + User-Agent?** Versions before 1.2.0 hashed `IP + User-Agent`. On real-world connections the client IP is not stable — multi-WAN/failover routers send different sessions out via different uplinks, mobile tethering and CGNAT rotate the public IP, and IPv6 privacy addressing rotates it on a timer. Every IP change looked like a brand-new device, so users were re-challenged for 2FA repeatedly throughout the day even though nothing about their machine had changed. Binding trust to a cookie the device carries makes it independent of the network path, and as a bonus removes the old shared-NAT fingerprint-collision caveat entirely. The IP and User-Agent are still recorded in the [event log](account-locking.md) for incident investigation — they just no longer decide access.

A consequence of cookie-based trust: if the user clears their cookies, uses a different browser, or browses in a private/incognito window, that counts as an untrusted device and they'll be asked to verify.

## Expiry

Each trusted-device entry stores its own expiry timestamp, set when the device was trusted. On each login:

- If the cookie token matches an entry **and** it hasn't expired → trusted, skip verification
- If it matches **but** has expired → the entry is silently removed and verification is required

Each device's expiry is its own timer. Separately, a login session that has verified counts as verified for the Verification Period, so revoking devices doesn't interrupt it. (When trusted devices are *disabled*, the session is verified until it ends: each login session verifies once.)

## Revoking devices

Users can manage their own trusted devices in the **Two-Factor Authentication** section of their **Profile** page. Each device is listed with its expiry, and the browser viewing the page is marked "This Device". Each device has a **Revoke** button, and **Revoke All Devices** clears the whole list.

Anyone who can edit another user's profile can revoke that user's devices in the same place. From WP-CLI:

```bash
wp quick-2fa clear-devices <user>
```

**Changing a password revokes all of the user's trusted devices**, whichever way it's changed: the password reminder page, the profile page, a password reset, or `wp user update --user_pass`. The session that made the change carries on, but every device, including that one, needs a code at its next login.

Revoking devices doesn't end sessions that are already open. A session that verified recently stays verified until the Verification Period runs out or the user logs out. To force everyone out straight away, see [WP-CLI → force everyone to verify again](wp-cli.md#force-everyone-to-verify-again-after-a-security-incident).

**Locking an account doesn't revoke its devices.** When the account is unlocked, its trusted devices work again.

If you suspect an account is compromised, change its password. That revokes its devices; end its sessions too if an attacker might have one open.

## Disabling the feature

Trusted devices can be switched off so that every login session verifies once, whatever the device. There is no field for this on the settings page; set the `quick2fa_disable_trusted_devices` option instead:

```bash
wp option update quick2fa_disable_trusted_devices 1
```

`1`, `true`, `yes` and `on` switch device trust off. Set it back to `0` to switch it on again.

While the feature is off, the device lists are ignored but kept. Switching it back on restores them, apart from any entries that have expired in the meantime.
