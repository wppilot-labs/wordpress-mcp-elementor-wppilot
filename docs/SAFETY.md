# Safety profiles

## Production Safe

This is the installation default. It permits ordinary content, design, SEO, form, and commerce operations while blocking critical primitives such as raw PHP, code-snippet and privileged dynamic-shortcode engines, raw database access, WP-CLI, filesystem access, plugin and theme installation and deletion, and temporary administrator access.

Activating, deactivating, and updating already-installed plugins and themes is permitted here, with explicit confirmation — see [Plugin and theme lifecycle](#plugin-and-theme-lifecycle).

Destructive operations that are otherwise allowed require explicit confirmation. Examples include permanent deletion and refunds.

## Read Only

Only abilities marked readonly, plus MCP resources and prompts, can execute. Use this profile for audits, discovery, reporting, and support sessions that must not mutate the site.

## Developer Full Access

All manually enabled abilities can execute, including critical developer primitives. Critical and destructive calls still require explicit confirmation. Use this profile only for deliberate development or recovery work with current backups and appropriate site access controls.

## Confirmation contract

For an MCP adapter call, confirmation belongs inside the target parameters:

```json
{
  "ability_name": "wppilot/woocommerce-create-refund",
  "parameters": {
    "order_id": 123,
    "amount": "10.00",
    "refund_payment": false,
    "confirm": true
  }
}
```

WPPilot removes the control-only `confirm` field before target schema validation unless the target ability explicitly declares its own field with that name.

## WordPress core surface

The typed WordPress abilities in `includes/abilities/wordpress/` are subject to every rule above, and add their own:

- **Draft-first.** Content creation defaults to `draft`. An absent, blank, mistyped, or unrecognised status resolves to `draft` before any capability is evaluated, so content is never published by omission or by a malformed value. Publication requires `status: "publish"` explicitly.
- **The post type's own capability object.** `edit_posts` is never assumed. `create_posts`, `publish_posts`, `edit_others_posts`, `edit_post`, and `delete_post` are read from the registered post type, so a custom type declaring a separate capability set is enforced on its own terms. Publishing is a distinct grant from editing: moving a draft to `publish`, `future`, or `private` is checked separately.
- **Taxonomy capabilities** come from the taxonomy — `manage_terms`, `edit_terms`, `delete_terms`, `assign_terms` — never from `manage_categories`.
- **Closed surfaces.** Internal and plugin-private post types and taxonomies are refused: `attachment`, `revision`, `nav_menu_item`, `wp_block`, `wp_template*`, `wp_navigation`, `wp_global_styles`, changesets, `nav_menu`, and anything registered neither `public` nor `show_in_rest`.
- **Commenter privacy.** Email and IP are withheld unless the connected account holds `moderate_comments`, which is also required to list comments that are not approved.
- **URL schemes.** Menu item URLs are validated against `wp_allowed_protocols()`; `javascript:`, `data:`, and `vbscript:` are refused before storage.

## Plugin and theme lifecycle

The split across profiles follows what an operation actually does to the server, not what it is called:

| Ability | Class | Production Safe | Read Only |
| --- | --- | --- | --- |
| `search-extensions`, `get-extension` | read | yes | yes |
| `activate-plugin`, `deactivate-plugin` | destructive | yes, with `confirm` | no |
| `update-plugin`, `update-theme`, `switch-theme` | destructive | yes, with `confirm` | no |
| `install-plugin`, `install-theme` | critical | no | no |
| `delete-plugin`, `delete-theme` | critical | no | no |

Installing and deleting fetch and write executable code, which is the same class of operation as `execute-php`, so they are Developer Full Access only. Activating an already-installed plugin fetches nothing and writes no files — but it runs that plugin's activation hooks and can fatal the site, so it stays confirmation-gated on every profile that allows it.

Additional rules specific to these abilities:

- **`DISALLOW_FILE_MODS` is honoured** through `wp_is_file_mod_allowed()` before any file operation begins, so a site that has opted out of code changes gets a named refusal rather than a partial install.
- **Direct filesystem access is required.** WPPilot runs headless, and `request_filesystem_credentials()` has no screen to render an FTP form on. Anything other than the `direct` method is refused with the method WordPress selected, instead of stalling or half-writing.
- **WPPilot cannot target itself.** `deactivate-plugin` and `delete-plugin` refuse WPPilot and WPPilot Pro: severing the connection mid-call would leave the agent unable to undo what it just did.
- **Deletion requires prior deactivation.** WordPress's own `delete_plugins()` does not check, so the refusal lives in the ability. The active theme, and the parent of an active child theme, are refused for the same reason.
- **Package URLs must be HTTPS.** An install from an explicit ZIP validates the scheme before download; plain HTTP would let anything on the path substitute its own code.
- **Slugs and plugin files are path-validated.** Both go through `validate_file()` and a plain-directory-name pattern, because WordPress concatenates a stylesheet directly into a filesystem path.
- **Ledger coverage.** Activation, deactivation, and theme switches record a before-image and roll back with verification. Installs, updates, and deletions are recorded as non-reversible, each stating the actual reason and the manual path back.

Terms, menus, and comments have no WordPress trash. Deleting any of them is permanent, requires explicit confirmation, and is recorded as non-reversible.

## Protocol parity

Safety is enforced identically under both MCP revisions. The modern dispatcher runs the same guards in the same order as the legacy path — safety profile, then rate limit, then the ability's permission callback, then execution — so a client on `2026-07-28` cannot reach a weaker code path than one on `2025-11-25`. Read Only blocks every mutation in both eras, including rollback.

## Change ledger

The ledger retains at most 500 records and is also capped by total serialized size. Secrets and sensitive metadata are redacted or excluded. Before images are bounded. Rollback is offered only for supported operations and succeeds only when the observed result matches the expected fingerprint.

Permanent deletion, payment refunds, and other irreversible external side effects are recorded as non-reversible.

Each record names the agent behind the write as well as the WordPress user. The user is not an agent identity — several AI clients usually connect as the same administrator — so the credential is what distinguishes them: an OAuth client id, stored hashed, or an application-password UUID. The client name and version the agent introduced itself with are recorded alongside it. Writes that arrive outside an authenticated MCP request, from wp-admin, WP-CLI or another plugin, are recorded as `direct` rather than attributed to the last agent seen.
