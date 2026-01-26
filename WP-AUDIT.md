# FediBoost WordPress Audit

**Date:** January 25, 2026
**Plugin Version:** 1.0.0
**Scope:** WordPress Coding Standards compliance, modern WordPress best practices, plugin directory readiness

---

## Executive Summary

FediBoost is a clean, well-organized WordPress plugin that follows the majority of WordPress Coding Standards. It uses proper escaping, i18n, Settings API, hook patterns, and capability checks. The main areas for improvement are: adding `@since` tags to all docblocks, documenting developer-facing filter hooks, and declaring the OpenSSL dependency to users. The plugin is close to WordPress.org directory-ready.

---

## Open Issues

---

### CODING STANDARDS

#### ISSUE 4: Missing `@since` Tags on All Methods and Hooks

**Priority:** Medium
**Category:** WordPress Coding Standards (Documentation)
**Files:** All PHP files

No method, class, or hook in the plugin has a `@since` tag. WPCS requires `@since` on all public and private methods, classes, and hook definitions. For a 1.0.0 release, every item should have `@since 1.0.0`. This is important for long-term maintenance — developers referencing filters like `fediboost_should_boost_post` need to know which version introduced them.

---

#### ISSUE 5: Global Functions in Main Plugin File

**Priority:** Low
**Category:** Code organization
**File:** `fediboost.php:31-184`

The main plugin file defines seven global functions (`fediboost_php_version_notice`, `fediboost_activate`, `fediboost_deactivate`, `fediboost_is_activitypub_active`, `fediboost_check_activitypub_dependency`, `fediboost_activitypub_missing_notice`, `fediboost_init`). While prefixed correctly, modern WordPress plugins typically use a namespace or a bootstrap class to avoid polluting the global function namespace.

Since the plugin requires PHP 7.4+, these could be moved into a namespace (`FediBoost\`) or consolidated into the main class.

---

#### ISSUE 6: No PHP Namespaces

**Priority:** Low
**Category:** Modern PHP practices
**Files:** All PHP class files

The plugin uses class-name prefixes (`FediBoost_*`) instead of PHP namespaces. Since the minimum PHP version is 7.4, namespaces are fully available and would provide cleaner code organization:

```php
namespace FediBoost;
class OAuth { ... }
```

This is not a WPCS violation (WordPress core itself doesn't use namespaces), but it is the direction modern WordPress plugins are moving, including the ActivityPub plugin that FediBoost depends on.

---

#### ISSUE 7: No Autoloader

**Priority:** Low
**Category:** Modern PHP practices
**File:** `fediboost.php:54-61`

Class files are loaded via seven `require_once` statements. With Composer already in the project, a PSR-4 autoloader could replace these. Alternatively, a simple `spl_autoload_register` function mapping `FediBoost_*` class names to file paths would work.

---

### ARCHITECTURE AND PATTERNS

#### ISSUE 9: Singleton Pattern Overuse

**Priority:** Low
**Category:** Architecture
**Files:** All class files

Every class in the plugin uses the singleton pattern with `private __construct()` and `get_instance()`. Several of these classes are stateless utilities (`FediBoost_Security`, `FediBoost_Encryption`, `FediBoost_Accounts`, `FediBoost_ActivityPub`) that don't benefit from singleton enforcement. The singleton pattern makes unit testing harder because instances can't be replaced with mocks without reflection hacks.

This is a common WordPress pattern and not a WPCS violation, but for a plugin targeting PHP 7.4+, dependency injection or simple static methods on utility classes would be more testable.

---

#### ISSUE 10: Mixed Hook Registration Locations

**Priority:** Low
**Category:** Code organization
**File:** `fediboost.php`, `admin/class-fediboost-admin.php`, `includes/class-fediboost-boost.php`

Some hooks are registered in the main plugin file (`admin_init`, `admin_notices`, `plugins_loaded`), while others are registered in class constructors (`FediBoost_Admin::init_hooks()`, `FediBoost_Boost::init_hooks()`). This makes it harder to audit all registered hooks in one place.

Consider a centralized hook registration approach, or at minimum document in the main file which classes register their own hooks.

---

### INTERNATIONALIZATION

#### ISSUE 12: Developer-Facing Filter Hooks Undocumented

**Priority:** Medium
**Category:** Developer documentation
**Files:** `includes/class-fediboost-boost.php`, `includes/class-fediboost-security.php`

The plugin provides three useful filter hooks for developers but none are documented in the readme.txt or in standalone developer docs:

- `fediboost_should_boost_post` (bool, WP_Post) — Per-post boost eligibility.
- `fediboost_boost_delay` (int) — Delay in seconds before boost (default 30).
- `fediboost_manage_capability` (string) — Required capability (default `manage_options`).

These should be documented in the readme FAQ or a dedicated section so developers know they exist.

---

### USER INTERFACE AND ACCESSIBILITY

#### ISSUE 13: Missing `aria-describedby` on Instance URL Input

**Priority:** Low
**Category:** Accessibility
**File:** `admin/class-fediboost-admin.php:674`

The instance URL input field has a `<label>` association but the description paragraph below it is not linked via `aria-describedby`. Screen readers won't automatically announce the helper text.

```html
<input ... aria-describedby="instance-url-description">
<p class="description" id="instance-url-description">...</p>
```

---

#### ISSUE 14: Inline Script Registration Uses Empty Source

**Priority:** Low
**Category:** Asset management
**File:** `admin/class-fediboost-admin.php:146-150`

The admin class registers a script with an empty source string:

```php
wp_enqueue_script( 'fediboost-admin', '', array(), FEDIBOOST_VERSION, true );
wp_add_inline_script( 'fediboost-admin', '...' );
```

Registering a script handle with no source is a known workaround but produces a technically invalid enqueue. The `wp_add_inline_script` call should attach to a real script file (even a minimal one), or the inline JS should be output via `admin_print_footer_scripts` action instead.

---

#### ISSUE 15: CSS Uses Hardcoded Colors Instead of Admin Theme Variables

**Priority:** Low
**Category:** CSS best practices
**File:** `admin/css/admin.css`

The status indicator colors (`#007c4d`, `#cc1818`) and empty state colors (`#f0f0f1`, `#c3c4c7`, `#50575e`) are hardcoded. WordPress admin provides CSS custom properties and standard color classes. Using admin theme variables or standard WordPress notice/status classes would ensure consistency with custom admin color schemes.

The current colors do match the default admin theme, so this is a polish issue rather than a bug.

---

### PERFORMANCE

#### ISSUE 19: No Cron Event Cleanup for Orphaned Events

**Priority:** Low
**Category:** Maintenance
**File:** `fediboost.php:99-115`, `includes/class-fediboost-boost.php`

The deactivation hook calls `wp_clear_scheduled_hook( 'fediboost_boost_post' )` which removes all scheduled instances. This is correct. However, if a scheduled event fails (e.g., the cron runner dies mid-execution), there's no mechanism to retry or clean up. WP-Cron doesn't provide built-in retry logic.

For v1.0, this is acceptable. For future versions, consider logging boost outcomes to post meta so admins can see which posts were boosted and which failed.

---

### WORDPRESS.ORG READINESS

#### ISSUE 20: OpenSSL Dependency Not Declared

**Priority:** Medium
**Category:** Requirements transparency
**File:** `includes/class-fediboost-encryption.php`

The plugin requires the OpenSSL PHP extension for token encryption but doesn't declare this dependency in the plugin header or readme.txt. The `FediBoost_Encryption::is_available()` method checks at runtime, and `encrypt()` returns `false` if OpenSSL is unavailable — but the user gets no upfront warning.

If OpenSSL is not available, `store_connected_account()` silently fails (returns `false`), and the user sees a generic "Failed to save account credentials" error.

**Recommendation:** Add a startup check and admin notice when OpenSSL is not available, similar to the PHP version check.

---

### TESTING

#### ISSUE 21: Test Coverage Gaps

**Priority:** Low
**Category:** Testing infrastructure
**File:** `tests/bootstrap.php`, `phpunit.xml`

The test suite uses PHPUnit 9 with Yoast polyfills, which is the correct modern setup for WordPress plugin testing. The `.wp-env.json` includes `plugin-check` which suggests Plugin Check integration.

No issues with the test infrastructure itself. The test coverage areas (foundation, OAuth, security, admin, boost, integration) cover the key functionality. Consider adding:
- Tests for the stale-index disconnect issue (Issue 8).
- Tests for encryption/decryption round-trip with edge cases.
- Tests for `is_external_url()` with IPv6 addresses.

---

## Summary Table

| # | Issue | Priority | Status |
|---|-------|----------|--------|
| 4 | Missing `@since` tags on all methods and hooks | Medium | Open |
| 5 | Global functions in main plugin file | Low | Open |
| 6 | No PHP namespaces | Low | Open |
| 7 | No autoloader | Low | Open |
| 9 | Singleton pattern overuse | Low | Open |
| 10 | Mixed hook registration locations | Low | Open |
| 12 | Developer filter hooks undocumented | Medium | Open |
| 13 | Missing `aria-describedby` on input | Low | Open |
| 14 | Inline script uses empty source handle | Low | Open |
| 15 | CSS uses hardcoded colors | Low | Open |
| 19 | No cron retry/cleanup mechanism | Low | Open |
| 20 | OpenSSL dependency not declared to users | Medium | Open |
| 21 | Test coverage gaps | Low | Open |

---

## Resolved Issues

| # | Issue | Resolution |
|---|-------|------------|
| 1 | Missing `Requires Plugins` header | Fixed Jan 25. Added `Requires Plugins: activitypub` header. |
| 2 | readme.txt installation instructions inaccurate | Fixed Jan 26. Replaced with correct Settings > FediBoost flow. |
| 3 | readme.txt missing privacy/external services section | Fixed Jan 26. Added External Services section to readme.txt. |
| 8 | Stale array index on disconnect (functional bug) | Fixed Jan 25. Replaced numeric indices with stable `md5(hostname:username)` keys. |
| 11 | No `.pot` file for translations | N/A. Plugin will use translate.wordpress.org. |
| 16 | `.distignore` missing `agent-os/` directory | Fixed Jan 25. Added `agent-os/`, audit files to `.distignore`. |
| 17 | `.distignore` and build script not in sync | Fixed Jan 26. Added `.idea/` and `phpcs.xml` to `.distignore`. |
| 18 | No `index.php` in subdirectories | Fixed Jan 26. Added `index.php` to `includes/`, `admin/`, `admin/css/`. |

---

## Positive Practices Observed

- **Consistent WPCS formatting** — Tabs for indentation, Yoda conditions, proper spacing, aligned array arrows.
- **Proper escaping everywhere** — `esc_html()`, `esc_url()`, `esc_attr()`, `esc_js()` used consistently on output.
- **Correct i18n usage** — All user-facing strings wrapped in translation functions with correct text domain and translator comments on all placeholder strings.
- **Settings API** — Proper use of `register_setting()`, `add_settings_section()`, and `sanitize_callback`.
- **Option autoloading disabled** — All `update_option()` calls pass `false` for the autoload parameter. Correct for data not needed on every page load.
- **Admin assets scoped to plugin page** — CSS only loads on `settings_page_fediboost`. No global admin asset bloat.
- **`WP_Error` usage** — Consistent use of `WP_Error` for error propagation and `is_wp_error()` checks.
- **Activation/deactivation hooks** — Proper use of `register_activation_hook` and `register_deactivation_hook` with clean separation of concerns.
- **Uninstall routine** — Comprehensive data cleanup via `uninstall.php` (not register_uninstall_hook), using `WP_UNINSTALL_PLUGIN` guard.
- **Prepared SQL** — All direct database queries use `$wpdb->prepare()` with `$wpdb->esc_like()`.
- **Hook priority documented** — The `wp_after_insert_post` priority 50 is documented as intentionally after ActivityPub's priority 33.
- **PHP version check** — Graceful degradation with admin notice when PHP requirement isn't met.
- **WPCS tooling configured** — `composer.json` includes `wp-coding-standards/wpcs` with `phpcs` and `phpcbf` scripts.
