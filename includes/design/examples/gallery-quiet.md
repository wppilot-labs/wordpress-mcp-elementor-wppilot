---
name: Gallery Quiet
description: A near-monochrome direction for work that has to be looked at — the interface recedes to grey and sage so photography, art, or product imagery carries the page.
colors:
  bg: "#ebebe8"
  ink: "#1a1a1a"
  accent: "#556052"
  muted: "#77776f"
  surface: "#f6f6f4"
  rule: "#d4d4cf"
typography:
  heading:
    fontFamily: "Cormorant Garamond"
    fontWeight: "500"
    fontSize: "52px"
    lineHeight: "1.06"
    letterSpacing: "0.01em"
  body:
    fontFamily: "Jost"
    fontWeight: "300"
    fontSize: "17px"
    lineHeight: "1.7"
    letterSpacing: "0.01em"
    measure: "62"
spacing:
  sm: "1rem"
  md: "2.5rem"
  lg: "6rem"
rounded:
  sm: "0"
  md: "0"
  lg: "0"
components:
  button: "Ink text with a 1px ink rule, no fill, square corners"
  image: "No border, no shadow, no radius; caption in muted directly beneath, left-aligned"
  link: "Ink with an accent underline that appears on hover"
  nav: "Small, wide-tracked, muted until active; never a background bar"
dials:
  variance: 0.5
  density: 0.25
  motion: 0.2
---

# Gallery Quiet

For photographers, galleries, architecture practices, furniture, and any catalogue where
the images are the argument. The design's job is to be the wall, not the work.

## The reasoning

The background is a warm grey rather than white because a white page competes with a
photograph. Almost any image placed on `#ebebe8` reads as brighter and more saturated than
the same image on `#ffffff` — the page steps back and the picture steps forward. This is
the oldest trick in gallery hanging and it transfers directly.

The accent is a desaturated sage that most visitors will not consciously register as a
colour. That is the intent. It marks a link or an active state without introducing a hue
that any photograph now has to coexist with. It still clears AA at about 5.5:1, so it is
usable for real interface text rather than decoration only.

Body text is Jost at weight 300. Light weights are usually a mistake — they fail on low-end
displays and at small sizes — so this is conditional: it holds at 17px and above on this
background, and below that step up to 400. The direction is quiet, not fragile.

Density is 0.25, the lowest here. Images need margin around them the way objects need wall
around them, and a tight grid of thumbnails is a contact sheet, not an exhibition.

## What to build with it

Large images with generous, uneven space. Captions small, in `muted`, set close under the
image rather than centred beneath it. No borders or shadows on images at all — a frame
around a photograph on a grey ground adds nothing and dates immediately.

Where a page needs a panel, use `surface`; it is one step lighter than the ground and does
not require a rule to be legible as a group.

## Don't

- Never put a border, shadow, or rounded corner on an image.
- Don't set body text below 17px at weight 300; step the weight up instead.
- Avoid uniform thumbnail grids. Vary size and let the layout breathe unevenly.
- Never introduce a saturated accent. Any real colour on this page belongs to the work.
- Don't autoplay carousels or fade images in on scroll.
