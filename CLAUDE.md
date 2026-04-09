# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Quick 2FA is a WordPress plugin providing email-based two-factor authentication for admin access. It intercepts login via `admin_init` (priority 1) and redirects unverified users to `wp-login.php?q2fa=verify`. It is designed to be non-breaking — REST API, WP-CLI, AJAX, cron, and XML-RPC requests bypass 2FA checks.

**Requirements:** PHP 8.0+, WordPress 6.0+

## Commands

```bash
phpcs                          # Check WordPress Coding Standards (configured in phpcs.xml)
phpcbf                         # Auto-fix coding standards violations
phpcs includes/class-plugin.php  # Check a specific file
```

No build step, test framework, or package.json. Code quality is enforced via phpcs only.

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
- **Device fingerprinting:** IP + user agent, hashed for storage, expires after configurable TTL
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
- **Constants for all magic strings/numbers** in `constants.php` — never use raw strings for meta keys, option names, etc.
- **Type hints and return types** on all functions (PHP 8.0+ features: union types, nullsafe operators, named arguments)
- **Dates stored as human-readable strings** (`Y-m-d H:i:s T`), not Unix timestamps

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

1. Update version in `quick-2fa.php` header
2. Update `CHANGELOG.md` with changes
3. Update `readme.txt` stable tag
4. Run `phpcs` to verify compliance
5. Tag release in git

## Developer Documentation

Detailed pattern guides live in `dev-notes/patterns/` covering: admin tabs, caching, database, JavaScript, settings API, templates, and WooCommerce integration. The copilot instructions at `.github/copilot-instructions.md` contain comprehensive coding standards.
