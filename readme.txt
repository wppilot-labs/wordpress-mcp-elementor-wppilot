=== WPPilot ===
Contributors: wppilot
Tags: mcp, ai, claude, agent, automation
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.1.0
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

* **Production Safe** — the default. Content and configuration work, no raw code execution.
* **Read Only** — agents can look and report, and change nothing.
* **Developer Full Access** — adds PHP execution, WP-CLI, filesystem and database access. Intended for development and staging sites.

Destructive and critical operations require an explicit confirmation flag on top of the profile, and supported changes are recorded in a redacted change ledger you can roll back.

= Two ways to connect =

* **OAuth 2.1** with PKCE — sign in through the browser, no password pasted into a config file. Client ID Metadata Documents are the preferred registration mechanism, with dynamic client registration kept for clients that do not support them.
* **Application passwords** — for clients that do not run an OAuth flow.

Both MCP protocol revisions are served during the migration window: the stateless `2026-07-28` and the session-based `2025-11-25`. Existing clients keep working and do not need to reconnect.

Either way, WPPilot generates the exact configuration for your client and shows you where to put it.

= Clients it generates configuration for =

Claude Code, Claude Desktop, Claude on the web, Codex, Cursor, VS Code, GitHub Copilot, Antigravity CLI, Antigravity IDE, Windsurf, Zed, Cline, Roo Code, Kilo Code, Amazon Q and OpenCode.

= See what is connected =

The Overview screen names the AI clients actually talking to your site — identified by what each one reported when it connected, not by a label you typed — along with how it authenticated, when it first and last called, and how many requests it has made.

= Also included =

* Per-credential rate limiting on writes, with read-only abilities exempt.
* Typed design-library, Gutenberg staging, diagnostics, skills, change-ledger, file and developer operations, with every ability individually switchable.
* Skills and site-wide instructions, so agents start with your context rather than guessing.
* A sandbox for agent-authored PHP, isolated behind directory guards.
* A diagnostics screen that probes the connection the way a client does and names what to fix.

= External services =

The MCP endpoint runs on your own site, and your AI client connects to it directly without a WPPilot-hosted content relay.

WPPilot Chat is different: when you use Chat, WordPress sends the conversation history, selected attachments, site instructions, tool definitions and relevant tool results to the AI provider and model you selected through the WordPress AI Client. That provider is an external service and its own terms and privacy policy apply. WPPilot adds suggested text to WordPress's Privacy Policy Guide and integrates with the personal-data export and erase tools.

When you use OAuth, the client you are connecting registers itself with your site — that traffic is between your site and your own AI client, and no WPPilot server is involved.

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

No. WPPilot is the server. Your AI client — Claude, Cursor, Codex or another — connects to it, and that client handles its own model access.

= Which WordPress and PHP versions are required? =

The MCP server requires WordPress 6.9 or newer and PHP 8.0 or newer. WPPilot Chat additionally requires WordPress 7.0 or newer and a configured AI provider; on WordPress 6.9 the MCP server continues to work while Chat reports that it is unavailable.

= Can I control which abilities are exposed? =

Yes. **WPPilot → Abilities** lists every registered ability, grouped by provider, and each one can be switched off individually. Disabled abilities disappear from discovery and cannot be executed.

= What does WPPilot Pro add? =

Pro is for the plugins your site adds on top of WordPress: WooCommerce, page builders, forms, SEO suites, custom fields, themes and multilingual tools, plus persistent memory across conversations and an approval queue. It is a separate paid plugin from wppilot.co and is not required.

WordPress itself is Free. Content, taxonomies, media, comments, revisions, menus, user reads and the allowlisted settings surface are all in this plugin and need no licence, entitlement service or Pro install.

= Which WordPress operations work without Pro? =

* Posts, pages and public custom post types — list, search, read, create, update, trash, restore, and permanently delete with explicit confirmation.
* Categories, tags and other public taxonomies — discover, list, read, create, update, delete with confirmation, and assign to content.
* Media — list, read, import from a URL, update metadata and alt text, set and clear featured images, attach and detach, and delete with confirmation.
* Comments — list, read, reply, edit, approve, hold, spam, unspam, trash, restore, and delete with confirmation.
* Revisions — list with autosaves distinguished, read against the live post, and restore.
* Menus — create, rename, delete, add and remove items, reorder, and assign to the theme's navigation locations.
* Users — privacy-minimized reads.
* Site information and an explicit settings allowlist.

New content is created as a draft unless you ask for publication explicitly, and publishing is checked against the post type's own capability.

== Screenshots ==

1. Overview — the AI clients connected to your site, how they authenticated, and how much they call.
2. Connect — generated configuration for your AI client, for OAuth or an application password.
3. Settings — every site-wide switch in one place.
4. Abilities — every ability exposed to agents, grouped by provider, each one switchable.
5. Diagnostics — checks that probe the connection the way a client does.

== Development ==

Most of WPPilot is plain PHP, readable as shipped. The one compiled asset is the Chat screen's JavaScript, `includes/assets/chat/index.js`, built from the TypeScript source included in this package at `src/chat/index.tsx`.

To rebuild it from source:

1. Install dependencies: `npm install` (or `bun install`)
2. Build: `npm run build` — this runs `@wordpress/scripts` against `src/chat/index.tsx` and writes the bundle to `includes/assets/chat/`

The PHP dependencies under `vendor/` are installed with `composer install --no-dev` from the included `composer.json`.

== Changelog ==

= 1.1.0 =
* The WordPress core surface now ships in the free plugin: content, taxonomies, media, comments, revisions, menus, privacy-minimized user reads, and an allowlisted settings surface. No licence, entitlement service or Pro install is involved. Ability count is 92, up from 42.
* New content is created as a draft unless publication is requested explicitly. An absent, blank or malformed status resolves to draft before any capability check, so nothing is published by accident.
* Capabilities come from each post type's and taxonomy's own capability object, so a custom post type declaring its own set is enforced on its own terms. Publishing is checked separately from editing.
* MCP 2026-07-28 is served alongside 2025-11-25. The new revision is stateless — no handshake, no session. Existing clients are unaffected and do not need to reconnect.
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

= 1.1.0 =
Adds the WordPress core surface to the free plugin and fixes three security defects in content and menu handling, plus a privacy defect in user reads. Your AI client will see roughly twice as many tools after updating. Existing connections keep working and do not need to be re-authorised.

= 1.0.0 =
First public release.
