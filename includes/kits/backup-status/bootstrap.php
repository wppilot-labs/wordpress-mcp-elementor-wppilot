<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BackupStatus;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * backup-status: read the site's backups from UpdraftPlus, Duplicator 5 and BackWPup.
 *
 * Returned to the kit loader, which registers the ability files inside wp_abilities_api_init.
 * Skipped when none of the three is active, with the same test WPPilot Pro's backups kit is gated
 * on; which of them answers is decided per call (active_providers()), so one ability file serves
 * any mix.
 */
if (
    !class_exists('UpdraftPlus')
    && !defined('UPDRAFTPLUS_DIR')
    && !class_exists('BackWPup')
    && !class_exists('Duplicator\\Package\\DupPackage')
) {
    return ['skip' => 'no supported backup plugin (UpdraftPlus, Duplicator 5, BackWPup) is active'];
}

require_once __DIR__ . '/src/providers.php';
require_once __DIR__ . '/src/status.php';

return [
    'ability_files' => [
        __DIR__ . '/src/abilities/backup-status.php',
        __DIR__ . '/src/abilities/backup-list.php',
    ],
];
