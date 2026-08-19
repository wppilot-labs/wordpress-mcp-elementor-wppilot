<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Unit-test bootstrap.
 *
 * These are unit tests, not WordPress integration tests: they exercise WPPilot's
 * own decision logic (status resolution, post-type exposure, capability gating)
 * against WordPress function doubles rather than a live install. That keeps the
 * safety invariants under test on any machine with PHP, with no database.
 *
 * Anything that needs real WordPress behaviour — wp_insert_post(), the ledger's
 * before/after hooks, the REST surface — is out of scope here and belongs in a
 * WordPress integration suite.
 */

define('ABSPATH', __DIR__ . '/');

// The plugin-file constants the real plugin defines at load. The extension
// abilities read them to refuse deactivating or deleting WPPilot itself, so the
// suite needs them to exercise that refusal.
define('WPPILOT_PLUGIN_FILE', ABSPATH . 'wp-content/plugins/wppilot/wppilot.php');
define('WPPILOT_PRO_FILE', ABSPATH . 'wp-content/plugins/wppilot-pro/wppilot-pro.php');

require_once __DIR__ . '/doubles/wordpress.php';

// Load the whole WordPress-core module, not just its helpers: registration
// happens at file scope, so requiring every file is what proves the surface
// comes up with Free alone — no Pro class, licence check, or entitlement call.
require_once dirname(__DIR__) . '/includes/abilities/wordpress/bootstrap.php';
wppilot_load_wordpress_abilities();

// The safety layer registers nothing at file scope and reaches WordPress only
// from inside its functions, so it loads here for its risk classification: the
// MCP transport asks it which tools must be confirmed before it will run them,
// and that answer decides what the tool list advertises.
require_once dirname(__DIR__) . '/includes/safety.php';

// The MCP protocol layer is deliberately free of WordPress dependencies beyond
// the ABSPATH guard, so it loads and is exercised here directly.
foreach (['protocol', 'errors', 'headers', 'results', 'discover', 'transport'] as $module) {
    require_once dirname(__DIR__) . '/includes/mcp/' . $module . '.php';
}

// The preview differ is pure — no WordPress state, no options, no registry — so
// it loads on its own. That is the point of keeping it separate from the
// projectors, which do read WordPress.
require_once dirname(__DIR__) . '/includes/preview/diff.php';

require_once dirname(__DIR__) . '/includes/oauth/client-id-metadata.php';

// The access-token module and the Bearer middleware. Both touch WordPress only
// from inside their functions, so the parts under test here — credential shape,
// digesting, and the route boundary a token identity is allowed to cross — run
// without a database. Storage itself is integration territory.
require_once dirname(__DIR__) . '/includes/tokens.php';
require_once dirname(__DIR__) . '/includes/oauth/middleware.php';

// The change ledger registers nothing at file scope, so it loads here purely for
// its before-image capture: that code calls WordPress functions taking arguments
// by reference, and only a real call proves the call sites still satisfy them.
require_once dirname(__DIR__) . '/includes/change-log.php';

// Registrations captured during load are the baseline the suite asserts against.
// Snapshot them before any test calls WPPilot_Test_State::reset(). The full
// registration arguments are kept alongside the names so a test can assert on
// the annotations a profile decision is made from.
define('WPPILOT_TEST_BOOT_ABILITIES', WPPilot_Test_State::$registered_abilities);
define('WPPILOT_TEST_BOOT_REGISTRATIONS', WPPilot_Test_State::$registrations);
