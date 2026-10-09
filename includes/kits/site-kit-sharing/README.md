# site-kit-sharing kit

Switches on Site Kit by Google's read-only Dashboard sharing for the Administrator role.

| Ability | Kind | What it does |
|---|---|---|
| `wppilot/site-kit-enable-sharing` | write, confirm | Adds `administrator` to the shared roles of Search Console, Analytics 4 and PageSpeed Insights (or the `modules` given) through Site Kit's sharing route. Per module: `shared`, `already_shared`, `not_permitted` (with the owner), `not_connected` or `refused_by_site_kit`. |

The undo strategy `kits/site-kit-sharing` restores `googlesitekit_dashboard_sharing` and then
`googlesitekit_pagespeed-insights_settings` exactly as stored (absent rows are deleted again). It
is registered on every request; the ability only while Site Kit is active.

## Vendor data, verified 2026-10-09 on Site Kit by Google 1.189.0

- `Module_Sharing_Settings::OPTION` is `googlesitekit_dashboard_sharing`:
  `{<slug>: {sharedRoles: list<string>, management: "owner"|"all_admins"}}`; the sanitizer keeps
  only roles with `edit_posts`. `Modules` filters the option to add
  `{sharedRoles: [], management: "all_admins"}` for PageSpeed Insights when it is missing, so the
  snapshot reads the stored row directly.
- The Dashboard sharing dialog saves with `POST google-site-kit/v1/core/modules/data/sharing-settings`
  `{data: {...}}` (`REST_Dashboard_Sharing_Controller`), permission `googlesitekit_manage_options`
  (signed in with Google, verified, setup complete). Per module, `sharedRoles` is dropped unless
  the user has `googlesitekit_manage_module_sharing_options` for that slug: the module owner, or
  any signed-in admin when `management` is `all_admins`. `merge()` is one level deep, so the
  request carries the full role list.
- On change, `Modules` writes the current user into `ownerID` of every changed module without a
  service entity (PageSpeed Insights); Search Console and Analytics 4 keep their owner. The
  response's `newOwnerIDs` reports it.
- Network mode is off unless a filter turns it on (`Context::is_network_mode()`), so the options
  are per site.

## Host needs

`Runtime\can_run()` plus `manage_options`, a ledger with `capture_for` and `register_strategy`,
and `Runtime\confirm_guard()`.

## Tests

`tests/Unit/Kits/SiteKitSharing`: the request sent to Site Kit, per-module outcomes, the owner
named on refusal, and the option before/after plus undo.
