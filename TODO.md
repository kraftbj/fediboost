# FediBoost TODO

Remaining items from audits (January 2026). All are low severity and not required before initial release.

## Code

### Open redirect via OAuth flow
**File:** `admin/class-admin.php`
The connect handler uses `wp_redirect()` (not `wp_safe_redirect()`) to redirect to the Mastodon authorization URL. This is standard OAuth behavior — the URL is validated as HTTPS and the path is constrained to `/oauth/authorize`. Only admins can trigger it. Optionally add a confirmation interstitial before redirecting.

### Debug logging may expose sensitive data
**Files:** `includes/class-oauth.php`, `includes/class-boost.php`
When `WP_DEBUG` is enabled, `error_log()` calls may include response bodies from Mastodon API calls, instance URLs, usernames, and error details. Audit all log statements to ensure no access tokens or client secrets are ever logged. Consider a dedicated logging mechanism with configurable verbosity.

### Encryption key tied to WordPress auth salts
**File:** `includes/class-encryption.php`
If WordPress auth salts are rotated, all encrypted tokens become unrecoverable. This is documented in the readme FAQ. Optionally detect salt changes by storing a hash of the current salt and proactively warn the admin that accounts need reconnection.

### Race condition in account data operations
**Files:** `includes/class-accounts.php`, `includes/class-oauth.php`
Account operations use a read-modify-write pattern without locking. Concurrent cron jobs could theoretically overwrite each other's changes. The window is very small and account modifications are infrequent (connect/disconnect only). Consider transient-based locks if this becomes an issue.

### Capability filter floor
**File:** `includes/class-security.php`
The `fediboost_manage_capability` filter enforces a floor of `edit_others_posts` (Editor level). Consider using `manage_options` as the hard-coded floor instead, or document that lowering below `manage_options` grants Editors access to OAuth tokens.

## Testing

### Test coverage gaps
Suggested additions:
- Encryption/decryption round-trip with edge cases
- `is_external_url()` with IPv6 addresses
- Cron retry/failure scenarios

## Performance

### No cron retry mechanism
If a scheduled boost event fails (e.g., the cron runner dies), there is no retry. WP-Cron doesn't provide built-in retry logic. Consider logging boost outcomes to post meta so admins can see which posts were boosted and which failed.
