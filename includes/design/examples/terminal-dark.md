---
name: Terminal Dark
description: A dark developer-tooling direction — deep blue-black ground, amber accent, monospace carrying real weight rather than appearing only in code blocks.
colors:
  bg: "#10131a"
  ink: "#dfe4ec"
  accent: "#d9822b"
  muted: "#8b95a7"
  surface: "#1a1f2b"
  rule: "#2a3242"
typography:
  heading:
    fontFamily: "IBM Plex Sans"
    fontWeight: "600"
  body:
    fontFamily: "IBM Plex Sans"
    fontWeight: "400"
  mono:
    fontFamily: "IBM Plex Mono"
spacing:
  sm: "0.5rem"
  md: "1rem"
  lg: "2rem"
rounded:
  sm: "3px"
  md: "6px"
  lg: "6px"
components:
  button: "Surface fill with a rule border; accent fill reserved for the one destructive or primary action"
  code: "Mono on surface, 3px radius, no border, never wrapped"
  table: "Rule between rows only, no zebra striping, numeric columns right-aligned in mono"
  badge: "Mono uppercase at 11px, 3px radius, colour from state rather than from accent"
dials:
  variance: 0.35
  density: 0.75
  motion: 0.25
---

# Terminal Dark

For developer tools, API documentation, dashboards, logs — anything where the reader is
scanning structured output rather than reading prose, and is likely to have this open next
to an editor in the same colour range.

## The reasoning

The ground is `#10131a`, not black. Pure black against bright text produces halation — the
text appears to bleed — and it removes the ability to show a raised surface without a
border. A near-black with a blue cast gives `surface` somewhere to sit one step up.

Ink is `#dfe4ec` rather than white for the same reason inverted: white on near-black is
harsher than most people can read for an hour. This pair still lands around 14:1, so the
comfort costs nothing in accessibility.

Amber is the accent because the two obvious dark-theme accents are both wrong here. Bright
cyan/blue is what every terminal theme already uses for links and types, so it reads as
syntax rather than as interface. Purple-on-dark is the single most recognisable AI-default
combination there is. Amber is warm against a cool ground, passes comfortably as text, and
does not collide with syntax highlighting underneath it.

One family in three cuts. IBM Plex Sans and IBM Plex Mono are designed together, which
means an inline `code` span sits on the same baseline and at the same apparent size as the
sentence containing it. That is worth more than a "characterful" pairing in a system where
prose and code interleave constantly.

## What to build with it

Density is high (0.75). This is a direction for tables, trees, and lists that go on for
screens — cramped is closer to right than airy. Use `surface` and `rule` for structure
rather than shadow; shadows barely read on a dark ground and mostly add haze.

Monospace is a first-class body face here, not decoration. Identifiers, paths, versions,
and values belong in it inline, not just in fenced blocks.

## Don't

- Never use pure `#000000` as a background or pure `#ffffff` as text.
- Don't use the amber for large filled areas; it is an accent at text and icon scale.
- Avoid glassmorphism, blur, and translucent panels. They destroy contrast on dark grounds.
- Never rely on a coloured border alone to indicate state; a dark theme flattens hue differences.
- Don't add a purple or violet accent. That is the tell this palette exists to avoid.
