<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\PromptLibrary\Admin;

use WPPilot\PromptLibrary;

/**
 * The Prompts screen: pick a builder, pick an industry, copy the brief.
 *
 * Deliberately static HTML with a copy button and no server round-trip. A
 * brief is text; the moment this screen needs saving, filtering or state it
 * has stopped being a library and started being an app nobody asked for. The
 * one piece of client-side behaviour is the builder picker, which rewrites the
 * first line of every brief in place so what is copied names the editor the
 * agent will actually be driving.
 *
 * Locked briefs are shown, not hidden: someone on the free plugin should be
 * able to see that a Pro brief exists and what it covers.
 */

if (!defined('ABSPATH')) {
    exit();
}

function register_menu(): void
{
    add_submenu_page(
        parent_slug: 'wppilot-connect',
        page_title: \wppilot_nav_label(PromptLibrary\PAGE, __('Prompts', domain: 'wppilot')),
        menu_title: \wppilot_nav_label(PromptLibrary\PAGE, __('Prompts', domain: 'wppilot')),
        capability: (string) \wppilot_manage_capability(),
        menu_slug: PromptLibrary\PAGE,
        callback: __NAMESPACE__ . '\\render',
    );
}

/**
 * @param mixed $map
 * @return mixed
 */
function register_nav(mixed $map): mixed
{
    if (!is_array($map)) {
        return $map;
    }

    $map[PromptLibrary\PAGE] = ['label' => __('Prompts', domain: 'wppilot'), 'group' => 'agent'];

    return $map;
}

/**
 * Whether Pro is licensed and answering.
 *
 * Uses the same filter the Dashboard reads, so this screen never calls into Pro
 * and never checks whether it is installed. An unlicensed Pro does not answer,
 * which is the correct reading: its briefs are not available.
 */
function pro_active(): bool
{
    /** @var mixed $status */
    $status = apply_filters('wppilot_pro_status', value: null);

    return is_array($status);
}

function render(): void
{
    if (!\wppilot_current_user_can_manage()) {
        return;
    }

    $briefs = PromptLibrary\briefs();
    $sectors = PromptLibrary\by_sector($briefs);
    $builders = PromptLibrary\builders();
    $default_builder = PromptLibrary\default_builder();
    $licensed = pro_active();
    $free_count = count(array_filter($briefs, static fn(array $b): bool => ($b['pro'] ?? false) !== true));

    \wppilot_render_admin_header();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(\wppilot_nav_label(PromptLibrary\PAGE, __('Prompts', domain: 'wppilot'))); ?></h1>
        <p class="wppilot-lede"><?php esc_html_e(
            'A complete landing-page brief for each kind of business: palette, type, a design signature that makes the page its own, the sections it must carry, and the facts to use verbatim. Choose the builder, copy the brief, paste it at your agent.',
            domain: 'wppilot',
        ); ?></p>
        <?php // These are a shortcut, not the interface. Nobody should read this
              // screen and conclude their own wording will not work. ?>
        <p class="description" style="margin:-6px 0 18px;max-width:70ch;"><?php esc_html_e(
            'You do not have to use any of these. Your agent asks this site what it can do before it starts and loads the right skill on its own, so asking in your own words works just as well. A brief this specific is what stops every business getting the same centred hero with three cards under it.',
            domain: 'wppilot',
        ); ?></p>

        <?php if ($briefs === []) {
            ?>
            <section class="wppilot-panel">
                <p class="description"><?php esc_html_e('No briefs are available.', domain: 'wppilot'); ?></p>
            </section>
            <?php

            return;
        } ?>

        <div class="wppilot-prompt-toolbar">
            <label for="wppilot-prompt-builder"><?php esc_html_e('Build with', domain: 'wppilot'); ?></label>
            <select id="wppilot-prompt-builder">
                <?php foreach ($builders as $slug => $label) { ?>
                    <option value="<?php echo esc_attr($slug); ?>"<?php selected($slug, $default_builder); ?>><?php
                        echo esc_html($label); ?></option>
                <?php } ?>
            </select>
            <span class="description"><?php esc_html_e(
                'Written into the first line of every brief, so the agent builds with that editor\'s own elements.',
                domain: 'wppilot',
            ); ?></span>
        </div>

        <nav class="wppilot-client-tabs wppilot-prompt-sectors" aria-label="<?php esc_attr_e('Sectors', domain: 'wppilot'); ?>">
            <?php foreach ($sectors as $sector => $sector_briefs) { ?>
                <a class="wppilot-client-tab" href="#<?php echo esc_attr('wppilot-sector-' . sanitize_title($sector)); ?>">
                    <?php echo esc_html($sector); ?>
                    <span class="wppilot-prompt-count"><?php echo esc_html((string) count($sector_briefs)); ?></span>
                </a>
            <?php } ?>
        </nav>
        <p class="description" style="margin:6px 0 18px;"><?php
        printf(
            /* translators: %d: number of briefs included with the free plugin */
            esc_html(_n(
                single: '%d brief for a simple single-page site, each for a different industry.',
                plural: '%d briefs for simple single-page sites, each for a different industry.',
                number: $free_count,
                domain: 'wppilot',
            )),
            $free_count,
        );
        ?></p>

        <?php foreach ($sectors as $sector => $sector_briefs) { ?>
            <section class="wppilot-panel wppilot-prompt-sector" id="<?php echo esc_attr('wppilot-sector-' . sanitize_title($sector)); ?>">
                <h2 class="wppilot-setting-group__title"><?php echo esc_html($sector); ?></h2>
                <?php foreach ($sector_briefs as $brief) {
                    render_brief($brief, $default_builder, $licensed);
                } ?>
            </section>
        <?php } ?>
    </div>

    <?php // Styles for this screen live in includes/assets/admin.css. ?>
    <script>
    (function () {
        function fallbackClipboardCopy(text) {
            return new Promise(function (resolve, reject) {
                var textarea = document.createElement('textarea');
                var active = document.activeElement;
                textarea.value = text;
                textarea.setAttribute('readonly', '');
                textarea.setAttribute('aria-hidden', 'true');
                textarea.style.position = 'fixed';
                textarea.style.top = '-9999px';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.focus();
                textarea.select();

                var copied = false;
                try {
                    copied = document.execCommand('copy');
                } catch (error) {
                    copied = false;
                }

                document.body.removeChild(textarea);
                if (active && typeof active.focus === 'function') {
                    active.focus();
                }
                copied ? resolve() : reject(new Error('copy command was rejected'));
            });
        }

        // The Prompts screen is independent from Connect and Troubleshoot, so
        // it must own the helper it calls. Also fall back when the modern API
        // exists but rejects because of browser permissions or an HTTP origin.
        if (!window.wppilotClipboardCopy) {
            window.wppilotClipboardCopy = function (text) {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    return navigator.clipboard.writeText(text).catch(function () {
                        return fallbackClipboardCopy(text);
                    });
                }
                return fallbackClipboardCopy(text);
            };
        }

        function showCopyState(button, label) {
            var original = button.getAttribute('data-label') || button.textContent;
            button.setAttribute('data-label', original);
            button.textContent = label;
            setTimeout(function () { button.textContent = original; }, 1800);
        }

        // The builder picker rewrites the first line of every brief. The
        // choice is remembered per browser: a person building Elementor sites
        // should not have to say so on every visit.
        var picker = document.getElementById('wppilot-prompt-builder');
        var prefix = <?php echo wp_json_encode(PromptLibrary\BUILDER_LINE_PREFIX); ?>;
        var storageKey = 'wppilotPromptBuilder';

        function applyBuilder() {
            if (!picker) {
                return;
            }
            var label = picker.options[picker.selectedIndex] ? picker.options[picker.selectedIndex].text : '';
            document.querySelectorAll('.wppilot-prompt__builder').forEach(function (line) {
                line.textContent = prefix + label;
            });
            try {
                window.localStorage.setItem(storageKey, picker.value);
            } catch (error) {
                // Storage can be unavailable; the picker still works for this page view.
            }
        }

        if (picker) {
            try {
                var remembered = window.localStorage.getItem(storageKey);
                if (remembered && picker.querySelector('option[value="' + remembered + '"]')) {
                    picker.value = remembered;
                }
            } catch (error) {
                // Ignore: no storage, no memory, nothing lost.
            }
            picker.addEventListener('change', applyBuilder);
            applyBuilder();
        }

        document.querySelectorAll('.wppilot-prompt-copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var body = document.getElementById(btn.getAttribute('data-target'));
                if (!body) {
                    showCopyState(btn, btn.getAttribute('data-failed'));
                    return;
                }
                window.wppilotClipboardCopy(body.textContent).then(function () {
                    showCopyState(btn, btn.getAttribute('data-copied'));
                }).catch(function () {
                    showCopyState(btn, btn.getAttribute('data-failed'));
                });
            });
        });
    })();
    </script>
    <?php
}

/**
 * One brief: its heading, what makes it distinct, and the text to copy.
 *
 * The builder line is rendered as its own span so the picker can rewrite it.
 * Everything after it is the brief body plus the shared standards, exactly as
 * compose() would return it for the default builder.
 *
 * @param array<string, mixed> $brief
 */
function render_brief(array $brief, string $builder, bool $licensed): void
{
    $slug = (string) ($brief['slug'] ?? '');
    $industry = (string) ($brief['industry'] ?? '');
    $title = (string) ($brief['title'] ?? $industry);
    $description = (string) ($brief['description'] ?? '');
    $signature = (string) ($brief['signature'] ?? '');
    $pro = ($brief['pro'] ?? false) === true;
    $is_locked = $pro && !$licensed;
    $id = 'wppilot-brief-' . $slug;
    $copied = __('Copied', domain: 'wppilot');
    $copy_failed = __('Copy failed', domain: 'wppilot');
    $composed = PromptLibrary\compose($brief, $builder);
    $builder_line = PromptLibrary\builder_line($builder);
    $rest = str_starts_with($composed, $builder_line) ? substr($composed, strlen($builder_line)) : "\n\n" . $composed;
    ?>
    <div class="wppilot-prompt" id="<?php echo esc_attr($id . '-card'); ?>">
        <div class="wppilot-prompt__head">
            <div>
                <p class="wppilot-prompt__meta">
                    <span class="wppilot-prompt__industry"><?php echo esc_html($industry); ?></span>
                    <?php if ($pro) { ?>
                        <span class="wppilot-prompt-lock" aria-label="<?php esc_attr_e('Pro', domain: 'wppilot'); ?>">&#128274;</span>
                    <?php } ?>
                </p>
                <h3 class="wppilot-prompt__title"><?php echo esc_html($title); ?></h3>
                <?php if ($description !== '') { ?>
                    <p class="description" style="margin:2px 0 0;"><?php echo esc_html($description); ?></p>
                <?php } ?>
                <?php if ($signature !== '') { ?>
                    <p class="wppilot-prompt__signature"><strong><?php esc_html_e('Signature:', domain: 'wppilot'); ?></strong> <?php
                        echo esc_html($signature); ?></p>
                <?php } ?>
            </div>
            <?php if (!$is_locked) { ?>
                <button
                    type="button"
                    class="button wppilot-prompt-copy"
                    data-target="<?php echo esc_attr($id); ?>"
                    data-copied="<?php echo esc_attr($copied); ?>"
                    data-failed="<?php echo esc_attr($copy_failed); ?>"
                    aria-live="polite"
                ><?php esc_html_e('Copy brief', domain: 'wppilot'); ?></button>
            <?php } ?>
        </div>
        <?php if ($is_locked) { ?>
            <p class="description" style="margin:0;"><?php esc_html_e(
                'Included with WPPilot Pro.',
                domain: 'wppilot',
            ); ?></p>
        <?php } else { ?>
            <pre class="wppilot-prompt__body" id="<?php echo esc_attr($id); ?>"><span class="wppilot-prompt__builder"><?php
                echo esc_html($builder_line); ?></span><?php echo esc_html($rest); ?></pre>
        <?php } ?>
    </div>
    <?php
}

add_action('admin_menu', __NAMESPACE__ . '\register_menu', priority: 42);
add_filter('wppilot_nav_map', __NAMESPACE__ . '\register_nav');
