# Contributing to RouteMile for WooCommerce

Thank you for your interest in contributing! This document provides guidelines for contributing to this project.

## Code of Conduct

Be respectful and professional in all interactions.

## Development Setup

### Prerequisites
- WordPress 6.0+
- WooCommerce 7.0+
- PHP 7.4+
- Local development environment (Local, MAMP, Docker, etc.)

### Getting Started

1. Clone the repository:
   ```bash
   git clone https://github.com/codermillat/RouteMile-for-WooCommerce.git
   ```

2. Copy to your WordPress plugins directory:
   ```bash
   cp -r RouteMile-for-WooCommerce /path/to/wordpress/wp-content/plugins/
   ```

3. Activate the plugin in WordPress admin

## Coding Standards

### PHP
- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- Use tabs for indentation
- Include ABSPATH check in all PHP files:
  ```php
  if (!defined('ABSPATH')) {
      exit;
  }
  ```

### JavaScript
- Use strict mode: `'use strict';`
- Wrap in IIFE with jQuery: `(function($) { ... })(jQuery);`

### Security
- Always verify nonces for form submissions and AJAX
- Check user capabilities before actions
- Sanitize all input: `sanitize_text_field()`, `absint()`, etc.
- Escape all output: `esc_html()`, `esc_attr()`, `wp_kses_post()`

## Testing

Run the test suite before submitting changes:

```bash
cd wp-content/plugins/routemile-woocommerce
php tests/FXWTestRunner.php
```

All 118 tests must pass.

## Pull Request Process

1. Create a feature branch from `main`
2. Make your changes with clear commit messages
3. Ensure all tests pass
4. Update CHANGELOG.md if applicable
5. Submit a pull request with a clear description

New feature work follows the phased plan in [docs/ROADMAP.md](docs/ROADMAP.md) — please open an issue before starting large changes so work doesn't collide with an in-flight phase. Full architecture context lives in [AGENTS.md](AGENTS.md).

## License

By contributing, you agree that your contributions will be licensed under the [GPL-3.0-or-later](LICENSE.md) license that covers this project.

## Questions?

Open an issue on GitHub for any questions or discussions.
