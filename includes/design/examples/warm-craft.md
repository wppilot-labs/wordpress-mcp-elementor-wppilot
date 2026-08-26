---
name: Warm Craft
description: A maker and hospitality direction in cream and terracotta — and a worked example of waiving an anti-slop rule on purpose rather than tripping it by accident.
allow:
  - warm-craft-palette
colors:
  bg: "#f4ece1"
  ink: "#2f2a25"
  accent: "#9c4823"
  muted: "#7a6a58"
  surface: "#fbf6ef"
  rule: "#e0d3c1"
typography:
  heading:
    fontFamily: "Bitter"
    fontWeight: "600"
    fontSize: "46px"
    lineHeight: "1.1"
    letterSpacing: "-0.01em"
  body:
    fontFamily: "Karla"
    fontWeight: "400"
    fontSize: "17px"
    lineHeight: "1.65"
    letterSpacing: "0"
    measure: "68"
spacing:
  sm: "0.75rem"
  md: "1.5rem"
  lg: "3rem"
rounded:
  sm: "4px"
  md: "8px"
  lg: "8px"
components:
  button: "Solid accent with cream label at 8px radius; secondary is an accent rule with no fill"
  card: "Surface fill, 8px radius, 1px rule; shadow only where a card genuinely lifts"
  link: "Accent, underlined, thickening on hover"
  divider: "A rule in the rule colour, never a decorative glyph or ornament"
dials:
  variance: 0.6
  density: 0.45
  motion: 0.35
---

# Warm Craft

For bakeries, studios, small hospitality, makers, and independent shops — businesses whose
appeal is that a person is behind them.

## Why this one carries a waiver

Cream-and-terracotta is on the pre-flight's list of AI tells. It became one because it is
the palette a model reaches for whenever a brief contains the words artisan, handmade, or
local — it turns up unrequested, on businesses it does not describe, roughly as often as
purple turns up on a SaaS landing page.

It is still the right answer for some businesses. A wood-fired bakery is not badly served
by warm neutrals just because a model would have guessed them. The difference between a
default and a decision is whether anybody made it, so this direction declares
`allow: warm-craft-palette` in its front matter. That is a claim on the record that the
palette was chosen for this site, and it stops the gate flagging every write.

Copy that pattern rather than the palette. Any anti-slop rule can be waived the same way,
and the waiver is visible in the admin screen and in the design context an agent reads, so
the decision stays attributable instead of silently disappearing into the CSS.

## The reasoning

The terracotta is darkened past the obvious one. A mid terracotta around `#b1552b` looks
right in isolation and lands at about 4.3:1 on this cream — just under AA, so every link
set in it fails. `#9c4823` reaches 5.3:1 and reads as the same colour at a glance.

Bitter over Karla keeps the warmth from tipping into pastiche. The heading face has slab
serifs and a sturdy build; the body face is a plain grotesque. Two decorative faces here
would produce a wedding invitation.

Variance is 0.6, the highest in this set apart from the poster direction. Pages are allowed
to differ from each other — that irregularity is part of what makes a small business look
like a small business rather than a template.

## Don't

- Never add a third warm hue. Cream, terracotta and the brown ink are the whole range.
- Don't lighten the accent for aesthetics; it is sitting just above the contrast floor already.
- Avoid script and handwritten faces. The warmth is in the palette; a script face makes it costume.
- Never use pure white as a page background here — it breaks the paper reading of the cream.
- Don't use this direction because a brief said "artisan". Use it because the business actually is.
