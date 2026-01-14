# FediBoost Plugin Code Review

**Review Date:** 2026-01-13
**Target WordPress Version:** 6.9+
**Reviewer Focus:** WordPress best practices, security, WP 6.9 compatibility, unnecessary legacy code

---

## Executive Summary

FediBoost is a well-structured WordPress plugin that automatically boosts posts on connected Mastodon accounts when published via ActivityPub. The codebase demonstrates good separation of concerns and follows WordPress coding standards. However, several issues need addressing before WordPress.org submission.

**Critical Issues:** 2 *(Phase 1 - COMPLETE)*
**High Priority Issues:** 6 *(Phase 2 - COMPLETE)*
**Phase 2.5 Issues:** 7 *(Plugin Check findings - NEW)*
**Medium Priority Issues:** 7
**Low Priority Issues:** 5

---

## Critical Issues

### 1. CRITICAL: Incomplete Plugin Header Metadata
**File:** `fediboost.php:1-16`
**Issue:** Plugin header has placeholder values and missing required fields for WordPress.org submission.

**Current:**
```php
* Author: Your Name
```

**Required Changes:**
- [ ] Set actual `Author` name
- [ ] Add `Author URI` field

**Recommendation:** Fill in author details before submission. WordPress.org requires accurate author information.

---

### 2. CRITICAL: WordPress Version Requirement Mismatch
**File:** `fediboost.php:7`
**Issue:** Plugin declares `Requires at least: 6.0` but target is WordPress 6.9.

**Current:**
```php
* Requires at least: 6.0
```

**Required:**
```php
* Requires at least: 6.9
```

---

## High Priority Issues

### 3. HIGH: CSS Class Naming Inconsistency
**Files:** `admin/css/admin.css`, `admin/class-fediboost-admin.php`
**Issue:** CSS uses `.auto-tooter-*` prefix but PHP uses `.fediboost-*`. **Styles won't apply.**

**CSS file uses:**
- `.auto-tooter-empty-state`
- `.auto-tooter-status`
- `.auto-tooter-status-connected`
- `.auto-tooter-status-disconnected`
- `.auto-tooter-accounts-table`

**PHP file uses:**
- `.fediboost-empty-state` (line 580)
- `.fediboost-status` (line 617, 621)
- `.fediboost-status-connected` (line 617)
- `.fediboost-status-disconnected` (line 621)
- `.fediboost-accounts-table` (line 595)

**Fix:** Update `admin/css/admin.css` to use `fediboost-*` prefix consistently.

---

### 4. HIGH: Composer Package Name Mismatch
**File:** `composer.json:2`
**Issue:** Package name is `auto-tooter/auto-tooter` but plugin is now `fediboost`.

**Current:**
```json
"name": "auto-tooter/auto-tooter",
```

**Required:**
```json
"name": "fediboost/fediboost",
```

---

### 5. HIGH: Build Scripts Reference Wrong Filename
**File:** `composer.json:29-38`
**Issue:** Build scripts reference `auto-tooter.php` but main plugin file is `fediboost.php`.

**Affected lines:**
```json
"mkdir -p dist/auto-tooter",
"cp auto-tooter.php dist/auto-tooter/",
"cd dist && zip -r ../auto-tooter.zip auto-tooter",
```

**Should be:**
```json
"mkdir -p dist/fediboost",
"cp fediboost.php dist/fediboost/",
"cd dist && zip -r ../fediboost.zip fediboost",
```

---

### 6. HIGH: Unnecessary load_plugin_textdomain Call
**File:** `fediboost.php:163-164`
**Issue:** For plugins hosted on WordPress.org, `load_plugin_textdomain()` is unnecessary. WordPress automatically loads translations from translate.wordpress.org.

**Current:**
```php
// Load text domain for translations.
load_plugin_textdomain( 'fediboost', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
```

**Fix:** Remove these lines entirely.

---

### 7. HIGH: Unnecessary Languages Directory
**File:** `/languages/` directory
**Issue:** Empty languages directory is not needed. WordPress.org's translate.wordpress.org handles translations automatically.

**Fix:** Delete the `/languages/` directory.

---

### 8. HIGH: Missing Uninstall Hook
**Issue:** Plugin has no `uninstall.php` or `register_uninstall_hook()`. When users delete the plugin, data remains in the database.

**Missing cleanup for:**
- `fediboost_accounts` option
- `fediboost_instance_apps` option
- `fediboost_activated` option
- `fediboost_show_activitypub_notice` option
- Transients with prefix `fediboost_oauth_state_`
- Transients with prefix `fediboost_account_`

**Recommendation:** Create `uninstall.php` file to clean up all plugin data.

---

## Medium Priority Issues

### 9. MEDIUM: Options Stored Without Explicit Autoload Setting
**Files:** `fediboost.php:76-86`, `includes/class-fediboost-oauth.php:424`, `includes/class-fediboost-accounts.php:188`
**Issue:** `update_option()` calls don't specify autoload parameter. For options not needed on every page load, autoload should be explicitly set to `false`.

**Affected options:**
- `fediboost_accounts` - Contains account data, not needed on frontend
- `fediboost_instance_apps` - Contains OAuth app credentials, rarely needed
- `fediboost_activated` - One-time flag
- `fediboost_show_activitypub_notice` - Admin-only

**Recommendation:** Use `update_option( 'option_name', $value, false )` for these options.

---

### 10. MEDIUM: Sensitive Data in Error Logs
**Files:** Multiple files with `log_error()` methods
**Issue:** Error logging includes potentially sensitive information (instance URLs, usernames) that could be exposed in shared hosting environments.

**Example from `class-fediboost-oauth.php:510-517`:**
```php
$log_message = sprintf(
    'FediBoost OAuth: %s - %s',
    $message,
    wp_json_encode( $context )
);
error_log( $log_message );
```

**Recommendation:**
- Add `WP_DEBUG` and `WP_DEBUG_LOG` checks before logging
- Consider adding a plugin-specific debug mode setting

---

### 11. MEDIUM: Hardcoded Boost Delay
**File:** `includes/class-fediboost-boost.php:33`
**Issue:** 30-second delay is hardcoded. Users may want to adjust this.

```php
const BOOST_DELAY = 30;
```

**Recommendation:** Make this filterable:
```php
$delay = apply_filters( 'fediboost_boost_delay', self::BOOST_DELAY );
```

---

### 12. MEDIUM: Missing Capability Filter
**File:** `includes/class-fediboost-security.php:73-75`
**Issue:** `manage_options` capability is hardcoded. Some sites may want different roles to manage FediBoost.

```php
public function user_can_manage() {
    return current_user_can( 'manage_options' );
}
```

**Recommendation:** Add a filter:
```php
public function user_can_manage() {
    $capability = apply_filters( 'fediboost_manage_capability', 'manage_options' );
    return current_user_can( $capability );
}
```

---

### 13. MEDIUM: Missing Post Type Filter
**File:** `includes/class-fediboost-boost.php:92-94`
**Issue:** Only `post` type is supported. Custom post types that support ActivityPub are excluded.

```php
if ( 'post' !== $post->post_type ) {
    return;
}
```

**Recommendation:** Add filter for supported post types:
```php
$supported_types = apply_filters( 'fediboost_supported_post_types', array( 'post' ) );
if ( ! in_array( $post->post_type, $supported_types, true ) ) {
    return;
}
```

---

### 14. MEDIUM: No Rate Limiting for OAuth Registration
**File:** `includes/class-fediboost-oauth.php:83-164`
**Issue:** No rate limiting on OAuth app registration attempts. Malicious users with admin access could abuse this.

**Recommendation:** Add transient-based rate limiting to prevent excessive requests to Mastodon instances.

---

### 15. MEDIUM: HTTP Timeout May Be Too Long
**Files:** `includes/class-fediboost-oauth.php:102`, `includes/class-fediboost-boost.php:283`
**Issue:** 30-second timeout for HTTP requests may be too long for cron context.

```php
'timeout' => 30,
```

**Recommendation:** Consider reducing timeout for cron-executed requests (15 seconds) to prevent long-running cron processes.

---

## Low Priority Issues

### 16. LOW: Inline JavaScript in Admin
**File:** `admin/class-fediboost-admin.php:640`
**Issue:** Confirmation dialog uses inline `onclick` handler.

```php
onclick="return confirm('<?php esc_attr_e( 'Are you sure...', 'fediboost' ); ?>');"
```

**Recommendation:** Consider moving to enqueued JavaScript file for better CSP compliance, though this is acceptable for simple confirmations.

---

### 17. LOW: CSS Could Use WordPress Color Variables
**File:** `admin/css/admin.css`
**Issue:** Hardcoded color values instead of CSS custom properties.

```css
background: #d4edda;
color: #155724;
```

**Recommendation:** Consider using WordPress admin color scheme variables for better theme consistency.

---

### 18. LOW: Missing ARIA Labels
**File:** `admin/class-fediboost-admin.php:595-647`
**Issue:** Accounts table could benefit from ARIA attributes for better accessibility.

**Recommendation:** Add `aria-label` to action buttons and status indicators.

---

### 19. LOW: Transient Cleanup on Deactivation
**File:** `fediboost.php:99-105`
**Issue:** Deactivation doesn't clean up OAuth state transients.

**Note:** These transients expire in 1 hour anyway, so this is minor.

---

### 20. LOW: Composer Autoload Not Used
**File:** `composer.json:15-19`, `fediboost.php:53-61`
**Issue:** Composer classmap autoload is defined but plugin manually requires files.

**Recommendation:** Either remove composer autoload config or use it consistently. For WordPress.org submission, manual requires are fine since Composer isn't available.

---

## WordPress 6.9 Compatibility Notes

### Deprecated Functions Check
No deprecated functions were found. The plugin uses current WordPress APIs.

### Removed Legacy Patterns
The following legacy patterns are NOT present (good):
- No use of `create_function()`
- No use of `mysql_*` functions
- No use of deprecated `$_REQUEST` without proper sanitization
- No use of `extract()`

---

## Security Review Summary

### Positive Security Practices
- Nonce verification on all form submissions
- Capability checks before sensitive operations
- Input sanitization using WordPress functions
- Encrypted token storage using AES-256-CBC
- CSRF protection with state parameter in OAuth flow
- Safe redirect usage with `wp_safe_redirect()`

### Areas for Improvement
- [ ] Add rate limiting for OAuth operations
- [ ] Sanitize logged data to remove sensitive information
- [ ] Add `WP_DEBUG` checks before logging

---

## Files Requiring Changes

### Must Fix Before Submission
| File | Lines | Issue |
|------|-------|-------|
| `fediboost.php` | 7 | WordPress version requirement |
| `fediboost.php` | 9 | Author metadata |
| `fediboost.php` | 163-164 | Remove load_plugin_textdomain |
| `admin/css/admin.css` | All | Class name prefix |
| `composer.json` | 2, 29-38 | Package name and build scripts |
| `/languages/` | - | Delete directory |

### Should Fix
| File | Lines | Issue |
|------|-------|-------|
| `fediboost.php` | 76-86 | Autoload parameter |
| `includes/class-fediboost-oauth.php` | 424 | Autoload parameter |
| New file | - | Create `uninstall.php` |

### Nice to Have
| File | Lines | Issue |
|------|-------|-------|
| `includes/class-fediboost-boost.php` | 33, 92-94 | Add filters |
| `includes/class-fediboost-security.php` | 73-75 | Capability filter |
| Multiple files | Various | Add WP_DEBUG checks to logging |

---

## Recommended Fix Order

1. **Phase 1 - Critical (Blocking)**
   - Update plugin header metadata (author, author URI)
   - Update WordPress version requirement to 6.9
   - Fix CSS class naming (auto-tooter → fediboost)

2. **Phase 2 - High Priority**
   - Fix composer.json package name and build scripts
   - Remove `load_plugin_textdomain()` call
   - Delete `/languages/` directory
   - Create `uninstall.php`

3. **Phase 2.5 - Plugin Check Findings (Blocking)**

   *Discovered via `wp plugin check` on 2026-01-14*

   **Errors (Must Fix):**
   - Create `readme.txt` file (required for WordPress.org)
   - Remove `Domain Path` header from `fediboost.php` (points to deleted `/languages/` directory)
   - Delete `/dist/` directory (contains old auto-tooter build artifacts with wrong text domain)
   - Delete `fediboost.zip` file (compressed files not permitted)
   - Add `.wp-env.json` to `.distignore` (hidden files not permitted in distribution)
   - Add direct file access protection to `tests/bootstrap.php`

   **Warnings (Should Fix):**
   - Add `.claude/` to `.distignore` (AI instruction directories not permitted in production)
   - Add `CODE_REVIEW.md` to `.distignore` (unexpected markdown files)
   - Consider adding `// phpcs:ignore` comments to `uninstall.php` direct DB queries (acceptable for transient cleanup)

   **Files to exclude from distribution (.distignore):**
   ```
   .wp-env.json
   .claude/
   CODE_REVIEW.md
   tests/
   phpunit.xml
   composer.json
   composer.lock
   vendor/
   dist/
   *.zip
   ```

4. **Phase 3 - Medium Priority**
   - Add explicit autoload parameters to options
   - Add filters for extensibility (boost delay, post types, capability)
   - Add WP_DEBUG checks to logging

5. **Phase 4 - Low Priority**
   - Accessibility improvements
   - Admin UI enhancements

---

## Phase 2.5 Details - Plugin Check Results

### 21. HIGH: Missing readme.txt
**Issue:** WordPress.org requires a `readme.txt` file in standard format.
**File:** New file needed: `readme.txt`

**Required sections:**
- Plugin name and header
- Description (short and long)
- Installation instructions
- FAQ
- Changelog
- Screenshots (optional)

---

### 22. HIGH: Domain Path Header Points to Deleted Directory
**File:** `fediboost.php:13`
**Issue:** `Domain Path: /languages` header still exists but `/languages/` directory was deleted.

**Fix:** Remove the `Domain Path` line from the plugin header entirely.

---

### 23. HIGH: Stale Build Artifacts in /dist/
**File:** `/dist/` directory
**Issue:** Contains old `auto-tooter` build with wrong text domain ('auto-tooter' instead of 'fediboost'). Plugin Check found 44+ text domain mismatch errors in these files.

**Fix:** Delete entire `/dist/` directory. It will be regenerated by build scripts.

---

### 24. HIGH: Compressed File in Repository
**File:** `fediboost.zip`
**Issue:** Compressed files are not permitted in WordPress.org plugin submissions.

**Fix:** Delete `fediboost.zip` and add `*.zip` to `.gitignore`.

---

### 25. HIGH: Missing .distignore File
**Issue:** No `.distignore` file exists to exclude development files from distribution.

**Fix:** Create `.distignore` with:
```
.wp-env.json
.claude/
.git/
.gitignore
CODE_REVIEW.md
tests/
phpunit.xml
composer.json
composer.lock
vendor/
dist/
*.zip
node_modules/
```

---

### 26. MEDIUM: tests/bootstrap.php Missing Access Protection
**File:** `tests/bootstrap.php`
**Issue:** PHP file should prevent direct access.

**Fix:** Add at top of file:
```php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
```

**Note:** This file is for testing only and should be in `.distignore` anyway.

---

### 27. LOW: Direct Database Queries in uninstall.php
**File:** `uninstall.php:25, 34`
**Issue:** Plugin Check warns about direct database queries without caching.

**Note:** This is acceptable and expected for uninstall cleanup. The queries are necessary to delete transients by prefix. Add phpcs:ignore comments to suppress warnings:
```php
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
```

---

## Conclusion

FediBoost is a solid plugin with good architecture and security practices. The main issues are naming inconsistencies from a rename (auto-tooter to fediboost), outdated version requirements, missing metadata, and unnecessary legacy translation handling.

For WordPress.org submission, focus on Phase 1 and Phase 2 fixes. The plugin should pass review after these changes are made.
