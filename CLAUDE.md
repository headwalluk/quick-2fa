# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Quick 2FA is a WordPress plugin providing email-based two-factor authentication for admin access. It intercepts login via `admin_init` (priority 1) and redirects unverified users to `wp-login.php?q2fa=verify`. It is designed to be non-breaking — REST API, WP-CLI, AJAX, cron, and XML-RPC requests bypass 2FA checks.

- **Namespace:** `Quick_2FA` for all classes
- **Text Domain:** `quick-2fa`
- **PHP:** 8.0+ (do NOT use `declare(strict_types=1)` — breaks WordPress interop)
- **WordPress:** 6.0+
- **No build system** — no npm, no Composer, no bundler. Assets are plain CSS/JS.

This plugin is published publicly on GitHub. Tracked files must contain no client names,
client URLs, fleet measurements or client data of any kind — in code, comments, docs,
fixtures or commit messages.

`dev-notes/` is **private and untracked** (`.gitignore`), backed up with the dev site, and
blocked from the web by `dev-notes/.htaccess`. It is the right home for client-identifying
material. Never copy content from `dev-notes/` into a tracked file without scrubbing it, and
never reference a `dev-notes/` path from a file that ships in the release zip.

## Commands

```bash
phpcs                          # Check WordPress Coding Standards (configured in phpcs.xml)
phpcbf                         # Auto-fix coding standards violations
phpcs includes/class-plugin.php  # Check a specific file
```

```bash
wp-translate . --check-instructions   # Is the block at the end of this file still current?
wp-translate . --sync-instructions    # Update it; review the diff afterwards
wp-translate . --dry-run              # Preview; no DeepL calls, no writes
```

Run `--check-instructions` after a `wp-translate` upgrade; if it reports drift, run
`--sync-instructions` and review the diff. Never hand-edit inside the
`wp-translate:begin`/`end` markers — the block is hash-validated and edits break it.

No build step or package.json. Code quality is enforced via phpcs; see **Testing** below for
how behaviour is exercised.

## Testing

There is no unit-test framework and none is wanted. Behaviour is exercised against the live
dev site through WP-CLI, which is enough for a plugin this size:

```bash
# Fire a hook and inspect what it wrote
wp eval '$user = get_user_by( "id", 1 ); do_action( "wp_login", $user->user_login, $user );'
wp user meta get 1 _quick2fa_last_login

# Exercise a filter path
wp eval 'add_filter( "quick2fa_record_last_login", "__return_false" ); ...'
```

Two rules: reset the state you touch afterwards (`wp user meta delete`, `wp option delete`),
and test the **defensive** path as well as the happy one — firing `wp_login` with a single
argument is what proves a hook callback tolerates a sloppy third-party caller.

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
4. User submits code → `Verification_Code_Handler::verify()` → success sets trusted device → retrieves return URL from transient → redirect
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

### Data Storage

- **User meta** for per-user state (code hashes, lock status, trusted devices, logs)
- **`wp_options`** for plugin settings (mode, code length, lock duration, etc.)
- **Transients** for temporary data (return URLs, rate limits)

### Security Implementation

- **Code generation:** `random_int()` for cryptographically secure codes
- **Code storage:** hashed with `wp_hash_password()`, verified with `wp_check_password()` — never store plaintext
- **Rate limiting:** max 3 code generation requests per 15 min, max 5 verification attempts per session
- **Account locking:** 10 failed attempts in 1 hour triggers 1-hour lockout (all configurable via constants)
- **Device trust:** a random token set as a secure cookie (`HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS); only the token's SHA-256 is stored in user meta, expiring after a configurable TTL. Identity is the cookie, **not** IP/User-Agent (those churn on real connections and are log-only). See `dev-notes/00-project-tracker.md` (v1.2.0) and `docs/trusted-devices.md`
- **Session management:** on password change, destroy other sessions via `WP_Session_Tokens::destroy_others()` while keeping current session alive

### 2FA Modes

- `MODE_ALL` — every user requires 2FA
- `MODE_ROLES` — only configured roles (default: admins)
- `MODE_DISABLED` — 2FA off

## Code Conventions

### PHP Style

- **Namespace:** `Quick_2FA` for all classes
- **No `declare(strict_types=1)`** — breaks WordPress interop
- **Single-Entry Single-Exit (SESE):** Functions should generally have one return at the end. Top-of-function guard clauses (capability checks, disabled-mode short-circuits, missing-input early-exits) are acceptable when they keep the rest of the function flat and readable. What is **not** acceptable: `return` statements scattered mid-function, inside loops, or nested several `if` blocks deep — these make the control flow hard to trace when debugging
- **No unreachable `return`.** A `return` after a call that always `exit()`s (a redirect helper, `wp_die()`) is dead code, and so is a bare `return;` as the last statement of a `void` function. Both read as control flow that isn't there
- **Constants for all magic strings/numbers** in `constants.php` — never use raw strings for meta keys, option names, etc.
- **Type hints and return types** on all functions (PHP 8.0+ features: union types, nullsafe operators, named arguments)
- **Guard object lookups with `instanceof`, not truthiness.** `get_userdata()`, `get_user_by()`, `wc_get_product()` and friends are documented as returning `Object|false` — but `get_userdata()` and `get_user_by()` are *pluggable*, so any plugin loading earlier can replace them wholesale with no contract at all. `if ( ! $user )` and `if ( ! empty( $user ) )` are exactly equivalent (`empty()` is just `! isset() || == false`), and both let truthy junk — a `stdClass`, a non-empty string, a populated array — through to `$user->user_email` and a fatal. `if ( ! $user instanceof \WP_User )` accepts only the contract the code actually relies on. Never pass an unguarded lookup into a view
- **Cast at the boundary.** WordPress returns loosely-typed data — `get_user_meta()` and `get_option()` hand back strings (or `''`, or `false`). Cast on the way in, at the point of read, so the rest of the function works with a known type: `$locked_until = (int) get_user_meta( $user_id, META_LOCKED_UNTIL, true );`. Don't feed a raw meta value straight into a numeric comparison
- **Don't duplicate derived values.** Where two hooks need the same message or computation, extract a helper. Two copies of a rule (a lockout message, a threshold) will diverge the first time one is edited
- **Dates stored as Unix timestamps** (`time()`), matching `_quick2fa_last_verified`, `_quick2fa_locked_until` and the event log. Format for display only, at the point of output, with `wp_date()` / `date_i18n()` so the site's locale and timezone apply

### Template Pattern (Code-First)

Templates use `printf()`/`echo` exclusively — no inline HTML mixed with PHP snippets. This prevents whitespace bleeding into attributes/values.

```php
// Correct
printf(
    '<button>%s</button>',
    esc_html__( 'Click', 'quick-2fa' )
);

// Wrong — no inline HTML
<button><?php esc_html_e( 'Click', 'quick-2fa' ); ?></button>
```

Template variables must be prefixed with `q2fa_` to comply with WordPress global naming standards (phpcs requirement).

### Login Page Templates

Login pages (`views/verification-page.php`, `views/password-page.php`) have specific requirements:
- Use `login_head` and `login_footer` actions — NOT `wp_head`/`wp_footer` (no theme context)
- Use WordPress login page structure: `#login`, `#loginform`, `.message` CSS classes
- Include `<meta name="robots" content="noindex, nofollow">` on all auth pages
- Suppress Query Monitor on 2FA pages via `do_action( 'qm/cease' )`
- Shared CSS lives in `assets/css/login-pages.css`
- No inline JavaScript — all JS externalized to files in `assets/` and loaded via `wp_enqueue_script()`

### Logging

Two methods, deliberately split — see `Github_Updater::log()` / `log_error()`:

- `log_error()` — genuine failures (HTTP errors, malformed responses, missing assets). Logs
  **unconditionally**, so a sysadmin diagnosing a broken install sees it without touching config
- `log()` — routine flow tracing (cache hits, version comparisons, "up to date"). Gated on `WP_DEBUG`

An error that only appears under `WP_DEBUG` is a silent failure in production. Never gate an
error behind a debug flag, and never leave a `catch` that records nothing.

There is no logging dependency. WooCommerce is not a hard dependency here, so `error_log()`
with a `phpcs:ignore` is correct — do not add a logging library.

### Boolean Options

Use `filter_var()` with `FILTER_VALIDATE_BOOLEAN` to handle all WordPress boolean formats (`'1'`, `'yes'`, `'on'`, `true`):

```php
$enabled = (bool) filter_var( get_option( OPTION_ENABLED, false ), FILTER_VALIDATE_BOOLEAN );
```

### Commit Messages

Format: `type: brief description` where type is one of: `feat:`, `fix:`, `chore:`, `refactor:`, `docs:`, `style:`, `test:`

### Pre-Commit Workflow

1. `phpcs` — check violations
2. `phpcbf` — auto-fix
3. `phpcs` — verify clean
4. Stage and commit

## WP-CLI Commands

```bash
wp quick-2fa lock <user>                    # Lock a user account
wp quick-2fa unlock <user>                  # Unlock a user account
wp quick-2fa status <user>                  # Check user 2FA status
wp quick-2fa lock-all --exclude=admin       # Emergency lockdown
wp quick-2fa list-locked                    # List all locked users
wp quick-2fa clear-devices <user>           # Clear trusted devices
wp quick-2fa emergency_disable --yes        # Disable 2FA entirely
```

## Release Workflow

1. Update the version in `quick-2fa.php` — **both** the `Version:` header and the `QUICK_2FA_VERSION` constant
2. Update `CHANGELOG.md` with changes
3. Update `readme.txt` stable tag
4. Run `phpcs` to verify compliance
5. Tag release in git

The version lives in **three** places that must agree with the git tag: the header `Version:`
field, `QUICK_2FA_VERSION`, and the `readme.txt` stable tag. `.github/workflows/release.yml`
refuses to build on a mismatch. This gate exists because v1.2.2 shipped with the header bumped
and the constant left behind, which made the updater advertise a permanent false update on
every site — see the 1.3.0 entry in `CHANGELOG.md`.

**The `Version` must always correspond to a real GitHub Release tag.** Setting it to a version
that doesn't exist on GitHub degrades the updater experience.

## Reference Files

`docs/` is the maintained entry point and is tracked/public. **One audience per document** — do
not mix operator and developer material in the same file:

- `docs/how-it-works.md`, `docs/configuration.md`, `docs/trusted-devices.md`,
  `docs/account-locking.md`, `docs/wp-cli.md`, `docs/troubleshooting.md` — site operators
- `docs/developers/hooks-and-filters.md` — the public extension surface
- `docs/developers/extending.md` — practical developer recipes

Rationale, evidence and history belong in `docs/`, not in code comments. Where a mechanism
needs more than a sentence to justify, write it up in `docs/` and reference the file from the
code.

Supporting material (private, untracked):

- `dev-notes/00-project-tracker.md` — current milestones and tech debt
- `dev-notes/archive/` — superseded material kept for reference, including the retired
  `copilot-instructions.md` and the old `patterns/` and `workflows/` guides. Archived, not
  authoritative: **this file is the standard**, not anything under `archive/`
- `CHANGELOG.md` — per-version release notes (tracked)

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
