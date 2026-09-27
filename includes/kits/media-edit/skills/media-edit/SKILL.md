---
name: media-edit
description: Resize, crop, rotate or flip an image in the media library, as a new copy or in place, with undo. Activate when the user asks to resize, crop, straighten, rotate, flip or mirror an uploaded image, or to make an image smaller for the web.
---

# Editing media-library images

`wppilot/edit-image` runs WordPress's own image editor on one attachment.

## Operations

`operations` is a list applied in order, at most 10:

- `{op: "resize", width?, height?}` fits the image inside the box and keeps its aspect ratio.
  One side is enough.
- `{op: "crop", x, y, width, height}` in pixels of the image **as it is at that step**, so a
  crop after a rotate uses the rotated size.
- `{op: "rotate", degrees: 90 | 180 | 270}` clockwise.
- `{op: "flip", direction: "horizontal" | "vertical"}`: horizontal mirrors left to right,
  vertical turns it upside down.

To crop to a region, find the image's width and height first (the media library's attachment
details list them), then give pixel coordinates. An operation that does not fit the image at its
step is refused with the size it found, before anything is written.

## Copy or replace

- `mode: "copy"` (default) saves a new attachment next to the original and copies its alt
  text. The original and every page using it are untouched. Use this unless the person asked
  to change the image everywhere.
- `mode: "replace"` works like the Edit Image screen: it writes a new `-e<number>` file for the
  same attachment, regenerates its sizes, and records the old file as a backup. Every page
  showing the attachment changes. Some sites convert saved images to another format; replace is
  then refused, and copy works.

## Limits

- JPEG, PNG, GIF, WebP and AVIF only; the file must be on this server.
- Each side is capped at 8000 px, and sources over 50 megapixels are refused.
- Enlarging is refused unless `allow_upscale: true`. Only set it when the person asked for a
  bigger image, and tell them it will look softer.
- Nothing is deleted: a replace keeps the old file on disk.

## Undo

Both modes are recorded in the change log. Undoing a copy deletes the new attachment and its
files, and refuses if that attachment has since been changed to point at another file. Undoing
a replace points the attachment back at its old file and metadata, and refuses if the old file
is no longer on disk. If the copy was inserted into a post, undoing it leaves a broken image in
that post; check first.
