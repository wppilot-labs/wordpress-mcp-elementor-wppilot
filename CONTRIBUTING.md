# Contributing to WPPilot

WPPilot is the free WordPress MCP server in this repository. WPPilot Pro, which
adds the Elementor MCP, WooCommerce MCP and other builder-aware layers, is a
commercial plugin and is not developed here — but bug reports about how Pro
behaves against this plugin are welcome, because the seam between them is where
most real problems live.

## Before opening an issue

Run the built-in diagnostics first: **WPPilot → Diagnostics** in wp-admin runs
the same checks an AI client does and usually names the problem outright.

Include the plugin, WordPress and PHP versions, the safety profile, the AI
client you connected with, and the exact error text. "It does not work" is not
reproducible; the literal message almost always is.

**Never paste a password, an application password, an API key, a licence key or
an unredacted database export into an issue.** They are public and permanent. If
you have already done it, rotate the credential — deleting the comment does not
remove it from the history.

## Security

Do not open a public issue for a vulnerability. Email
<security@wppilot.co>, which reaches the same people through a channel meant for
disclosure. See [SECURITY.md](SECURITY.md).

## Pull requests

- Match the surrounding code. This codebase is typed PHP with named arguments
  and explicit return types; comments explain *why*, not *what*.
- Every ability is capability-checked and classified by risk. A new one that
  skips either is not mergeable, however useful it is.
- Run the unit suite before pushing. It covers the safety invariants, and those
  are the ones a change is most likely to break without noticing.
- One change per pull request. A refactor bundled with a fix makes the fix
  impossible to review or revert on its own.

## Running the tests

Development dependencies are not vendored, so install them into a scratch copy
rather than polluting the tracked `vendor/` directory:

```bash
composer install
composer dump-autoload --dev
php vendor/bin/phpunit --no-coverage
```

## What is likely to be accepted

Bug fixes with a failing test. Compatibility work for a WordPress or PHP
release. Better refusal messages — an ability that declines a call should say
which one to use instead.

## What is unlikely

Abilities that bypass the safety profile, anything that stores a credential,
and new page-builder integrations, which belong in Pro.
