

# WP Translation Fixer

Fixes translation loading issues in WordPress 6.7+ by preventing premature textdomain loading and ensuring translations are loaded at the correct time.

## The Problem

WordPress 6.7 introduced stricter timing requirements for translation loading, causing issues with plugins that load translations too early. This particularly affects:
- Bedrock installations
- Sites using mu-plugins
- Multilingual WordPress sites
- Plugins using the `plugins_loaded` hook for translation initialization

Common symptoms include:
- Missing translations
- Debug warnings about `_load_textdomain_just_in_time`
- Translations only working after a page refresh

## The Solution

This plugin automatically:
1. Moves `plugins_loaded` hooks to `init` when they handle translations
2. Prevents early translation loading before `init`
3. Reloads translations at the correct time
4. Provides debug logging when `WP_DEBUG` is enabled

## Installation

### For Bedrock

1. Add to your composer.json:
```json
{
    "require": {
        "jonesrussell/wp-translation-fixer": "^1.0"
    }
}
```

2. Run:
```bash
composer update
```

### For Standard WordPress

1. Download the latest release
2. Place `fix-translations-loading.php` in your `wp-content/mu-plugins/` directory
3. If the `mu-plugins` directory doesn't exist, create it

## Configuration

No configuration needed! The plugin works automatically.

When `WP_DEBUG` and `WP_DEBUG_LOG` are enabled, the plugin will log:
- Hook movements
- Early translation prevention
- Translation loading issues

## Compatibility

- WordPress 6.7+
- PHP 7.4+
- Compatible with all well-coded plugins that use WordPress translation functions

## Support

- [Report issues](https://github.com/your-username/wp-translation-fixer/issues)
- [Submit pull requests](https://github.com/your-username/wp-translation-fixer/pulls)

## License

GPL-2.0-or-later

## Changelog

### 1.0.3
- Added improved logging with request tracking
- Added deduplication of log messages
- Improved performance by skipping non-main requests

### 1.0.2
- Initial public release
- Added support for static method callbacks
- Added debug logging

## Credits

Developed by [Russell Jones](https://github.com/jonesrussell)
