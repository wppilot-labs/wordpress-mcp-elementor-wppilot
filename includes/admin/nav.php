<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The one place WPPilot's screens are named and ordered.
 *
 * Thirteen screens used to name themselves at their own registration site, which
 * produced two problems this file exists to fix.
 *
 * The names drifted apart. "Configuration" and "Settings" were separate screens
 * whose titles are synonyms, so no label told you which one held the thing you
 * wanted. "Abilities Hub" and "Block Editor Queue" carried internal vocabulary
 * outward. Every screen now takes its label from wppilot_nav_label(), so a
 * rename happens once and reaches the sidebar and the tab rail together.
 *
 * And thirteen sibling items read as a list to be searched rather than a
 * structure to be navigated. Grouping them says what each screen is for:
 * Connection is how agents reach the site, Agent is what they may do once here,
 * Studio is what they build, System is the machinery underneath.
 *
 * Slugs are deliberately untouched. They are in bookmarks, in support threads,
 * and in the `page=` links other plugins may hold; renaming a screen should not
 * break a URL someone saved.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Section headings for the tab rail, in the order they appear.
 *
 * @return array<string, string>
 */
function wppilot_nav_groups(): array
{
    $groups = [
        'connection' => __('Connection', domain: 'wppilot'),
        'agent' => __('Agent', domain: 'wppilot'),
        'studio' => __('Studio', domain: 'wppilot'),
        'system' => __('System', domain: 'wppilot'),
    ];

    /**
     * Filter the tab rail's section headings.
     *
     * @param array<string, string> $groups Group key to heading, in display order.
     */
    /** @var mixed $filtered */
    $filtered = apply_filters('wppilot_nav_groups', $groups);
    if (!is_array($filtered)) {
        return $groups;
    }

    $safe = [];
    /** @var mixed $heading */
    foreach ($filtered as $key => $heading) {
        $safe[(string) $key] = (string) (is_scalar($heading) ? $heading : $key);
    }

    return $safe;
}

/**
 * Every WPPilot screen: its label and the group it belongs to.
 *
 * An add-on registering a screen adds itself here through the filter; anything
 * absent still appears on the rail, in the group named by the fallback below.
 *
 * @return array<string, array<array-key, mixed>>
 */
function wppilot_nav_map(): array
{
    $map = [
        // How agents reach this site, and whether they are getting through.
        //
        // Overview and Connect used to be two screens, which split one question
        // across both: Overview said whether anything was connected, Connect was
        // where you made that true, and neither was complete on its own. They are
        // now one screen. The slug stays `wppilot-connect` because it is the
        // parent every other WPPilot screen registers under, and renaming it
        // would break every one of those registrations along with any saved link.
        'wppilot-connect' => ['label' => __('Overview', domain: 'wppilot'), 'group' => 'connection'],
        'wppilot-troubleshoot' => ['label' => __('Diagnostics', domain: 'wppilot'), 'group' => 'connection'],

        // What an agent is allowed to do, and what it knows before it starts.
        'wppilot-abilities' => ['label' => __('Abilities', domain: 'wppilot'), 'group' => 'agent'],
        'wppilot-context' => ['label' => __('Instructions', domain: 'wppilot'), 'group' => 'agent'],
        'wppilot-skills' => ['label' => __('Skills', domain: 'wppilot'), 'group' => 'agent'],
        'wppilot-pro-memory' => ['label' => __('Memory', domain: 'wppilot'), 'group' => 'agent'],

        // Where work on the site actually gets made.
        'wppilot-chat' => ['label' => __('Chat', domain: 'wppilot'), 'group' => 'studio'],
        'wppilot-design' => ['label' => __('Design', domain: 'wppilot'), 'group' => 'studio'],

        // Deliberately absent: wppilot-gutenberg-finalize. It is a hidden
        // machinery page rather than a destination — see
        // includes/admin/gutenberg-finalizer.php — and keeping it out of this
        // map keeps it off the tab rail as well as out of the sidebar. It names
        // itself through gutenberg_finalizer_title(), which still consults this
        // map first so a rename here would reach it.

        // The machinery underneath.
        'wppilot-sandbox' => ['label' => __('Sandbox', domain: 'wppilot'), 'group' => 'system'],
        'wppilot-settings' => ['label' => __('Settings', domain: 'wppilot'), 'group' => 'system'],
    ];

    /**
     * Filter the WPPilot screen registry.
     *
     * @param array<string, array<string, mixed>> $map Page slug to label and group.
     */
    /** @var mixed $filtered */
    $filtered = apply_filters('wppilot_nav_map', $map);
    if (!is_array($filtered)) {
        return $map;
    }

    $safe = [];
    /** @var mixed $entry */
    foreach ($filtered as $slug => $entry) {
        if (is_array($entry)) {
            $safe[(string) $slug] = $entry;
        }
    }

    return $safe;
}

/**
 * The label for a screen, for both the WordPress sidebar and the tab rail.
 *
 * Registration sites pass their own previous title as the fallback, so a screen
 * this file does not know about keeps working and simply keeps its old name.
 */
function wppilot_nav_label(string $slug, string $fallback = ''): string
{
    $entry = wppilot_nav_map()[$slug] ?? null;
    if (is_array($entry) && is_string($entry['label'] ?? null) && $entry['label'] !== '') {
        return $entry['label'];
    }

    return $fallback !== '' ? $fallback : $slug;
}

/**
 * The group a screen belongs to.
 *
 * Unknown screens land in the last group rather than being dropped: an add-on
 * that has not declared itself should still be reachable.
 */
function wppilot_nav_group(string $slug): string
{
    $entry = wppilot_nav_map()[$slug] ?? null;
    if (is_array($entry) && is_string($entry['group'] ?? null) && $entry['group'] !== '') {
        return $entry['group'];
    }

    $groups = wppilot_nav_groups();
    $keys = array_keys($groups);

    return $keys === [] ? 'system' : (string) end($keys);
}
