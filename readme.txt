=== WPPilot ===
Contributors: wppilot
Tags: mcp, ai, claude, agent, automation
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.4.1
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

When you use OAuth, the client you are connecting registers itself with your site. That traffic is between your site and your own AI client, and no WPPilot server is involved.

Builds downloaded from wppilot.co check that site for plugin updates. The copy distributed through the WordPress.org directory does not: it is updated by WordPress.org like any other plugin.

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
