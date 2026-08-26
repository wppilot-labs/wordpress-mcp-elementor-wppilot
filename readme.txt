=== WPPilot ===
Contributors: wppilot
Tags: mcp, ai, claude, agent, automation
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.10.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude, Cursor, Codex and other AI clients to your WordPress site over MCP, with safety profiles you control.

== Description ==

WPPilot turns your WordPress site into an MCP (Model Context Protocol) server, so the AI client you already use can build in it directly instead of handing you code to paste. Pages, block-editor content, menus, taxonomies, media, SEO metadata: one prompt from you becomes hundreds of typed calls from the agent, each checked against your WordPress capabilities before it runs.

It is built on the WordPress Abilities API and the official MCP Adapter, and it ships a compact tool set for discovery, inspection and execution rather than a sprawl of one-off endpoints.

A prompt library ships in wp-admin. The prompts name the abilities they call, in order, so a build does not stall halfway through with no explanation.

Full documentation is at [wppilot.co](https://wppilot.co).

= You decide what agents may do =

Every remote call is checked against a safety profile before it runs, on the server, for MCP and REST alike:

* **Production Safe**, the default. Content and configuration work, no raw code execution.
* **Read Only**. Agents can look and report, and change nothing.
* **Developer Full Access**: adds PHP execution, WP-CLI, filesystem and database access. Intended for development and staging sites.

Destructive and critical operations require an explicit confirmation flag on top of the profile, and supported changes are recorded in a redacted change ledger you can roll back.

= Three ways to connect =

* **OAuth 2.1** with PKCE, sign in through the browser, no password pasted into a config file. Client ID Metadata Documents are the preferred registration mechanism, with dynamic client registration kept for clients that do not support them.
* **Application passwords**, for clients that do not run an OAuth flow.
* **Access tokens**, a long-lived bearer token for callers that have no browser at all: the Claude Messages API MCP connector, the OpenAI Responses API, cron jobs, automation platforms and scripts. Created with an optional expiry, shown once, stored only as a digest, and revocable one at a time.

Both MCP protocol revisions are served during the migration window: the stateless `2026-07-28` and the session-based `2025-11-25`. Existing clients keep working and do not need to reconnect.

Either way, WPPilot generates the exact configuration for your client and shows you where to put it.

= Clients it generates configuration for =

**Editors and CLIs:** Claude Code, Claude Desktop, Codex CLI, the Codex desktop app, Cursor, VS Code, GitHub Copilot, Factory Droid, Antigravity CLI, Antigravity IDE, Devin Desktop (formerly Windsurf), Zed, Cline, Roo Code, Kilo Code, Amazon Q, OpenCode, OpenClaw, Kimi Code CLI, Qwen Code, Gemini CLI and ZCode (GLM).

**Web apps**, each with its own walkthrough: Claude on the web, ChatGPT, Perplexity, Mistral Le Chat and Manus. Every one adds this site from its own settings screen, and WPPilot tells you which credential that app accepts — three of the five can take an access token instead of signing in.

**Anything else that speaks HTTP:** the Claude Messages API MCP connector, the OpenAI Responses API, and plain curl.

Every client's snippet is written in the shape that client actually parses. The field names disagree more than they should — VS Code nests servers under "servers", Antigravity and Devin Desktop want "serverUrl", Qwen Code and Gemini CLI want "httpUrl", Cline spells the transport "streamableHttp" where Kilo spells it "streamable-http", Codex uses TOML — and a snippet copied from the wrong client's documentation often parses cleanly and then connects to nothing.

= Or let your AI set it up =

Every connection method also offers its setup as a ready-made prompt for an AI coding agent, carrying the server name, the URL, the exact snippet, the file it belongs in, and the rules that stop an agent inventing a transport that was never mentioned. Paste it into the agent already open next to WordPress.

= See what is connected =

The Overview screen names the AI clients actually talking to your site, identified by what each one reported when it connected, not by a label you typed, along with how it authenticated, when it first and last called, and how many requests it has made.

= Also included =

* Per-credential rate limiting on writes, with read-only abilities exempt.
* Typed design-library, Gutenberg staging, diagnostics, skills, change-ledger, file and developer operations, with every ability individually switchable.
* Skills and site-wide instructions, so agents start with your context rather than guessing.
* A sandbox for agent-authored PHP, isolated behind directory guards.
* A diagnostics screen that probes the connection the way a client does and names what to fix.

= External services =

The MCP endpoint runs on your own site, and your AI client connects to it directly without a WPPilot-hosted content relay.

WPPilot Chat is different: when you use Chat, WordPress sends the conversation history, selected attachments, site instructions, tool definitions and relevant tool results to the AI provider and model you selected through the WordPress AI Client. That provider is an external service and its own terms and privacy policy apply. WPPilot adds suggested text to WordPress's Privacy Policy Guide and integrates with the personal-data export and erase tools.

The stock-image search ability queries Openverse (api.openverse.org), WordPress.org's openly-licensed media search, and only when an agent calls it. The search terms and filters are sent to Openverse; nothing about your site, your users, or your content goes with them, and no account or API key is involved. Openverse is operated by the WordPress.org project and its own terms apply. Importing a chosen image then downloads that file from wherever its source hosts it, exactly as pasting the URL into the media importer would.

When you use OAuth, the client you are connecting registers itself with your site. That traffic is between your site and your own AI client, and no WPPilot server is involved.

WPPilot is distributed from wppilot.co and GitHub releases, not the WordPress.org directory, so it checks wppilot.co for plugin updates rather than the directory API. The update check sends the plugin version and nothing else.

Builds downloaded from wppilot.co can send anonymous usage data to wppilot.co once a day, but only if you switch it on under **WPPilot > Settings > Anonymous usage reporting**. It is off until then: nothing is sent, and there is no notice asking. Switching it back off also asks us to delete what was already collected. When on, it sends this site's URL, the WPPilot, WordPress and PHP versions, your locale, whether Pro is active, your safety profile, and how many connections exist. It never sends usernames, email addresses, page or post content, or any record of what an agent did. Reports are kept in detail for 90 days and then reduced to daily totals; an install that stops reporting for 400 days is deleted.

== Installation ==

1. Install and activate WPPilot.
2. Open **WPPilot → Connect** and turn on AI abilities.
3. Choose a safety profile. Production Safe is the default and is the right choice for a live site.
4. Pick your authentication method and your AI client, then copy the generated configuration into it.
5. Use the client once. **WPPilot → Overview** will show it connected.

If something does not work, **WPPilot → Diagnostics** runs the same checks a client does and tells you what to fix.

== Frequently Asked Questions ==

= Is it safe to run this on a production site? =

On the default Production Safe profile, agents can work with content and configuration but cannot execute code, reach the filesystem, or query the database. Every call is capability-checked against the logged-in user, so an agent can never do more than the account it is connected as.

Developer Full Access is a different matter: it grants PHP execution, WP-CLI and filesystem access. Use it on development and staging sites.

= Do I need an OpenAI or Anthropic API key? =

No. WPPilot is the server. Your AI client, Claude, Cursor, Codex or another, connects to it, and that client handles its own model access.

= Which WordPress and PHP versions are required? =

The MCP server requires WordPress 6.9 or newer and PHP 8.0 or newer. WPPilot Chat additionally requires WordPress 7.0 or newer and a configured AI provider; on WordPress 6.9 the MCP server continues to work while Chat reports that it is unavailable.

= Can I control which abilities are exposed? =

Yes. **WPPilot → Abilities** lists every registered ability, grouped by provider, and each one can be switched off individually. Disabled abilities disappear from discovery and cannot be executed.

= What does WPPilot Pro add? =

Pro is for the plugins your site adds on top of WordPress: WooCommerce, page builders, forms, SEO suites, custom fields, themes and multilingual tools, plus persistent memory across conversations and an approval queue. It is a separate paid plugin from wppilot.co and is not required.

WordPress itself is Free. Content, taxonomies, media, comments, revisions, menus, user reads and the allowlisted settings surface are all in this plugin and need no licence, entitlement service or Pro install.

= Which WordPress operations work without Pro? =

* Posts, pages and public custom post types: list, search, read, create, update, trash, restore, and permanently delete with explicit confirmation.
* Categories, tags and other public taxonomies: discover, list, read, create, update, delete with confirmation, and assign to content.
* Media: list, read, import from a URL, update metadata and alt text, set and clear featured images, attach and detach, and delete with confirmation.
* Comments: list, read, reply, edit, approve, hold, spam, unspam, trash, restore, and delete with confirmation.
* Revisions: list with autosaves distinguished, read against the live post, and restore.
* Menus: create, rename, delete, add and remove items, reorder, and assign to the theme's navigation locations.
* Users, privacy-minimized reads.
* Site information and an explicit settings allowlist.
* Plugins and themes: search the WordPress.org directory, read one in detail, activate, deactivate, update, and switch themes. Installing and deleting are Developer Full Access only, because they write executable code to the server.

New content is created as a draft unless you ask for publication explicitly, and publishing is checked against the post type's own capability.

== Screenshots ==

1. Overview: the AI clients connected to your site, how they authenticated, and how much they call.
2. Connect, generated configuration for your AI client, for OAuth, an application password, or an access token.
3. Settings, every site-wide switch in one place.
4. Abilities: every ability exposed to agents, grouped by provider, each one switchable.
5. Diagnostics, checks that probe the connection the way a client does.

== Development ==

Most of WPPilot is plain PHP, readable as shipped. The one compiled asset is the Chat screen's JavaScript, `includes/assets/chat/index.js`, built from the TypeScript source included in this package at `src/chat/index.tsx`.

To rebuild it from source:

1. Install dependencies: `npm install` (or `bun install`)
2. Build: `npm run build`. This runs `@wordpress/scripts` against `src/chat/index.tsx` and writes the bundle to `includes/assets/chat/`

The PHP dependencies under `vendor/` are installed with `composer install --no-dev` from the included `composer.json`.

== Changelog ==

= 1.10.0 =
* New ability: wppilot/adopt-design-from-site. On any site that is not brand new, the brand already exists - in the theme's global styles, in the customizer, in the pages somebody built - and a fresh direction invented alongside it produces work that clashes with every page already there. This reads what the site already has and returns a DESIGN.md draft, with the adopted palette's contrast reported alongside it, because an inherited palette was never chosen and finding out it cannot carry body text is worth knowing before anybody builds on it. Nothing is saved; review the draft, add the reasoning it cannot know, then save it.
* New ability: wppilot/list-design-examples. Seven worked example directions - light and dark, serif and sans, dense and airy, quiet and loud - shipped as real DESIGN.md files that pass the contract. They are reference, not a menu: nothing applies one, deliberately. Fifty selectable palettes produce fifty sites that look like the palette rather than like the business, which is the failure the design system exists to prevent. Read the nearest one to see how a finished direction argues its palette and phrases its rules, then write one for the site in front of you. One of them is a worked example of declaring a deliberate waiver for an anti-slop rule rather than tripping it.
* New ability: wppilot/verify-rendered-page. Everything else checks the string an agent produced. This fetches a published page and checks what the server actually sends - heading order, images without alt text, empty elements, PHP notices that made it into the markup, page weight, and the colours and faces actually present. A build can pass every check on its own output and still land on a page whose accent is the theme's, because the theme's stylesheet loads last. It does not run JavaScript or fetch external stylesheets, and its result says so.
* The Design screen now offers those seven directions as starter kits, shown as colour rather than as a list of hex values, each with a live specimen of its own headline, body text and button. Choosing one copies it into your designs and opens it for editing; it is never activated by the click and never applied to your site, because a palette adopted unchanged is the generic result the design system exists to prevent.
* A design can now declare its type scale, and it is enforced like everything else. Typography roles have always accepted fontSize, lineHeight, letterSpacing and measure - the parser read them - but nothing downstream looked, so a design could set its sizes and every page still invented its own. They now reach the preview, the CSS variables and, with Pro, the builder's own typography presets. The readiness check warns when a design has no scale, and warns separately when it sets sizes without leading, because large type at the browser default is the most recognisable untouched-web look there is.
* All seven shipped starter kits gained a scale suited to their direction, and the test that guards them now fails any example whose heading leading exceeds 1.25 or whose body is not set looser than its heading.
* Three new abilities, all read-only. No permission changes; existing connections keep working and do not need re-authorising.

= 1.9.0 =
* New ability: wppilot/check-contrast. Computes WCAG contrast ratios for every pair in the active design's palette and reports which surfaces can carry body text at AA. Ask it what to put on a background and it answers with the design's own colours instead of defaulting to black or white, and it names a palette that cannot produce readable text at all rather than leaving that to be discovered page by page.
* The active design is now put in front of the agent automatically. Its palette, type stack and Don't rules are appended to the server instructions every session, so an agent starts a build already knowing the direction rather than having to ask for it. Prevention is cheaper than a refusal; the design block is fenced and marked untrusted, because a design file may have been imported from anywhere.
* No permission changes. One new read-only ability; existing connections keep working and do not need re-authorising.

= 1.8.0 =
* New: the active design can now be enforced on the write path. Under **WPPilot > Settings > Design direction on writes**, choose Off, Warn and record, or Refuse the write. A write using a colour outside the palette, a font outside the type stack, or something the design's own Don't rules forbid is either recorded in the change ledger or refused outright.
* A refusal names the offending value and the palette entry to use instead, chosen by the role the colour was playing rather than by raw numeric distance, so the agent can correct it in one step instead of guessing again.
* Builder-agnostic: colours and fonts are read out of the write itself, so the same check covers Elementor settings, Bricks trees, Flatsome shortcodes, Gutenberg block specs and raw HTML without per-builder parsing. rgb(), rgba(), hsl() and hsla() values are normalised, so the same colour is judged the same way whichever notation a builder uses.
* Off by default, and it does nothing at all until a design is active. Only the palette, the type stack and the design's Don't rules are gated; the copy and anti-slop rules stay in wppilot/check-design, where an agent asks for a judgement rather than being blocked by one.

= 1.7.0 =
* New ability: wppilot/search-images. Searches Openverse — WordPress.org's openly-licensed media search — for CC-licensed and public-domain images, with licence, licence-type, orientation, extension and source filters. No API key and no account. Every result carries a ready-made attribution string, and the ability's own description tells the agent to carry it into the caption when importing, because attribution is a licence obligation for every CC licence except CC0 and the public domain mark.
* Search and import stay separate abilities on purpose: search is read-only and costs nothing, and a failed import should not look like a failed search. Import a chosen result with the existing wppilot/import-media-url.
* See External services above for exactly what the search sends: the query and filters, and nothing else.

= 1.6.4 =
* Preview inputs are encrypted at rest and malformed, tampered, legacy or oversized payloads fail closed. A stale apply can recover safely, while discard now refuses a preview another request is applying instead of racing it.
* Menu item partial updates preserve fields the request did not change. Moving an item through the wrong menu, or assigning a parent from another menu, is refused.
* The Block Editor Queue can claim an eligible batch beyond the first fifty records instead of reporting that no work exists.
* Front-page site settings reject invalid page combinations before writing anything, and their preview shows the settings that will change.
* Approval hooks now cover abilities added through the modern MCP transport, so Pro extensions receive the same preview and live safety checks as built-in abilities.
* No new abilities and no permission changes. Existing connections keep working and do not need re-authorising.

= 1.6.3 =
* Fixed a Block Editor Queue batch getting stuck with nobody working it. If the tab that picked up a batch was closed, navigated away from, or left in the background long enough for the browser to throttle it, the batch stayed marked as running and no other tab would take it — an administrator had to cancel and queue it again. It is picked up and retried automatically now. A batch another tab is actively working is still left alone.
* No new abilities and no permission changes. Existing connections keep working and do not need re-authorising.

= 1.6.2 =
* Fixed blocks from libraries that fill in part of their own settings from the editor — Spectra among them — saving without those settings. An agent-built page could reach the database with the block's style id missing, render unstyled, and still be reported as finished. Blocks now go through the hidden block editor before they are saved.
* Fixed the Block Editor Queue getting stuck on a batch when a request arrived without a readable body. The endpoint ended in a fatal error part-way through an item, and nothing was left running to finish it or hand it back. It now refuses the request and says what to send.
* Fixed revoking a connection not freeing its connection slot. The slot stayed in use until that connection expired on its own, so a site could report itself full immediately after an administrator made room.
* Fixed a recursive directory listing coming back empty because of a single folder it could not read. Unreadable entries are skipped and the rest of the listing is returned.
* No new abilities and no permission changes. Existing connections keep working and do not need re-authorising.

= 1.6.1 =
* Anonymous usage reporting is now off until you switch it on, and the notice that appeared on your dashboard in 1.6.0 is gone. A feature that only runs when asked for has nothing to disclose on activation, so there is nothing left to dismiss.
* Sites that already turned reporting off in 1.6.0 stay off. Sites that pressed **Keep it on** stay on — that was a recorded choice and this release does not overrule it. Every other site stops reporting on update, whether or not it ever saw the notice.
* No new abilities and no permission changes. Existing connections keep working and do not need re-authorising.

= 1.6.0 =
* Added optional anonymous usage reporting, so compatibility decisions about WordPress and PHP versions stop being guesswork. A notice explains it the first time you open wp-admin, and one click turns it off under **WPPilot > Settings**. Full detail under External services above.
* No new abilities and no permission changes. Existing connections keep working and do not need re-authorising.

= 1.5.1 =
* Fixed the tool list being rejected outright by AI clients that validate it. An ability declaring no input reached the client with `properties` encoded as a JSON array where a JSON object is required, and one such ability fails the whole tool payload — every tool on the connection, not just that one.
* Fixed abilities that take no input refusing every call with "input is not of type object". 1.5.0 fixed the client sending an empty object; this fixes the server sending nothing at all, which those abilities reject just as firmly.
* Fixed destructive tools demanding a confirmation they never advertised. WPPilot refuses them without `confirm`, but the field appeared in no schema and abilities forbid undeclared fields, so a validating client dropped it and the refusal repeated with no way to comply. It is now declared, and required, on the 44 tools that are gated.
* No new abilities and no permission changes. Existing connections keep working and do not need re-authorising.

= 1.5.0 =
* Added preview before write. An agent can ask what a change would do and get the answer without doing it: `wppilot/preview-ability` returns a before/after diff plus a URL, and `wppilot/apply-preview` performs the write once a person has agreed to it. Nothing is written until then.
* The diff is computed rather than performed, so the site is never touched to produce one — not in a transaction, not on a copy.
* A preview never invents a diff. An ability with no projector is refused by name with a reason, and a call that would be rejected is reported as such instead of being shown as a change that would never have landed.
* New Preview screen under Agent, listing what is waiting for review, with the AI client and the WordPress user named on each entry.
* Applying re-checks that the target has not moved, scoped to the fields in the diff. A change elsewhere is reported as a warning; a change to what you reviewed refuses and writes nothing.
* Applying re-runs the safety profile check and the ability's own confirmation requirement, so it is not a route around either.
* New Settings option, off by default: require a reviewed preview before agent writes, over MCP and REST. Abilities that cannot be previewed are exempt rather than blocked.
* Previews are capped at fifty and expire after a day. Two administrators cannot apply the same one twice.
* Fixed importing media from a URL, which could never run: the ability required a WordPress core file under a name that does not exist, so every call failed before the download started.
* Fixed abilities that take no input rejecting a call that sends an empty object — which is what AI clients send rather than omitting the field. Six abilities were affected, including the site settings read and the diagnostics reports.

= 1.4.1 =
* Fixed the plugin version constant, which still read 1.3.0 in the 1.4.0 release while the plugin header read 1.4.0. That constant is what the update check advertises, what admin assets are cache-busted against, and what WPPilot Pro compares when it decides which features the free plugin owns, so the three disagreed on every 1.4.0 install.
* Writing post_content to a page built with Elementor, Bricks or Beaver Builder is now refused instead of silently doing nothing. Those builders keep their layout in postmeta and render that, so the write was stored, reported as successful, and changed nothing a visitor could see — and the builder overwrote it from its own tree at the next save. The refusal names the builder and the ability that does own the page. Passing allow_raw_content_on_builder_post: true still performs the write, for feeds and search, and returns an audit note saying the page itself did not change. Divi, Etch and WPBakery were already covered, because they store markup in post_content where it can be recognised; Breakdance keeps its own separate gate.
* The refusal for hand-written builder storage meta no longer names an ability that is not installed. On a site without Pro it now says to edit in the builder instead, matching what the post_content refusal has always done.
* Fixed the WordPress-core abilities being served by an older copy inside WPPilot Pro. Registration order follows plugin load order, which follows activation order rather than the alphabet, so on a site where Pro had been activated first its own copies of 23 core abilities registered ahead of the free plugin's and won. Every fix shipped to those abilities since 1.1.0 was inert on those sites. The free plugin owns them from 1.1.0 onward, and Pro 1.1.2 now stands aside; older Pro builds are unaffected because the free plugin's own duplicate guard still yields to them.

= 1.4.0 =
* Added access tokens, a third way to connect. A long-lived bearer token for callers that have no browser: the Claude Messages API MCP connector, the OpenAI Responses API, cron jobs, automation platforms and scripts. OAuth and application passwords are unchanged and existing connections keep working.
* A token is shown once when you create it and stored only as a digest, so it cannot be read back afterwards. Give it an expiry of 30 days, 90 days, a year, or none, and revoke it on its own at any time. Deleting the WordPress user deletes their tokens.
* A token has exactly the access of the account that created it, checked on every request. Removing that account's permissions closes the token immediately.
* The Connect screen now offers all three methods side by side, with ready-made configuration for seventeen AI clients plus the Claude API, the OpenAI API and curl.
* Creating a token now takes you straight to it, with the three steps that connect a client and a jump to the ready-made configuration. Previously the page reloaded at the top and the token — shown only once — was far below.
* The Overview shows an Access tokens block with a direct link to create or manage one.
* Every method now offers its setup as a copy-paste prompt for an AI coding agent, alongside the existing snippets and instructions rather than in place of them.
* Setup guides for the web chat interfaces are rewritten for their current menus, and Mistral Le Chat, Perplexity and Manus are added alongside ChatGPT and Claude on the web. Each says where the setting actually lives, what plan it needs, and to switch the connector on in the chat afterwards.
* Claude on the web, Le Chat and Perplexity can also be connected with an access token instead of signing in, with step-by-step instructions for each.
* Client setup instructions refreshed for August 2026, including the config format changes in Antigravity, Devin Desktop, Cline and Kilo Code, and a note that Roo Code was discontinued in May 2026.

= 1.3.0 =
* Fixed restoring a revision, which could not run at all on 1.1.0 through 1.2.1. Every call failed with a fatal error before the post was touched. Nothing else was affected, and no other ability shared the fault.
* The same ability reported success when the restore had not written anything. A revision with no restorable fields, or a write WordPress refused, both came back as though the post had been rolled back. Failure is now reported as failure.
* The change ledger now names the agent behind each write, not only the WordPress user. Claude Code, Cursor and Codex usually connect as the same administrator, so entries could not say which of them made a change. Each entry now carries the OAuth client or application password used, and the client name and version it introduced itself with.
* Changes made outside an AI client — in wp-admin, over WP-CLI, or by another plugin — are recorded as direct rather than attributed to the last agent that connected. OAuth client identifiers are stored hashed.
* Ability count is unchanged at 103. No permission changes, and existing connections keep working.

= 1.2.1 =
* Fixed extension search against the themes directory. It returns the author as a profile record where the plugins directory returns a link, so every theme search raised an "Array to string conversion" warning and reported the author as the literal word "Array".
* An extension that declares no minimum WordPress version reports it as false, not as text. That was rendered as "1" or as an empty string; it now reads as no requirement.
* Theme results report a download count where plugin results report active installs, so theme popularity came back as zero. Both are read now.

= 1.2.0 =
* Plugins and themes can now be managed, not just listed. Eleven new abilities: search the WordPress.org directory, read one plugin or theme in detail, activate, deactivate, update, switch themes, install and delete. Ability count is 103, up from 92.
* Installing and deleting write executable code to the server, so they are treated like PHP execution: blocked on Production Safe and Read Only, available in Developer Full Access with explicit confirmation.
* Activating, deactivating, updating and switching themes fetch nothing and write no files, so they work on a live site — but each one can take a site down, so all four require explicit confirmation. Ordinary content editing is unaffected.
* An install leaves the plugin inactive and the theme unswitched, so the review step is a separate, separately confirmed call.
* Sites with DISALLOW_FILE_MODS set, or without direct filesystem access, get a named refusal explaining what to fix instead of a stalled or partial install.
* Security: WPPilot cannot deactivate or delete itself, an active plugin cannot be deleted before it is deactivated, and the active theme and its parent cannot be deleted. Slugs, plugin files and package URLs are validated before use.
* Activation, deactivation and theme switches are recorded in the change ledger and can be rolled back. Installs, updates and deletions are recorded as non-reversible, each stating why and how to undo it manually.

= 1.1.0 =
* The WordPress core surface now ships in the free plugin: content, taxonomies, media, comments, revisions, menus, privacy-minimized user reads, and an allowlisted settings surface. No licence, entitlement service or Pro install is involved. Ability count is 92, up from 42.
* New content is created as a draft unless publication is requested explicitly. An absent, blank or malformed status resolves to draft before any capability check, so nothing is published by accident.
* Capabilities come from each post type's and taxonomy's own capability object, so a custom post type declaring its own set is enforced on its own terms. Publishing is checked separately from editing.
* MCP 2026-07-28 is served alongside 2025-11-25. The new revision is stateless, no handshake, no session. Existing clients are unaffected and do not need to reconnect.
* Client ID Metadata Documents are now the preferred OAuth registration mechanism, with Dynamic Client Registration kept as a fallback.
* Security: create, update and delete of content previously ran on a single administrator check and ignored the post type's capabilities and post ownership. Both are now enforced.
* Security: menu item URLs are validated before storage. A javascript:, data: or vbscript: URL was previously stored verbatim and rendered into a link.
* Privacy: user reads no longer return email addresses by default. Login name, roles and registration date require the capability to list users; the email address requires the capability to edit them.
* Comment listings withhold commenter email and IP from accounts that cannot moderate comments.
* The change ledger covers the new operations, with verified rollback for term edits, comment edits and moderation, menu placement and menu ordering. Permanent deletions are recorded as non-reversible with the reason stated.

= 1.0.0 =
* Initial public release.
* MCP server built on the WordPress Abilities API and the official MCP Adapter.
* OAuth 2.1 with PKCE and dynamic client registration, or application passwords.
* Generated connection configuration for sixteen AI clients.
* Overview screen showing which AI clients are connected and how much they call.
* Production Safe, Read Only and Developer Full Access profiles, enforced on the server.
* Explicit confirmation for destructive and critical operations, with a redacted change ledger and rollback.
* Per-credential rate limiting on writes.
* Skills, site instructions, and a guarded sandbox for agent-authored PHP.

== Upgrade Notice ==

= 1.6.4 =
Fixes preview confidentiality and lifecycle races, menu partial updates and ownership checks, queue discovery beyond the first fifty records, front-page settings validation, and approval enforcement for Pro abilities on the modern MCP transport. No new abilities or permission changes.

= 1.6.3 =
Fixes a Block Editor Queue batch getting stuck when the tab working it goes away — closed, navigated off, or throttled in the background. The batch is retried automatically instead of needing an administrator to cancel and re-queue it. One more fix, no new abilities, and existing connections keep working.

= 1.6.2 =
Four fixes, no new abilities. Blocks from libraries such as Spectra keep the settings their editor assigns, so an agent-built page stops rendering unstyled while the queue says it worked. The Block Editor Queue no longer strands a batch when a request arrives without a readable body, revoking a connection frees its slot straight away, and a recursive directory listing survives a folder it cannot read. Existing connections keep working.

= 1.6.1 =
Makes anonymous usage reporting opt-in and removes the dashboard notice that 1.6.0 introduced. Sites that made a choice in 1.6.0 keep it; every other site stops reporting on update. No new abilities, no permission changes, and existing connections keep working.

= 1.6.0 =
Adds optional anonymous usage reporting, which tells us which WordPress and PHP versions are actually in use. A notice explains what is sent the first time you open wp-admin and offers a one-click switch off. No new abilities, no permission changes, and existing connections keep working.

= 1.5.1 =
Fixes three defects that together made WPPilot unusable from any AI client that checks its calls against the schemas the server hands it — Claude among them. The tool list itself was rejected, abilities that take no input refused every call, and destructive tools asked for a confirmation the client had no way to send, so the same refusal repeated forever. A more forgiving client saw none of this. No new abilities and no permission changes; existing connections keep working.

= 1.5.0 =
Adds preview before write: an agent can show you exactly what a change would do, as a field-by-field diff, before anything is written — and you apply it from a screen in wp-admin. WPPilot could already undo a change; this is the first release where it can show you one first. Nothing about how existing calls behave changes unless you switch on the new Settings option, which is off by default. No permission changes, and existing connections keep working.

= 1.4.1 =
Fixes four defects, two of which affect what an agent can silently get wrong. Writing page content to an Elementor, Bricks or Beaver Builder page used to succeed and change nothing visible; it is now refused, with the builder and the right ability named. On sites running WPPilot Pro, 23 core abilities were being served by Pro's older copies whenever Pro had been activated first, so free's fixes since 1.1.0 never applied there — update Pro to 1.1.2 alongside this release to complete that fix. The version constant, which reported 1.3.0 throughout 1.4.0, is corrected. No new abilities and no permission changes; existing connections keep working.

= 1.4.0 =
A large release, all of it about getting connected. Access tokens are a third way in, for callers that cannot sign in through a browser: the Claude Messages API MCP connector, the OpenAI Responses API, cron jobs, automation platforms and scripts. Web apps get their own route, with real walkthroughs for Claude on the web, ChatGPT, Perplexity, Mistral Le Chat and Manus. Seven clients are new — Kimi Code CLI, Qwen Code, Gemini CLI, ZCode (GLM) and the web apps above — and every existing client's instructions were rewritten against its current interface, several of which had moved. Each method now also offers its setup as a copy-paste prompt for an AI coding agent. The Connect screen is reordered so the setup comes before the status panels. OAuth and application passwords are unchanged, existing connections keep working, and there are no new abilities and no permission changes.

= 1.3.0 =
Fixes restoring a revision, which failed with a fatal error on every call from 1.1.0 onward and, behind that, reported a restore that never happened as successful. Recommended for everyone. Change ledger entries now name the agent that made each write, so a site connected by more than one AI client can tell them apart. No new abilities, no permission changes, and existing connections keep working.

= 1.2.1 =
Fixes theme search, which reported every author as "Array" and every download count as zero on 1.2.0. Recommended for anyone using the plugin and theme abilities. No new abilities, no permission changes, and existing connections keep working.

= 1.2.0 =
Adds plugin and theme management to the free plugin. Installing and deleting stay off Production Safe; activating, updating and switching themes work there with explicit confirmation. Existing connections keep working and do not need to be re-authorised.

= 1.1.0 =
Adds the WordPress core surface to the free plugin and fixes three security defects in content and menu handling, plus a privacy defect in user reads. Your AI client will see roughly twice as many tools after updating. Existing connections keep working and do not need to be re-authorised.

= 1.0.0 =
First public release.
