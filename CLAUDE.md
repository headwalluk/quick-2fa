# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Repository: public

Follows the **headwall-wp-plugin** skill, which holds the shared conventions: PHP style,
templates, logging, comments, public-contract rules, testing, release and the GitHub updater.
Load it before working on this plugin. This file holds only what is specific to Quick 2FA.

## Project Overview

Quick 2FA is a WordPress plugin providing email-based two-factor authentication for admin access. It intercepts login via `admin_init` (priority 1) and redirects unverified users to `wp-login.php?q2fa=verify`. It is designed to be non-breaking — REST API, WP-CLI, AJAX, cron, and XML-RPC requests bypass 2FA checks.

- **Namespace:** `Quick_2FA` · **Text Domain:** `quick-2fa` · **Prefixes:** `quick2fa_` (options, filters), `_quick2fa_` (user meta), `q2fa_` (transients), `QUICK_2FA_` (constants)
- **PHP:** 8.2+. The floor is 8.2 because four handlers return `true|\WP_Error`, and the `true` type landed in 8.2; on 8.0/8.1 that is a compile-time fatal, not a graceful failure
- **WordPress:** 6.2+. The floor is 6.2 because revoking trusted devices on a password change hooks `wp_set_password`, which fires from 6.2
- **No build system** — no npm, no Composer, no bundler. Assets are plain CSS/JS.

Quick 2FA is the **reference implementation** for the headwall-wp-plugin skill: the skill sends
other plugins here to copy named files and functions. When changing one of those, keep it
plugin-neutral where it already is, and if the pattern itself changes, update the skill too.

## Testing

Eight harnesses live in `dev-notes/testing/`, one per class or helper file. Run each with
`wp eval-file wp-content/plugins/quick-2fa/dev-notes/testing/<file>.php`. All of them must pass
before a release and after any change to high-impact code.

## Architecture

### Design Principles

1. **Minimal Attack Surface** — uses existing `wp-login.php` rather than custom endpoints or rewrite rules
2. **Early Interception** — `admin_init` at priority 1, before admin loads
3. **No Theme/Plugin Interference** — 2FA pages render in login context where themes don't load
4. **Fail Secure** — deny access rather than allow if anything goes wrong

### Entry Point & Initialization

`quick-2fa.php` → `quick_2fa_run()` → `Plugin::instance()->run()` (singleton pattern). Classes are manually loaded (no autoloader).

### Hook Strategy

- **`admin_init` (priority 1)** — check verification before admin area loads. Skips: WP-CLI, AJAX, REST API, cron, XML-RPC, App Passwords, User Switching plugin, and requests already on a `?q2fa=` page.
- **`login_init`** — renders 2FA pages when `?q2fa=` query param is present on `wp-login.php`. Always calls `exit()` after rendering.
- **`wp_authenticate_user`** — blocks locked users at login time.

### Request Flow

1. User logs in → `admin_init` fires → `Plugin::check_verification()` checks if user needs 2FA
2. If yes → stores return URL in transient (`q2fa_return_{user_id}`, 5 min expiry) → redirect to `wp-login.php?q2fa=verify`
3. `login_init` hook fires → renders verification form, generates code, emails user
4. User submits code → `Verification_Code_Handler::verify()` → success records the verification against the login session → optional trusted device → retrieves return URL from transient → redirect
5. After verification → `check_password_reminder()` may redirect to `wp-login.php?q2fa=password` if password is aged

### Key Files

| File | Purpose |
|------|---------|
| `quick-2fa.php` | Main plugin file, activation/deactivation hooks, class loading |
| `constants.php` | All constants: meta keys, option keys, modes, rate limits, defaults |
| `functions-private.php` | Internal namespaced helpers: skip-check logic, URL generators, config getters. All functions here are private to the plugin — sites should use the public hooks/filters in `docs/developers/hooks-and-filters.md` instead. |
| `includes/class-plugin.php` | Main orchestrator: hook registration, verification flow, role checking |
| `includes/class-verification-code-handler.php` | Code generation (cryptographic), hashing, verification, rate limiting |
| `includes/class-account-security-handler.php` | Account locking/unlocking, device fingerprinting, trusted devices, event logging |
| `includes/class-email-handler.php` | Email template rendering and sending |
| `includes/class-settings.php` | Admin settings page, option registration and validation |
| `includes/class-password-reminder-handler.php` | Password age tracking, reminder cooldowns |
| `includes/class-user-management.php` | Users table UI customization, lock/unlock row actions |
| `includes/class-cli-commands.php` | WP-CLI commands for lock/unlock/status/emergency-disable |
| `includes/class-github-updater.php` | In-plugin updater, and the canonical copy every public Headwall plugin ports. Fixes go here first. Must not name Quick 2FA outside its `namespace` and `@package` lines; `dev-notes/testing/test-github-updater.php` fails if it does |

### Data Storage

- **User meta** for per-user state (code hashes, lock status, trusted devices, logs)
- **`wp_options`** for plugin settings (mode, code length, lock duration, etc.)
- **Transients** for temporary data (return URLs, rate limits)
- **Every stored key carries the plugin prefix.** `META_PASSWORD_LAST_CHANGED` predates the rule and is listed in `uninstall.php` by name
- **Uninstall deletes nothing by default.** Only with the *Delete all plugin data when uninstalled* setting on (`OPTION_DELETE_DATA_ON_UNINSTALL`) does `uninstall.php` remove the plugin's options, user meta and transients. Deactivation never deletes data
- **User meta is network-wide**, so on multisite a lock or a trusted device would apply on every site. Multisite is untested

### Security Implementation

- **Code generation:** `random_int()` for cryptographically secure codes
- **Code storage:** hashed with `wp_hash_password()`, verified with `wp_check_password()` — never store plaintext
- **Rate limiting:** at most 3 codes per user per 15 minutes (`RATE_LIMIT_CODE_GENERATION_MAX` / `_WINDOW`)
- **Account locking:** the 5th failed attempt against a code locks the account (`RATE_LIMIT_VERIFICATION_MAX`); storing a new code resets the count. The lock lasts for the *Lockout duration* setting (`OPTION_LOCKOUT_DURATION`, 60 minutes by default)
- **Device trust:** a random token set as a secure cookie (`HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS); only the token's SHA-256 is stored in user meta, expiring after a configurable TTL. Identity is the cookie, **not** IP/User-Agent (those churn on real connections and are log-only). See `dev-notes/00-project-tracker.md` (v1.2.0) and `docs/trusted-devices.md`
- **Per-session verification:** a successful `verify()` stamps `SESSION_KEY_VERIFIED` into the current WordPress login session (`WP_Session_Tokens`). With trusted devices disabled that stamp is the check, so each new login session is challenged once. With them enabled, a stamp younger than the verification period also passes, so revoking devices doesn't re-challenge a session that has just verified. `Password_Reminder_Handler::maintain_session()` reissues the auth cookie for the same token, so a password change keeps the stamp
- **A password change revokes every trusted device**, by any route: `wp_set_password` covers resets and the reminder page, `profile_update` covers the profile page and `wp user update`. The session that made the change stays verified; every device needs a code at its next login
- **Session management:** on a password change from the reminder page, destroy other sessions via `WP_Session_Tokens::destroy_others()` while keeping current session alive

### 2FA Modes

- `MODE_ALL` — every user requires 2FA
- `MODE_ROLES` — only configured roles (default: admins)
- `MODE_DISABLED` — 2FA off

## Public Contracts

The skill's `public-contracts.md` has the rules. Quick 2FA's contracts:

| Contract | Examples |
|----------|----------|
| Filters and actions | `quick2fa_record_last_login`, `quick2fa_account_locked`, `quick2fa_updater_enabled` |
| Stored key strings | `_quick2fa_locked_until`, `_quick2fa_trusted_devices`, `quick2fa_mode`. A new `META_LOCKED_UNTIL` string would unlock every locked account |
| Script and style handles | `quick-2fa-login`, `quick-2fa-password-page`, `quick-2fa-settings` |
| Login page URLs | `wp-login.php?q2fa=verify`, `?q2fa=password` (`QUERY_PARAM`, `ACTION_*`) |
| WP-CLI commands | `wp quick-2fa lock-all`. 1.3.0 renamed five with no aliases |

- `_quick2fa_last_login` is the one stored key documented as readable by outside code
- `quick_2fa_updater_enabled` is the one filter without the `quick2fa_` prefix, and it is deprecated in favour of `quick2fa_updater_enabled`, bridged by `apply_deprecated_updater_filter()`
- `Plugin::check_first_run()` only records the version; there is no migration path for stored data

### High-Impact Code

These paths decide who gets into an account:

- `Verification_Code_Handler::store()` and `verify()`
- `Plugin::check_verification()`, `Plugin::user_needs_verification()` and `should_skip_check()`
- Locking: `Account_Security_Handler::lock_account()` and `is_locked()`, and `Plugin::check_lockout_on_login()`
- Device trust and session verification: `Account_Security_Handler::trust_device()`, `is_device_trusted()`, `clear_trusted_devices()`, `mark_current_session_verified()` and `get_current_session_verified_time()`, and the password-change revocation in `Plugin::revoke_devices_on_password_set()` and `revoke_devices_on_profile_update()`
- `Password_Reminder_Handler::update_password()` and `maintain_session()`

`should_skip_check()` covers the verification redirect only; other callbacks still check their own request context.

## WP-CLI Commands

```bash
wp quick-2fa lock <user>                    # Lock a user account
wp quick-2fa unlock <user>                  # Unlock a user account
wp quick-2fa status <user>                  # Check user 2FA status
wp quick-2fa lock-all --exclude=admin       # Emergency lockdown
wp quick-2fa list-locked                    # List all locked users
wp quick-2fa clear-devices <user>           # Clear trusted devices
wp quick-2fa emergency-disable --yes        # Disable 2FA entirely
```

## Release

Follow the skill's `release.md` (public). The version check in `release.yml` exists because
v1.2.2 shipped with the header bumped and `QUICK_2FA_VERSION` left behind, which made the updater
advertise a permanent false update on every site; see the 1.3.0 entry in `CHANGELOG.md`. The
updater's repository is `UPDATER_GITHUB_REPO` in `constants.php`.

## Reference Files

`docs/` (tracked, public):

- `docs/how-it-works.md`, `docs/configuration.md`, `docs/trusted-devices.md`,
  `docs/account-locking.md`, `docs/wp-cli.md`, `docs/troubleshooting.md` — site operators
- `docs/developers/hooks-and-filters.md` — the public extension surface
- `docs/developers/extending.md` — practical developer recipes

`dev-notes/` (private, untracked):

- `dev-notes/00-project-tracker.md` — milestones, open design questions, deferred features
- `dev-notes/02-snagging-list.md` — small agreed items awaiting a convenient moment. Check it
  before starting work: an item there may already cover the file you are about to touch
- `dev-notes/archive/` — superseded material kept for reference. Archived, not authoritative
- `dev-notes/testing/` — the harnesses

## Translations

Run `wp-translate . --check-instructions` after a `wp-translate` upgrade; if it reports drift,
run `wp-translate . --sync-instructions` and review the diff. Never hand-edit inside the
`wp-translate:begin`/`end` markers — the block is hash-validated and edits break it.

<!-- wp-translate:begin v=1.2.0 hash=8804fd5245f02addf9bdc48d7baa6039253260454c3103ddccb5fed21df6aa3e -->
## Translating this plugin (wp-translate conventions)

This plugin's `.po`/`.mo` files are generated from source by
[wp-translate](https://github.com/headwalluk/wp-translate-tool), which
machine-translates strings with DeepL. Machine translation is only as good as
the strings you give it — follow these conventions when adding or editing
user-facing text.

### 1. Disambiguate short or ambiguous strings with `_x()`

DeepL handles full sentences well but guesses badly on short, context-free
labels. Give it context with `_x()` (or `esc_html_x()`, `_ex()`):

```php
// Ambiguous out of context — DeepL may read "Sent" as "late", "Folder" as "leaflet"
__( 'Sent', 'quick-2fa' );

// Disambiguated — the context is passed to the translator and to DeepL
_x( 'Sent', 'email delivery status', 'quick-2fa' );
_x( 'Folder', 'IMAP mailbox', 'quick-2fa' );
_x( 'Open', 'verb; button label', 'quick-2fa' );
```

The context (2nd argument) is never shown to users. Use it whenever a string is a
single word, a short label, or has more than one plausible meaning.

### 2. Use placeholders, never concatenation

Build dynamic text with `printf`/`sprintf` so the whole sentence translates as a
unit, and add a `translators:` comment to explain each placeholder:

```php
/* translators: %s is the user's display name */
printf( esc_html__( 'Welcome back, %s', 'quick-2fa' ), $name );
```

Never split a sentence across multiple translation calls — word order differs
between languages.

### 3. Use `_n()` for anything that can be counted

Never build a count-dependent sentence by hand, and never settle for a single
form that reads correctly only for one number. Languages differ in how many
plural forms they have — English and German have two, French treats 0 as
singular, Polish and Russian have three, Japanese has one, Arabic has six — and
`_n()` is the only way to express that.

```php
// Wrong — "1 reviews", and untranslatable into languages with other forms
printf( esc_html__( '%d reviews', 'quick-2fa' ), $count );

// Right — wp-translate fills every form the target locale needs
printf(
    esc_html( _n( '%d review', '%d reviews', $count, 'quick-2fa' ) ),
    $count
);
```

Keep the placeholder in **both** forms, even when the singular reads fine
without it (`'%d review'`, not `'One review'`) — some locales use the singular
slot for other numbers too.

For a short or ambiguous countable noun, use `_nx()` — the plural equivalent of
`_x()` — so the context reaches DeepL:

```php
// "Review" alone is ambiguous: critique? opinion? inspection?
_nx( '%d review', '%d reviews', $count, 'customer feedback on a company', 'quick-2fa' );
```

**Locales needing more than two forms will have their extra slots left empty for
a human translator.** DeepL supplies a singular and a plural; nobody can invent
Polish's third form from those, and wp-translate deliberately leaves it blank
rather than filling it with a plausible guess. Expect to see empty
`msgstr[2]` entries in `pl_PL` — that is correct behaviour, not a failure.

### 4. Acronyms and technical tokens

wp-translate keeps common acronyms (`TLS`, `API`, `SMTP`, `URL`, `ID`, `UTC`, …)
verbatim automatically. If you introduce an unusual acronym or product name that
must not be translated, keep it as its own standalone string so it is recognised,
or ask the maintainer to add it to the tool's acronym list.

### 5. Don't translate dates — let WordPress localise them

Never add month or day-of-week names (full or abbreviated) as translatable
strings. DeepL frequently mistranslates short forms like `Mon`, `Tue`, `Jan`,
`Feb` even with context hints. WordPress already ships locale-aware names — use
`$wp_locale`:

```php
global $wp_locale;
$wp_locale->get_month( $month_number );        // "January" (1-based)
$wp_locale->get_month_abbrev( $month_name );   // "Jan"
$wp_locale->get_weekday( $weekday_number );     // "Monday" (0 = Sunday)
$wp_locale->get_weekday_abbrev( $weekday_name ); // "Mon"
```

For formatted dates, prefer `wp_date()` / `date_i18n()`, which localise month and
day names automatically.

### 6. English source dialect

Write source strings in standard English. wp-translate handles English targets
locally (no DeepL): `en`/`en_US` use the source as-is, and `en_GB`/`en_AU`/… get
American spellings converted to British automatically (`color` → `colour`).

### Running wp-translate

After changing strings, regenerate translations:

```bash
wp-translate /path/to/this-plugin              # auto-detect locales from languages/
wp-translate /path/to/this-plugin en_GB,fr_FR  # explicit locales
wp-translate /path/to/this-plugin --dry-run    # preview; no API calls, no writes
```

Requires WP-CLI (`wp`) and a DeepL API key at `~/.config/deepl.env`. The tool
regenerates the `.pot` from source, translates new/changed strings for each
locale, and compiles the `.mo` files.
<!-- wp-translate:end -->
