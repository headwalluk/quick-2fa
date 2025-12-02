# Quick 2FA

Lightweight email-based two-factor authentication for WordPress admin access.

## Documentation

See the `docs/` directory for:

- `requirements.md` - Complete requirements specification
- `implementation.md` - Technical implementation guide

## Installation

### Standard WordPress Plugin

1. Upload the `quick-2fa` folder to `/wp-content/plugins/`
2. Activate through the WordPress admin
3. Configure at Settings > Quick 2FA (optional)

### Must-Use Plugin

1. Upload the `quick-2fa` folder to `/wp-content/mu-plugins/`
2. Plugin activates automatically
3. Access settings at Settings > Quick 2FA

## Features

- ✅ Email-based 2FA verification
- ✅ Password change reminders
- ✅ Role-based protection
- ✅ Non-breaking (REST API, WP-CLI, webhooks work normally)
- ✅ Lightweight (no JavaScript, minimal dependencies)
- ✅ Customizable branding
- ✅ User lock-out management (admin UI + WP-CLI)
- ✅ Trusted devices (optional)

## WP-CLI Commands

Quick 2FA provides comprehensive command-line tools for managing user security:

### Lock/Unlock Users

```bash
# Lock a user account (terminates all sessions)
wp quick-2fa lock <user>

# Unlock a user account
wp quick-2fa unlock <user>
```

### Emergency Lockdown

```bash
# Lock ALL users except one (emergency use)
wp quick-2fa lock-all --exclude=admin

# Unlock all locked users
wp quick-2fa unlock-all
```

### User Management

```bash
# Show comprehensive user status
wp quick-2fa status <user>

# List all locked users
wp quick-2fa list-locked

# Export locked users as CSV
wp quick-2fa list-locked --format=csv

# Clear all trusted devices for a user
wp quick-2fa clear-devices <user>
```

### Examples

```bash
# Emergency: Site under attack, lock everyone except your admin
wp quick-2fa lock-all --exclude=admin

# Check if a user is locked
wp quick-2fa status john_doe

# Unlock a user who can't access their email
wp quick-2fa unlock jane_smith

# View all locked users
wp quick-2fa list-locked --format=table
```

All commands accept user ID, login, or email address as the `<user>` identifier.

## Requirements

- WordPress 6.0 or higher
- PHP 8.2 or higher
- Working email delivery (wp_mail)

## Support

For issues and questions:

- GitHub: [Repository URL]
- WordPress.org: Plugin support forum

## License

GPL v2 or later. See LICENSE file.

## Development

This plugin follows WordPress coding standards and uses namespaces for organization.

### Architecture (v0.4.0+)

The plugin uses a class-based architecture with focused handler classes:

- `Account_Security_Handler` - Account locking and security event logging
- `Email_Handler` - Email template rendering and sending
- `Verification_Code_Handler` - Code generation, storage, and verification
- `Password_Reminder_Handler` - Password age tracking and updates
- `Plugin` - Main plugin orchestration
- `Settings` - Admin settings interface

### Directory Structure

```
quick-2fa/
├── assets/          # CSS/JS assets
├── emails/          # Email template files
├── includes/        # Core plugin classes
│   ├── class-account-security-handler.php
│   ├── class-email-handler.php
│   ├── class-verification-code-handler.php
│   ├── class-password-reminder-handler.php
│   ├── class-plugin.php
│   └── class-settings.php
├── views/           # HTML template files
├── docs/            # Documentation
├── constants.php    # Plugin constants (namespaced)
├── functions.php    # Utility functions (namespaced)
└── quick-2fa.php    # Main plugin file
```

## Contributing

Contributions welcome! Please follow WordPress coding standards.
