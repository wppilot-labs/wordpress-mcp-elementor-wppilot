# site-header kit

Saves the site-wide header the caller designs, does the plumbing around it, and checks the
result. There is no built-in layout: the caller (an AI client, usually) designs the header for
the brand, as it designs the rest of the site.

| Ability | Kind | What it does |
|---|---|---|
| `wppilot/build-site-header` | write, destructive (confirm) | Elementor Pro: the caller's Elementor tree as Theme Builder headers, one per language under Polylang, on every page, replacing the headers that had display conditions. Block themes: the caller's block markup as the `header` template part, one translated part per language. `dry_run` returns the site facts or checks a design without saving; `check_only` checks the served header. |

## Plumbing

- **Facts** (`dry_run` without a design): title, tagline, logo (or likely logos), colours and
  fonts from the Elementor kit / theme.json / the logo, the colours and fonts the home page is
  painted with, whether it opens with a hero, each language's menu, whether there is a shop,
  and `building_blocks` (menu, language switcher, cart, logo, row settings that render well).
- **Tokens** fill one design in per language: `{{menu}}`, `{{language_switcher}}` (a menu made
  once holding Polylang's switcher item as a styled dropdown), `{{navigation_ref}}` (block
  themes: a navigation post per language with the switcher last), `{{home_url}}`,
  `{{site_title}}`, `{{label:key}}`. `elements_by_language` / `block_markup_by_language` give a
  language its own design.
- **Saving** goes through the host's `elementor-content-writer` extension when it has one (its
  normalisation and validation), else Elementor's document save. On a translated page the
  default-language header is swapped for its translation through Elementor Pro's
  `elementor/theme/get_location_templates/template_id` filter, from a map on the default header.

## Checks

Reported as findings (`severity`, `check`, `element_id` or the element's path, `detail`, `fix`);
the design is never rewritten.

- Before saving (`precheck_elementor`, `precheck_blocks`): no menu button, a raw Polylang list,
  a cart without WooCommerce, a picture-less image, links to nowhere, WCAG AA contrast of the
  colours the design sets (kit globals resolved, measured on the container behind), unnamed
  menus, rows that wrap on phones, zero-width widgets in a row, unknown tokens. Errors block the
  save.
- On the served page of every language (`check_served`): the right header, raw language list,
  switcher present, a menu button for every row menu, the cart icon, accessible names, broken
  images and same-site links. An error restores the previous header unless `keep_on_fail`.
- In a browser (`probe.php`): a page opened with a probe URL (`?wppilot-kit-header-probe=<token>`,
  signed for the user who ran the ability, valid 15 minutes; a signed-in user who can
  edit_theme_options may use `=1`) measures its own
  header at the width it is opened at - menu rows, labels that wrap, text spilling out of
  squeezed elements, overlaps, sideways scroll, header height, a menu that cannot be reached or
  does not open, computed contrast (gradients by each stop), broken images - and publishes
  `window.siteHeaderProbe`. The server has no layout engine, so these are the caller's to
  run (1440, 768, 390). Anyone else gets the page exactly as every visitor does; a page that
  prints the probe is sent with no-cache headers and DONOTCACHEPAGE.

## Undo

`site-header/replace`: deletes the templates (and the switcher menu) the call created and gives
the replaced headers their display conditions back; for a block theme, deletes the navigation
posts and translated template parts it made and puts the header part back as it was (or back to
the theme file).

<!-- kit-export:omit -->
## Tests

`tests/Unit/Kits/SiteHeader` drives the tokens, `precheck_elementor()`, `precheck_blocks()`,
`check_served()` and the colour arithmetic.
<!-- /kit-export:omit -->
