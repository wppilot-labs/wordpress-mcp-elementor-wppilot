<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\ScheduledAudits;

use WP_Error;
use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The hooks a routine needs on every request, including the WP-Cron request that runs it.
 *
 * The kit's boot calls it, so the host must boot this kit on every request, not only when
 * abilities are first asked for: a cron request never asks for one on its own, and the tick would
 * fire with nothing listening. Idempotent, for a host that also calls it earlier.
 */
function register_hooks(Host $host): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    add_action(HOOK, __NAMESPACE__ . '\\tick', 10, 1);
    add_action(RECONCILE_HOOK, __NAMESPACE__ . '\\reconcile', 10, 0);

    // Every write to the definitions reschedules, however it was made: this kit's abilities, or
    // an undo from the change log, which restores the option directly.
    $changed = static function (): void {
        reconcile();
    };
    add_action('add_option_' . OPTION, $changed, 10, 0);
    add_action('update_option_' . OPTION, $changed, 10, 0);
    add_action('delete_option_' . OPTION, $changed, 10, 0);

    $host->ledger()->register_strategy(STRATEGY, static fn(array $payload): array|WP_Error => restore($payload));
}
