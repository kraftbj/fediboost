# FediBoost

WordPress plugin that automatically boosts (reblogs) WordPress posts on connected Mastodon accounts when published via ActivityPub.

## Architecture

- **Depends on:** [ActivityPub plugin](https://wordpress.org/plugins/activitypub/) (declared via `Requires Plugins` header)
- **Requires:** PHP 7.4+, WordPress 6.9+, OpenSSL extension
- **Namespace:** `FediBoost\` — all classes use PHP namespaces with a class-map autoloader (`includes/autoload.php`)
- **Singleton pattern:** All classes use `get_instance()` with `protected` constructors (protected, not private, for testability)

### Key files

| File | Purpose |
|------|---------|
| `fediboost.php` | Plugin bootstrap, constants, global hook callbacks, autoloader include |
| `includes/class-plugin.php` | `FediBoost\Plugin` — main plugin class |
| `includes/class-boost.php` | `FediBoost\Boost` — post publish hook, cron scheduling, search + reblog logic |
| `includes/class-oauth.php` | `FediBoost\OAuth` — OAuth app registration, token exchange, revocation, IP-pinned requests |
| `includes/class-security.php` | `FediBoost\Security` — capability checks, SSRF protection, IP validation |
| `includes/class-encryption.php` | `FediBoost\Encryption` — AES-256-CBC with HMAC-SHA256 token encryption |
| `includes/class-accounts.php` | `FediBoost\Accounts` — account CRUD, schema validation |
| `includes/class-activitypub.php` | `FediBoost\ActivityPub` — ActivityPub integration layer |
| `admin/class-admin.php` | `FediBoost\Admin` — settings page, OAuth flow UI, disconnect handling |
| `admin/js/admin.js` | Disconnect confirmation dialog |
| `admin/css/admin.css` | Admin settings page styles |
| `uninstall.php` | Full cleanup: revokes remote tokens, deletes all options and transients |

### How boosting works

1. User publishes a post → `wp_after_insert_post` fires at priority 50 (after ActivityPub's priority 33)
2. A WP-Cron event is scheduled with a configurable delay (default 30 seconds via `fediboost_boost_delay` filter)
3. Cron fires → searches the Mastodon instance for the post's ActivityPub URL → reblogs the found status

## Development

### Prerequisites

```
composer install
```

### Coding standards

PHPCS must pass clean before committing:

```
composer phpcs       # Check coding standards
composer phpcbf      # Auto-fix what it can
```

The ruleset (`phpcs.xml`) uses the full `WordPress` standard. All files in the project root are checked, excluding `vendor/`, `dist/`, and `node_modules/`.

### File naming

Class files follow WordPress conventions: `class-{name}.php` where `{name}` matches the class name (lowercase, not the prefixed name). For example, class `FediBoost\Encryption` lives in `includes/class-encryption.php`.

### Tests

Tests use PHPUnit 9 with Yoast polyfills and require the WordPress test library:

```
composer test
```

Tests are in `tests/` and require `WP_TESTS_DIR` to be set (or defaults to `sys_get_temp_dir()/wordpress-tests-lib`).

Local WordPress dev environment via wp-env:

```
npx wp-env start     # Starts WordPress with FediBoost + Plugin Check
```

### Build and release

Build the distribution zip:

```
composer zip
```

This cleans `dist/`, copies only distribution files into `dist/fediboost/`, and creates `fediboost.zip` in the project root. The `.distignore` file lists everything excluded from the distribution (tests, dev config, audit docs, build tools, etc.).

### Docblocks

All classes, methods, constants, properties, and hook definitions have `@since 1.0.0` tags. Maintain this convention — new additions should use the appropriate version number.

## Audit documents

These track review findings and their resolution status:

- `SECURITY-AUDIT.md` — Security-focused review (encryption, SSRF, OAuth, input validation)
- `CODE_REVIEW.md` — Code quality, WordPress best practices, WP 6.9 compatibility
- `WP-AUDIT.md` — WordPress Coding Standards compliance, plugin directory readiness
