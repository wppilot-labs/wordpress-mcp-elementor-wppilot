# site-issues kit

A log of what went wrong on the site. Read-only.

| Ability | Kind | What it does |
|---|---|---|
| `wppilot/site-issues` | read | PHP fatal errors the site's requests hit, paused extensions (recovery mode), updates the safe-updates kit rolled back, failed backups. `days` (30, max 90), `limit` (20). |

## The PHP error log

The kit hooks WordPress's fatal error handler (`wp_php_error_message`, plus a shutdown function
for requests where the handler prints nothing) on every request the kits boot in, and records the
fatal in one non-autoloaded option (`wppilot_kit_site_issues_php_errors`): at most 50 entries,
deduplicated by message, file and line, each with a count and first/last seen. A repeat within 5
seconds is not written again, so a site-wide fatal does not become a write storm; counts are a
lower bound. A request that ran out of memory gets 32 MB more for the write, where the host
allows raising the limit.

What is stored: the PHP error type, the message's first line (300 characters at most) with every
absolute path made relative to the WordPress root and credential-looking `key=value` pairs masked,
the file relative to the root (a path outside it keeps only its file name), the line, the plugin
or theme it belongs to (none when PHP ran out of memory or time: the file is then only where it
stopped), and the request path without its query string. Never stored: query strings, cookies,
headers, bodies, stack traces.

A fatal raised while a plugin that loads before this one is being included ends the request
before the recorder exists, and is not recorded.

## Reading it from other kits

- `wppilot_kit_site_issues_errors` filter: `apply_filters('wppilot_kit_site_issues_errors', [], $since)`
  returns the errors last seen at or after `$since` (unix time), newest first, each with a
  one-line `summary`. Without this kit the filter returns `[]`. The safe-updates kit uses it to
  name the error in its rollback reason.
- `wppilot_kit_site_issues_collect` filter: append `{kind, id, at (unix time), summary, source}`
  items to report them here. The safe-updates kit adds `update_rolled_back` items. Anything
  malformed is dropped.

## Host needs

`Runtime\can_run()` plus `manage_options`, `Runtime\unclaimed()` and `Runtime\run_ability()`
(runtime 1.2).
