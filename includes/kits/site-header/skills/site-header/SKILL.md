---
name: site-header
description: Design and build the site-wide header (logo, menu, language switcher, cart, call to action) yourself, then have the site save it in the right places and check it. Activate whenever the user asks to create, fix, redesign, modernise, tidy or "make professional" a header or top navigation, or complains that the menu wraps, the language switcher is a bare list, the cart is an empty box, or the header overlaps on phones.
---

# Designing the site header

You design the header, as you design the rest of the site. `wppilot/build-site-header` has no
template: it does the plumbing you cannot (Theme Builder templates and display conditions, one
header per language, each language's menu, a styled language switcher, undo) and reports
objective problems with your design. It never changes your design for you.

## 1. Read the brand first

Call `wppilot/build-site-header` with `dry_run: true` (and `confirm: true` - the gate asks even
for a dry run; nothing is written). `site` gives you:

- `title`, `tagline`, the `logo` (or `logo_candidates` when none is set),
- `colors` / `fonts` set on the site (Elementor kit, theme.json, the logo), and
  `home_page_paint`: the colours and fonts the home page is actually painted with, most used
  first. Use these. A header in colours the rest of the site does not use looks bolted on.
- `home_page.has_hero`: whether the home page opens with a full-bleed image,
- `menus`: each language's menu and its top-level labels (count them, measure the longest),
- `shop`: whether WooCommerce is active, `languages`, `current_headers`,
- `building_blocks`: settings for the menu, language switcher, cart, logo and row that are known
  to render well. Start from them and style them.

Look at the home page yourself too, if you have a browser.

## 2. Design for this brand

Decide what this business should feel like (a law firm: calm, established, generous spacing,
a serif title, a quiet underline; a SaaS: crisp, a strong call-to-action button, maybe a sticky
translucent bar; a boutique: editorial, centred logo, letter-spaced caps; a bakery: warm,
rounded, friendly). Tell the person in one line what you chose and why.

Principles that hold for every design:

- **One row at desktop.** The row container is `flex_direction: row` with `flex_wrap`,
  `flex_wrap_tablet` and `flex_wrap_mobile` all `"nowrap"` (Elementor wraps rows on phones by
  default). Every widget in a row has `_element_width: "auto"`; exactly one element grows
  (`_flex_size: "grow"`), usually the menu, so it pushes the rest to the edges.
- **The menu fits.** At about 15-16px, each item needs its label width plus its padding; six
  short items fit beside a logo and two icons in 1200px, nine usually do not. If it will not
  fit, use `layout: "dropdown"` (a menu button at every width) rather than a menu that wraps.
- **A menu button below desktop.** `dropdown: "tablet"`, `toggle: "burger"`, and give the open
  menu a solid background (`background_color_dropdown_item`) and a shadow.
- **The language switcher** is the `{{language_switcher}}` nav-menu from building_blocks: one
  styled item with the other languages under it. Never Polylang's widget (a bare list).
- **The cart** is `woocommerce-menu-cart` with an `icon`, only when `site.shop`.
- **Logo** 36-56px on desktop (`width`), 28-40px on phones (`width_mobile`); hide the site
  title on phones (`hide_mobile`) when logo, title, switcher, cart and button will not fit 375px.
- **Spacing**: 12-24px vertical padding, 16-32px between items; a header taller than about
  120px at desktop (180 with a top bar) pushes the page down.
- **Sticky** (optional): `"sticky": "top"`, `"sticky_on": ["desktop","tablet","mobile"]`,
  `"sticky_effects_offset": 40`; style the scrolled state in the container's `custom_css`
  under `selector.elementor-sticky--effects` (a shadow, less padding).
- **Contrast**: text, hovered and current items, and button labels at least 4.5:1 against what
  is behind them (3:1 for 24px+ text). Accent colours that are light (gold, orange, yellow)
  usually fail as text on white: darken them for text and keep the light one for underlines.
- **Accessibility**: a distinct `menu_name` on every nav-menu ("Main menu", "Language"), alt
  text on the logo, a visible focus style if you restyle links.
- Hover and current state: an accent underline (`pointer: "underline"`), a soft pill
  (`pointer: "background"` with `border_radius_menu_item`) or a colour change.

Tokens put the site's own pieces in: `{{menu}}` (each language's menu), `{{language_switcher}}`,
`{{home_url}}`, `{{site_title}}`, and `{{label:key}}` with `labels: {"key": {"en": "...", "ru": "..."}}`
for text that differs per language (a button label). One design then serves every language;
`elements_by_language` gives a language its own.

Block themes: pass `block_markup` for the header template part instead, with
`<!-- wp:navigation {"ref":"{{navigation_ref}}","overlayMenu":"mobile"} /-->` for the menu (the
language switcher is its last item). Colours, spacing and type go in block attributes; a
`<!-- wp:html --><style>` block works only for people who may post unfiltered HTML.

## 3. Check, build, look, fix

1. Pass your design with `dry_run: true`: `findings` lists problems that need no browser (no
   menu button, a raw language list, contrast of the colours you set, zero-width widgets, rows
   that wrap on phones). Fix every `error`.
2. Build with `confirm: true`. It saves, then reads each language's home page as a visitor and
   checks it. On an error it restores the previous header (unless `keep_on_fail: true`) and
   tells you what to change, by element id.
3. Open each `probe.urls` page at 1440, 768 and 390 px and read `window.siteHeaderProbe`:
   menu rows, wrapping labels, overlaps, sideways scroll, header height, the menu button opening,
   computed contrast. Take screenshots and look at them as a designer would: alignment,
   spacing, nothing clipped. Fix your design and build again until every width passes.
   `check_only: true` re-checks the header the site serves without writing. Probe URLs work
   for 15 minutes; `check_only: true` hands out fresh ones.
4. Undo with `wppilot/rollback-change` on its ledger row: the new headers are deleted and the old
   ones get their conditions back.

A classic theme without Elementor Pro is refused with what the theme supports instead (its
menu location and the Customizer logo).
