## Standards (non-negotiable)

### Accessibility — WCAG 2.1 AA
- Text contrast ≥ 4.5:1 (≥ 3:1 for large text) against its actual background — check text over photos and dark bands.
- Exactly one H1; headings descend logically (no skipped levels for styling reasons).
- Descriptive alt text on every image; meaningful link/button labels (never "click here").
- Comfortable touch targets on buttons and links; readable body size (≥ 16px); every form field has a visible, associated label.

### Images — real photography in every slot
- Source real photos from **Unsplash, Pexels, or Pixabay** (use the available stock-image search/sideload tools if present, otherwise direct source URLs). Never leave an image slot empty; never use placeholder URLs.
- Curate for one consistent grade that matches the palette; pick images that share a mood, not a random set that happens to match the keyword.

### Icons — one consistent SVG set
- Use inline SVG icons in a single consistent style — Lucide/Feather spec: `viewBox="0 0 24 24"`, `fill="none"`, `stroke="currentColor"`, `stroke-width="1.75"`, round caps/joins. Generate what you need.
- Do not use the builder's bundled icon font/library, and no emoji as icons.

### Builder-native construction
- Build with the chosen builder's **native elements/widgets/blocks** and its native styling, spacing, and motion features. Inspect the builder's available elements and their options first if tools for that exist.
- Raw HTML/custom code embedded inside a visual builder is a **last resort** for a micro-detail that is genuinely impossible natively — never for whole sections. (If the chosen target IS plain HTML/CSS, write clean semantic HTML with modern CSS instead.)

### Completeness — a half-built page is a failure
- Build and **fully populate one section at a time**: real headline, real copy, every item with its price, every quote written, every image placed — then move on. Never scaffold empty containers to "fill later."
- When finished, walk the entire page and fix any empty element, placeholder text, missing image, or contrast failure. Publish as a **draft**.
