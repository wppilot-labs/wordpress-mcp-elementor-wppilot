<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Telemetry;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/send.php';
require_once __DIR__ . '/notice.php';

/**
 * Wire up anonymous usage reporting.
 *
 * This entire directory is deleted from the wordpress.org build by
 * scripts/package.sh, and wppilot.php guards the require with file_exists() so
 * that removal is safe — the same arrangement includes/updater.php uses, for
 * the same reason. The directory's guidelines require reporting to be opt-in;
 * making the .org build physically incapable of it satisfies that by
 * construction rather than by a runtime flag someone could get wrong.
 */

add_action(CRON_HOOK, __NAMESPACE__ . '\\send');
add_action('admin_notices', __NAMESPACE__ . '\\render_notice');
add_action('admin_post_' . ACTION, __NAMESPACE__ . '\\handle_choice');
add_filter('wppilot_settings_sections', __NAMESPACE__ . '\\register_setting');

/**
 * Repair a schedule that went missing.
 *
 * Activation schedules the event, but an install that updates into this version
 * never runs activation, and a site can lose its cron entries to a database
 * restore or a migration plugin. Checked on admin requests only — this must not
 * cost a front-end page view anything.
 */
add_action('admin_init', static function (): void {
    if (enabled()) {
        schedule();
    }
});
