# Safety profiles

## Production Safe

This is the installation default. It permits ordinary content, design, SEO, form, and commerce operations while blocking critical primitives such as raw PHP, code-snippet and privileged dynamic-shortcode engines, raw database access, WP-CLI, filesystem access, plugin/theme installation, and temporary administrator access.

Destructive operations that are otherwise allowed require explicit confirmation. Examples include permanent deletion and refunds.

## Read Only

Only abilities marked readonly, plus MCP resources and prompts, can execute. Use this profile for audits, discovery, reporting, and support sessions that must not mutate the site.

## Developer Full Access

All manually enabled abilities can execute, including critical developer primitives. Critical and destructive calls still require explicit confirmation. Use this profile only for deliberate development or recovery work with current backups and appropriate site access controls.

## Confirmation contract

For an MCP adapter call, confirmation belongs inside the target parameters:

```json
{
  "ability_name": "wppilot/woocommerce-create-refund",
  "parameters": {
    "order_id": 123,
    "amount": "10.00",
    "refund_payment": false,
    "confirm": true
  }
}
```

WPPilot removes the control-only `confirm` field before target schema validation unless the target ability explicitly declares its own field with that name.

## Change ledger

The ledger retains at most 500 records and is also capped by total serialized size. Secrets and sensitive metadata are redacted or excluded. Before images are bounded. Rollback is offered only for supported operations and succeeds only when the observed result matches the expected fingerprint.

Permanent deletion, payment refunds, and other irreversible external side effects are recorded as non-reversible.
