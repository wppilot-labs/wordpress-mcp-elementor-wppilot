# block-theme kit

The site editor's own objects - Global Styles, templates and template parts, patterns and
block navigation menus - read and written through WordPress's own REST controllers, run
in-process. Core validates input, checks the current user's capabilities, filters HTML for
users without `unfiltered_html`, sanitises theme.json, and creates a template's database copy
on its first edit, exactly as the site editor does.

| Ability | Kind | What it does |
|---|---|---|
| `wppilot/get-global-styles` | read | The user layer of Global Styles (settings and styles), plus the theme's palette and font families. |
| `wppilot/update-global-styles` | write | Merges theme.json `settings`/`styles` into the user layer (or replaces it with `merge: false`); reports keys WordPress did not keep. |
| `wppilot/list-templates` | read | Templates or template parts of the active block theme, with `customized` and `has_theme_file`. |
| `wppilot/get-template` | read | One template or part with its block markup. |
| `wppilot/update-template` | write | New block markup for a template or part; the theme file is never changed. |
| `wppilot/revert-template` | write, destructive | Drops the customisation and goes back to the theme file (refused when there is no theme file). Needs confirm. |
| `wppilot/list-patterns` | read | The site's own patterns (synced and unsynced) and the names of registered patterns. |
| `wppilot/create-pattern` | write | A new synced or unsynced pattern; returns the markup to insert a synced one. |
| `wppilot/update-pattern` | write | New title or markup for one of the site's patterns. |
| `wppilot/list-navigation-menus` | read | Block navigation menus with their markup. |
| `wppilot/update-navigation-menu` | write | New markup for a navigation menu. |

Every write's block markup must parse into at least one block and stays under 512 KB.

## Undo

| Write | Strategy | What undo does |
|---|---|---|
| Global styles, pattern, navigation menu, an already customised template | `kits/post-partial` | Restores the post's content, title and status, verified by fingerprint. |
| First edit of a theme-file template, a new pattern | `block-theme/delete-created-post` | Deletes exactly the post the write created (refused if that ID now holds another kind of post), which for a template means back to the theme file. |
| Revert to the theme file | `block-theme/recreate-template` | Saves the customised markup and title back through the templates controller. |

Strategies and captures are registered at boot, so a rollback on a later request finds them.

## Host needs

- `Ledger::capture_for()` and `Ledger::register_strategy()`.
- WordPress 6.9+ with the templates, global-styles, blocks and navigation REST controllers.

## Safety

Templates, Global Styles and navigation need `edit_theme_options`; patterns need `edit_posts`.
Only `revert-template` is destructive, and it keeps the customisation in the change log.

<!-- kit-export:omit -->
## Tests

`tests/Unit/Kits/BlockTheme` covers markup validation, the theme.json merge and the undo
strategies. Live behaviour is checked on WordPress over MCP.
<!-- /kit-export:omit -->
