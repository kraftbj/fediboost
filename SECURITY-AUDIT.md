# FediBoost Security Audit

**Date:** January 25, 2026
**Plugin Version:** 1.0.0
**Scope:** Full source code review of all PHP files in the plugin

---

## Executive Summary

FediBoost is a well-structured WordPress plugin with a security-conscious design. It implements nonce verification, capability checks, SSRF protection, token encryption, and output escaping. However, several issues were identified ranging from a cryptographic weakness in token storage to SSRF bypass vectors. Most issues require either database-level access or admin credentials to exploit, which limits practical impact but should still be addressed before public release.

**Overall assessment:** The plugin is above average in security posture for a WordPress plugin. The issues below should be reviewed and the High/Medium items resolved before publishing.

---

## Findings

### ISSUE 1: Encryption Lacks Authentication (No HMAC) — RESOLVED

**Severity:** High
**Effort to fix:** Low (1-2 hours)
**Must fix before publish:** Yes
**Status:** Resolved — HMAC-SHA256 appended to ciphertext using a separate key derived from `secure_auth` salt. Legacy tokens without HMAC are accepted on decrypt with a logged notice.
**File:** `includes/class-fediboost-encryption.php`

The `encrypt()` method uses AES-256-CBC but does not include an HMAC (Hash-based Message Authentication Code) to verify ciphertext integrity. The stored format is `base64(IV + ciphertext)` with no authentication tag.

**Risk:** This makes the encryption vulnerable to padding oracle attacks. An attacker with database write access (e.g., via SQL injection in another plugin) could iteratively modify the ciphertext and observe decryption behavior to recover plaintext OAuth tokens. AES-CBC without HMAC is a well-known anti-pattern.

**Recommendation:** Append an HMAC-SHA256 of the IV+ciphertext to the stored value using a separate key derived from a different salt. Verify the HMAC before attempting decryption. Alternatively, switch to AES-256-GCM which provides authenticated encryption natively. On decrypt, reject any data that fails the HMAC check.

---

### ISSUE 2: SSRF Protection Does Not Cover IPv6 — RESOLVED

**Severity:** High
**Effort to fix:** Low (1-2 hours)
**Must fix before publish:** Yes
**Status:** Resolved — Replaced manual IPv4 range checks with `filter_var()` using `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`. Now checks both A and AAAA DNS records via `dns_get_record()`.
**File:** `includes/class-fediboost-security.php`

The `is_external_url()` method uses `gethostbyname()` which only resolves to IPv4 addresses, and the private range checks only cover IPv4 ranges. This leaves several gaps:

- **IPv6 private/reserved addresses** are not checked: `::1` (loopback), `fe80::/10` (link-local), `fc00::/7` (unique local), `::ffff:127.0.0.1` (IPv4-mapped IPv6).
- If the host has only an AAAA (IPv6) record, `gethostbyname()` returns the hostname unchanged, which is caught by the `$ip === $host` check — but this means **all IPv6-only hosts are rejected**, which is a functional bug, not just a security one.
- Hosts with dual-stack (A + AAAA records) pass the IPv4 check, but `wp_remote_post()`/`wp_remote_get()` may connect via IPv6 to a private address.

**Recommendation:** Use `filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)` which handles both IPv4 and IPv6 private/reserved ranges. Also consider using `dns_get_record()` to check both A and AAAA records, or configure `wp_remote_*` to use the resolved IP directly.

---

### ISSUE 3: DNS Rebinding / TOCTOU in SSRF Check — RESOLVED

**Severity:** Medium
**Effort to fix:** Medium (2-4 hours)
**Must fix before publish:** Recommended
**Status:** Resolved — `is_external_url()` now stores the validated IP in `$pinned_ip`. All HTTP requests use `make_pinned_request()` which replaces the hostname with the pinned IP and sets the `Host` header, closing the TOCTOU window.
**File:** `includes/class-security.php`, `includes/class-oauth.php`

The `is_external_url()` resolves the hostname to validate it, but the subsequent `wp_remote_post()`/`wp_remote_get()` calls resolve the hostname independently. This creates a Time-of-Check Time-of-Use (TOCTOU) gap. An attacker controlling a DNS server could:

1. Configure a domain to resolve to a public IP (passes `is_external_url()` check).
2. Immediately switch the DNS record to `127.0.0.1` or a private IP.
3. The HTTP request connects to the internal host.

This is a DNS rebinding attack that can bypass SSRF protections. Mastodon instance URLs are admin-supplied, which limits the attack surface to compromised admin accounts or social engineering.

**Recommendation:** Resolve the hostname once and pass the resolved IP to the HTTP request via the `curl_before_send` or equivalent WordPress HTTP API hook. Alternatively, set a very short DNS TTL for the validation resolve and pin the IP for the actual request.

---

### ISSUE 4: Client Secrets Stored Unencrypted — RESOLVED

**Severity:** Medium
**Effort to fix:** Low (1-2 hours)
**Must fix before publish:** Recommended
**Status:** Resolved — `cache_app_credentials()` encrypts `client_secret` before storage; `get_cached_app_credentials()` decrypts on read. Decryption failure treated as cache miss.
**File:** `includes/class-oauth.php`

OAuth `client_id` and `client_secret` values are stored in the `fediboost_instance_apps` option as plaintext. While access tokens are encrypted, client secrets are sensitive credentials. An attacker with database read access (e.g., via SQL injection in another plugin, or a database backup leak) could obtain these credentials.

With client credentials, an attacker could impersonate the FediBoost application to the Mastodon instance, potentially intercepting authorization codes if combined with a redirect URI manipulation.

**Recommendation:** Encrypt client secrets using the same encryption class used for access tokens.

---

### ISSUE 5: Unsanitized `status_id` in URL Path Construction — RESOLVED

**Severity:** Medium
**Effort to fix:** Low (30 minutes)
**Must fix before publish:** Yes
**Status:** Resolved — `status_id` from search API response is validated against `/^[a-zA-Z0-9]+$/` before use in URL path construction.
**File:** `includes/class-fediboost-boost.php`

The `build_reblog_request()` method concatenates `$status_id` directly into the URL path:

```php
'url' => $instance_url . '/api/v1/statuses/' . $status_id . '/reblog',
```

The `$status_id` originates from the JSON response of the Mastodon search API (`$data['statuses'][0]['id']` at line 326). A malicious or compromised Mastodon instance could return a crafted status ID containing path traversal characters (e.g., `../../admin/destroy`) to redirect the POST request to an unintended endpoint on the same instance.

Similarly, the search request URL at `build_search_request()` uses `http_build_query()` which properly encodes parameters, so that path is safe.

**Recommendation:** Validate that `$status_id` matches the expected format (numeric string or alphanumeric ID) before using it. A simple regex check like `/^[a-zA-Z0-9]+$/` would suffice.

---

### ISSUE 6: No OAuth Token Revocation on Disconnect — RESOLVED

**Severity:** Medium-Low
**Effort to fix:** Low (1-2 hours)
**Must fix before publish:** Recommended
**Status:** Resolved — `handle_disconnect_action()` now calls `revoke_account_token()` before removing the account. Revocation is best-effort; failures are logged but do not block the disconnect.
**File:** `admin/class-admin.php`

When an account is disconnected, the plugin removes the account data from the database but does not revoke the OAuth token on the Mastodon instance (via `POST /oauth/revoke`). The token remains valid on the remote instance until it expires or is manually revoked by the user.

**Risk:** If the encrypted token was compromised before disconnection (e.g., via a database breach), it could still be used to perform actions on the Mastodon account. Proper cleanup should always revoke remote credentials.

**Recommendation:** Before removing the account, decrypt the token and send a revocation request to `{instance_url}/oauth/revoke` with the `client_id`, `client_secret`, and `token`. Handle failures gracefully (still remove the local account even if revocation fails).

---

### ISSUE 7: `sanitize_accounts` Callback Is a Passthrough — RESOLVED

**Severity:** Medium-Low
**Effort to fix:** Low (1 hour)
**Must fix before publish:** Recommended
**Status:** Resolved — `sanitize_accounts()` now validates each account entry against the schema (required keys, types, status enum). Non-conforming entries are dropped. Valid entries are sanitized with `esc_url_raw()` and `sanitize_text_field()`.
**File:** `admin/class-admin.php`

The Settings API `sanitize_callback` for `fediboost_accounts` only checks if the value is an array:

```php
public function sanitize_accounts( $accounts ) {
    if ( ! is_array( $accounts ) ) {
        return array();
    }
    return $accounts;
}
```

It does not validate the structure of each account entry (required keys, value types, URL format, status enum). While the Settings API requires `manage_options` capability, a malformed or tampered save could inject unexpected data structures that cause errors or unexpected behavior in other parts of the plugin.

**Recommendation:** Validate each account entry against the schema defined in `FediBoost_Accounts::get_schema()`. Strip any unexpected keys and validate types.

---

### ISSUE 8: Open Redirect via OAuth Flow (Admin-Only)

**Severity:** Low
**Effort to fix:** Low (30 minutes)
**Must fix before publish:** No (acceptable for OAuth)
**File:** `admin/class-fediboost-admin.php:303-305`

The connect handler uses `wp_redirect()` (not `wp_safe_redirect()`) to redirect to the Mastodon authorization URL. While the destination is constructed from the sanitized instance URL (validated as HTTPS), it allows redirecting an admin user to any HTTPS domain.

This is standard OAuth flow behavior and the code includes a phpcs ignore comment acknowledging this. The risk is limited because:
1. Only admins can trigger it.
2. The URL path is constrained to `/oauth/authorize?...`.
3. The instance URL has been validated as a proper HTTPS URL.

**Recommendation:** No immediate action required. Optionally, add a confirmation interstitial ("You are being redirected to {instance_url}...") or validate the instance URL resolves and returns a valid Mastodon API response before redirecting.

---

### ISSUE 9: Debug Logging May Expose Sensitive Data

**Severity:** Low
**Effort to fix:** Low (1 hour)
**Must fix before publish:** No (only affects debug mode)
**Files:** `includes/class-fediboost-oauth.php:557-567`, `includes/class-fediboost-boost.php:436-464`, multiple others

When `WP_DEBUG` is enabled, the plugin logs various operational data via `error_log()`. Some log entries include:

- Full response bodies from Mastodon API calls (OAuth class, lines 168-169) which could contain token fragments or user data.
- Instance URLs, usernames, and error details.
- Status IDs and post IDs.

In production with `WP_DEBUG` disabled, none of this is logged. However, development and staging environments often have debug mode enabled and log files accessible.

**Recommendation:** Audit all log statements to ensure no access tokens, client secrets, or other credentials are ever logged. Redact sensitive fields. Consider a dedicated logging mechanism with configurable verbosity levels.

---

### ISSUE 10: Encryption Key Tied to WordPress Auth Salts

**Severity:** Low
**Effort to fix:** Medium (design decision)
**Must fix before publish:** No (document the behavior)
**File:** `includes/class-fediboost-encryption.php:73-79`

The encryption key is derived from `wp_salt('auth')` via SHA-256. If WordPress auth salts are rotated (e.g., via a security plugin, manual `wp-config.php` edit, or migration), all stored encrypted tokens become permanently unrecoverable. Accounts would silently fail and eventually be marked as disconnected when the decrypted token is invalid.

This is a common pattern in WordPress plugins and not inherently wrong, but the failure mode is silent and confusing to users.

**Recommendation:** Document this behavior in the readme/FAQ. Optionally, detect salt changes (by storing a hash of the current salt) and proactively warn the admin that accounts need reconnection.

---

### ISSUE 11: No Limit on Connected Accounts — RESOLVED

**Severity:** Low
**Effort to fix:** Low (30 minutes)
**Must fix before publish:** No
**Status:** Resolved — `add_account()` enforces a maximum of 10 accounts (filterable via `fediboost_max_accounts`). Returns `WP_Error` when limit is reached.
**File:** `includes/class-accounts.php`

There is no maximum limit on the number of Mastodon accounts that can be connected. Each connected account adds processing overhead on every post publish (search + reblog API calls per account). A compromised admin or misconfigured system could connect many accounts, causing performance degradation or rate limiting from Mastodon instances.

**Recommendation:** Add a configurable maximum account limit (e.g., 10) with a filter hook for customization.

---

### ISSUE 12: Race Condition in Account Data Operations

**Severity:** Low
**Effort to fix:** Medium (2-3 hours)
**Must fix before publish:** No
**Files:** `includes/class-fediboost-accounts.php`, `includes/class-fediboost-oauth.php`

Account operations use a read-modify-write pattern (`get_option()` -> modify array -> `update_option()`) without any locking mechanism. If two processes (e.g., concurrent cron jobs) modify account data simultaneously, one write could overwrite the other's changes.

In practice, the window for this race is very small and account modifications are infrequent (only during connect/disconnect/auth-failure). The risk is primarily data consistency, not security.

**Recommendation:** Accept the risk for v1.0 but consider using WordPress transient locks or `wp_cache` locks if concurrent modifications become an issue.

---

### ISSUE 13: `fediboost_manage_capability` Filter Could Weaken Access Control

**Severity:** Low
**Effort to fix:** N/A (design review)
**Must fix before publish:** No
**File:** `includes/class-fediboost-security.php:73-80`

The `fediboost_manage_capability` filter allows other code to change the required capability. The secondary check for `edit_others_posts` provides a floor, but this is Editor-level access. If a filter sets the capability to something like `edit_posts`, the `edit_others_posts` floor still applies, but Editors (who have `edit_others_posts`) could then manage OAuth tokens and connected accounts.

The default `manage_options` restricts to Administrators, which is correct.

**Recommendation:** Document that this filter should not be used to lower the capability below `manage_options` without understanding the implications. Consider using `manage_options` as the hard-coded floor instead of `edit_others_posts`.

---

### ISSUE 14: Uninstall Does Not Revoke Remote Tokens — RESOLVED

**Severity:** Low
**Effort to fix:** Medium (2 hours)
**Must fix before publish:** No (nice-to-have)
**Status:** Resolved — `uninstall.php` now loads the autoloader, iterates all accounts, decrypts tokens, and attempts best-effort revocation via `revoke_token()` before deleting options.
**File:** `uninstall.php`

The uninstall routine deletes all local data (options, transients) but does not revoke OAuth tokens on connected Mastodon instances. After uninstalling the plugin, the registered OAuth app and issued tokens remain valid on each Mastodon instance.

**Recommendation:** Before deleting account data, iterate through accounts, decrypt tokens, and attempt revocation. Fall through gracefully if revocation fails (network errors, instance offline, etc.).

---

## Summary Table

| # | Issue | Severity | Fix Effort | Must Fix? |
|---|-------|----------|------------|-----------|
| 1 | ~~Encryption lacks HMAC authentication~~ | High | Low | Resolved |
| 2 | ~~SSRF protection does not cover IPv6~~ | High | Low | Resolved |
| 3 | ~~DNS rebinding / TOCTOU in SSRF check~~ | Medium | Medium | Resolved |
| 4 | ~~Client secrets stored unencrypted~~ | Medium | Low | Resolved |
| 5 | ~~Unsanitized status_id in URL path~~ | Medium | Low | Resolved |
| 6 | ~~No token revocation on disconnect~~ | Medium-Low | Low | Resolved |
| 7 | ~~sanitize_accounts is a passthrough~~ | Medium-Low | Low | Resolved |
| 8 | Open redirect via OAuth flow | Low | Low | No |
| 9 | Debug logging may expose sensitive data | Low | Low | No |
| 10 | Encryption key tied to auth salts | Low | Medium | No |
| 11 | ~~No limit on connected accounts~~ | Low | Low | Resolved |
| 12 | Race condition in account operations | Low | Medium | No |
| 13 | Capability filter could weaken access | Low | N/A | No |
| 14 | ~~Uninstall does not revoke remote tokens~~ | Low | Medium | Resolved |

---

## Positive Security Practices Observed

The following security practices are correctly implemented and worth acknowledging:

- **ABSPATH checks** on every PHP file prevent direct access.
- **Nonce verification** on all form submissions and admin actions (connect, disconnect).
- **Capability checks** (`manage_options` + `edit_others_posts`) on all admin operations.
- **Output escaping** consistently uses `esc_html()`, `esc_url()`, `esc_attr()`, `esc_js()`.
- **Input sanitization** uses `sanitize_text_field()`, `wp_unslash()`, `intval()`.
- **CSRF protection** in OAuth flow via state parameter with user binding and single-use enforcement.
- **HTTPS enforcement** on all Mastodon instance URLs.
- **Rate limiting** on OAuth app registration (5 attempts per hour per instance).
- **Prepared SQL statements** using `$wpdb->prepare()` for all direct queries.
- **Token encryption** at rest using AES-256-CBC (though lacking HMAC).
- **Auth failure handling** marks accounts as disconnected on 401/403 responses.
- **Duplicate schedule prevention** prevents redundant cron event registration.
- **Settings API** used for option registration with sanitize callbacks.
- **Post eligibility checks** via ActivityPub integration before boosting.
