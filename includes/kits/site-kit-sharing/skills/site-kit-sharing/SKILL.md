---
name: site-kit-sharing
description: Turn on Site Kit by Google's read-only Dashboard sharing for Administrators so Search Console, Analytics and PageSpeed data can be read by admins who are not signed in to Site Kit. Activate when a Site Kit read or a PageSpeed check returns `fix` naming wppilot/site-kit-enable-sharing, or when the user asks to share Site Kit data with other administrators.
---

# Site Kit dashboard sharing

`wppilot/site-kit-enable-sharing` shares Search Console, Analytics 4 and PageSpeed Insights
(or the `modules` you name) with the Administrator role, read-only, through Site Kit's own
sharing settings. It never touches Google tokens and never changes who manages sharing.

## When to offer it

Only when a result says sharing is the problem (`fix.ability` is this ability). Offer it once,
explaining that every administrator will be able to read that Google data through the Site Kit
owner's account. Run it only after the user agrees, with `confirm: true`. If they decline, do not
offer it again in this conversation.

## Reading the result

Per module `status`:

- `shared`: done. `already_shared`: nothing to do.
- `not_permitted`: Site Kit lets only the module's owner (the administrator who connected it,
  signed in to Site Kit with Google) change its sharing; `owner` names them. Tell the user who
  can do it. Do not retry.
- `not_connected`: the module is not connected to Google in Site Kit; a person has to connect
  it in Site Kit first. No plugin can sign in to Google for them.
- `refused_by_site_kit`: Site Kit accepted the request but kept the old roles. Report it.

Saving makes the person who ran it the owner of PageSpeed Insights in Site Kit (Site Kit's own
rule). Undo the whole change with `wppilot/rollback-change` and its change id; that restores the
previous owner too.
