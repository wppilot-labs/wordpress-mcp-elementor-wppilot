---
name: Clinical Calm
description: A quiet healthcare and wellbeing direction — cool near-white, deep slate ink, a single desaturated teal that never raises the temperature of the page.
colors:
  bg: "#f7f9f9"
  ink: "#22333b"
  accent: "#2b7a78"
  muted: "#61757e"
  surface: "#ffffff"
  rule: "#dde5e6"
typography:
  heading:
    fontFamily: "Newsreader"
    fontWeight: "500"
  body:
    fontFamily: "Figtree"
    fontWeight: "400"
spacing:
  sm: "0.75rem"
  md: "1.75rem"
  lg: "4rem"
rounded:
  sm: "6px"
  md: "12px"
  lg: "20px"
components:
  button: "Solid accent with white label at 20px radius; secondary is accent text on surface"
  card: "White surface on the cool ground, 20px radius, no border and no shadow"
  link: "Accent, underlined on hover only, never below 16px"
  callout: "Surface panel with generous padding; no icon, no tinted background"
dials:
  variance: 0.4
  density: 0.3
  motion: 0.3
---

# Clinical Calm

For clinics, therapy practices, patient-facing health content, and wellbeing services.
The reader may be worried. The job of the page is to be unhurried and to not add to that.

## The reasoning

Density is 0.3 — the lowest in this set. Space is the primary tool. A page in this system
should feel like there is time, and cramming it defeats the entire direction regardless of
whether the colours are right.

The teal is deliberately desaturated. Saturated medical blues and greens read as
institutional signage, and the brighter the accent, the more the page feels like a system
rather than a person. `#2b7a78` clears AA against the background at 4.75:1 — enough for
links and labels, and close enough to the floor that it must not be used for small or thin
text. Use `ink` for anything under 16px.

Heading is a serif at medium weight rather than bold. A 500-weight Newsreader headline says
something without announcing it; the same headline at 700 starts to sound like marketing,
which is exactly the register to avoid here.

Corners are generously rounded and they increase with size — 6px on inputs, 20px on
containers. This is the one direction in the set where softness is doing real work, because
the underlying subject is not soft.

## What to build with it

Give every block more vertical room than looks necessary. Prefer one idea per section over
a dense feature grid. Use `surface` white cards on the cool background where you need to
group things — the difference between `#ffffff` and `#f7f9f9` is small enough to group
without fencing.

Never use red or amber as decoration in this palette. In a health context, a warm colour
is read as a warning whether you meant it that way or not.

## Don't

- Never use the teal for text below 16px or at light weights; it is at the AA floor.
- Don't use red, orange or amber decoratively; reserve warm colour for genuine alerts.
- Avoid stock photography of smiling clinicians. It reads as false in exactly this context.
- Never tighten the spacing to fit more above the fold. Density is the direction.
- Don't set headings above weight 600.
