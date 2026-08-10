<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * WPPilot Configuration page.
 *
 * Walks the site owner from "AI Abilities are off" to a connected MCP client.
 * The steps are split across this directory:
 *
 *   abilities-toggle.php  step 1 — turning AI Abilities on, and the production warning
 *   passwords.php         the Application Password connection method
 *   oauth-panel.php       the OAuth connection method
 *   client-configs.php    generated config for each supported client
 *   page.php              the top-level render and shared sections
 */

if (!defined('ABSPATH')) {
    exit();
}

require_once dirname(__DIR__) . '/connect-methods.php';

require_once __DIR__ . '/abilities-toggle.php';
require_once __DIR__ . '/passwords.php';
require_once __DIR__ . '/oauth-panel.php';
require_once __DIR__ . '/client-configs.php';
require_once __DIR__ . '/page.php';
