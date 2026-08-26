---
name: Editorial Broadsheet
description: A reading-first newspaper direction — warm paper, near-black ink, one deep red used only where something is genuinely urgent.
colors:
  bg: "#fbfaf7"
  ink: "#16181d"
  accent: "#9a2617"
  muted: "#6b6f76"
  rule: "#ddd8cf"
typography:
  heading:
    fontFamily: "Fraunces"
    fontWeight: "700"
    fontSize: "58px"
    lineHeight: "1.04"
    letterSpacing: "-0.02em"
  body:
    fontFamily: "Source Serif 4"
    fontWeight: "400"
    fontSize: "18px"
    lineHeight: "1.7"
    letterSpacing: "0"
    measure: "70"
spacing:
  sm: "0.75rem"
  md: "1.5rem"
  lg: "3.5rem"
rounded:
  sm: "2px"
  md: "2px"
  lg: "2px"
components:
  button: "Text link with a 1px accent underline; a filled button only for the single primary action on a page"
  card: "Not used. Group with a hairline rule and vertical space instead"
  link: "Underlined in ink at all times, accent on hover; never colour-only"
  pullquote: "Larger heading face, no quotation marks, thick accent rule on the left"
dials:
  variance: 0.45
  density: 0.7
  motion: 0.15
---

# Editorial Broadsheet

For sites where the writing is the product: long-form journalism, research, essays,
documentation that people actually sit and read. Everything here is subordinate to a
column of text somebody will spend ten minutes inside.

## The reasoning

The background is paper rather than white. `#fbfaf7` is barely off-white, but under a
screen's backlight the difference between pure white and warm white is the difference
between a page and a lightbox — the eye stops bracing against it.

Both faces are serifs, which is the unusual choice and the deliberate one. The
conventional move is a sans heading over a serif body for "contrast"; that contrast is
decorative and it costs the page its voice. Fraunces and Source Serif 4 differ enough in
weight and width to separate a headline from a paragraph without the page sounding like
two different publications.

Corners are square at every size. A 2px radius is not a style decision here so much as an
absence of one — nothing on this page is a card, a chip, or a pill, so nothing needs a
radius to say so.

The red is the only saturated thing in the system and it carries a single meaning:
this matters more than the text around it. Spend it on one thing per screen. A page with
three red elements has told the reader that none of them are urgent.

## What to build with it

Rules and whitespace do the work that borders and shadows do elsewhere. A hairline in
`rule` under a section heading separates more cleanly than a boxed panel, and it does not
imply the content is a widget. Measure matters more than any token here: hold body text
near 65–75 characters a line and the direction largely enforces itself.

Density is set high (0.7) because editorial pages are allowed to be full. Motion is near
zero — text that moves while you are trying to read it is a defect.

## Don't

- Never put body text on the accent red; it is a foreground colour, not a background.
- Don't add a third typeface. If something needs to stand apart, change its size or weight.
- Never use drop shadows to separate content. Use a rule, or use space.
- Avoid card grids. This direction is columns and rules; a grid of boxes turns an article into a dashboard.
- Don't animate anything on scroll. Motion is 0.15 for a reason.
