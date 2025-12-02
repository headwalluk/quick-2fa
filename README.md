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

### Directory Structure

```
quick-2fa/
├── assets/          # CSS/JS assets (if needed)
├── includes/        # Core plugin classes
├── views/           # HTML template files
├── docs/            # Documentation
├── constants.php    # Plugin constants (namespaced)
├── functions.php    # Global functions
├── functions-private.php  # Private functions (namespaced)
└── quick-2fa.php    # Main plugin file
```

## Contributing

Contributions welcome! Please follow WordPress coding standards.
