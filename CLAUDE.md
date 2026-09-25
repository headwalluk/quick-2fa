# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Quick 2FA is a WordPress plugin providing email-based two-factor authentication for admin access. It intercepts login via `admin_init` (priority 1) and redirects unverified users to `wp-login.php?q2fa=verify`. It is designed to be non-breaking — REST API, WP-CLI, AJAX, cron, and XML-RPC requests bypass 2FA checks.

- **Namespace:** `Quick_2FA` for all classes
- **Text Domain:** `quick-2fa`
- **PHP:** 8.2+ (do NOT use `declare(strict_types=1)` — breaks WordPress interop). The floor is 8.2 because four handlers return `true|\WP_Error`, and the `true` type landed in 8.2; on 8.0/8.1 that is a compile-time fatal, not a graceful failure
- **WordPress:** 6.2+. The floor is 6.2 because revoking trusted devices on a password change hooks `wp_set_password`, which fires from 6.2
- **No build system** — no npm, no Composer, no bundler. Assets are plain CSS/JS.

Quick 2FA is also the maintainer's **reference plugin**: new WordPress plugins take their
structure, conventions and patterns from it. Code should be easy to read and to trace by hand,
and this file and the code comments must match what the code actually does. A stale rule or
comment here gets copied into the next project.

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
| `includes/class-github-updater.php` | In-plugin updater: checks GitHub Releases and feeds the WordPress update transient. Holds the `log()` / `log_error()` split described under **Logging** |

### Data Storage

- **User meta** for per-user state (code hashes, lock status, trusted devices, logs)
- **`wp_options`** for plugin settings (mode, code length, lock duration, etc.)
- **Transients** for temporary data (return URLs, rate limits)
- **Every stored key carries the plugin prefix:** `OPTION_PREFIX` for options, `META_PREFIX` for user meta. `uninstall.php` finds keys by prefix, so a key without one survives an uninstall. `META_PASSWORD_LAST_CHANGED` predates the rule and is listed there by name
- **Uninstall deletes nothing by default.** Only with the *Delete all plugin data when uninstalled* setting on (`OPTION_DELETE_DATA_ON_UNINSTALL`) does `uninstall.php` remove the plugin's options, user meta and transients. Deactivation never deletes data

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

Code outside the plugin depends on more than `docs/developers/hooks-and-filters.md` lists. Treat
everything in this table as a contract, whether or not it is documented as public:

| Contract | Examples | Breaks when |
|----------|----------|-------------|
| Filters and actions | `quick2fa_record_last_login`, `quick2fa_account_locked` | renamed or removed, or an argument is removed or reordered |
| Stored key strings | `_quick2fa_locked_until`, `_quick2fa_trusted_devices`, `quick2fa_mode` | a constant's **value** changes. Data stored under the old string is ignored, so a new `META_LOCKED_UNTIL` string would unlock every locked account |
| Script and style handles | `quick-2fa-login`, `quick-2fa-password-page`, `quick-2fa-settings` | renamed. Anything listing the handle as a dependency stops loading |
| Login page URLs | `wp-login.php?q2fa=verify`, `?q2fa=password` | `QUERY_PARAM` or an `ACTION_*` value changes. Firewall rules and links pointing at them break |
| WP-CLI commands | `wp quick-2fa lock-all` | a command or argument is renamed. Scripts fail; 1.3.0 renamed five with no aliases |

- **Expose behaviour, not storage.** Give integrations functions and hooks (`quick2fa_account_locked`, a getter) rather than meta keys or option names to read. A key that outside code reads directly can never move or change format: WooCommerce could only move orders to custom tables (HPOS) because code went through `WC_Order` getters. Document a stored key as readable only where a hook or getter would genuinely cost more, and then read-only; `_quick2fa_last_login` is the one such key
- **Add, don't change.** New filter arguments go at the end. New behaviour gets a new filter, not a new meaning for an existing one
- **Deprecate, don't rename.** Fire the old name through `apply_filters_deprecated()` and pass its result into the new filter, as `Github_Updater::is_enabled()` does for `quick_2fa_updater_enabled`. Remove the old name no earlier than the next major version
- **Filters are prefixed `quick2fa_`.** `quick_2fa_updater_enabled` is the one exception, and it is deprecated
- **Rename a handle through an alias:** register the old handle with no source and the new handle as its only dependency. Never register the same file under both
- **Stored data has no migration path.** `Plugin::check_first_run()` only records the version, so changing a stored key or value format is a breaking change: stop and ask

Before changing anything in the table:

1. Name the contract you are touching
2. Assume there are consumers you can't see
3. Take the additive path if one exists
4. Record it in `CHANGELOG.md`, under **Deprecated** or as a breaking change with an upgrade notice in `readme.txt`
5. If you can't tell whether anything outside the plugin depends on it, stop and ask

### High-Impact Code

These paths decide who gets into an account:

- `Verification_Code_Handler::store()` and `verify()`
- `Plugin::check_verification()`, `Plugin::user_needs_verification()` and `should_skip_check()`
- Locking: `Account_Security_Handler::lock_account()` and `is_locked()`, and `Plugin::check_lockout_on_login()`
- Device trust and session verification: `Account_Security_Handler::trust_device()`, `is_device_trusted()`, `clear_trusted_devices()`, `mark_current_session_verified()` and `get_current_session_verified_time()`, and the password-change revocation in `Plugin::revoke_devices_on_password_set()` and `revoke_devices_on_profile_update()`
- `Password_Reminder_Handler::update_password()` and `maintain_session()`

When a change touches one, say so, run every harness in `dev-notes/testing/`, and recommend the
maintainer read that diff line by line before tagging. A review by an AI agent, your own included,
does not count as that read.

### Where the Plugin Runs

- **Request context.** Hook callbacks run under WP-CLI, cron, AJAX and REST as well as browser requests, where there may be no current user, login session or admin screen. Check for what the code needs (`is_user_logged_in()`, `did_action()`, `function_exists()`). `should_skip_check()` covers the verification redirect only
- **Install layout.** Build URLs and paths with `wp_login_url()`, `admin_url()`, `QUICK_2FA_URL` and `QUICK_2FA_PATH`, never by joining strings onto the domain. WordPress may run from a subdirectory, with `wp-content` moved, or behind a reverse proxy
- **Multisite is untested.** Settings are per-site options, but user meta is network-wide, so a lock or a trusted device applies on every site in the network. A change touching either must say which it assumes

## Code Conventions

### PHP Style

- **Namespace:** `Quick_2FA` for all classes
- **No `declare(strict_types=1)`** — breaks WordPress interop
- **Single-Entry Single-Exit (SESE):** Functions should generally have one return at the end. Top-of-function guard clauses (capability checks, disabled-mode short-circuits, missing-input early-exits) are acceptable when they keep the rest of the function flat and readable. What is **not** acceptable: `return` statements scattered mid-function, inside loops, or nested several `if` blocks deep — these make the control flow hard to trace when debugging
- **An `if` with one or more `elseif` branches ends in a plain `else`**, never an `elseif`, so every case is handled on purpose instead of falling through. A branch that does nothing is still written out, with a short comment. A lone `if` needs no `else`. `phpcs.xml` excludes the `if`/`elseif`/`else` codes of `Generic.CodeAnalysis.EmptyStatement` so these comment-only branches pass; empty `catch`, loop and `switch` bodies are still errors
- **No assignment inside a condition** — WPCS flags it (`AssignmentInCondition`, `DisallowMultipleAssignments`). Assign on the line before, and nest when the value is only needed by a later branch:

```php
if ( $code_handler->has_valid_code() ) {
	// Existing code still valid; nothing to send.
} else {
	$result = $code_handler->send_via_email();

	if ( is_wp_error( $result ) ) {
		$error = $result;
	} else {
		// Sent.
	}
}
```

- **No unreachable `return`.** A `return` after a call that always `exit()`s (a redirect helper, `wp_die()`) is dead code, and so is a bare `return;` as the last statement of a `void` function. Both read as control flow that isn't there
- **Constants for all magic strings/numbers** in `constants.php` — never use raw strings for meta keys, option names, etc. A key constant's **value** is stored data and never changes; see **Public Contracts**
- **Type hints and return types** on all functions, and on class properties too (union types, nullsafe operators, named arguments). Check any type syntax newer than 8.2 against the declared floor before using it — nothing here enforces it mechanically
- **Callbacks on hooks the plugin doesn't own take `mixed`.** Any callback earlier in the chain can hand over the wrong type, and a typed parameter turns that into a `TypeError` on a page the plugin doesn't control. Declare parameters `mixed`, check each value before use (`instanceof`, `is_array()`, `is_numeric()`), and give a filter callback a `mixed` return that passes a value it can't use through unchanged. `User_Management::render_lockout_column()` is the pattern
- **Check what a filter returns.** Any callback can return the wrong type. Read a boolean result with `filter_var()` as under **Boolean Options**, and fall back to the default for a malformed array, as `Password_Reminder_Handler::generate_strong_password()` does
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

phpcs treats a template's scope as global, so variables a template defines itself are prefixed
`quick_2fa_` (`WordPress.NamingConventions.PrefixAllGlobals`). Variables handed in by the rendering
method keep their plain names and are listed in the template's `@var` docblock.

### Login Page Templates

Login pages (`views/verification-page.php`, `views/password-page.php`) have specific requirements:
- Use `login_head` and `login_footer` actions — NOT `wp_head`/`wp_footer` (no theme context)
- Use WordPress login page structure: `#login`, `#loginform`, `.message` CSS classes
- Include `<meta name="robots" content="noindex, nofollow">` on all auth pages
- Suppress Query Monitor on 2FA pages via `do_action( 'qm/cease' )`
- Shared CSS lives in `assets/css/login-pages.css`
- No inline JavaScript — all JS externalized to files in `assets/` and loaded via `wp_enqueue_script()`

### CSS and JavaScript

- **Logical properties for anything with a left or right.** Use `margin-inline-start`, `padding-inline-end`, `border-inline-start-color`, `inset-inline-end` and `text-align: start`, never `margin-left`, `right` or `text-align: left`. Core's admin and login styles switch sides in right-to-left locales and this plugin's CSS has no mirrored copy, so a physical property styles the wrong side. `.q2fa-message-warning` in `assets/css/login-pages.css` is the pattern. Top and bottom stay physical
- **Plain JavaScript.** New scripts have no jQuery dependency, like `assets/js/password-page.js`. `assets/admin/settings.js` uses jQuery only because Select2 requires it

### Logging

Two methods, deliberately split — see `Github_Updater::log()` / `log_error()`:

- `log_error()` — genuine failures (HTTP errors, malformed responses, missing assets). Logs
  **unconditionally**, so a sysadmin diagnosing a broken install sees it without touching config
- `log()` — routine flow tracing (cache hits, version comparisons, "up to date"). Logs only when `WP_DEBUG` is on

An error that only appears under `WP_DEBUG` is a silent failure in production. Never hide an
error behind a debug flag, and never leave a `catch` that records nothing.

There is no logging dependency. WooCommerce is not a hard dependency here, so `error_log()`
with a `phpcs:ignore` is correct — do not add a logging library.

### Comments

- One-line docblock summary per function, saying what it does. `@param`, `@return` and `@since` lines don't count
- Inline comments only where the mechanism isn't obvious: a load-order trap, an API behaving unexpectedly, a guard whose absence would be silently wrong
- Don't restate what the names already say, and don't label the obvious (`// Loop over users`)
- Plain words: say "check", "guard" or "only when", not "gate" or "gating"
- Match the wrap width of the surrounding file; there is no fixed column
- Reasoning and history go in `docs/`, not in comments; see **Reference Files**

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
3. `phpcs` — verify clean: no errors **and no warnings**
4. Stage and commit

Every `phpcs:ignore` and `phpcs:disable` names the exact sniff and ends with `-- reason`.

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

## Release Workflow

1. Update the version in `quick-2fa.php` — **both** the `Version:` header and the `QUICK_2FA_VERSION` constant
2. Update `CHANGELOG.md`: move the `[Unreleased]` entries under the new version, and give each bug fix the version that introduced it, when known. If the version differs from the one in new `@since` or `@deprecated` tags, update those too
3. Update `readme.txt` stable tag
4. Run `phpcs` to verify compliance
5. If the release touches **High-Impact Code**, the maintainer reads that diff line by line
6. Tag release in git

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

- `dev-notes/00-project-tracker.md` — milestones, open design questions, deferred features
- `dev-notes/02-snagging-list.md` — small agreed items awaiting a convenient moment. Check it
  before starting work: an item there may already cover the file you are about to touch
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
