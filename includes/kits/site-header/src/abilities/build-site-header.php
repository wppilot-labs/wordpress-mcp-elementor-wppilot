<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteHeader;

use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

$tri = ['type' => 'string', 'enum' => ['auto', 'yes', 'no'], 'default' => 'auto'];

wp_register_ability('wppilot/build-site-header', [
    'label' => __('Build Site Header', domain: 'wppilot'),
    'description' => __(
        'Builds the site-wide header from a proven layout instead of a hand-made element tree: logo and site title on the left, the menu in one row (a menu button on tablets and phones), and on the right the language switcher as a styled dropdown, an optional call-to-action button and the cart icon. Use this for any request to create, fix, redesign or "make professional" a site header - do not hand-build a header template. Elementor Pro: a Theme Builder header shown on every page, one per language when Polylang is active (each with that language\'s menu, swapped in automatically), replacing the headers that had display conditions (they are kept, only their conditions are removed). The layout is sized from the menu: a menu too long for one row becomes a menu button at every width, and the site title gives way to the logo on phones when both do not fit. After saving, it reads each language\'s home page as a visitor and checks the header that came back (the right template, the menu, a menu button for small screens, the switcher styled rather than a bare list, the cart icon, the phone row\'s width); if a check fails it removes what it built, restores the previous header and returns the failed checks. Block themes (builder block-theme, or auto without Elementor Pro): the header template part of the theme as one row - site logo and title, a navigation block made from the menu of each language with the Polylang switcher as its last item and an overlay menu on phones, and the WooCommerce mini-cart - with one translated template part per Polylang language. Undo restores the previous header. dry_run returns the plan without writing. A classic theme without Elementor Pro is refused with what the theme supports instead.',
        domain: 'wppilot',
    ),
    'category' => 'appearance',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'builder' => ['type' => 'string', 'enum' => ['auto', 'elementor', 'block-theme'], 'default' => 'auto', 'description' => 'auto picks Elementor Pro when it is active, else the block theme.'],
            'logo_id' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Media library image for the logo; 0 or omitted uses the site logo (Customizer), if any.'],
            'site_title' => ['type' => 'string', 'maxLength' => 120, 'description' => 'Text beside the logo; defaults to the site title. Empty string for logo only.'],
            'menu' => ['type' => ['integer', 'string'], 'description' => 'Menu ID or slug for a single-language site. Defaults to the main (or longest) menu.'],
            'menus' => ['type' => 'object', 'additionalProperties' => ['type' => ['integer', 'string']], 'description' => 'Per language: {"en": 63, "ru": 64}. Defaults to the menu whose name ends in the language code, such as "Main menu (EN)".'],
            'show_language_switcher' => $tri + ['description' => 'auto: when Polylang has two or more languages.'],
            'show_cart' => $tri + ['description' => 'auto: when WooCommerce is active.'],
            'cta' => [
                'type' => 'object',
                'properties' => ['label' => ['type' => 'string', 'maxLength' => 40], 'url' => ['type' => 'string', 'maxLength' => 2000]],
                'required' => ['label', 'url'],
                'additionalProperties' => false,
                'description' => 'Optional button on the right at desktop width, such as {"label": "Order online", "url": "/shop/"}.',
            ],
            'sticky' => ['type' => 'boolean', 'default' => false, 'description' => 'Keep the header at the top while scrolling.'],
            'colors' => [
                'type' => 'object',
                'properties' => [
                    'background' => ['type' => 'string', 'maxLength' => 7],
                    'text' => ['type' => 'string', 'maxLength' => 7],
                    'accent' => ['type' => 'string', 'maxLength' => 7],
                ],
                'additionalProperties' => false,
                'description' => 'Hex colours. Text and accent default to the Elementor kit\'s global colours when the site has set them (otherwise near-black); background defaults to white.',
            ],
            'fonts' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'maxLength' => 60],
                    'menu' => ['type' => 'string', 'maxLength' => 60],
                ],
                'additionalProperties' => false,
                'description' => 'Font family names for the site title and the menu, such as the site heading and body fonts. Omitted, they follow the global typography of the Elementor kit.',
            ],
            'dry_run' => ['type' => 'boolean', 'default' => false, 'description' => 'Return the layout plan (menus found, width estimates, what would be replaced) without writing.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input = []): array|WP_Error => build($input),
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('edit_theme_options') && current_user_can('publish_pages'),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
