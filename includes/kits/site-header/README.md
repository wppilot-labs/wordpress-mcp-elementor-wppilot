# site-header kit

Builds the site-wide header from a layout decided by the site's own menu, then checks the header
the site actually serves.

| Ability | Kind | What it does |
|---|---|---|
| `wppilot/build-site-header` | write, destructive (confirm) | Elementor Pro Theme Builder header, one per language under Polylang, replacing the headers that had display conditions; or, on a block theme, the `header` template part (one translated part per language). `dry_run` returns the plan. |

## Layout

One boxed flex row, no wrapping: brand (logo + title) | menu (grows) | language switcher, call to
action, cart. `plan()` estimates widths from the menu labels: a menu that does not fit one row at
1200px becomes a menu button at every width, and on a 375px phone the title gives way to the logo
when both do not fit. The menu button is ordered last on tablets and phones.

The language switcher is Polylang's own menu item (`#pll_switcher`, dropdown) in a small menu,
rendered by a second nav-menu widget, so it is styled like the menu instead of a bare list. On a
translated page the default-language header is swapped for its translation through Elementor
Pro's `elementor/theme/get_location_templates/template_id` filter, from a map kept on the
default header.

## Check

After saving, each language's home page is fetched as a visitor and `check_served_header()`
confirms: the new template is the one served, menu items and a menu button are rendered, the
switcher items sit inside a styled menu (no bare `li.lang-item` list), the cart button has its
icon, and the phone-row estimate fits. Any failure undoes the build and returns the checks. The
check reads HTML only; it does not measure pixels.

## Undo

`site-header/replace`: deletes the templates (and the switcher menu) the call created and gives
the replaced headers their display conditions back; for a block theme, deletes the navigation
posts and translated template parts it made and puts the header part back as it was (or back to
the theme file).

<!-- kit-export:omit -->
## Tests

`tests/Unit/Kits/SiteHeader` drives `plan()`, `elementor_tree()` and `check_served_header()`.
<!-- /kit-export:omit -->
