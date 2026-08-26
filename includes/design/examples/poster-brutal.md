---
name: Poster Brutal
description: A loud, flat, high-contrast direction for launches and events — enormous type, one red, hard edges, and nothing soft anywhere.
colors:
  bg: "#ffffff"
  ink: "#000000"
  accent: "#e10600"
  muted: "#666666"
  rule: "#000000"
typography:
  heading:
    fontFamily: "Archivo Black"
    fontWeight: "400"
    fontSize: "96px"
    lineHeight: "0.94"
    letterSpacing: "-0.03em"
  body:
    fontFamily: "Archivo"
    fontWeight: "400"
    fontSize: "18px"
    lineHeight: "1.5"
    letterSpacing: "0"
    measure: "64"
spacing:
  sm: "0.5rem"
  md: "1.5rem"
  lg: "5rem"
rounded:
  sm: "0"
  md: "0"
  lg: "0"
components:
  button: "Square block, accent fill, white uppercase label, 2px ink border, no hover fade"
  card: "Not used. Sections are full-bleed and separated by 2px ink rules"
  link: "Ink with a 2px accent underline; accent text on hover"
  nav: "Uppercase, large, horizontal, separated by ink rules rather than space"
dials:
  variance: 0.8
  density: 0.55
  motion: 0.4
---

# Poster Brutal

For launches, events, campaigns, portfolios, record labels — short-lived pages whose job is
to be remembered rather than to be comfortable. This is the least polite direction here and
it should stay that way.

## The reasoning

Type does everything. Archivo Black at genuinely large sizes — 6rem and up on desktop, not
"large" at 2.5rem — is the layout. If a headline in this system fits comfortably on one
line at a normal size, it is set too small.

Black on white with a single red is the entire palette, and the red is used flat: no
gradients, no tints, no 40% opacity version of itself. `muted` exists for legal text and
captions and nothing else.

Zero radius everywhere and full-strength black rules. Where other directions imply
structure with space, this one draws the line and makes it 2px.

Variance is 0.8 — the highest in the set. Pages are supposed to differ. A hero that breaks
the grid on one page and not the next is correct here, and consistency between pages is a
much smaller virtue than impact on any one of them.

## What to build with it

Full-bleed sections with hard boundaries. Type that overlaps or crops at the viewport edge.
Asymmetry on purpose. One idea per screen, stated at maximum size.

The discipline in this direction is not restraint in the layout — it is restraint in the
palette. Loud type on two colours reads as designed; loud type on five reads as broken.

## Don't

- Never use a gradient, a shadow, a blur, or a rounded corner.
- Don't tint or fade the red. It is one value, used flat.
- Never set a headline small enough to be comfortable.
- Avoid this direction for anything transactional — checkout, forms, account settings. It is a poster, not an interface.
- Don't add a third colour. The moment there is a second accent this becomes a template.
