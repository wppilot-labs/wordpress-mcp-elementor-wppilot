---
name: pagespeed
description: Measure a page's speed with Google PageSpeed Insights (Lighthouse scores, lab metrics, Core Web Vitals field data, top opportunities) without any Google key. Activate when the user asks how fast the site or a page is, about PageSpeed, Lighthouse or Core Web Vitals scores, or before and after a speed or cache change.
---

# PageSpeed check

One read-only ability: `wppilot/pagespeed-check` with `url` (default: home page; a URL or a
path on this site), `strategy` (`mobile` default, `desktop` or `both`) and `refresh`.

No one needs a Google key. The ability asks, in order: Site Kit by Google's PageSpeed module
(when it is connected and this user may read it), the plugin's cloud PageSpeed service, a key the
site owner saved in the plugin's settings, then Google's keyless API. `source` names the one that
answered and `attempts` says why earlier ones did not.

## Reading results

- `scores` are 0-100. `null` means the category was not measured (Site Kit measures performance
  only), not a zero.
- `metrics` are one simulated load (lab): `lcp_ms`, `tbt_ms`, `cls`, `fcp_ms`, `si_ms`,
  `ttfb_ms`. Say so when you report them, and expect a few points of noise between runs.
- `field_data` is what real visitors experienced over 28 days (Chrome UX Report, 75th
  percentile: `lcp_ms`, `cls`, `inp_ms`, `fcp_ms`, `ttfb_ms`, `overall`), when Google has enough
  traffic; `scope: "origin"` means it describes the whole site rather than this page.
  `null` is normal for small sites.
- `opportunities` are ordered by time saved; `id` is the Lighthouse audit id. Work from the top.
- A run takes 10-60 seconds; results are reused for 15 minutes. Pass `refresh: true` only to
  measure again after a change.

## Errors

- `kit_pagespeed_page_too_slow`: Google could not load the page in time. This is about the
  page: the uncached page is likely too slow. Measure the server response (and warm the cache)
  before trying again; do not retry in a loop.
- `kit_pagespeed_page_unreachable`: Google cannot fetch the public URL (local site,
  password protection, maintenance mode).
- `kit_pagespeed_quota`: wait (`retry_after` seconds when given). Suggest the site owner add
  their own PageSpeed API key in the plugin's settings only if they test often.

## Site Kit

When the result carries `fix` naming `wppilot/site-kit-enable-sharing`, Site Kit could have
answered but its data is not shared with this user's role. Offer once to turn on read-only
dashboard sharing for Administrators; run it only if the user agrees.
