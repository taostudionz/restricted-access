# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A single-file WordPress plugin ("Restricted Access") that forces visitors to log in before viewing the site. 

There is no build system, test suite, linter, or dependency manager. The only useful check is a PHP syntax lint: `php -l restricted-access.php`.

## Architecture

All logic lives in `restricted-access.php`:

- `restricted_access()` on `template_redirect` — the core gate. Redirects anonymous visitors (302, with nocache headers) to the login URL resolved by `restricted_access_get_login_url()`: the page selected under Settings → Restricted Access (option `restricted_access_login_page_id`), falling back to `wp_login_url()` when no page is selected or the page is deleted/unpublished — or is the Posts Page (`page_for_posts`), which `is_page()` never matches and therefore can't be loop-protected. The `restricted_access_login_url` filter has the final say (cross-host URLs additionally require whitelisting via `allowed_redirect_hosts`, or `wp_safe_redirect()` falls back to `admin_url()`). The visited URL is passed as a `redirect_to` query arg. Bails early for AJAX/Cron/WP-CLI requests, logged-in users, the login page itself (loop prevention: `is_page( $login_page_id )`, plus a scheme-insensitive query-less URL comparison for filter-overridden URLs — query-string login URLs must self-exempt via `restricted_access_bypass`), and anything allowed via the `restricted_access_bypass` filter. On Multisite it additionally blocks logged-in users who aren't members of the current blog.
- `restricted_access_rest_api()` on `rest_authentication_errors` (priority 99) — restricts the REST API to authenticated users.
- Admin: a Settings API page (`restricted_access_admin_menu()` / `restricted_access_register_settings()`, option group `restricted_access`) with a `wp_dropdown_pages` selector for the login page, plus a Settings action link on the Plugins screen. `uninstall.php` deletes the option (per-site on Multisite).
- `restricted_access_load_textdomain()` on `plugins_loaded` — localization (text domain: `restricted-access`).

The public developer API is the `restricted_access_bypass`, `restricted_access_redirect`, `restricted_access_login_url`, and `restricted_access_multisite_message` filters — site owners hook these in their themes, so renaming them is a breaking change.

Notes on the custom login page:

- The plugin does not create the login page; the selected page must contain a login form that honors the `redirect_to` query arg for post-login redirect to work.
- `wp-login.php` is not gated by this plugin — `template_redirect` never fires there — so the core login screen stays directly reachable (it is also the fallback redirect target).

## Constraints
- `readme.txt` follows the WordPress.org plugin directory format (`Stable tag`, `Tested up to`, etc.); most historical commits are bumps to those fields. Keep that format when rewriting it.
