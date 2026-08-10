# Architecture

## Request path

1. WordPress registers typed abilities through `wp_register_ability()`.
2. The official MCP Adapter exposes the default HTTP server at `/wp-json/mcp/mcp-adapter-default-server`.
3. An authenticated client discovers public abilities, reads one ability's schema, and executes it through the adapter's compact meta-tool.
4. WPPilot applies the manual ability policy and active safety profile before execution.
5. The target ability performs its WordPress capability check and validates its schema.
6. The change ledger records a redacted before/after fingerprint for supported state changes.

The compact adapter surface keeps client context small while retaining typed schemas for every target operation.

## Base plugin boundaries

- `includes/abilities/`: built-in WordPress and developer abilities.
- `includes/safety.php`: profiles, risk classification, and explicit-confirmation enforcement.
- `includes/change-log.php`: bounded audit records, redaction, fingerprints, and verified rollback.
- `includes/rest/transport-hardening.php`: MCP/REST host validation and response security headers.
- `includes/oauth/`: OAuth authorization server, token repositories, discovery, and connected-app management.
- `includes/abilities/diagnostics.php`: scoped health, performance, and configuration-security checks.
- `includes/abilities/bootstrap.php`: ability categories and loaders.

## Pro boundaries

WPPilot Pro registers specializations only when their third-party dependency is available. Every manifest gate, bootstrap, category registration, and loader is isolated with per-integration health reporting. An error in one integration must not prevent unrelated abilities from registering.

Typed WordPress and WooCommerce operations use public platform APIs. WooCommerce order reads use `wc_get_orders()` for HPOS compatibility. Refund payment and inventory restocking are opt-in and destructive dispatch requires explicit confirmation.

## Extension rules

- Prefer a typed ability over raw PHP, filesystem, database, or WP-CLI access.
- Use the narrowest WordPress capability that matches the operation.
- Set `meta.mcp.public` only for operations intended for remote discovery.
- Mark readonly, destructive, and idempotent behavior accurately.
- Bound pagination, recursion, payload size, and result size.
- Do not turn diagnostics into unsupported malware, compliance, accounting, or uptime claims.
- Use public WordPress/WooCommerce APIs so storage implementations such as WooCommerce HPOS remain supported.
