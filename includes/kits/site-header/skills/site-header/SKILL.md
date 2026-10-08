---
name: site-header
description: Build or fix the site-wide header (logo, menu, language switcher, cart, call-to-action) with one ability that lays it out from the site's own menu and checks the result on the served page. Activate whenever the user asks to create, fix, redesign, tidy or "make professional" a header or top navigation, or complains that the menu wraps, the language switcher is a bare list, the cart is an empty box, or the header overlaps on phones.
---

# Building the site header

Use `wppilot/build-site-header`. Do not hand-build a header template with element trees: a
nav-menu dropped into a flex row shrinks and wraps, a Polylang widget prints a bare list, and
nothing checks the phone width. This ability decides those from the menu itself.

1. `dry_run: true` first (the ability is gated as destructive, so even a dry run carries
   `confirm: true`; nothing is written). Read `plans` (per language: which menu it found, whether the menu fits
   one row, whether the title stays on phones) and `replaces` (headers that lose their display
   conditions). Tell the user what will change, and pass `menus` if it picked the wrong menu.
2. Run it with `confirm: true`. It saves one header per language when Polylang is active, shows
   it on every page, and checks each language's home page as a visitor gets it.
3. If it returns `kit_site_header_check_failed`, nothing changed: read `data.checks`, fix the
   cause (usually the menu: too many top-level items, or none found) and run it again.
4. On success, look at it: open each language's home page at 1440, 768 and 390 px wide
   in a browser and screenshot it. The ability's check reads HTML, not pixels.
5. Undo with `wppilot/rollback-change` on its ledger row: the new headers are deleted and the old
   ones get their conditions back.

Inputs worth knowing:

- `logo_id` (else the Customizer site logo), `site_title` (empty string for logo only).
- `show_language_switcher` / `show_cart`: `auto` follows Polylang and WooCommerce.
- `cta: {label, url}` adds a button on desktop only.
- `colors: {background, text, accent}` as hex; text and accent otherwise follow the Elementor
  kit's global colours.
- `sticky: true` keeps it at the top while scrolling.

Block themes (`builder: "block-theme"`, or `auto` without Elementor Pro) get the theme's
header template part rebuilt the same way, with one translated part per Polylang language;
`cta`, `colors`, `fonts` and `sticky` are Elementor-only there (the theme's Global Styles set
the look). A page on an Elementor page template shows Elementor's header, not the theme's.
A classic theme without Elementor Pro is refused with what the theme supports instead (its
menu location and the Customizer logo).
