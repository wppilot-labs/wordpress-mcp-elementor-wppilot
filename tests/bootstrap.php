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
require_once dirname(__DIR__) . '/includes/abilities/wordpress/helpers.php';
