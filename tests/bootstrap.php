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

require_once __DIR__ . '/doubles/wordpress.php';

// Load the whole WordPress-core module, not just its helpers: registration
// happens at file scope, so requiring every file is what proves the surface
// comes up with Free alone — no Pro class, licence check, or entitlement call.
require_once dirname(__DIR__) . '/includes/abilities/wordpress/bootstrap.php';
wppilot_load_wordpress_abilities();

// Registrations captured during load are the baseline the suite asserts against.
// Snapshot them before any test calls WPPilot_Test_State::reset().
define('WPPILOT_TEST_BOOT_ABILITIES', WPPilot_Test_State::$registered_abilities);
