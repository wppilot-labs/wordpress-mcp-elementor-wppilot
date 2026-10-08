<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteHeader;

if (!defined('ABSPATH')) {
    exit();
}

/*
 * The header's layout, decided from what goes in it, and the checks run on the served page.
 * Nothing here touches WordPress: it is plain data in and out, so it is tested on its own.
 */

/** Widths the header is laid out for: the boxed content width and the narrowest phone. */
const CONTENT_WIDTH = 1200;
const PHONE_WIDTH = 375;

/**
 * Average advance of one character, in px per px of font size: 0.56 for a sans or serif
 * at regular weight, 0.62 bold. Deliberately on the wide side; an estimate that errs narrow
 * is the one that ships a wrapped menu.
 */
const CHAR_EM = 0.56;
const CHAR_EM_BOLD = 0.62;

function text_width(string $text, float $font_px, bool $bold = false): float
{
    $chars = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);

    return $chars * $font_px * ($bold ? CHAR_EM_BOLD : CHAR_EM);
}

/**
 * Decide the layout.
 *
 * @param array{title: string, has_logo: bool, nav: list<array{title: string, children: bool}>, language: bool, cart: bool, cta: string} $parts
 * @return array<string, mixed>
 */
function plan(array $parts): array
{
    $title = trim($parts['title']);
    $has_logo = $parts['has_logo'];
    $nav = $parts['nav'];
    $language = $parts['language'];
    $cart = $parts['cart'];
    $cta = trim($parts['cta']);

    $title_px = 20;
    $brand = ($has_logo ? 44 + 12 : 0) + ($title !== '' ? min(text_width($title, $title_px, bold: true), 320.0) : 0);
    $right = ($language ? 110 : 0) + ($cart ? 48 : 0) + ($cta !== '' ? text_width($cta, 15, bold: true) + 48 : 0);
    $right += max(0, (int) $language + (int) $cart + (int) ($cta !== '') - 1) * 16;
    $gaps = 2 * 32;
    $available = CONTENT_WIDTH - $brand - $right - $gaps;

    // Try the comfortable menu first, then a tighter one; a menu that fits neither is a menu
    // button at every width, which is a working header rather than one that wraps.
    $layout = 'dropdown';
    $nav_font = 15;
    $nav_pad = 10;
    $needed = 0.0;
    foreach ([[16, 16], [15, 12], [15, 9]] as [$font, $pad]) {
        $needed = 0.0;
        foreach ($nav as $item) {
            $needed += text_width($item['title'], $font) + 2 * $pad + ($item['children'] ? 14 : 0);
        }
        if ($needed <= $available) {
            $layout = 'horizontal';
            $nav_font = $font;
            $nav_pad = $pad;
            break;
        }
    }
    if ($nav === []) {
        $layout = 'none';
    }

    // The phone row: logo, title, language, cart, menu button. The title wraps at spaces, so
    // only its longest word must fit; when even that does not, the logo stands alone.
    $longest = 0.0;
    foreach (preg_split('/\s+/u', $title) ?: [] as $word) {
        $longest = max($longest, text_width((string) $word, 16, bold: true));
    }
    $phone_fixed = 2 * 16 + ($has_logo ? 36 + 10 : 0) + ($language ? 92 + 10 : 0) + ($cart ? 40 + 10 : 0) + ($layout !== 'none' ? 32 + 10 : 0);
    $hide_title_on_phone = $has_logo && $title !== '' && $phone_fixed + $longest > PHONE_WIDTH;
    // Without a logo the title is the brand: it shrinks instead of leaving.
    $phone_title_px = !$has_logo && $phone_fixed + $longest > PHONE_WIDTH ? 14 : 16;

    return [
        'nav_layout' => $layout,
        'nav_font_px' => $nav_font,
        'nav_item_padding_px' => $nav_pad,
        'title_px' => $title_px,
        'phone_title_px' => $phone_title_px,
        'hide_title_on_phone' => $hide_title_on_phone,
        'hide_cta_below_desktop' => $cta !== '',
        'estimate' => [
            'content_width' => CONTENT_WIDTH,
            'brand_px' => (int) round($brand),
            'right_px' => (int) round($right),
            'nav_needed_px' => (int) round($needed),
            'nav_available_px' => (int) round($available),
            'phone_needed_px' => (int) round($phone_fixed + ($hide_title_on_phone ? 0 : $longest)),
            'phone_width' => PHONE_WIDTH,
        ],
    ];
}

/**
 * The Elementor tree: one boxed flex row of widgets, no inner containers. Classic flexbox
 * container and widgets, because their responsive controls (order, visibility, the menu's
 * breakpoint) keep the phone row from overlapping, and every Elementor Pro site has them.
 *
 * Each widget is `_element_width: auto` (sized by its content) and fixed (`_flex_size: none`),
 * except the title, which may shrink and wrap, and the menu, which grows into the free space
 * and so pushes the switcher, button and cart to the right edge. Inner containers are avoided
 * on purpose: a nested container is 100% wide by default and pushes its siblings out of the row,
 * and a widget left at the default width in a row is zero wide, so its text spills over the next.
 *
 * @param array<string, mixed> $plan  From plan().
 * @param array{title: string, home_url: string, logo_id: int, logo_url: string, menu: string, switcher_menu: string, cart: bool, cta_label: string, cta_url: string, sticky: bool, colors: array<string, string>, fonts: array<string, string>} $content
 * @return list<array<string, mixed>>
 */
function elementor_tree(array $plan, array $content, callable $id): array
{
    $colors = $content['colors'] + ['text' => '#1f1f1f', 'accent' => '#1f1f1f', 'background' => '#ffffff'];
    $fonts = $content['fonts'];
    $px = static fn(int|float $n): array => ['unit' => 'px', 'size' => $n, 'sizes' => []];
    $box = static fn(int $t, int $r, int $b, int $l): array => ['unit' => 'px', 'top' => (string) $t, 'right' => (string) $r, 'bottom' => (string) $b, 'left' => (string) $l, 'isLinked' => false];
    $gap = static fn(int $n): array => ['unit' => 'px', 'size' => $n, 'column' => (string) $n, 'row' => (string) $n, 'isLinked' => true];
    $fixed = ['_element_width' => 'auto', '_flex_size' => 'none'];
    $font = static function (array $settings, string $prefix, string $family): array {
        if ($family !== '') {
            $settings[$prefix . '_font_family'] = $family;
        }

        return $settings;
    };
    $link = static fn(string $url): array => ['url' => $url, 'is_external' => '', 'nofollow' => ''];

    $row = [];
    if ($content['logo_id'] > 0) {
        $row[] = ['id' => $id(), 'elType' => 'widget', 'widgetType' => 'image', 'settings' => $fixed + [
            'image' => ['id' => $content['logo_id'], 'url' => $content['logo_url'], 'alt' => $content['title']],
            'image_size' => 'full',
            'link_to' => 'custom',
            'link' => $link($content['home_url']),
            'width' => $px(44),
            'width_mobile' => $px(36),
        ]];
    }
    if ($content['title'] !== '') {
        $title = $font([
            'title' => $content['title'],
            'header_size' => 'div',
            'link' => $link($content['home_url']),
            'title_color' => $colors['text'],
            'typography_typography' => 'custom',
            'typography_font_size' => $px($plan['title_px']),
            'typography_font_size_mobile' => $px($plan['phone_title_px']),
            'typography_font_weight' => '700',
            'typography_line_height' => ['unit' => 'em', 'size' => 1.15, 'sizes' => []],
            '_element_width' => 'auto',
            // Below desktop the menu is a button, so the title takes the free space and pushes
            // the switcher, cart and menu button to the right edge. With no menu it always does.
            '_flex_size' => $plan['nav_layout'] === 'none' ? 'grow' : 'custom',
            '_flex_grow' => 0,
            '_flex_shrink' => 1,
            // Elementor's "grow" also sets flex-shrink 0, which would stop the title wrapping
            // on a narrow phone; custom keeps both.
            '_flex_size_tablet' => 'custom',
            '_flex_grow_tablet' => 1,
            '_flex_shrink_tablet' => 1,
            '_flex_size_mobile' => 'custom',
            '_flex_grow_mobile' => 1,
            '_flex_shrink_mobile' => 1,
        ], 'typography', (string) ($fonts['title'] ?? ''));
        if ($plan['hide_title_on_phone']) {
            $title['hide_mobile'] = 'hidden-mobile';
        }
        $row[] = ['id' => $id(), 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => $title];
    }

    $base_style = static fn(array $settings, int $size): array => $font($settings + [
        'menu_typography_typography' => 'custom',
        'menu_typography_font_size' => $px($size),
        'menu_typography_font_weight' => '500',
        'color_menu_item' => $colors['text'],
        'color_menu_item_hover' => $colors['accent'],
        'color_menu_item_active' => $colors['accent'],
        'pointer_color_menu_item_hover' => $colors['accent'],
        'pointer_color_menu_item_active' => $colors['accent'],
        'color_dropdown_item' => $colors['text'],
        'background_color_dropdown_item' => $colors['background'],
        'color_dropdown_item_hover' => $colors['accent'],
        'background_color_dropdown_item_hover' => $colors['background'],
        'dropdown_typography_typography' => 'custom',
        'dropdown_typography_font_size' => $px(15),
        // The open menu sits over the page; a shadow keeps it from reading as page content.
        'dropdown_box_shadow_box_shadow_type' => 'yes',
        'dropdown_box_shadow_box_shadow' => ['horizontal' => 0, 'vertical' => 8, 'blur' => 24, 'spread' => 0, 'color' => 'rgba(0,0,0,0.12)'],
    ], 'menu_typography', (string) ($fonts['menu'] ?? ''));
    $menu_style = $base_style;
    if (($fonts['menu'] ?? '') !== '') {
        $menu_style = static fn(array $settings, int $size): array => $base_style($settings, $size) + ['dropdown_typography_font_family' => (string) $fonts['menu']];
    }

    $has_menu = $plan['nav_layout'] !== 'none' && $content['menu'] !== '';
    if ($has_menu) {
        $row[] = ['id' => $id(), 'elType' => 'widget', 'widgetType' => 'nav-menu', 'settings' => $menu_style([
            'menu' => $content['menu'],
            'layout' => $plan['nav_layout'] === 'dropdown' ? 'dropdown' : 'horizontal',
            'align_items' => 'end',
            'pointer' => 'underline',
            'animation_line' => 'fade',
            'submenu_icon' => ['value' => 'fas fa-chevron-down', 'library' => 'fa-solid'],
            'dropdown' => 'tablet',
            'full_width' => 'stretch',
            'text_align' => 'aside',
            'toggle' => 'burger',
            'toggle_align' => 'right',
            'padding_horizontal_menu_item' => $px($plan['nav_item_padding_px']),
            'menu_space_between' => $px(0),
            'toggle_color' => $colors['text'],
            'toggle_size' => $px(24),
            'dropdown_top_distance' => $px(14),
            'toggle_background_color' => 'rgba(0,0,0,0)',
            '_element_width' => 'auto',
            '_flex_size' => 'grow',
            '_flex_size_tablet' => 'none',
            // A phone without the title has nothing else to take the free space.
            '_flex_size_mobile' => $plan['hide_title_on_phone'] ? 'grow' : 'none',
            // A menu button sits last, after the switcher and cart, wherever it shows.
            '_flex_order' => $plan['nav_layout'] === 'dropdown' ? 'end' : '',
            '_flex_order_tablet' => 'end',
            '_flex_order_mobile' => 'end',
        ], (int) $plan['nav_font_px'])];
    }

    if ($content['switcher_menu'] !== '') {
        // Polylang's switcher as one menu item with its own dropdown: a nav-menu widget styles
        // it like the menu and opens it on tap. Never a bare widget list.
        $row[] = ['id' => $id(), 'elType' => 'widget', 'widgetType' => 'nav-menu', 'settings' => $fixed + $menu_style([
            'menu' => $content['switcher_menu'],
            'layout' => 'horizontal',
            'pointer' => 'none',
            'submenu_icon' => ['value' => 'fas fa-chevron-down', 'library' => 'fa-solid'],
            'dropdown' => 'none',
            'padding_horizontal_menu_item' => $px(6),
            'padding_vertical_menu_item' => $px(8),
        ], 14)];
    }
    if ($content['cta_label'] !== '') {
        $row[] = ['id' => $id(), 'elType' => 'widget', 'widgetType' => 'button', 'settings' => $fixed + [
            'text' => $content['cta_label'],
            'link' => $link($content['cta_url']),
            'size' => 'sm',
            'background_color' => $colors['accent'],
            'button_text_color' => $colors['background'],
            'hide_tablet' => 'hidden-tablet',
            'hide_mobile' => 'hidden-mobile',
        ]];
    }
    if ($content['cart']) {
        $row[] = ['id' => $id(), 'elType' => 'widget', 'widgetType' => 'woocommerce-menu-cart', 'settings' => $fixed + [
            'icon' => 'bag-medium',
            'items_indicator' => 'bubble',
            'hide_empty_indicator' => 'yes',
            'show_subtotal' => '',
            'cart_type' => 'side-cart',
            'toggle_button_border_width' => $px(0),
            'toggle_icon_size' => $px(22),
            'toggle_button_padding' => $box(6, 4, 6, 4),
            'toggle_button_icon_color' => $colors['text'],
            'toggle_button_background_color' => 'rgba(0,0,0,0)',
        ]];
    }

    $outer = [
        'html_tag' => 'header',
        'content_width' => 'boxed',
        'boxed_width' => $px(CONTENT_WIDTH),
        'flex_direction' => 'row',
        'flex_direction_tablet' => 'row',
        'flex_direction_mobile' => 'row',
        'flex_wrap' => 'nowrap',
        'flex_wrap_tablet' => 'nowrap',
        'flex_wrap_mobile' => 'nowrap',
        'flex_justify_content' => 'flex-start',
        'flex_align_items' => 'center',
        'flex_gap' => $gap(16),
        'flex_gap_mobile' => $gap(10),
        'padding' => $box(14, 24, 14, 24),
        'padding_mobile' => $box(10, 16, 10, 16),
        'background_background' => 'classic',
        'background_color' => $colors['background'],
        'border_border' => 'solid',
        'border_width' => ['unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '1', 'left' => '0', 'isLinked' => false],
        'border_color' => 'rgba(0,0,0,0.08)',
        'z_index' => 50,
    ];
    if ($content['sticky']) {
        $outer['sticky'] = 'top';
        $outer['sticky_on'] = ['desktop', 'tablet', 'mobile'];
    }

    return [['id' => $id(), 'elType' => 'container', 'isInner' => false, 'settings' => $outer, 'elements' => $row]];
}

/**
 * Check a served page's header. Static: no layout engine runs here, so overlap and wrapping
 * are judged from what was rendered against the plan's width estimate, and the real-browser
 * look is left to a screenshot.
 *
 * @param array{template_id: int, nav_items: int, language: bool, cart: bool, plan: array<string, mixed>} $expect
 * @return array{passed: bool, checks: list<array{check: string, passed: bool, detail: string}>}
 */
function check_served_header(string $html, array $expect): array
{
    $checks = [];
    $add = static function (string $check, bool $passed, string $detail) use (&$checks): void {
        $checks[] = ['check' => $check, 'passed' => $passed, 'detail' => $detail];
    };
    $document = new \DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $html !== '' && $document->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        $add('served', false, 'The page could not be read.');

        return ['passed' => false, 'checks' => $checks];
    }
    $xpath = new \DOMXPath($document);
    $class = static fn(string $c): string => 'contains(concat(" ", normalize-space(@class), " "), " ' . $c . ' ")';

    $header = $xpath->query('//*[@data-elementor-type="header"][@data-elementor-id="' . $expect['template_id'] . '"]');
    $root = $header !== false && $header->length > 0 ? $header->item(0) : null;
    $add('header_served', $root instanceof \DOMElement, $root instanceof \DOMElement
        ? 'The page renders header template ' . $expect['template_id'] . '.'
        : 'The page does not render header template ' . $expect['template_id'] . ' (another header won, or the theme does not print Elementor headers).');
    if (!$root instanceof \DOMElement) {
        return ['passed' => false, 'checks' => $checks];
    }

    $plan = $expect['plan'];
    if ($expect['nav_items'] > 0) {
        // The site menu is the first desktop menu in the header; the switcher is a later one.
        $navs = $xpath->query('.//nav[' . $class('elementor-nav-menu--main') . ']', $root);
        $first = $navs !== false && $navs->length > 0 ? $navs->item(0) : null;
        $items = $first instanceof \DOMElement ? $xpath->query('./ul[' . $class('elementor-nav-menu') . ']/li', $first) : false;
        $count = $items === false ? 0 : $items->length;
        $toggle = $xpath->query('.//*[' . $class('elementor-menu-toggle') . ']', $root);
        $has_toggle = $toggle !== false && $toggle->length > 0;
        if ($plan['nav_layout'] === 'horizontal') {
            // The first menu nav-menu; the switcher is a second one with a single item.
            $add('menu_one_row', $count >= 1 && $plan['estimate']['nav_needed_px'] <= $plan['estimate']['nav_available_px'], sprintf(
                '%d menu items rendered; they need about %dpx of the %dpx left between the brand and the right-hand group at desktop width.',
                $count,
                $plan['estimate']['nav_needed_px'],
                $plan['estimate']['nav_available_px'],
            ));
        } else {
            $add('menu_one_row', true, 'The menu is too long for one row at desktop width, so it is a menu button at every width.');
        }
        $add('menu_button_on_small_screens', $has_toggle, $has_toggle ? 'Tablets and phones get a menu button.' : 'No menu button is rendered for tablets and phones.');
    }

    $raw = $xpath->query('.//ul/li[' . $class('lang-item') . '][not(ancestor::*[' . $class('elementor-nav-menu') . '])]', $root);
    $raw_count = $raw === false ? 0 : $raw->length;
    if ($expect['language']) {
        $switch = $xpath->query('.//nav[' . $class('elementor-nav-menu--main') . ']//li[' . $class('lang-item') . ']', $root);
        $switch_count = $switch === false ? 0 : $switch->length;
        $add('language_switcher_styled', $switch_count > 0 && $raw_count === 0, $switch_count > 0
            ? sprintf('The language switcher is a menu with %d languages, styled with the header.', $switch_count)
            : 'No language switcher items are rendered.');
    } else {
        $add('no_raw_language_list', $raw_count === 0, $raw_count === 0 ? 'No unstyled language list.' : 'An unstyled language list is printed in the header.');
    }

    if ($expect['cart']) {
        $icon = $xpath->query('.//*[' . $class('elementor-menu-cart__toggle_button') . ']//svg', $root);
        $has_icon = $icon !== false && $icon->length > 0;
        $add('cart_icon', $has_icon, $has_icon ? 'The cart button has its icon.' : 'The cart button renders without an icon.');
    }

    $add('phone_row_fits', $plan['estimate']['phone_needed_px'] <= $plan['estimate']['phone_width'], sprintf(
        'The phone row needs about %dpx of %dpx%s.',
        $plan['estimate']['phone_needed_px'],
        $plan['estimate']['phone_width'],
        $plan['hide_title_on_phone'] ? ' (the site title is hidden on phones; the logo stays)' : '',
    ));

    $passed = true;
    foreach ($checks as $check) {
        $passed = $passed && $check['passed'];
    }

    return ['passed' => $passed, 'checks' => $checks];
}
