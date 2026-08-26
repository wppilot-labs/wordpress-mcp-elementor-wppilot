<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file: require()d inside a namespaced render function, so every variable is function-scoped, never global. The prefix sniff cannot see across the include boundary. Reads are type-checked and escaped on output.

use WPPilot\Design\Admin;
use WPPilot\Design\Contract;
use WPPilot\Design\Library;
use WPPilot\Design\Store;
use WPPilot\Design\Tokens;

if (!defined('ABSPATH')) {
    exit();
}

if (!Admin\current_user_can_manage()) {
    wp_die(esc_html__('You do not have permission to view this page.', domain: 'wppilot'));
}

$library = Library\all();
$active_slug = Store\get_active_slug();
$action_url = admin_url('admin-post.php');

// The page chrome quietly wears the active design's accent colour.
$active = $active_slug !== '' ? Library\find($active_slug) : null;
$page_accent = $active !== null ? Tokens\css_vars(Tokens\extract($active['content']))['--wppilot-accent'] ?? '' : '';
$page_style = $page_accent !== '' ? '--ds-accent:' . $page_accent : '';
?>
<?php wppilot_render_admin_header(legend: __('Design', domain: 'wppilot')); ?>
<div class="wrap wppilot-design" style="<?php echo esc_attr($page_style); ?>">
    <h1 class="wp-heading-inline"><?php echo esc_html(\wppilot_nav_label('wppilot-design')); ?></h1>
    <a href="<?php echo
        esc_url(add_query_arg(['page' => Admin\PAGE_SLUG, 'import' => 1], admin_url('admin.php')))
    ; ?>" class="page-title-action"><?php esc_html_e('Import DESIGN.md', domain: 'wppilot'); ?></a>
    <hr class="wp-header-end" />
    <p class="wppilot-design-intro"><?php esc_html_e(
        'Your site has one design direction: the active design your AI builds within. It is created for this site from a brief, not picked from a catalog. On every page the AI also enforces a floor of anti-slop rules.',
        domain: 'wppilot',
    ); ?></p>

    <?php if ($library === []): ?>
        <div class="wppilot-design-empty">
            <p><?php esc_html_e(
                'No design system yet. Ask your AI agent to create one for this site, or import a DESIGN.md.',
                domain: 'wppilot',
            ); ?></p>
        </div>
    <?php endif; ?>

    <div class="wppilot-design-gallery">
        <?php foreach ($library as $entry):
            $tokens = Tokens\extract($entry['content']);
            $inspection = Contract\inspect($entry['content']);
            $is_ready = $inspection['readiness']['ready'];
            $vars_style = Tokens\css_vars_string($tokens);
            $accent = Tokens\css_vars($tokens)['--wppilot-accent'] ?? '';
            $is_active = $entry['slug'] === $active_slug;

            $edit_url = '';
            $post = Store\find_user_post($entry['slug']);
            if ($post instanceof \WP_Post) {
                $edit_url = add_query_arg([
                    'page' => Admin\PAGE_SLUG,
                    'design' => $post->ID,
                ], admin_url('admin.php'));
            }
            $card_style = $accent !== '' ? '--ds-accent:' . $accent : '';
            $view_url = add_query_arg(['page' => Admin\PAGE_SLUG, 'view' => $entry['slug']], admin_url('admin.php'));
            ?>
            <article class="wppilot-design-card2<?php echo $is_active ? ' is-active' : ''; ?>" style="<?php echo
                esc_attr($card_style)
            ; ?>">
                <a class="wppilot-design-stage" href="<?php echo esc_url($view_url); ?>">
                    <?php require __DIR__ . '/preview.php'; ?>
                </a>
                <div class="wppilot-design-card2-body">
                    <div class="wppilot-design-card2-head">
                        <h2><a href="<?php echo esc_url($view_url); ?>"><?php echo esc_html($entry['name']); ?></a></h2>
                        <?php if ($is_active): ?>
                            <span class="wppilot-design-active-badge"><?php esc_html_e(
                                'Active',
                                domain: 'wppilot',
                            ); ?></span>
                        <?php endif; ?>
                        <?php if (!$is_ready): ?>
                            <span class="wppilot-design-incomplete-badge"><?php esc_html_e(
                                'Incomplete',
                                domain: 'wppilot',
                            ); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($entry['description'] !== ''): ?>
                        <p class="wppilot-design-desc"><?php echo esc_html($entry['description']); ?></p>
                    <?php endif; ?>
                    <?php $waivers = WPPilot\Design\Preflight\waivers($entry['content']); ?>
                    <?php if ($waivers !== []): ?>
                        <span class="wppilot-design-allows"><?php echo
                            esc_html(sprintf(
                                /* translators: %s: list of anti-slop rules this design waives */
                                __('Allows: %s', domain: 'wppilot'),
                                implode(' · ', $waivers),
                            ))
                        ; ?><span class="wppilot-design-allows-help" title="<?php echo
                            esc_attr__(
                                'Anti-slop rules this design intentionally waives. WPPilot normally flags these AI tells; here they count as a deliberate house-style choice, not a mistake.',
                                domain: 'wppilot',
                            )
                        ; ?>">?</span></span>
                    <?php endif; ?>
                    <div class="wppilot-design-card2-actions">
                        <?php if (!$is_active && $is_ready): ?>
                            <form method="post" action="<?php echo esc_url($action_url); ?>">
                                <?php wp_nonce_field('wppilot_design_activate'); ?>
                                <input type="hidden" name="action" value="wppilot_design_activate" />
                                <input type="hidden" name="slug" value="<?php echo esc_attr($entry['slug']); ?>" />
                                <button type="submit" class="button button-primary"><?php esc_html_e(
                                    'Activate',
                                    domain: 'wppilot',
                                ); ?></button>
                            </form>
                        <?php endif; ?>
                        <?php if (!$is_active && !$is_ready): ?>
                            <button type="button" class="button" disabled title="<?php echo
                                esc_attr(Contract\activation_error($inspection))
                            ; ?>"><?php esc_html_e('Activate', domain: 'wppilot'); ?></button>
                        <?php endif; ?>
                        <?php if ($edit_url !== ''): ?>
                            <a class="button-link" href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e(
                                'Edit',
                                domain: 'wppilot',
                            ); ?></a>
                        <?php endif; ?>
                        <form method="post" action="<?php echo esc_url($action_url); ?>">
                            <?php wp_nonce_field('wppilot_design_duplicate'); ?>
                            <input type="hidden" name="action" value="wppilot_design_duplicate" />
                            <input type="hidden" name="slug" value="<?php echo esc_attr($entry['slug']); ?>" />
                            <button type="submit" class="button-link"><?php esc_html_e(
                                'Duplicate',
                                domain: 'wppilot',
                            ); ?></button>
                        </form>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php
    /**
     * Sections other plugins add to this screen.
     *
     * The design and the things done with it belong on one screen. Pro's Brand
     * Kit was a second menu item whose every section was about the active
     * design — what it looks like, whether its palette is readable, where it can
     * be written — which meant two entries that sounded like the same thing and
     * no way to guess which one to open.
     *
     * @param string $active_slug The active design's slug, or '' when none is active.
     */
    do_action('wppilot_design_panel_sections', $active_slug);
    ?>

    <?php require __DIR__ . '/starters.php'; ?>
</div>
