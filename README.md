# Quick 2FA

[![Version](https://img.shields.io/badge/version-0.11.2-blue.svg)](https://github.com/create-element/quick-2fa/releases/tag/v0.11.2)
[![PHP](https://img.shields.io/badge/PHP-8.0+-purple.svg)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/WordPress-6.0+-21759B.svg)](https://wordpress.org/)
[![License](https://img.shields.io/badge/license-GPL--2.0+-green.svg)](LICENSE)
[![Coding Standards](https://img.shields.io/badge/WordPress-Coding%20Standards-blue.svg)](https://github.com/WordPress/WordPress-Coding-Standards)

Lightweight email-based two-factor authentication for WordPress admin access.

---

## Quick Start

### Standard WordPress Plugin

1. Upload the `quick-2fa` folder to `/wp-content/plugins/`
2. Activate through the WordPress admin
3. Configure at Settings > Quick 2FA (optional)

### Must-Use Plugin

1. Upload the `quick-2fa` folder to `/wp-content/mu-plugins/`
2. Plugin activates automatically
3. Access settings at Settings > Quick 2FA

---

## Documentation

### For Developers

See [`dev-notes/`](dev-notes/) for:
- [Project Tracker](dev-notes/00-project-tracker.md) - Current development status and milestones
- [Implementation Guide](dev-notes/implementation.md) - Technical architecture and design decisions
- [Refactoring History](dev-notes/refactoring-summary.md) - Code evolution and improvements
- Patterns and workflows in [`dev-notes/patterns/`](dev-notes/patterns/) and [`dev-notes/workflows/`](dev-notes/workflows/)

---

## Features

- ✅ Email-based 2FA verification
- ✅ Password change reminders
- ✅ Role-based protection
- ✅ Non-breaking (REST API, WP-CLI, webhooks work normally)
- ✅ Lightweight (no JavaScript, minimal dependencies)
- ✅ Customizable branding
- ✅ User lock-out management (admin UI + WP-CLI)
- ✅ Trusted devices (optional)

---

## WP-CLI Commands

Quick 2FA provides comprehensive WP-CLI tools for user management:

```bash
# Lock/unlock users
wp quick-2fa lock <user>
wp quick-2fa unlock <user>

# Emergency lockdown (lock all except one user)
wp quick-2fa lock-all --exclude=admin
wp quick-2fa unlock-all

# User management
wp quick-2fa status <user>
wp quick-2fa list-locked
wp quick-2fa clear-devices <user>

# Emergency disable 2FA
wp quick-2fa emergency_disable --yes
```

All commands accept user ID, login, or email address as `<user>`.

---

## Requirements

- WordPress 6.0 or higher
- PHP 8.0 or higher
- Working email delivery (wp_mail)

---

## Customization

### Password Generator Filter

```php
add_filter( 'quick2fa_password_parameters', function( $params ) {
    return array(
        'length'              => random_int( 12, 20 ),
        'special_chars'       => true,
        'extra_special_chars' => false,
    );
} );
```

---

## License

GPL v2 or later. See [LICENSE](LICENSE) file.

---

## Contributing

Contributions welcome! Please follow [WordPress coding standards](.github/copilot-instructions.md).
