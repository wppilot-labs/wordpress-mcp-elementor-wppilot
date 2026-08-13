# WPPilot — WordPress MCP Server

**Point Claude Code, Codex, Cursor or Antigravity at your WordPress site and let it build — pages, block layouts, menus, taxonomies, media, SEO metadata — through typed abilities your permissions still govern.**

[![Version](https://img.shields.io/badge/version-1.1.0-142017)](https://github.com/wppilot-labs/wppilot/releases)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-142017)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-142017)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-D9FF63)](LICENSE)

WPPilot turns your WordPress site into an **MCP server**, built on the WordPress Abilities API and the official WordPress MCP Adapter. AI clients discover, inspect and execute *typed* WordPress abilities through a compact three-tool interface instead of loading hundreds of one-off endpoints into context.

## MCP protocol support

WPPilot serves both protocol revisions during the migration window:

| Revision | State | How it is served |
| --- | --- | --- |
| `2026-07-28` | Stateless. No `initialize`, no session. Each request carries its version and client capabilities in `_meta`. | `includes/mcp/`, dispatched ahead of the adapter |
| `2025-11-25` | Legacy. `initialize` handshake and `Mcp-Session-Id` sessions. | The bundled MCP Adapter, unchanged |

A request is served under the modern revision **only** when it carries modern per-request `_meta`; everything else reaches the adapter untouched. **Existing users do not need to reconnect** unless their client requires the newer revision.

`server/discover` is implemented and advertises both versions plus the capabilities actually registered on the site. Subscriptions, the tasks extension and logging are deliberately not advertised — WPPilot has no change-notification producer, so `subscriptions/listen` is not implemented.

For OAuth, **Client ID Metadata Documents are the preferred registration mechanism**; RFC 7591 Dynamic Client Registration remains available as a compatibility fallback. Application Passwords stay an independent fallback for clients that run no OAuth flow.

It is a control layer, not an AI wrapper. **No AI model is bundled** — external MCP clients bring their own model access, and policy is enforced server-side on your install.

## What an agent can actually build

One prompt from you becomes hundreds of typed calls from the agent, each checked against your WordPress capabilities and the active safety profile before it runs.

Free covers the core surface: posts and pages, block-editor content, taxonomies, menus and menu locations, media with alt text, users, site settings including the front page, plus design documents, skills and a change ledger with rollback.

**You do not have to phrase anything in a particular way.** A client's first call is discovery, and WPPilot answers with every ability registered on your install plus a catalogue of the skills available for them, carrying one instruction: if a skill matches the request, load its full instructions before starting the work. So "rebuild the pricing page and keep our spacing" already pulls in the right build skill, the element schemas and your existing design tokens without you naming any of it. Write your own prompts; the routing is on the site.

The library is a shortcut, not a dependency. WPPilot ships a **prompt library** in wp-admin for the jobs people run most, and those prompts name the abilities they call rather than describing an outcome and hoping:

```text
Create a new draft page titled "[PAGE TITLE]" using core Gutenberg blocks only.

How the write works, so you do not get halfway and stall:
1. wppilot/create-post — create the draft first. It defaults to draft; leave it there.
2. wppilot/gutenberg-create-pending-batch — core blocks cannot be written straight
   from the server, because the block editor's own JavaScript is what validates and
   serialises them.
3. wppilot/gutenberg-add-pending-change — queue the block tree against the new post.
4. wppilot/gutenberg-enable-batch-finalization, then
   wppilot/gutenberg-get-finalization-url — open that link and the batch commits.
```

That last part is the honest bit: **core blocks are not a headless write**. Anything the block editor validates in JavaScript needs a browser session to finalise, and the prompts say so rather than leaving you stuck at step three.

WPPilot Pro adds plugin-aware modules — page builders, WooCommerce, forms, custom fields, SEO, themes — each with its own ability chains. The full published library is at <https://wppilot.co/prompts>.

- 🌐 Website: <https://wppilot.co>
- 🧱 What it builds: <https://wppilot.co/build>
- 💬 Prompt library: <https://wppilot.co/prompts>
- 📚 Documentation: <https://wppilot.co/docs>
- 🔌 Client setup guides: <https://wppilot.co/wordpress-mcp>

---

## Quick start

1. Download the latest `wppilot.zip` from [Releases](https://github.com/wppilot-labs/wppilot/releases) and install it as `wp-content/plugins/wppilot`.
   *A GitHub “Source code (zip)” download is **not** installable — it lacks `vendor/` and uses the wrong folder name.*
2. Activate WPPilot.
3. Open **WPPilot → Configuration** and leave **Production Safe** selected.
4. Open **WPPilot → Connect**, choose your AI client, and follow the OAuth or Application Password route.

Canonical MCP endpoint:

```text
https://example.com/wp-json/mcp/wppilot
```

OAuth-authenticated clients use `/wp-json/mcp/wppilot-oauth`. The older `/wp-json/mcp/mcp-adapter-default-server` route still resolves as a legacy alias, but new configurations should use the canonical path above.

## Supported AI clients

Claude Code · Claude Desktop · Claude on the web · Codex CLI · Codex desktop app · Cursor · VS Code · GitHub Copilot · Devin Desktop (formerly Windsurf) · Factory Droid · Antigravity CLI · Antigravity IDE · Zed · Cline · Roo Code · Kilo Code · Amazon Q · OpenCode · OpenClaw · Manus

Per-client setup guides: <https://wppilot.co/wordpress-mcp>

## Authentication

- **OAuth 2.1 with PKCE** and dynamic client registration. Access tokens last 1 hour, refresh tokens 14 days, and every authorization is listed under **Connected Apps** in WordPress so it can be revoked individually.
- **Application Passwords** as a fallback for clients that cannot run a browser flow.

Neither is a product licence. WPPilot needs no activation key, entitlement check or subscription service to run.

## Safety model

| Profile | What it allows |
| --- | --- |
| **Read Only** | Discovery and inspection. Every state-changing ability is blocked. |
| **Production Safe** | Normal content, design, SEO, forms and commerce work. Blocks raw PHP, WP-CLI, filesystem, database, plugin/theme installation and temporary admin access. |
| **Developer Full Access** | Every enabled ability, including privileged surfaces. Critical calls still require explicit confirmation. |

On top of the profile: WordPress user capabilities still apply, individual abilities can be switched off, destructive operations require an explicit confirmation flag, writes are rate-limited per credential, and supported changes are recorded in a redacted change ledger with rollback.

## What the free plugin can do

92 registered abilities on a fresh install, plus one MCP prompt per skill you save. The WordPress ones are grouped under a single **WordPress** category in the Abilities screen and can be switched off individually.

| Domain | Abilities | What it covers |
| --- | --- | --- |
| **Content** | `8` | List, search and read posts, pages and public custom post types. Create, update, trash, restore, and permanently delete with explicit confirmation. |
| **Taxonomies** | `7` | Discover taxonomies, list and read terms, create, update, delete with confirmation, and assign terms to content. |
| **Media** | `9` | List, read, import from a URL, update metadata and alt text, set and clear featured images, attach and detach, delete with confirmation. |
| **Comments** | `6` | List and read with commenter email and IP withheld below `moderate_comments`. Reply, edit, approve, hold, spam, unspam, trash, restore, delete with confirmation. |
| **Menus** | `10` | Create, rename and delete menus, add, update, reorder and remove items, list theme locations and assign menus to them. |
| **Revisions** | `3` | List with autosaves distinguished, read against the live post, and restore. |
| **Users** | `4` | Privacy-minimized reads. Login name, roles and registration date need `list_users`; the email address needs `edit_users`. |
| **Site** | `3` | Site information, an explicit settings allowlist, and installed extensions. |
| **Gutenberg** | `11` | Block-editor content, staged pending changes and browser finalization for native blocks. |
| **Design library** | `7` | Typed design tokens, saved designs and activation. |
| **Skills** | `4` + prompts | Reusable skills and site-wide instructions. Each saved skill also registers one MCP prompt, so this grows with the skills you write. |
| **Changes** | `3` | Read the redacted change ledger and roll a change back. |
| **Diagnostics** | `3` | Scoped health, performance and configuration-security checks. |
| **Developer** | `13` | PHP execution, WP-CLI, filesystem and temporary admin access. Blocked outside Developer Full Access, and excluded entirely from the WordPress.org build. |

Content creation is draft-first: an absent, blank or malformed status resolves to `draft` before any capability check, so nothing is published by accident. Capabilities are read from each post type's and taxonomy's own capability object, so a custom type declaring its own set is enforced on its own terms.

## WPPilot Pro — 968 plugin-aware abilities

The free plugin in this repository is a complete WordPress MCP server: connection, authentication, safety profiles, Gutenberg workflows, diagnostics, change evidence and **92 abilities**, including the whole WordPress core surface — content, taxonomies, media, comments, revisions, menus, user reads and allowlisted settings. Free needs no licence, entitlement service or Pro install.

[**WPPilot Pro**](https://wppilot.co/pro) adds **plugin-aware abilities across 51 integrations** — typed operations that understand each plugin's own data model rather than writing generic content. Modules load only when their plugin is detected, and each loads in isolation, so a missing or broken plugin cannot stop the rest of the registry from registering.

| Category | Integrations · `ability count` |
| --- | --- |
| **Page builders** | [Elementor](https://wppilot.co/integrations/elementor) `33` · [Bricks](https://wppilot.co/integrations/bricks) `49` · [Breakdance](https://wppilot.co/integrations/breakdance) `33` · [Divi](https://wppilot.co/integrations/divi) `47` · [Oxygen](https://wppilot.co/integrations/oxygen) `37` · [Beaver Builder](https://wppilot.co/integrations/beaver-builder) `21` · [WPBakery](https://wppilot.co/integrations/wpbakery) `18` · [Etch](https://wppilot.co/integrations/etch) `60` · [Mosaic](https://wppilot.co/integrations/mosaic) `36` |
| **Blocks and site design** | [GenerateBlocks](https://wppilot.co/integrations/generateblocks) `3` · [Kadence Blocks](https://wppilot.co/integrations/kadenceblocks) `3` · [Spectra](https://wppilot.co/integrations/spectra) `20` · [Spectra One](https://wppilot.co/integrations/spectra-one) `22` |
| **Themes** | [Astra](https://wppilot.co/integrations/astra) `34` · [Avada](https://wppilot.co/integrations/avada) `16` · [GeneratePress](https://wppilot.co/integrations/generatepress) `23` · [Kadence](https://wppilot.co/integrations/kadence) `5` · [OceanWP](https://wppilot.co/integrations/oceanwp) `15` · [WordPress Block Themes](https://wppilot.co/integrations/block-themes) `4` · [Blocksy](https://wppilot.co/integrations/blocksy) `4` · [Neve](https://wppilot.co/integrations/neve) `4` · [WoodMart](https://wppilot.co/integrations/woodmart) `4` |
| **Commerce** | [WooCommerce](https://wppilot.co/integrations/woocommerce) `35` |
| **Forms** | [WPForms](https://wppilot.co/integrations/wpforms) `28` · [Gravity Forms](https://wppilot.co/integrations/gravityforms) `28` · [Fluent Forms](https://wppilot.co/integrations/fluentforms) `37` · [Formidable Forms](https://wppilot.co/integrations/formidable) `39` · [Contact Form 7](https://wppilot.co/integrations/contact-form-7) `9` · [Ninja Forms](https://wppilot.co/integrations/ninja-forms) `21` |
| **SEO suites** | [AIOSEO](https://wppilot.co/integrations/aioseo) `12` · [Rank Math](https://wppilot.co/integrations/rank-math) `8` · [SEOPress](https://wppilot.co/integrations/seopress) `16` · [Yoast SEO](https://wppilot.co/integrations/yoast) `10` |
| **Custom data** | [Advanced Custom Fields](https://wppilot.co/integrations/acf) `23` · [ACPT](https://wppilot.co/integrations/acpt) `24` · [Admin and Site Enhancements](https://wppilot.co/integrations/ase) `18` · [JetEngine](https://wppilot.co/integrations/jetengine) `26` · [Meta Box](https://wppilot.co/integrations/meta-box) `32` · [Pods](https://wppilot.co/integrations/pods) `25` · [Dynamic Shortcodes](https://wppilot.co/integrations/dynamic-shortcodes) `9` |
| **Localization** | [Weglot](https://wppilot.co/integrations/weglot) `19` · [Polylang](https://wppilot.co/integrations/polylang) `6` |
| **Site operations** | [The Events Calendar](https://wppilot.co/integrations/the-events-calendar) `7` · [Paid Memberships Pro](https://wppilot.co/integrations/paid-memberships-pro) `5` · [Tutor LMS](https://wppilot.co/integrations/tutor-lms) `7` · [BuddyPress](https://wppilot.co/integrations/buddypress) `8` |
| **Developer tools** | [Code Snippets](https://wppilot.co/integrations/code-snippets) `11` · [Bricksforge](https://wppilot.co/integrations/bricksforge) `21` |
| **WordPress platform** | [Agent Memory](https://wppilot.co/integrations/memory) `4` · [WPPilot Skills](https://wppilot.co/integrations/skills) `1` |

### Why plugin-aware matters

A page builder does not store a page as HTML. It stores an element tree, references to shared classes and design tokens, template rules and dynamic bindings. Writing generated markup into that store is how a layout stops opening in its own editor.

Pro gives the agent that builder's own vocabulary — `bricks-patch-elements`, `elementor-create-atomic-widget`, `divi-apply-global-preset`, `etch-get-query-preview` — so it can read a schema before it proposes a change.

### Page-builder guides

| Builder | Abilities | Guide |
| --- | --- | --- |
| Etch | 60 | [MCP for Etch](https://wppilot.co/mcp-for-etch) |
| Bricks | 49 | [MCP for Bricks](https://wppilot.co/mcp-for-bricks) |
| Divi | 47 | [MCP for Divi](https://wppilot.co/mcp-for-divi) |
| Oxygen | 37 | [MCP for Oxygen](https://wppilot.co/mcp-for-oxygen) |
| Mosaic | 36 | [MCP for Mosaic](https://wppilot.co/mcp-for-mosaic) |
| Elementor | 33 | [MCP for Elementor](https://wppilot.co/mcp-for-elementor) |
| Breakdance | 33 | [MCP for Breakdance](https://wppilot.co/mcp-for-breakdance) |
| Beaver Builder | 21 | [MCP for Beaver Builder](https://wppilot.co/mcp-for-beaver-builder) |
| WPBakery | 18 | [MCP for WPBakery](https://wppilot.co/mcp-for-wpbakery) |

### Beyond integrations

- **Persistent agent memory** — approved context that carries between sessions, so an agent does not relearn your stack every conversation.
- **Human approval queue** — holds an agent write until a person approves it, with email notification. The agent receives a structured “pending” response, not a false success.
- **Integration health reporting** — see which modules loaded, which were skipped, and why.
- **Plugin-aware skill packs** — guided sequences that encode the read-before-write workflow for the plugins you run.

[Compare Free vs Pro](https://wppilot.co/free-vs-pro) · [Pricing](https://wppilot.co/pricing) · [All integrations](https://wppilot.co/integrations)

> WPPilot Pro is a commercial plugin and is not distributed from this repository.

## Requirements

- WordPress 6.9 or newer
- PHP 8.0 or newer
- HTTPS for any remotely reachable connection
- WPPilot Chat additionally requires WordPress 7.0 and an AI provider configured through the WordPress AI Client

## Privacy

The MCP endpoint is self-hosted; there is no WPPilot relay. When WPPilot Chat is used, WordPress sends conversation history, selected attachments, site instructions, tool definitions and relevant tool results to the AI provider you configured. Suggested policy text is available in **Settings → Privacy → Policy Guide**.

## Documentation

| Guide | |
| --- | --- |
| Getting started | <https://wppilot.co/docs/getting-started> |
| Connect an AI client | <https://wppilot.co/docs/connect-ai-client> |
| OAuth 2.1 setup | <https://wppilot.co/docs/oauth-setup> |
| Application Passwords | <https://wppilot.co/docs/application-passwords> |
| Safety profiles | <https://wppilot.co/docs/safety-profiles> |
| Page builder workflows | <https://wppilot.co/docs/page-builder-workflows> |
| Change ledger and rollback | <https://wppilot.co/docs/change-ledger-and-rollback> |
| Troubleshooting | <https://wppilot.co/docs/troubleshooting> |

In-repo: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) · [`docs/SAFETY.md`](docs/SAFETY.md) · [`SECURITY.md`](SECURITY.md)

## Security

Report suspected vulnerabilities privately — see [SECURITY.md](SECURITY.md). Do not open a public issue for a vulnerability, and never include production credentials or customer data.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE) and [`LICENSES/`](LICENSES) for the full SPDX texts.
