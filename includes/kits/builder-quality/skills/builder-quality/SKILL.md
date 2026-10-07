---
name: builder-quality
description: Check that an Elementor page an agent built or changed is easy for a person to edit afterwards - no layout pasted into HTML widgets, no inline styles or scripts, global colours instead of fixed ones, sensible nesting - and fix what it finds. Activate after building or redesigning an Elementor page, or when the user asks whether a page is clean, editable or "done properly".
---

# Checking an Elementor page is editable

Run `wppilot/elementor-audit-output` with the page's `post_id` after every build or larger edit.

- `score` 85+ (`good`): done.
- `fair` or `poor`: work through `items`, most costly kind first (`counts` is sorted that way).
  `fixes` says how to fix each kind.

What to do per finding:

- `html_widget`: rebuild that markup with native widgets - a heading widget for headings, a text
  editor for paragraphs, a button widget for links styled as buttons, an image widget for images.
  If the site offers an ability that converts HTML widgets to native ones, use it for the simple
  ones and rebuild the rest by hand.
- `hardcoded_color`: set the colour from the site's global palette (the `__globals__` of the
  setting) instead of a hex value.
- `inline_style`, `custom_css`: move the styling into the widget's style controls or a global class.
- `script`: never put scripts in page content; tell the user and leave scripts to a plugin.
- `deep_nesting`, `empty_container`: flatten or remove containers.

Run the audit again after fixing, and report the before and after scores to the user.
