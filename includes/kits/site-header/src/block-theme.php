<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteHeader;

use WP_Error;
use WPPilot\Kits\Runtime\Page;

if (!defined('ABSPATH')) {
    exit();
}

/*
 * The block-theme header: the theme's `header` template part, rewritten as one row - logo and
 * site title, then a navigation block per language (Polylang's `pll_lang` block attribute shows
 * each only on its own language's pages) with Polylang's switcher as its last item, then the
 * WooCommerce mini-cart. Each language's classic menu becomes a navigation post of its own.
 */

/**
 * Navigation-link blocks for a classic menu's items, nested one level.
 *
 * @param list<array{id: int, parent: int, title: string, url: string, object: string, object_id: int, type: string}> $items
 */
function navigation_markup(array $items, bool $switcher): string
{
    $children = [];
    foreach ($items as $item) {
        $children[$item['parent']][] = $item;
    }
    $link = static function (array $item): array {
        $attrs = ['label' => $item['title'], 'url' => $item['url']];
        if ($item['type'] === 'post_type' && $item['object_id'] > 0) {
            $attrs += ['type' => $item['object'], 'id' => $item['object_id'], 'kind' => 'post-type'];
        } else {
            $attrs['kind'] = 'custom';
        }

        return $attrs;
    };
    $json = static fn(array $attrs): string => (string) wp_json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    $out = '';
    foreach ($children[0] ?? [] as $item) {
        $sub = $children[$item['id']] ?? [];
        if ($sub === []) {
            $out .= '<!-- wp:navigation-link ' . $json($link($item)) . ' /-->' . "\n";
            continue;
        }
        $out .= '<!-- wp:navigation-submenu ' . $json($link($item)) . ' -->' . "\n";
        foreach ($sub as $child) {
            $out .= '<!-- wp:navigation-link ' . $json($link($child)) . ' /-->' . "\n";
        }
        $out .= '<!-- /wp:navigation-submenu -->' . "\n";
    }
    if ($switcher) {
        $out .= '<!-- wp:polylang/navigation-language-switcher {"dropdown":true,"show_names":true} /-->' . "\n";
    }

    return $out;
}

/**
 * The template part's content.
 *
 * @param array<string, mixed> $plan
 * @param array{logo: string, navigations: array<string, int>, cart: bool} $parts  logo is block markup or ''.
 */
function header_part_markup(array $plan, array $parts): string
{
    $overlay = $plan['nav_layout'] === 'dropdown' ? 'always' : 'mobile';
    $nav = '';
    // One navigation block, pointing at the default language's navigation. Polylang translates
    // navigation posts, so on a translated page it renders that language's linked navigation.
    $ref = $parts['navigations'] !== [] ? (int) reset($parts['navigations']) : 0;
    if ($ref > 0) {
        $attrs = ['ref' => $ref, 'overlayMenu' => $overlay, 'layout' => ['type' => 'flex', 'justifyContent' => 'right', 'flexWrap' => 'nowrap']];
        $nav .= '<!-- wp:navigation ' . wp_json_encode($attrs, JSON_UNESCAPED_SLASHES) . ' /-->' . "\n";
    }
    $title = '<!-- wp:site-title {"level":0} /-->';

    return '<!-- wp:group {"tagName":"header","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30"}}},"layout":{"type":"constrained"}} -->' . "\n"
        . '<header class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30)">'
        . '<!-- wp:group {"align":"wide","layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->' . "\n"
        . '<div class="wp-block-group alignwide">'
        . '<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"}} -->' . "\n"
        . '<div class="wp-block-group">' . $parts['logo'] . $title . '</div>' . "\n"
        . '<!-- /wp:group -->' . "\n"
        . '<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"right"}} -->' . "\n"
        . '<div class="wp-block-group">' . $nav . ($parts['cart'] ? '<!-- wp:woocommerce/mini-cart /-->' . "\n" : '') . '</div>' . "\n"
        . '<!-- /wp:group --></div>' . "\n"
        . '<!-- /wp:group --></header>' . "\n"
        . '<!-- /wp:group -->';
}

/**
 * Check a served block-theme header.
 *
 * @param array{nav_items: int, language: bool, cart: bool|string, plan: array<string, mixed>} $expect  cart 'hidden': WooCommerce hides it.
 * @return array{passed: bool, checks: list<array{check: string, passed: bool, detail: string}>}
 */
function check_served_block_header(string $html, array $expect): array
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
    $xpath = $loaded ? new \DOMXPath($document) : null;
    $class = static fn(string $c): string => 'contains(concat(" ", normalize-space(@class), " "), " ' . $c . ' ")';
    $headers = $xpath?->query('//header[' . $class('wp-block-template-part') . ']|//*[' . $class('wp-block-template-part') . ']//header');
    $root = $headers !== null && $headers !== false && $headers->length > 0 ? $headers->item(0) : null;
    $add('header_served', $root instanceof \DOMElement, $root instanceof \DOMElement ? 'The theme header template part is served.' : 'No block-theme header is served (another plugin\'s header may replace it).');
    if (!$root instanceof \DOMElement || $xpath === null) {
        return ['passed' => false, 'checks' => $checks];
    }
    $navs = $xpath->query('.//nav[' . $class('wp-block-navigation') . ']', $root);
    $nav = $navs !== false && $navs->length > 0 ? $navs->item(0) : null;
    if ($expect['nav_items'] > 0) {
        $links = $nav instanceof \DOMElement ? $xpath->query('.//ul[' . $class('wp-block-navigation__container') . ']/li', $nav) : false;
        $count = $links === false ? 0 : $links->length;
        // This language's own menu: its top-level items plus the switcher, no more, no fewer.
        $want = $expect['nav_items'] + ($expect['language'] ? 1 : 0);
        $add('menu_rendered', $count === $want, sprintf('%d menu items rendered; the menu for this language has %d.', $count, $want));
        $open = $nav instanceof \DOMElement ? $xpath->query('.//*[' . $class('wp-block-navigation__responsive-container-open') . ']', $nav) : false;
        $add('menu_button_on_small_screens', $open !== false && $open->length > 0, 'The navigation has its overlay menu button.');
        if ($navs !== false && $navs->length > 1) {
            $add('one_language_menu', false, 'More than one language\'s menu is printed on this page.');
        }
    }
    if ($expect['language']) {
        $switch = $nav instanceof \DOMElement ? $xpath->query('.//*[' . $class('lang-item') . ']', $nav) : false;
        $add('language_switcher_styled', $switch !== false && $switch->length > 0, 'The language switcher is an item of the navigation.');
    }
    $raw = $xpath->query('.//ul/li[' . $class('lang-item') . '][not(ancestor::nav)]', $root);
    $add('no_raw_language_list', $raw === false || $raw->length === 0, 'No unstyled language list.');
    if ($expect['cart'] === 'hidden') {
        $add('cart_icon', true, 'WooCommerce is in coming-soon mode for store pages, so it hides the mini-cart from visitors; it shows once the store is live.');
    } elseif ($expect['cart']) {
        $cart = $xpath->query('.//*[' . $class('wc-block-mini-cart') . ']', $root);
        $add('cart_icon', $cart !== false && $cart->length > 0, 'The mini-cart block is rendered.');
    }

    $passed = true;
    foreach ($checks as $check) {
        $passed = $passed && $check['passed'];
    }

    return ['passed' => $passed, 'checks' => $checks];
}

/**
 * Items of a classic menu as navigation_markup() takes them.
 *
 * @return list<array{id: int, parent: int, title: string, url: string, object: string, object_id: int, type: string}>
 */
function menu_items(\WP_Term $menu): array
{
    $items = wp_get_nav_menu_items($menu->term_id, ['update_post_term_cache' => false]);
    $out = [];
    foreach (is_array($items) ? $items : [] as $item) {
        $out[] = [
            'id' => (int) $item->ID,
            'parent' => (int) $item->menu_item_parent,
            'title' => wp_strip_all_tags((string) $item->title),
            'url' => (string) $item->url,
            'object' => (string) $item->object,
            'object_id' => (int) $item->object_id,
            'type' => (string) $item->type,
        ];
    }

    return $out;
}

/**
 * A page of this language that the theme itself renders, so its header template part shows.
 * A page on an Elementor page template (Canvas, Full Width) prints Elementor's header locations
 * instead, so the home page is skipped when it uses one.
 */
function theme_template_url(string $language): string
{
    $home = $language !== '' && function_exists('pll_home_url') ? (string) pll_home_url($language) : home_url('/');
    $front = (int) get_option('page_on_front');
    if ($front > 0 && $language !== '' && function_exists('pll_get_post')) {
        // A language without its own front page is served the default one.
        $front = (int) pll_get_post($front, $language) ?: $front;
    }
    if (get_option('show_on_front') !== 'page' || $front <= 0 || !str_starts_with((string) get_page_template_slug($front), 'elementor_')) {
        return $home;
    }
    $args = ['post_type' => ['post', 'page'], 'post_status' => 'publish', 'posts_per_page' => 20, 'orderby' => 'date', 'order' => 'DESC'];
    if ($language !== '') {
        $args['lang'] = $language;
    }
    foreach (get_posts($args) as $post) {
        $slug = (string) get_page_template_slug($post);
        if (!str_starts_with($slug, 'elementor_') && get_post_meta($post->ID, '_elementor_edit_mode', true) !== 'builder') {
            return (string) get_permalink($post);
        }
    }

    return '';
}

/** The active theme's header template part, as the site editor sees it. */
function header_part(): ?\WP_Block_Template
{
    $found = get_block_templates(['slug__in' => ['header']], 'wp_template_part');

    return $found[0] ?? null;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function build_block_theme(array $input): array|WP_Error
{
    $part = header_part();
    if (!$part instanceof \WP_Block_Template) {
        return new WP_Error('kit_site_header_no_part', 'The active block theme has no "header" template part to build.', ['status' => 400]);
    }
    $langs = languages();
    $language_list = $langs['languages'] !== [] ? $langs['languages'] : [''];
    $want_switcher = $langs['plugin'] === 'polylang' && (string) ($input['show_language_switcher'] ?? 'auto') !== 'no';
    $want_cart = class_exists('WooCommerce') && (string) ($input['show_cart'] ?? 'auto') !== 'no';
    $logo_id = (int) ($input['logo_id'] ?? 0);
    $site_logo = (int) get_theme_mod('custom_logo', 0);
    $title = (string) get_bloginfo('name');

    $plans = [];
    $menus = [];
    $notes = [];
    foreach ($language_list as $language) {
        $menus[$language] = pick_menu($input, $language);
    }
    $fallback = null;
    foreach ($menus as $menu) {
        if ($menu instanceof \WP_Term && (!$fallback instanceof \WP_Term || $menu->count > $fallback->count)) {
            $fallback = $menu;
        }
    }
    $widest = null;
    foreach ($language_list as $language) {
        if (!$menus[$language] instanceof \WP_Term && $fallback instanceof \WP_Term) {
            $menus[$language] = $fallback;
            $notes[] = sprintf('No menu for language "%s" was found, so its header uses "%s".', $language, $fallback->name);
        }
        $nav = $menus[$language] instanceof \WP_Term ? menu_top_level($menus[$language]) : [];
        // The switcher is a navigation item here, so it counts toward the menu's width.
        $plans[$language] = plan(['title' => $title, 'has_logo' => $logo_id > 0 || $site_logo > 0, 'nav' => array_merge($nav, $want_switcher ? [['title' => 'English', 'children' => true]] : []), 'language' => false, 'cart' => $want_cart, 'cta' => '']);
        if ($widest === null || $plans[$language]['estimate']['nav_needed_px'] > $widest['estimate']['nav_needed_px']) {
            $widest = $plans[$language];
        }
    }
    $summary = [
        'builder' => 'block-theme',
        'template_part' => $part->id,
        'languages' => $langs['languages'],
        'language_switcher' => $want_switcher,
        'cart' => $want_cart,
        'plans' => $plans,
        'notes' => array_merge($notes, array_values(array_filter([
            isset($input['cta']) || isset($input['colors']) || isset($input['fonts']) || !empty($input['sticky'])
                ? 'cta, colors, fonts and sticky apply to the Elementor header; a block-theme header takes its colours and type from the theme (Global Styles).'
                : '',
        ]))),
    ];
    if (!empty($input['dry_run'])) {
        return $summary + ['dry_run' => true];
    }

    $before = ['source' => (string) $part->source, 'wp_id' => (int) $part->wp_id, 'content' => (string) $part->content];
    $created_navigations = [];
    $created_parts = [];
    $restore = static function () use (&$created_navigations, &$created_parts, $before, $part): void {
        foreach (array_merge($created_parts, $created_navigations) as $id) {
            wp_delete_post($id, true);
        }
        restore_part($part->id, $before);
    };
    try {
        $refs = [];
        foreach ($language_list as $language) {
            if (!$menus[$language] instanceof \WP_Term) {
                continue;
            }
            $nav_id = wp_insert_post(wp_slash([
                'post_type' => 'wp_navigation',
                'post_status' => 'publish',
                'post_title' => $language !== '' ? sprintf('Header navigation (%s)', strtoupper($language)) : 'Header navigation',
                'post_content' => navigation_markup(menu_items($menus[$language]), $want_switcher),
            ]), true);
            if ($nav_id instanceof WP_Error) {
                $restore();

                return $nav_id;
            }
            $created_navigations[] = (int) $nav_id;
            if ($language !== '' && function_exists('pll_set_post_language')) {
                pll_set_post_language((int) $nav_id, $language);
            }
            $refs[$language] = (int) $nav_id;
        }
        if (count($refs) > 1 && function_exists('pll_save_post_translations')) {
            pll_save_post_translations($refs);
        }
        $logo = '';
        if ($logo_id > 0 && $logo_id !== $site_logo) {
            $url = (string) wp_get_attachment_image_url($logo_id, 'full');
            $logo = '<!-- wp:image {"id":' . $logo_id . ',"width":"44px","sizeSlug":"full","linkDestination":"custom"} -->' . "\n"
                . '<figure class="wp-block-image size-full is-resized"><a href="' . esc_url(home_url('/')) . '"><img src="' . esc_url($url) . '" alt="' . esc_attr($title) . '" class="wp-image-' . $logo_id . '" style="width:44px"/></a></figure>' . "\n"
                . '<!-- /wp:image -->';
        } elseif ($site_logo > 0) {
            $logo = '<!-- wp:site-logo {"width":44} /-->';
        }
        $default = $language_list[0];
        $markup = static fn(string $language): string => header_part_markup((array) $widest, ['logo' => $logo, 'navigations' => isset($refs[$language]) ? [$language => $refs[$language]] : [], 'cart' => $want_cart]);
        $saved = save_part($part->id, $markup($default));
        if ($saved instanceof WP_Error) {
            $restore();

            return $saved;
        }
        // Polylang Pro serves a translated template part by slug: "header___ru" for Russian,
        // linked to the default one as its translation. Each carries its own language's menu.
        if ($langs['plugin'] === 'polylang' && function_exists('pll_set_post_language')) {
            $saved_part = header_part();
            $group = [$default => $saved_part instanceof \WP_Block_Template ? (int) $saved_part->wp_id : 0];
            if ($group[$default] > 0) {
                pll_set_post_language($group[$default], $default);
            }
            foreach (array_slice($language_list, 1) as $language) {
                $existing = get_posts(['post_type' => 'wp_template_part', 'name' => $part->slug . '___' . $language, 'post_status' => 'any', 'posts_per_page' => 1, 'lang' => '']);
                if ($existing !== []) {
                    // A translation someone made by hand: left alone, and said so.
                    $notes[] = sprintf('The %s header template part already exists and was left as it is.', strtoupper($language));
                    continue;
                }
                $id = wp_insert_post(wp_slash([
                    'post_type' => 'wp_template_part',
                    'post_status' => 'publish',
                    'post_name' => $part->slug . '___' . $language,
                    'post_title' => sprintf('Header (%s)', strtoupper($language)),
                    'post_content' => $markup($language),
                ]), true);
                if ($id instanceof WP_Error) {
                    $restore();

                    return $id;
                }
                $created_parts[] = (int) $id;
                wp_set_post_terms((int) $id, [get_stylesheet()], 'wp_theme');
                wp_set_post_terms((int) $id, ['header'], 'wp_template_part_area');
                pll_set_post_language((int) $id, $language);
                $group[$language] = (int) $id;
            }
            if (count(array_filter($group)) > 1 && function_exists('pll_save_post_translations')) {
                pll_save_post_translations(array_filter($group));
            }
        }

        $checks = [];
        $passed = true;
        foreach ($language_list as $language) {
            $url = theme_template_url($language);
            if ($url === '') {
                $checks[$language !== '' ? $language : 'site'] = ['url' => '', 'passed' => true, 'checks' => [[
                    'check' => 'header_served',
                    'passed' => true,
                    'detail' => 'Not checked: every page in this language uses an Elementor page template, which prints the Elementor header instead of the theme header.',
                ]]];
                continue;
            }
            $page = Page::fetch(add_query_arg('wppilot-kit-header-check', (string) time(), $url));
            $check = check_served_block_header(is_array($page) ? $page['html'] : '', [
                'nav_items' => $menus[$language] instanceof \WP_Term ? count(menu_top_level($menus[$language])) : 0,
                'language' => $want_switcher,
                'cart' => $want_cart && get_option('woocommerce_coming_soon') === 'yes' && get_option('woocommerce_store_pages_only') === 'yes' ? 'hidden' : $want_cart,
                'plan' => $plans[$language],
            ]);
            $checks[$language !== '' ? $language : 'site'] = ['url' => $url] + $check;
            $passed = $passed && $check['passed'];
        }
        if (!$passed) {
            $restore();

            return new WP_Error('kit_site_header_check_failed', 'The header was built but did not pass its own check on the served page, so the previous header was restored. Failed: ' . failed_checks($checks), ['status' => 422, 'checks' => $checks]);
        }
    } catch (\Throwable $e) {
        $restore();

        return new WP_Error('kit_site_header_failed', 'Building the header failed and the previous header was restored: ' . $e->getMessage());
    }

    return $summary + [
        'kind' => 'block-theme',
        'navigations' => $refs,
        'created_navigation_ids' => $created_navigations,
        'created_part_ids' => $created_parts,
        'part_before' => $before,
        'checks' => $checks,
        'look' => 'Structural checks passed. Screenshot each language at 1440, 768 and 390 px wide to see it; this check does not run a browser.',
    ];
}

/** Save the header template part through WordPress's own templates controller. */
function save_part(string $id, string $content): bool|WP_Error
{
    $request = new \WP_REST_Request('POST', '/wp/v2/template-parts/' . $id);
    $request->set_param('content', $content);
    $response = rest_do_request($request);

    return $response->is_error() ? $response->as_error() : true;
}

/**
 * Put the template part back: its earlier database copy, or the theme file when there was none.
 *
 * @param array{source: string, wp_id: int, content: string} $before
 */
function restore_part(string $id, array $before): bool|WP_Error
{
    if ($before['source'] === 'custom' && $before['wp_id'] > 0) {
        return save_part($id, $before['content']);
    }
    $request = new \WP_REST_Request('DELETE', '/wp/v2/template-parts/' . $id);
    $request->set_param('force', true);
    $response = rest_do_request($request);
    // Nothing to delete means it is already the theme file's.
    return $response->is_error() && $response->get_status() !== 404 ? $response->as_error() : true;
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>|WP_Error
 */
function undo_block_theme(array $payload): array|WP_Error
{
    foreach ((array) ($payload['created_part_ids'] ?? []) as $id) {
        $post = get_post((int) $id);
        if ($post instanceof \WP_Post && $post->post_type === 'wp_template_part') {
            wp_delete_post((int) $id, true);
        }
    }
    foreach ((array) ($payload['created_navigation_ids'] ?? []) as $id) {
        $post = get_post((int) $id);
        if ($post instanceof \WP_Post && $post->post_type === 'wp_navigation') {
            wp_delete_post((int) $id, true);
        }
    }
    $before = (array) ($payload['part_before'] ?? []);
    $id = (string) ($payload['template_part'] ?? '');
    $restored = restore_part($id, ['source' => (string) ($before['source'] ?? ''), 'wp_id' => (int) ($before['wp_id'] ?? 0), 'content' => (string) ($before['content'] ?? '')]);
    if ($restored instanceof WP_Error) {
        return $restored;
    }
    $part = header_part();
    $verified = $part instanceof \WP_Block_Template
        && (($before['source'] ?? '') === 'custom' ? $part->content === (string) $before['content'] : $part->source === 'theme');
    foreach ((array) ($payload['created_navigation_ids'] ?? []) as $nav) {
        $verified = $verified && !get_post((int) $nav) instanceof \WP_Post;
    }

    return ['template_part' => $id, 'navigations_deleted' => (array) ($payload['created_navigation_ids'] ?? []), 'verified' => $verified];
}
