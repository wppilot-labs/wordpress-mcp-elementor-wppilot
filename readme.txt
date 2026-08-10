=== WPPilot ===
Contributors: wppilot
Tags: mcp, ai, claude, agent, automation
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude, Cursor, Codex and other AI clients to your WordPress site over MCP, with safety profiles you control.

== Description ==

WPPilot turns your WordPress site into an MCP (Model Context Protocol) server, so AI clients can read and edit it directly instead of you copying content back and forth.

It is built on the WordPress Abilities API and the official MCP Adapter, and it ships a compact tool set for discovery, inspection and execution rather than a sprawl of one-off endpoints.

Full documentation is at [wppilot.co](https://wppilot.co).

= You decide what agents may do =

Every remote call is checked against a safety profile before it runs, on the server, for MCP and REST alike:

* **Production Safe** — the default. Content and configuration work, no raw code execution.
* **Read Only** — agents can look and report, and change nothing.
* **Developer Full Access** — adds PHP execution, WP-CLI, filesystem and database access. Intended for development and staging sites.

Destructive and critical operations require an explicit confirmation flag on top of the profile, and supported changes are recorded in a redacted change ledger you can roll back.

= Two ways to connect =

* **OAuth 2.1** with PKCE and dynamic client registration — sign in through the browser, no password pasted into a config file.
* **Application passwords** — for clients that do not run an OAuth flow.

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

Specialized abilities for WooCommerce, page builders, forms, SEO, custom fields, themes and multilingual sites, plus persistent memory across conversations. Pro is a separate paid plugin from wppilot.co and is not required — WPPilot works on its own.

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

= 1.0.0 =
First public release.
