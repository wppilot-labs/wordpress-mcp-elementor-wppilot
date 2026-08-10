<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * One entry in the WordPress sidebar, not fifteen.
 *
 * WPPilot has thirteen screens of its own and gains more from Pro. Listed
 * individually in the admin sidebar they pushed every other plugin — and half
 * of WordPress' own menu — below the fold, and they did it on every screen of
 * the site, not just WPPilot's. A plugin does not get to own the sidebar
 * because it happens to have a lot of pages. Navigation between WPPilot screens
 * belongs on the grouped tab rail inside the plugin, which is easier to read
 * than a flat list of fifteen anyway.
 *
 * This is done with CSS, and that is a deliberate choice rather than a
 * shortcut. The obvious implementation — remove_submenu_page() for each entry —
 * breaks every page it hides:
 *
 *   WordPress resolves a plugin page's hook through get_admin_page_parent(),
 *   which finds the parent by *searching $submenu for the page's slug*. Empty
 *   that array and the parent is unknown, so get_plugin_page_hookname() returns
 *   `admin_page_<slug>` instead of `wppilot_page_<slug>`, no callback is
 *   registered under that name, and admin.php answers "Sorry, you are not
 *   allowed to access this page."
 *
 * So the entries stay registered and stay in $submenu, exactly as WordPress
 * expects. Only their pixels go. The tab rail keeps reading $submenu as it
 * always has, and every page keeps loading.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The top-level menu slug WPPilot registers everything under.
 */
const WPPILOT_SIDEBAR_PARENT = 'wppilot-connect';

/**
 * Whether to collapse the sidebar to the single top-level item.
 *
 * Filterable because it is a presentation choice, and a site that genuinely
 * wants the flat list — or an agency pinning one screen for a client — should
 * be able to say so without editing the plugin.
 */
function wppilot_sidebar_is_collapsed(): bool
{
    return apply_filters('wppilot_collapse_admin_submenu', value: true) !== false;
}

/**
 * Hide WPPilot's submenu in the admin sidebar.
 *
 * Scoped to WPPilot's own top-level item, so no other plugin's menu is
 * affected, and applied with `display: none` on the container rather than on
 * individual items — WordPress renders that list both as the hover flyout and
 * as the expanded tree on WPPilot screens, and both should go.
 *
 * `aria-hidden` is not used and `display: none` is: the items are removed from
 * the accessibility tree along with the visual one, which is correct here
 * because they are duplicated by the tab rail on the page itself. A keyboard or
 * screen-reader user reaches every screen through the rail.
 */
function wppilot_sidebar_hide_submenu(): void
{
    if (!wppilot_sidebar_is_collapsed()) {
        return;
    }

    $selector = '#toplevel_page_' . WPPILOT_SIDEBAR_PARENT;

    printf(
        '<style id="wppilot-collapse-submenu">%s .wp-submenu{display:none !important;}</style>',
        esc_html($selector),
    );
}

add_action('admin_head', callback: 'wppilot_sidebar_hide_submenu');
