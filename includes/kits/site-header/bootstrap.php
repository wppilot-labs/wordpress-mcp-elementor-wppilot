<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteHeader;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/src/functions.php';

/**
 * site-header: the site-wide header from a proven layout, checked on the served page.
 *
 * The undo strategy is registered at boot (an undo can arrive on a request that never registers
 * abilities), and so is the swap to a translated header, which runs on every front-end page.
 */
return [
    'ability_files' => [__DIR__ . '/src/abilities/build-site-header.php'],
    'boot' => static function (Host $host): void {
        register_ledger($host->ledger());
        add_filter('elementor/theme/get_location_templates/template_id', __NAMESPACE__ . '\\translated_template');
    },
];
