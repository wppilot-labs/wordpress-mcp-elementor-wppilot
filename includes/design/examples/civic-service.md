---
name: Civic Service
description: A plain public-service direction built for people who are stressed, on a bad connection, or using a screen reader — maximum legibility, no ornament.
colors:
  bg: "#ffffff"
  ink: "#1b1b1b"
  accent: "#0b5d3b"
  muted: "#505a5f"
  focus: "#ffdd00"
  error: "#b1041b"
typography:
  heading:
    fontFamily: "Libre Franklin"
    fontWeight: "700"
  body:
    fontFamily: "Source Sans 3"
    fontWeight: "400"
spacing:
  sm: "0.5rem"
  md: "1.25rem"
  lg: "2.5rem"
rounded:
  sm: "0"
  md: "0"
  lg: "0"
components:
  button: "Solid accent, white label, square corners, minimum 44px tall, label names the action"
  input: "Square, 2px ink border, visible label above, error text above the field in error"
  link: "Accent with a persistent underline; visited state left at the browser default"
  callout: "Left border 4px in accent or error, no background tint, heading in bold ink"
dials:
  variance: 0.2
  density: 0.5
  motion: 0.05
---

# Civic Service

For anything where failing to understand the page has a real cost: government forms,
benefits, healthcare enrolment, legal notices, utilities. The user is often not browsing.
They are trying to finish something, sometimes under pressure, sometimes on a phone with
one bar.

## The reasoning

Pure white and near-black, because this is the one context where maximum contrast beats
comfort. The ink/background pair here is roughly 16:1 — far past AA — and that headroom is
deliberate: it survives a cracked screen, direct sunlight, and a cheap panel with a blue
cast.

The green accent is dark enough to carry white text on a button and to pass AA as text on
white, so a link and a button can share one colour without one of them failing. That is
the whole reason it is this green and not a brighter one.

`focus` is separate from `accent` and non-negotiable. A yellow focus ring against a dark
outline is visible on every background in this palette, and keyboard users are a large
share of the people this kind of site exists for. Never remove the focus outline; never
replace it with a subtle one.

Variance is 0.2 — pages in this system should look nearly identical to each other. Novelty
between pages is a cost here, not a feature. Somebody who learned the layout on page one
should not have to relearn it on page four.

## What to build with it

One column. Question-per-page for anything form-shaped. Buttons are rectangles with real
labels — "Save and continue", not "Next" and certainly not an arrow. Error messages go
above the field, in `error`, with text that says what to do rather than what went wrong.

Square corners throughout. Not a stylistic preference: a radius reads as "app chrome", and
this should read as a document.

## Don't

- Never remove or restyle the focus indicator into something subtle.
- Don't use colour as the only carrier of meaning; pair it with text or an icon every time.
- Never put placeholder text in place of a label.
- Avoid icons without text labels. An icon alone is a guess.
- Don't introduce a second accent colour. Two greens is one green too many.
- Never animate a page transition or a form step.
