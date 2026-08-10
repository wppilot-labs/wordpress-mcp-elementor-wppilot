<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\PromptLibrary\Admin;

use WPPilot\PromptLibrary;

/**
 * The Prompts screen: pick a category, copy a prompt, paste it at your agent.
 *
 * Deliberately static HTML with a copy button and no server round-trip. A
 * prompt is text; the moment this screen needs saving, filtering or state it
 * has stopped being a library and started being an app nobody asked for.
 *
 * Locked packs are shown, not hidden. Someone on the free plugin should be able
 * to see that the Elementor prompts exist and what they cover — that is the
 * honest version of an upsell, and it is also the answer to "does Pro have
 * anything for my builder?".
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
 * which is the correct reading: its packs are not available.
 */
function pro_active(): bool
{
    /** @var mixed $status */
    $status = apply_filters('wppilot_pro_status', value: null);

    return is_array($status);
}

/**
 * Group the packs for display, preserving the order groups() declares.
 *
 * @param list<array<string, mixed>> $packs
 * @return array<string, list<array<string, mixed>>>
 */
function grouped(array $packs): array
{
    $grouped = [];
    foreach (array_keys(PromptLibrary\groups()) as $group) {
        $matching = array_values(array_filter(
            $packs,
            static fn(array $pack): bool => ($pack['group'] ?? '') === $group,
        ));
        if ($matching !== []) {
            $grouped[$group] = $matching;
        }
    }

    return $grouped;
}

function render(): void
{
    if (!\wppilot_current_user_can_manage()) {
        return;
    }

    $packs = PromptLibrary\packs();
    $groups = PromptLibrary\groups();
    $grouped = grouped($packs);
    $licensed = pro_active();
    $locked = array_values(array_filter($packs, static fn(array $p): bool => $p['pro'] === true && !$licensed));

    \wppilot_render_admin_header();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(\wppilot_nav_label(PromptLibrary\PAGE, __('Prompts', domain: 'wppilot'))); ?></h1>
        <p class="wppilot-lede"><?php esc_html_e(
            'Ready-made instructions for your AI client. Copy one, edit the parts in brackets, and paste it wherever you talk to your agent.',
            domain: 'wppilot',
        ); ?></p>

        <?php if ($packs === []) {
            ?>
            <section class="wppilot-panel">
                <p class="description"><?php esc_html_e('No prompt packs are available.', domain: 'wppilot'); ?></p>
            </section>
            <?php

            return;
        } ?>

        <div class="wppilot-client-tabs" id="wppilot-prompt-tabs">
            <?php $first = true; ?>
            <?php foreach ($grouped as $group => $group_packs) { ?>
                <?php foreach ($group_packs as $pack) { ?>
                    <button
                        type="button"
                        class="wppilot-client-tab wppilot-prompt-tab<?php echo $first ? ' active' : ''; ?>"
                        data-pack="<?php echo esc_attr((string) $pack['slug']); ?>"
                    >
                        <?php echo esc_html((string) $pack['label']); ?>
                        <?php if ($pack['pro'] === true && !$licensed) { ?>
                            <span class="wppilot-prompt-lock" aria-label="<?php

                            esc_attr_e('Pro', domain: 'wppilot'); ?>">&#128274;</span>
                        <?php } ?>
                    </button>
                    <?php $first = false; ?>
                <?php } ?>
            <?php } ?>
        </div>
        <p class="description" style="margin:6px 0 18px;"><?php

        $labels = [];
        foreach ($grouped as $group => $group_packs) {
            $labels[] = sprintf('%s (%d)', $groups[$group] ?? $group, count($group_packs));
        }
        echo esc_html(implode(' · ', $labels));
        ?></p>

        <?php $first = true; ?>
        <?php foreach ($packs as $pack) { ?>
            <div
                class="wppilot-prompt-pack"
                data-pack="<?php echo esc_attr((string) $pack['slug']); ?>"
                <?php echo $first ? '' : 'hidden'; ?>
            >
                <?php render_pack($pack, $licensed); ?>
            </div>
            <?php $first = false; ?>
        <?php } ?>

        <?php if ($locked !== []) { ?>
            <section class="wppilot-panel is-attention">
                <h2 class="wppilot-setting-group__title"><?php esc_html_e(
                    'More prompts with WPPilot Pro',
                    domain: 'wppilot',
                ); ?></h2>
                <p><?php

                printf(
                    /* translators: 1: number of locked packs, 2: comma-separated pack names */
                    esc_html(_n(
                        single: '%1$d more pack is included with Pro: %2$s.',
                        plural: '%1$d more packs are included with Pro: %2$s.',
                        number: count($locked),
                        domain: 'wppilot',
                    )),
                    count($locked),
                    esc_html(implode(', ', array_map(static fn(array $p): string => (string) $p['label'], $locked))),
                );
                ?></p>
                <p>
                    <a class="button button-primary" href="https://wppilot.co/pricing/" target="_blank" rel="noopener">
                        <?php esc_html_e('See WPPilot Pro', domain: 'wppilot'); ?>
                    </a>
                </p>
            </section>
        <?php } ?>
    </div>

    <?php // Styles for this screen live in includes/assets/admin.css. ?>
    <script>
    (function () {
        var tabs = document.querySelectorAll('.wppilot-prompt-tab');
        var packs = document.querySelectorAll('.wppilot-prompt-pack');
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var want = tab.getAttribute('data-pack');
                tabs.forEach(function (t) { t.classList.remove('active'); });
                tab.classList.add('active');
                packs.forEach(function (p) {
                    p.hidden = p.getAttribute('data-pack') !== want;
                });
            });
        });

        document.querySelectorAll('.wppilot-prompt-copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var body = document.getElementById(btn.getAttribute('data-target'));
                if (!body || !window.wppilotClipboardCopy) { return; }
                window.wppilotClipboardCopy(body.textContent).then(function () {
                    var original = btn.textContent;
                    btn.textContent = btn.getAttribute('data-copied');
                    setTimeout(function () { btn.textContent = original; }, 1500);
                });
            });
        });
    })();
    </script>
    <?php
}

/**
 * One pack: its prompts, or the reason they are not shown.
 *
 * @param array<string, mixed> $pack
 */
function render_pack(array $pack, bool $licensed): void
{
    $is_locked = ($pack['pro'] ?? false) === true && !$licensed;
    /** @var list<array{title: string, description: string, prompt: string}> $prompts */
    $prompts = is_array($pack['prompts'] ?? null) ? $pack['prompts'] : [];
    $copied = __('Copied', domain: 'wppilot');
    ?>
    <section class="wppilot-panel">
        <h2 class="wppilot-setting-group__title"><?php echo esc_html((string) $pack['label']); ?></h2>
        <?php if ((string) ($pack['description'] ?? '') !== '') { ?>
            <p class="description" style="margin-top:0;"><?php

            echo esc_html((string) $pack['description']); ?></p>
        <?php } ?>

        <?php if ($is_locked) { ?>
            <p><?php

            printf(
                /* translators: %d: number of prompts in this pack */
                esc_html(_n(
                    single: '%d prompt in this pack, included with WPPilot Pro.',
                    plural: '%d prompts in this pack, included with WPPilot Pro.',
                    number: count($prompts),
                    domain: 'wppilot',
                )),
                count($prompts),
            );
            ?></p>
            <ul class="wppilot-prompt-locked-list">
                <?php foreach ($prompts as $prompt) { ?>
                    <li>
                        <strong><?php echo esc_html($prompt['title']); ?></strong>
                        <?php if ($prompt['description'] !== '') { ?>
                            — <?php echo esc_html($prompt['description']); ?>
                        <?php } ?>
                    </li>
                <?php } ?>
            </ul>
        <?php } else { ?>
            <?php foreach ($prompts as $index => $prompt) { ?>
                <?php $id = 'wppilot-prompt-' . sanitize_key((string) $pack['slug']) . '-' . $index; ?>
                <div class="wppilot-prompt">
                    <div class="wppilot-prompt__head">
                        <div>
                            <h3 class="wppilot-prompt__title"><?php echo esc_html($prompt['title']); ?></h3>
                            <?php if ($prompt['description'] !== '') { ?>
                                <p class="description" style="margin:2px 0 0;"><?php

                                echo esc_html($prompt['description']); ?></p>
                            <?php } ?>
                        </div>
                        <button
                            type="button"
                            class="button wppilot-prompt-copy"
                            data-target="<?php echo esc_attr($id); ?>"
                            data-copied="<?php echo esc_attr($copied); ?>"
                        ><?php esc_html_e('Copy', domain: 'wppilot'); ?></button>
                    </div>
                    <pre class="wppilot-prompt__body" id="<?php echo esc_attr($id); ?>"><?php

                    echo esc_html($prompt['prompt']); ?></pre>
                </div>
            <?php } ?>
        <?php } ?>
    </section>
    <?php
}

add_action('admin_menu', __NAMESPACE__ . '\register_menu', priority: 42);
add_filter('wppilot_nav_map', __NAMESPACE__ . '\register_nav');
