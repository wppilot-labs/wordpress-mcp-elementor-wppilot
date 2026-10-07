---
name: block-theme
description: Change a block theme's look and structure the way the site editor does - Global Styles (colours, fonts, spacing), templates and template parts (header, footer, single, page), patterns and navigation menus - with undo. Activate when the user asks to change site-wide colours or fonts, edit the header, footer or a page template, create or change a reusable pattern, or edit the main menu on a block theme.
---

# Working with a block theme

Check first: `wppilot/get-global-styles` reports `block_theme`. Templates and navigation menus
exist only on block themes; Global Styles and patterns also work with classic themes that use
theme.json.

## Global Styles

1. Read with `wppilot/get-global-styles`: `user` is what the site editor saved, `theme_palette`
   and `theme_font_families` are the theme's own presets.
2. Change with `wppilot/update-global-styles`. Send only what changes; it merges by default.
   Refer to presets as `var(--wp--preset--color--<slug>)` instead of hard-coding hex values, so
   the whole site follows the palette.
3. Look at `not_saved` in the answer: keys WordPress dropped were not valid theme.json.

## Templates and template parts

1. `wppilot/list-templates` (type `wp_template_part` for header/footer).
2. `wppilot/get-template` to read the current markup.
3. `wppilot/update-template` with the **complete** new markup. Keep block comments intact; a
   header usually wraps `site-logo`, `site-title` and `navigation` blocks in a `group`.
4. To undo everything done to a template, `wppilot/revert-template` (needs confirm) goes back to
   the theme file. Single changes can also be undone from the change log.

## Patterns

- `wppilot/create-pattern` with `synced: true` for anything that must stay identical everywhere
  (a call-to-action, a footer notice); insert it with the returned `insert_markup`.
- `wppilot/update-pattern` changes a synced pattern everywhere it is used - say so before doing it.

## Navigation menus

`wppilot/list-navigation-menus`, then `wppilot/update-navigation-menu` with the complete markup.
Links are `<!-- wp:navigation-link {"label":"About","url":"/about/","kind":"custom"} /-->`;
submenus are `navigation-submenu` blocks holding links.
