# WPPilot — WordPress MCP Server, Elementor MCP and WooCommerce MCP

**Point Claude Code, Codex, Cursor or Antigravity at your WordPress site and let it build, pages, Elementor layouts, block content, menus, taxonomies, media, SEO metadata, through typed abilities your permissions still govern.**

[![Version](https://img.shields.io/github/v/release/wppilot-labs/wordpress-mcp-elementor-wppilot?color=142017&label=version)](https://github.com/wppilot-labs/wordpress-mcp-elementor-wppilot/releases)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-142017)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-142017)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-D9FF63)](LICENSE)

[![Download wppilot.zip](https://img.shields.io/badge/Download-wppilot.zip-D9FF63?style=for-the-badge&labelColor=142017)](https://github.com/wppilot-labs/wordpress-mcp-elementor-wppilot/releases/latest/download/wppilot.zip)

Installs straight into **Plugins - Add New - Upload Plugin**. That link always resolves to the newest release, so it does not go stale. Older versions are on the [releases page](https://github.com/wppilot-labs/wordpress-mcp-elementor-wppilot/releases). GitHub's own *Source code (zip)* is **not** installable: it has no `vendor/` and uses a versioned folder name.

WPPilot turns your WordPress site into an **MCP server**, built on the WordPress Abilities API and the official WordPress MCP Adapter. AI clients discover, inspect and execute *typed* WordPress abilities through a compact three-tool interface instead of loading hundreds of one-off endpoints into context.

The free plugin is the **WordPress MCP server**, and since 1.10.0 it is also a working **Elementor MCP server**: 16 abilities that read an Elementor document, report the widgets and style properties your install actually offers, and add, edit, move, duplicate, reorder and delete elements in the tree. No licence, no key, no Pro install. [WPPilot Pro](https://wppilot.co/pro) then extends that same endpoint into a **WooCommerce MCP server** and a Bricks, Divi, Oxygen, Etch or WPBakery MCP server, and adds Elementor's authoring layer on top: whole-page composition, templates and theme parts, popups, forms, dynamic tags, global classes and variables.

### Looking for an Elementor, Divi or Beaver Builder MCP server?

This is it, with one server instead of one per plugin. **Elementor editing is free**, in this repository, and needs nothing else installed — see [Elementor MCP in the free plugin](#elementor-mcp-in-the-free-plugin). [WPPilot Pro](https://wppilot.co/pro) adds the builder-aware layer on top of the same endpoint, so an agent that connects once can work in whichever editor the site actually uses:

[Elementor MCP](https://wppilot.co/mcp-for-elementor) · [Bricks MCP](https://wppilot.co/mcp-for-bricks) · [Divi MCP](https://wppilot.co/mcp-for-divi) · [Beaver Builder MCP](https://wppilot.co/mcp-for-beaver-builder) · [Oxygen MCP](https://wppilot.co/mcp-for-oxygen) · [Breakdance MCP](https://wppilot.co/mcp-for-breakdance) · [WPBakery MCP](https://wppilot.co/mcp-for-wpbakery) · [Etch MCP](https://wppilot.co/mcp-for-etch) · [Mosaic MCP](https://wppilot.co/mcp-for-mosaic)

Beyond page builders, Pro also covers WooCommerce, Advanced Custom Fields, Meta Box, JetEngine, Pods, ACPT, WPForms, Gravity Forms, Fluent Forms, Formidable, Contact Form 7, Ninja Forms, Yoast SEO, Rank Math, AIOSEO, SEOPress, WPML, Polylang, Weglot, The Events Calendar, Tutor LMS, Paid Memberships Pro and BuddyPress. Full table below: [51 integrations](#wppilot-pro-1042-plugin-aware-abilities).

## MCP protocol support

WPPilot serves both protocol revisions during the migration window:

| Revision | State | How it is served |
| --- | --- | --- |
| `2026-07-28` | Stateless. No `initialize`, no session. Each request carries its version and client capabilities in `_meta`. | `includes/mcp/`, dispatched ahead of the adapter |
| `2025-11-25` | Legacy. `initialize` handshake and `Mcp-Session-Id` sessions. | The bundled MCP Adapter, unchanged |

A request is served under the modern revision **only** when it carries modern per-request `_meta`; everything else reaches the adapter untouched. **Existing users do not need to reconnect** unless their client requires the newer revision.

`server/discover` is implemented and advertises both versions plus the capabilities actually registered on the site. Subscriptions, the tasks extension and logging are deliberately not advertised, WPPilot has no change-notification producer, so `subscriptions/listen` is not implemented.

For OAuth, **Client ID Metadata Documents are the preferred registration mechanism**; RFC 7591 Dynamic Client Registration remains available as a compatibility fallback. Application Passwords and access tokens stay independent fallbacks for clients that run no OAuth flow.

It is a control layer, not an AI wrapper. **No AI model is bundled**: external MCP clients bring their own model access, and policy is enforced server-side on your install.

## What an agent can actually build

One prompt from you becomes hundreds of typed calls from the agent, each checked against your WordPress capabilities and the active safety profile before it runs.

Free covers the core surface: posts and pages, block-editor content, **Elementor documents**, taxonomies, menus and menu locations, media with alt text, users, site settings including the front page, plus design documents, skills and a change ledger with rollback.

**You do not have to phrase anything in a particular way.** A client's first call is discovery, and WPPilot answers with every ability registered on your install plus a catalogue of the skills available for them, carrying one instruction: if a skill matches the request, load its full instructions before starting the work. So "rebuild the pricing page and keep our spacing" already pulls in the right build skill, the element schemas and your existing design tokens without you naming any of it. Write your own prompts; the routing is on the site.

The library is a shortcut, not a dependency. WPPilot ships a **prompt library** in wp-admin for the jobs people run most, and those prompts name the abilities they call rather than describing an outcome and hoping:

```text
Create a new draft page titled "[PAGE TITLE]" using core Gutenberg blocks only.

How the write works, so you do not get halfway and stall:
1. wppilot/create-post, create the draft first. It defaults to draft; leave it there.
2. wppilot/gutenberg-create-pending-batch, core blocks cannot be written straight
   from the server, because the block editor's own JavaScript is what validates and
   serialises them.
3. wppilot/gutenberg-add-pending-change, queue the block tree against the new post.
4. wppilot/gutenberg-enable-batch-finalization, then
   wppilot/gutenberg-get-finalization-url, open that link and the batch commits.
```

That last part is the honest bit: **core blocks are not a headless write**. Anything the block editor validates in JavaScript needs a browser session to finalise, and the prompts say so rather than leaving you stuck at step three.

WPPilot Pro adds plugin-aware modules, page builders, WooCommerce, forms, custom fields, SEO, themes, each with its own ability chains. The full published library is at <https://wppilot.co/prompts>.

- 🌐 Website: <https://wppilot.co>
- 🧱 What it builds: <https://wppilot.co/build>
- 💬 Prompt library: <https://wppilot.co/prompts>
- 📚 Documentation: <https://wppilot.co/docs>
- 🔌 Client setup guides: <https://wppilot.co/wordpress-mcp>
- 𝕏 Release notes and build demos: <https://x.com/WPPilotMCP>

---

## Quick start

1. [**Download `wppilot.zip`**](https://github.com/wppilot-labs/wordpress-mcp-elementor-wppilot/releases/latest/download/wppilot.zip) and install it as `wp-content/plugins/wppilot`, or upload it in **Plugins - Add New - Upload Plugin**.
   *A GitHub “Source code (zip)” download is **not** installable, it lacks `vendor/` and uses the wrong folder name.*
2. Activate WPPilot.
3. Open **WPPilot → Configuration** and leave **Production Safe** selected.
4. Open **WPPilot → Connect**, choose your AI client, and follow the OAuth or Application Password route.

Canonical MCP endpoint:

```text
https://example.com/wp-json/mcp/wppilot
```

OAuth-authenticated clients use `/wp-json/mcp/wppilot-oauth`. Application passwords and access tokens both authenticate on the canonical route. The older `/wp-json/mcp/mcp-adapter-default-server` route still resolves as a legacy alias, but new configurations should use the canonical path above.

## Supported AI clients

Claude Code · Claude Desktop · Claude on the web · Codex CLI · Codex desktop app · Cursor · VS Code · GitHub Copilot · Devin Desktop (formerly Windsurf) · Factory Droid · Antigravity CLI · Antigravity IDE · Zed · Cline · Roo Code · Kilo Code · Amazon Q · OpenCode · OpenClaw · Manus

Per-client setup guides: <https://wppilot.co/wordpress-mcp>

## Authentication

Three methods, chosen on **WPPilot → Connect**:

- **OAuth 2.1 with PKCE** and dynamic client registration. Access tokens last 1 hour, refresh tokens 14 days, and every authorization is listed under **Connected Apps** in WordPress so it can be revoked individually. Recommended wherever the client can open a browser.
- **Application Passwords** as a fallback for clients that cannot run a browser flow. Sent as HTTP Basic.
- **Access tokens** — a long-lived `Authorization: Bearer wpp_…` credential for callers with no browser and no interactive session: the Claude Messages API MCP connector, the OpenAI Responses API `mcp` tool, cron jobs, automation platforms, `curl`. Created with an optional expiry, shown once, stored only as a SHA-256 digest, and revocable per token. It authenticates on the canonical `/wp-json/mcp/wppilot` endpoint, so the URL is the same one every other snippet uses.

An access token borrows the capabilities of the user who created it, and that check is re-run on every request rather than frozen at creation — demoting or deleting the user closes the token in the same moment.

None of the three is a product licence. WPPilot needs no activation key, entitlement check or subscription service to run.

## Safety model

| Profile | What it allows |
| --- | --- |
| **Read Only** | Discovery and inspection. Every state-changing ability is blocked. |
| **Production Safe** | Normal content, design, SEO, forms and commerce work, plus plugin activation and updates with confirmation. Blocks raw PHP, WP-CLI, filesystem, database, plugin/theme installation and deletion, and temporary admin access. |
| **Developer Full Access** | Every enabled ability, including privileged surfaces. Critical calls still require explicit confirmation. |

On top of the profile: WordPress user capabilities still apply, individual abilities can be switched off, destructive operations require an explicit confirmation flag, writes are rate-limited per credential, and supported changes are recorded in a redacted change ledger with rollback.

Every ledger entry names the agent behind the write, not only the WordPress user. Claude Code, Cursor and Codex usually connect as the same administrator, so the user alone cannot answer which of them made a change: the OAuth client id or application-password UUID can, and it is what the ledger records, alongside the client name the agent introduced itself with. A write with no agent behind it — wp-admin, WP-CLI, cron — is recorded as `direct` rather than credited to the last agent seen. OAuth client ids are stored hashed.

## What the free plugin can do

133 registered abilities on a fresh install, plus one MCP prompt per skill you save. The WordPress ones are grouped under a single **WordPress** category in the Abilities screen and can be switched off individually.

| Domain | Abilities | What it covers |
| --- | --- | --- |
| **Content** | `8` | List, search and read posts, pages and public custom post types. Create, update, trash, restore, and permanently delete with explicit confirmation. |
| **Taxonomies** | `7` | Discover taxonomies, list and read terms, create, update, delete with confirmation, and assign terms to content. |
| **Media** | `10` | List, read, import from a URL, search openly-licensed stock, update metadata and alt text, set and clear featured images, attach and detach, delete with confirmation. |
| **Comments** | `6` | List and read with commenter email and IP withheld below `moderate_comments`. Reply, edit, approve, hold, spam, unspam, trash, restore, delete with confirmation. |
| **Menus** | `10` | Create, rename and delete menus, add, update, reorder and remove items, list theme locations and assign menus to them. |
| **Revisions** | `3` | List with autosaves distinguished, read against the live post, and restore. |
| **Users** | `4` | Privacy-minimized reads. Login name, roles and registration date need `list_users`; the email address needs `edit_users`. |
| **Site** | `2` | Site information and an explicit settings allowlist. |
| **Plugins and themes** | `12` | Search the WordPress.org directory and read one extension in detail. Activate, deactivate, update and switch themes with explicit confirmation. Install and delete are Developer Full Access only: they write executable code to the server. |
| **Gutenberg** | `11` | Block-editor content, staged pending changes and browser finalization for native blocks. |
| **Elementor** | `16` | Read a document, inspect the widgets and style properties this install offers, and edit the element tree: add, edit, move, duplicate, reorder and delete. Page settings included. [Detail below.](#elementor-mcp-in-the-free-plugin) |
| **Design system** | `19` | Typed design tokens, saved designs and activation, plus the checks that grade a built page against them: contrast, composition, layout grammars and a rendered-page verification pass. |
| **Preview** | `2` | Compute what a write would change without performing it, then apply the reviewed result. |
| **Skills** | `4` + prompts | Reusable skills and site-wide instructions. Each saved skill also registers one MCP prompt, so this grows with the skills you write. |
| **Changes** | `3` | Read the redacted change ledger, attributed to the agent credential that made each write, and roll a change back. |
| **Diagnostics** | `3` | Scoped health, performance and configuration-security checks. |
| **Developer** | `13` | PHP execution, WP-CLI, filesystem and temporary admin access. Blocked outside Developer Full Access, and excluded entirely from the WordPress.org build. |

Content creation is draft-first: an absent, blank or malformed status resolves to `draft` before any capability check, so nothing is published by accident. Capabilities are read from each post type's and taxonomy's own capability object, so a custom type declaring its own set is enforced on its own terms.

## Elementor MCP in the free plugin

**Since 1.10.0, editing an Elementor page is free.** The Elementor abilities load automatically when Elementor 3.6 or newer is active and stay unregistered otherwise, so an agent is never offered a tool that cannot work on this site.

Writing generated HTML into `post_content` does not change an Elementor page. The layout lives in postmeta as a typed element tree, and Elementor renders from that and ignores the markup. These abilities work on the tree itself.

| Ability | What it does |
| --- | --- |
| `wppilot/elementor-check-setup` | Elementor and Elementor Pro versions, whether the v4 atomic runtime, the style schema, global classes, variables and interactions are available on this install. The call an agent should make first. |
| `wppilot/elementor-get-schema` | Discover widgets, or describe named ones in compact form: which controls exist, their types and their allowed values. Filterable by category, by name and by whether a widget is atomic. |
| `wppilot/elementor-get-style-schema` | The 73 style properties Elementor's atomic engine accepts, with the value shape each one takes. |
| `wppilot/elementor-get-widget-params` | The parameters one widget accepts, without reading its whole schema. |
| `wppilot/elementor-get-content` | Read the element tree of a document, as structure or in full. |
| `wppilot/elementor-find-elements` | Locate elements by type, by widget, by id or by the text they contain. |
| `wppilot/elementor-set-content` | Replace a document's tree in one call. Invalid properties are dropped and reported rather than failing the whole page; pass `strict` to refuse instead. |
| `wppilot/elementor-add-element` | Insert a widget, a container or a whole subtree at a position you choose, with settings and per-element styles validated against the schema first. |
| `wppilot/elementor-edit-element` | Change one element's settings or styles in place, leaving the page around it untouched. |
| `wppilot/elementor-move-element` | Move an element to a new parent or a new position. |
| `wppilot/elementor-duplicate-element` | Copy an element, with fresh ids throughout its subtree. |
| `wppilot/elementor-reorder-children` | Reorder a container's children in one call. |
| `wppilot/elementor-delete-element` | Remove an element and its subtree. |
| `wppilot/elementor-get-page-settings` · `set-page-settings` | Read and write document-level settings: page layout, title visibility, background, and the rest. |
| `wppilot/elementor-clear-document-cache` | Regenerate Elementor's CSS for a document after a write. |

Both element models are supported: Elementor v4 atomic elements (`e-div-block`, `e-heading`, `e-paragraph`, `e-button`, `e-image` and the rest, with the atomic style schema) and classic v3 widgets and containers, with the settings keys each one actually uses.

### What Pro adds on top

The free abilities are the primitives, and they compose. What Pro adds is the authoring layer above them, plus everything Elementor keeps outside a single document:

**Composition** — `elementor-build-page` builds a whole page from one compact description instead of a dozen round trips; `elementor-compile-spec` and `elementor-build-from-spec` turn a reproduction spec into global classes and a matching tree. **Reuse** — templates, theme parts and display conditions, popups, global classes, v4 variables and v3 global colours and typography. **Content** — Elementor Pro forms and submissions, dynamic tags, interactions, SVG upload, stock-image placement, and site-wide custom code.

The dividing line is simple: free can **edit** an Elementor page, Pro can **compose** one.

## WPPilot Pro, 1,042 plugin-aware abilities

The free plugin in this repository is a complete WordPress MCP server: connection, authentication, safety profiles, Gutenberg workflows, **Elementor editing**, the design system, diagnostics, change evidence and **133 abilities**, including the whole WordPress core surface: content, taxonomies, media, comments, revisions, menus, user reads, allowlisted settings and the plugin/theme lifecycle. Free needs no licence, entitlement service or Pro install.

[**WPPilot Pro**](https://wppilot.co/pro) adds **plugin-aware abilities across 51 integrations**, typed operations that understand each plugin's own data model rather than writing generic content. Modules load only when their plugin is detected, and each loads in isolation, so a missing or broken plugin cannot stop the rest of the registry from registering.

| Category | Integrations · `ability count` |
| --- | --- |
| **Page builders** | [Elementor](https://wppilot.co/integrations/elementor) `51` · [Bricks](https://wppilot.co/integrations/bricks) `49` · [Breakdance](https://wppilot.co/integrations/breakdance) `33` · [Divi](https://wppilot.co/integrations/divi) `47` · [Oxygen](https://wppilot.co/integrations/oxygen) `37` · [Beaver Builder](https://wppilot.co/integrations/beaver-builder) `21` · [WPBakery](https://wppilot.co/integrations/wpbakery) `18` · [Etch](https://wppilot.co/integrations/etch) `60` · [Mosaic](https://wppilot.co/integrations/mosaic) `41` · [Flatsome UX Builder](https://wppilot.co/integrations/flatsome) `11` |
| **Blocks and site design** | [GenerateBlocks](https://wppilot.co/integrations/generateblocks) `3` · [Kadence Blocks](https://wppilot.co/integrations/kadenceblocks) `3` · [Spectra](https://wppilot.co/integrations/spectra) `20` · [Spectra One](https://wppilot.co/integrations/spectra-one) `22` |
| **Themes** | [Astra](https://wppilot.co/integrations/astra) `34` · [Avada](https://wppilot.co/integrations/avada) `16` · [GeneratePress](https://wppilot.co/integrations/generatepress) `23` · [Kadence](https://wppilot.co/integrations/kadence) `5` · [OceanWP](https://wppilot.co/integrations/oceanwp) `15` · [WordPress Block Themes](https://wppilot.co/integrations/block-themes) `4` · [Blocksy](https://wppilot.co/integrations/blocksy) `4` · [Neve](https://wppilot.co/integrations/neve) `4` · [WoodMart](https://wppilot.co/integrations/woodmart) `4` |
| **Commerce** | [WooCommerce](https://wppilot.co/integrations/woocommerce) `35` |
| **Forms** | [WPForms](https://wppilot.co/integrations/wpforms) `28` · [Gravity Forms](https://wppilot.co/integrations/gravityforms) `28` · [Fluent Forms](https://wppilot.co/integrations/fluentforms) `37` · [Formidable Forms](https://wppilot.co/integrations/formidable) `39` · [Contact Form 7](https://wppilot.co/integrations/contact-form-7) `9` · [Ninja Forms](https://wppilot.co/integrations/ninja-forms) `21` |
| **SEO suites** | [AIOSEO](https://wppilot.co/integrations/aioseo) `12` · [Rank Math](https://wppilot.co/integrations/rank-math) `8` · [SEOPress](https://wppilot.co/integrations/seopress) `16` · [Yoast SEO](https://wppilot.co/integrations/yoast) `10` |
| **Custom data** | [Advanced Custom Fields](https://wppilot.co/integrations/acf) `23` · [ACPT](https://wppilot.co/integrations/acpt) `24` · [Admin and Site Enhancements](https://wppilot.co/integrations/ase) `18` · [JetEngine](https://wppilot.co/integrations/jetengine) `26` · [Meta Box](https://wppilot.co/integrations/meta-box) `32` · [Pods](https://wppilot.co/integrations/pods) `25` · [Dynamic Shortcodes](https://wppilot.co/integrations/dynamic-shortcodes) `9` |
| **Localization** | [Weglot](https://wppilot.co/integrations/weglot) `19` · [WPML](https://wppilot.co/integrations/wpml) `8` · [Polylang](https://wppilot.co/integrations/polylang) `6` |
| **Site operations** | [The Events Calendar](https://wppilot.co/integrations/the-events-calendar) `7` · [Paid Memberships Pro](https://wppilot.co/integrations/paid-memberships-pro) `5` · [Tutor LMS](https://wppilot.co/integrations/tutor-lms) `7` · [BuddyPress](https://wppilot.co/integrations/buddypress) `8` |
| **Developer tools** | [Code Snippets](https://wppilot.co/integrations/code-snippets) `11` · [Bricksforge](https://wppilot.co/integrations/bricksforge) `21` |
| **WordPress platform** | [WordPress extras](https://wppilot.co/integrations/wordpress) `23` · [Brand Kit](https://wppilot.co/integrations/brand-kit) `8` · [Agent Memory](https://wppilot.co/integrations/memory) `4` · [WPPilot Skills](https://wppilot.co/integrations/skills) `1` |

### Why plugin-aware matters

A page builder does not store a page as HTML. It stores an element tree, references to shared classes and design tokens, template rules and dynamic bindings. Writing generated markup into that store is how a layout stops opening in its own editor.

Pro gives the agent that builder's own vocabulary: `bricks-patch-elements`, `elementor-create-atomic-widget`, `divi-apply-global-preset`, `etch-get-query-preview`, so it can read a schema before it proposes a change.

### Page builder MCP servers

One endpoint covers every builder below. The agent gets that builder's own
vocabulary rather than being handed raw HTML to guess at.

| Builder | Abilities | Free / Pro | Guide |
| --- | --- | --- | --- |
| **Elementor** | **67** | **16 free** · 51 Pro | [MCP for Elementor](https://wppilot.co/mcp-for-elementor) |
| Etch | 60 | Pro | [MCP for Etch](https://wppilot.co/mcp-for-etch) |
| Bricks | 49 | Pro | [MCP for Bricks](https://wppilot.co/mcp-for-bricks) |
| Divi | 47 | Pro | [MCP for Divi](https://wppilot.co/mcp-for-divi) |
| Mosaic | 41 | Pro | [MCP for Mosaic](https://wppilot.co/mcp-for-mosaic) |
| Oxygen | 37 | Pro | [MCP for Oxygen](https://wppilot.co/mcp-for-oxygen) |
| Breakdance | 33 | Pro | [MCP for Breakdance](https://wppilot.co/mcp-for-breakdance) |
| Beaver Builder | 21 | Pro | [MCP for Beaver Builder](https://wppilot.co/mcp-for-beaver-builder) |
| WPBakery | 18 | Pro | [MCP for WPBakery](https://wppilot.co/mcp-for-wpbakery) |
| Flatsome UX Builder | 11 | Pro | [All integrations](https://wppilot.co/integrations) |

#### Elementor MCP

**Elementor is the one builder whose editing surface is free.** The 16 abilities
in this repository read the document, report the widgets and the 73 style
properties your install actually offers, and add, edit, move, duplicate, reorder
and delete elements in the tree — v4 atomic elements and classic v3 widgets
alike. See [Elementor MCP in the free plugin](#elementor-mcp-in-the-free-plugin)
for the full list.

Pro's 51 add the authoring layer on the same endpoint: whole-page composition
from a description or a reproduction spec, templates and theme parts, display
conditions, popups, forms and submissions, dynamic tags, interactions, global
classes, v4 variables and v3 global colours and typography.

Writing generated markup into `post_content` does not change an Elementor page —
the layout lives in postmeta, and WPPilot refuses that write by name rather than
reporting a success that changes nothing.

#### Bricks MCP

Patches the Bricks element tree in place with `bricks-patch-elements`, so a
change to one section does not rewrite the page around it.

#### Divi MCP

Works with Divi modules and global presets through `divi-apply-global-preset`,
rather than flattening a layout into shortcodes.

#### Beaver Builder MCP

Beaver Builder keeps its layout in postmeta under `_fl_builder_data`, so writing
HTML into `post_content` changes nothing a visitor sees. Pro reads and writes
that store; the free plugin recognises the builder and refuses the write rather
than reporting a success that does nothing.

#### Oxygen MCP

Oxygen stores a JSON element tree in postmeta and renders from it, ignoring
`post_content` entirely. Pro's 37 abilities work against that tree.

#### WPBakery MCP

WPBakery is the exception that keeps its layout in `post_content`, as nested
shortcodes. Pro parses and edits those shortcodes instead of replacing the
markup around them.

#### Breakdance, Etch and Mosaic MCP

Breakdance (33 abilities), Etch (60) and Mosaic (36) each expose their own
element model. Etch has the largest surface of any builder in Pro.

#### Gutenberg MCP

Block editing is in the **free** plugin, not Pro: parse, insert, move and
replace blocks in the core block tree, with reusable blocks and patterns.

#### WooCommerce MCP

Products, variations, orders, coupons and stock as typed abilities rather than
raw REST calls. An agent can query the catalogue, create a variable product with
its attributes and variations, adjust stock, read orders and add order notes —
each one capability-checked against the connected WordPress user, so an agent
connected as a shop manager cannot do what that account could not do by hand.

Order status changes and anything touching money are classed destructive, so
they require explicit confirmation and are recorded in the change ledger with
rollback, the same as every other write.

### Beyond integrations

- **Persistent agent memory**, approved context that carries between sessions, so an agent does not relearn your stack every conversation.
- **Human approval queue**, holds an agent write until a person approves it, with email notification. The agent receives a structured “pending” response, not a false success.
- **Integration health reporting**: see which modules loaded, which were skipped, and why.
- **Plugin-aware skill packs**, guided sequences that encode the read-before-write workflow for the plugins you run.

[Compare Free vs Pro](https://wppilot.co/free-vs-pro) · [Pricing](https://wppilot.co/pricing) · [All integrations](https://wppilot.co/integrations)

> WPPilot Pro is a commercial plugin and is not distributed from this repository.

## FAQ

**What is a WordPress MCP server, and is this one a server or a client?**
A server. Your site exposes typed WordPress abilities over MCP at `https://example.com/wp-json/mcp/wppilot`, and the AI client you already use connects to it. No model is bundled and no key is stored here; the client brings its own model access.

**Is there an Elementor MCP server?**
Yes, and editing is free. The plugin in this repository registers 16 Elementor abilities as soon as Elementor 3.6 or newer is active: read the document, inspect the widget and style schemas, and add, edit, move, duplicate, reorder and delete elements in the tree, plus page settings. No licence and no Pro install. [WPPilot Pro](https://wppilot.co/pro) adds 51 more for the authoring layer — whole-page composition, templates and theme parts, popups, forms, dynamic tags, global classes and variables. Free can edit an Elementor page; Pro can compose one.

**What about Bricks, Divi, Beaver Builder, Oxygen, Breakdance, WPBakery, Etch, Mosaic and Flatsome?**
Those are [WPPilot Pro](https://wppilot.co/pro), which registers builder-aware abilities on the same endpoint. A page builder stores an element tree, shared classes, design tokens and dynamic bindings rather than HTML, so Pro gives the agent that builder's own vocabulary instead of writing markup into a store that will not open in its editor.

**Is there a WooCommerce MCP server?**
Yes, in [WPPilot Pro](https://wppilot.co/pro). Products, variations, orders, coupons and stock become typed abilities on the same endpoint, capability-checked against the connected WordPress user — an agent connected as a shop manager cannot do what that account could not do by hand. Anything touching money is classed destructive, so it needs explicit confirmation and lands in the change ledger with rollback.

**Do I need Pro to use this?**
No. The free plugin in this repository is a complete WordPress MCP server with 133 abilities — including Elementor editing and the design system — and it needs no licence, activation key or entitlement service. Pro is additive.

**Can an agent build an Elementor page with the free plugin?**
It can build one element at a time, which is what `elementor-add-element`, `elementor-edit-element` and `elementor-set-content` are for, and the design system in free gives it the palette, the type and spacing ladders and the compositions to build against. The single-call whole-page builders, `elementor-build-page` and `elementor-build-from-spec`, are Pro.

**How does it relate to the WordPress Abilities API and the official MCP Adapter?**
It is built on both. The Abilities API is where abilities are registered, and the MCP Adapter is bundled to serve the legacy protocol revision. WPPilot adds the parts an adapter does not: authentication, safety profiles, confirmation gates, rate limiting, a change ledger with rollback, and the abilities themselves.

**How do I connect WordPress to Claude, Cursor or Codex?**
Claude Code, Claude Desktop, Claude on the web, Codex CLI and desktop, Cursor, VS Code, GitHub Copilot, Devin Desktop, Factory Droid, Antigravity CLI and IDE, Zed, Cline, Roo Code, Kilo Code, Amazon Q, OpenCode, OpenClaw and Manus. Setup guides: <https://wppilot.co/wordpress-mcp>.

**Is it safe to point an agent at a production site?**
That is what the safety model is for. Read Only blocks every write, Production Safe blocks raw PHP, WP-CLI, filesystem, database and extension installation, destructive calls require an explicit confirmation flag, WordPress capabilities still apply on top, and supported changes are recorded in a ledger you can roll back. Start on Read Only and verify one call before enabling any write.

**Is there a Gutenberg MCP server — can an agent write native blocks?**
Partly, and the plugin says so rather than failing silently. Core blocks are validated and serialised by the block editor's own JavaScript, so writes are staged as a pending batch and finalised through a browser session.

**Is it on WordPress.org?**
No. Distribution is GitHub releases for the free plugin and wppilot.co for Pro. Install `wppilot.zip` from [Releases](https://github.com/wppilot-labs/wordpress-mcp-elementor-wppilot/releases); the GitHub "Source code" archive is not installable.

## Requirements

- WordPress 6.9 or newer
- PHP 8.0 or newer
- HTTPS for any remotely reachable connection
- The Elementor abilities require Elementor 3.6 or newer, and register only when it is active. Elementor 4.0 or newer additionally unlocks the atomic style schema and global classes. Elementor Pro is not required for anything in the free plugin.
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

Report suspected vulnerabilities privately, see [SECURITY.md](SECURITY.md). Do not open a public issue for a vulnerability, and never include production credentials or customer data.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE) and [`LICENSES/`](LICENSES) for the full SPDX texts.
