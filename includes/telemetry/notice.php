<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Telemetry;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The first-run disclosure.
 *
 * Reporting is on by default, so this notice is what makes that honest. It
 * appears once, before the first report can fire — the schedule is jittered by
 * one to seven hours precisely so an administrator who reads this and switches
 * reporting off in their first session is never counted at all.
 *
 * The first sentence says the site's address is sent. Burying that below a
 * fold, or behind a "learn more", would be the kind of disclosure that is
 * technically present and practically absent.
 */

const ACTION = 'wppilot_telemetry_choice';
const NONCE = 'wppilot_telemetry_choice';

/** Whether the operator has already answered, or dismissed the question. */
function notice_answered(): bool
{
    return decided() || get_option(OPTION_NOTICE_ACK, default_value: false) === true;
}

/**
 * Show the notice.
 *
 * Site-level, not per-user: whether this site reports is a property of the
 * site, so storing the answer in user meta would ask the next administrator the
 * same question and let two of them disagree.
 */
function render_notice(): void
{
    if (!current_user_can('manage_options') || notice_answered()) {
        return;
    }

    $keep = wp_nonce_url(
        admin_url('admin-post.php?action=' . ACTION . '&choice=on'),
        NONCE,
    );
    $stop = wp_nonce_url(
        admin_url('admin-post.php?action=' . ACTION . '&choice=off'),
        NONCE,
    );

    ?>
    <div class="notice notice-info">
        <p>
            <strong><?php esc_html_e('WPPilot sends anonymous usage data, including this site\'s address.', domain: 'wppilot'); ?></strong>
        </p>
        <p>
            <?php esc_html_e(
                'Once a day it reports the site URL, the WPPilot, WordPress and PHP versions, your locale, whether Pro is active, your safety profile, and how many connections exist. It never sends usernames, email addresses, page or post content, or any record of what an agent did.',
                domain: 'wppilot',
            ); ?>
        </p>
        <p>
            <a href="<?php echo esc_url($keep); ?>" class="button button-primary"><?php esc_html_e('Keep it on', domain: 'wppilot'); ?></a>
            <a href="<?php echo esc_url($stop); ?>" class="button"><?php esc_html_e('Turn it off', domain: 'wppilot'); ?></a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wppilot-settings#wppilot-telemetry')); ?>"><?php esc_html_e('What is sent?', domain: 'wppilot'); ?></a>
        </p>
    </div>
    <?php
}

/**
 * Record the answer from the notice.
 *
 * Either button is an answer, so both write the option — including "keep it
 * on", which stores the same value the default already implies. That is the
 * point: once stored, the choice survives reactivation, and no later change to
 * the default can quietly flip a site that already decided.
 */
function handle_choice(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to change this setting.', domain: 'wppilot'), response: 403);
    }

    check_admin_referer(NONCE);

    $choice = isset($_GET['choice']) && is_string($_GET['choice']) ? sanitize_key(wp_unslash($_GET['choice'])) : '';

    set_enabled($choice === 'on');
    update_option(OPTION_NOTICE_ACK, true, autoload: true);

    wp_safe_redirect(wp_get_referer() ?: admin_url());
    exit();
}
