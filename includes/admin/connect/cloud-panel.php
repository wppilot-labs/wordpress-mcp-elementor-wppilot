<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reads only: outcome codes and a pairing state set by this plugin's own redirects, each whitelist-compared or validated before use. Every action on this panel posts to admin-post.php with its own nonce.

/**
 * The WPPilot Cloud method card's panel.
 *
 * Three states: not connected (a Connect button), a pairing waiting for the
 * administrator to confirm what the Cloud asked for (§2), and connected. The
 * work happens in includes/cloud/pairing.php; this only renders it.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Whether the page should open on the Cloud card: a pairing step just
 * redirected back here.
 */
function wppilot_cloud_method_preselected(): bool
{
    return isset($_GET['wppilot_cloud_state']) || isset($_GET['wppilot_cloud_result']) || isset($_GET['wppilot_cloud_error']);
}

/**
 * The sentence for an error code a pairing step redirected with.
 */
function wppilot_cloud_error_message(string $code): string
{
    return match ($code) {
        'expired' => __('This connection request expired or was started by another user. Start again.', domain: 'wppilot'),
        'already_connected' => __('This site is already connected to WPPilot Cloud. Disconnect it first.', domain: 'wppilot'),
        'unavailable' => __('WPPilot Cloud cannot be used from this site. It needs HTTPS (or a local environment), and the configured WPPilot Cloud address must be HTTPS outside a local or development environment.', domain: 'wppilot'),
        'abilities_off' => __('Turn AI Abilities on before connecting WPPilot Cloud: it checks the connection works before it finishes.', domain: 'wppilot'),
        'declined' => __('The connection was cancelled on WPPilot Cloud. Nothing was changed on this site.', domain: 'wppilot'),
        'network' => __('This site could not reach WPPilot Cloud. Nothing was changed; try again in a moment.', domain: 'wppilot'),
        'policy' => __('WPPilot Cloud did not accept this connection request. Start again from this screen.', domain: 'wppilot'),
        'complete' => __('WPPilot Cloud could not finish connecting this site, so the access token created for it was revoked. WPPilot Cloud has to reach this site over the internet to finish.', domain: 'wppilot'),
        'invalid_response' => __('WPPilot Cloud sent an answer this site could not use. Nothing was changed.', domain: 'wppilot'),
        'token' => __('The access token for WPPilot Cloud could not be created.', domain: 'wppilot'),
        'keys' => __('This site cannot create the signing key WPPilot Cloud needs: PHP\'s sodium extension is unavailable.', domain: 'wppilot'),
        default => __('Connecting to WPPilot Cloud failed.', domain: 'wppilot'),
    };
}

/**
 * Render the panel under the WPPilot Cloud card.
 */
// Inherent: a template reading four request values and choosing one of three states.
// @mago-expect lint:cyclomatic-complexity
function wppilot_render_cloud_panel(): void
{
    $result = is_string($_GET['wppilot_cloud_result'] ?? null) ? sanitize_key(wp_unslash($_GET['wppilot_cloud_result'])) : '';
    $error = is_string($_GET['wppilot_cloud_error'] ?? null) ? sanitize_key(wp_unslash($_GET['wppilot_cloud_error'])) : '';
    $detail = is_string($_GET['wppilot_cloud_detail'] ?? null) ? sanitize_key(wp_unslash($_GET['wppilot_cloud_detail'])) : '';
    $state = is_string($_GET['wppilot_cloud_state'] ?? null) ? sanitize_text_field(wp_unslash($_GET['wppilot_cloud_state'])) : '';

    $result_message = match ($result) {
        'connected' => __('This site is connected to WPPilot Cloud.', domain: 'wppilot'),
        'disconnected' => __('Disconnected from WPPilot Cloud. Its access token was revoked.', domain: 'wppilot'),
        'cancelled' => __('Connection cancelled. Nothing was changed on this site.', domain: 'wppilot'),
        default => '',
    };
    ?>
    <div id="wppilot-cloud-panel" style="margin-top:16px;">
        <?php if ($result_message !== ''): ?>
            <div class="notice notice-success inline" style="margin:0 0 12px;"><p><?php echo esc_html($result_message); ?></p></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="notice notice-error inline" style="margin:0 0 12px;"><p>
                <?php echo esc_html(wppilot_cloud_error_message($error)); ?>
                <?php if ($detail !== ''): ?>
                    <br /><span class="description"><?php echo esc_html(sprintf(
                        /* translators: %s: machine-readable error code returned by WPPilot Cloud */
                        __('WPPilot Cloud said: %s', domain: 'wppilot'),
                        $detail,
                    )); ?></span>
                <?php endif; ?>
            </p></div>
        <?php endif; ?>
        <?php
        $link = wppilot_cloud_link();
        $pending = $link === null && $state !== '' ? wppilot_cloud_pending($state, get_current_user_id()) : null;
        if ($link !== null) {
            wppilot_render_cloud_connected($link);
        } elseif ($pending !== null && is_array($pending['policy'] ?? null)) {
            wppilot_render_cloud_confirm($state, $pending['policy']);
        } else {
            wppilot_render_cloud_disconnected();
        }
        ?>
    </div>
    <?php
}

function wppilot_render_cloud_disconnected(): void
{
    ?>
    <p><?php esc_html_e(
        'Connect this site to your WPPilot Cloud workspace once, and every AI client connected to the workspace can reach it - no credential to paste into each one.',
        domain: 'wppilot',
    ); ?></p>
    <p class="description"><?php esc_html_e(
        'You sign in to WPPilot Cloud and choose the access level there, then confirm it here. This site creates an access token for the Cloud and gives it only to the Cloud; your safety profile, confirmations and change log still apply to every call.',
        domain: 'wppilot',
    ); ?></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
        <input type="hidden" name="action" value="wppilot_cloud_begin" />
        <?php wp_nonce_field('wppilot_cloud_begin'); ?>
        <button type="submit" class="button button-primary"><?php esc_html_e('Connect to WPPilot Cloud', domain: 'wppilot'); ?></button>
    </form>
    <?php
}

/**
 * The §2 confirm screen.
 *
 * @param array<string, mixed> $policy As normalised by wppilot_cloud_parse_policy().
 */
function wppilot_render_cloud_confirm(string $state, array $policy): void
{
    $ceilings = wppilot_token_ceiling_choices();
    $ceiling = is_string($policy['ceiling'] ?? null) ? $policy['ceiling'] : 'readonly';
    $ceiling_label = $ceilings[$ceiling] ?? $ceiling;
    /** @var array{abilities: list<string>, categories: list<string>}|null $scope */
    $scope = is_array($policy['scope'] ?? null) ? $policy['scope'] : null;
    $workspace = is_string($policy['workspace'] ?? null) ? $policy['workspace'] : '';
    $label = is_string($policy['label'] ?? null) ? $policy['label'] : '';
    $login = (string) wp_get_current_user()->user_login;
    ?>
    <div class="notice notice-info inline" style="margin:0; padding:12px 16px;">
        <p style="font-size:14px; margin-top:0;"><strong><?php echo esc_html(sprintf(
            /* translators: 1: masked WPPilot Cloud account email, 2: safety profile label, 3: WordPress username */
            __('Connect this site to WPPilot Cloud account %1$s with %2$s access as WordPress user %3$s', domain: 'wppilot'),
            (string) ($policy['account_hint'] ?? ''),
            $ceiling_label,
            $login,
        )); ?></strong></p>
        <ul style="list-style:disc; margin-left:20px;">
            <?php if ($workspace !== ''): ?>
                <li><?php echo esc_html(sprintf(/* translators: %s: workspace name */ __('Workspace: %s', domain: 'wppilot'), $workspace)); ?></li>
            <?php endif; ?>
            <?php if ($label !== ''): ?>
                <li><?php echo esc_html(sprintf(/* translators: %s: site label chosen in WPPilot Cloud */ __('Site label: %s', domain: 'wppilot'), $label)); ?></li>
            <?php endif; ?>
            <li><?php echo esc_html(sprintf(
                /* translators: %s: abilities the token may use */
                __('May use: %s', domain: 'wppilot'),
                wppilot_token_policy_summary(['scope' => $scope, 'ceiling' => '']),
            )); ?></li>
            <li><?php esc_html_e(
                'An access token that does not expire is created for WPPilot Cloud. Disconnect here at any time to revoke it.',
                domain: 'wppilot',
            ); ?></li>
        </ul>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right:8px;">
            <input type="hidden" name="action" value="wppilot_cloud_confirm" />
            <input type="hidden" name="state" value="<?php echo esc_attr($state); ?>" />
            <?php wp_nonce_field('wppilot_cloud_confirm_' . $state); ?>
            <button type="submit" class="button button-primary"><?php esc_html_e('Confirm', domain: 'wppilot'); ?></button>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
            <input type="hidden" name="action" value="wppilot_cloud_cancel" />
            <input type="hidden" name="state" value="<?php echo esc_attr($state); ?>" />
            <?php wp_nonce_field('wppilot_cloud_confirm_' . $state); ?>
            <button type="submit" class="button"><?php esc_html_e('Cancel', domain: 'wppilot'); ?></button>
        </form>
    </div>
    <?php
}

/**
 * The connected state.
 *
 * @param array{site_id: string|int, token_id: int, account_hint: string, cloud_url: string, paired_at: string} $link
 */
function wppilot_render_cloud_connected(array $link): void
{
    $paired = strtotime($link['paired_at']);
    $paired_label = $paired !== false ? wp_date(wppilot_get_datetime_format('Y-m-d H:i'), $paired) : false;
    ?>
    <table class="form-table" role="presentation" style="margin-top:0;">
        <tr>
            <th scope="row"><?php esc_html_e('Account', domain: 'wppilot'); ?></th>
            <td><?php echo esc_html($link['account_hint'] !== '' ? $link['account_hint'] : __('Unknown', domain: 'wppilot')); ?></td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Site ID', domain: 'wppilot'); ?></th>
            <td class="wppilot-mono"><?php echo esc_html((string) $link['site_id']); ?></td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Connected', domain: 'wppilot'); ?></th>
            <td><?php echo esc_html(is_string($paired_label) ? $paired_label : __('Unknown', domain: 'wppilot')); ?></td>
        </tr>
    </table>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo
        esc_js(__('Disconnect from WPPilot Cloud? Its access token is revoked and AI clients using this site through the Cloud lose access.', domain: 'wppilot'))
    ; ?>');">
        <input type="hidden" name="action" value="wppilot_cloud_disconnect" />
        <?php wp_nonce_field('wppilot_cloud_disconnect'); ?>
        <button type="submit" class="button wppilot-revoke-btn"><?php esc_html_e('Disconnect', domain: 'wppilot'); ?></button>
    </form>
    <?php
}
