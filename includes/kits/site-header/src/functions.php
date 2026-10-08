<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteHeader;

use WP_Error;
use WPPilot\Kits\Runtime\Page;
use WPPilot\Kits\Runtime\Ledger;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/block-theme.php';

/** Undo: delete the headers (and switcher menu) this call made, give the old headers their conditions back. */
const STRATEGY = 'site-header/replace';

/** On the default-language header: {language slug: template id} of its translations. */
const TRANSLATIONS_META = '_wppilot_kit_header_translations';

/** Marks a menu this kit made to hold the language switcher, so a later run reuses it. */
const SWITCHER_MENU_NAME = 'Language switcher (header)';

/**
 * What the site can build a header with.
 *
 * @return array{builder: string, reason: string}
 */
function detect_builder(string $wanted): array
{
    $elementor_pro = defined('ELEMENTOR_PRO_VERSION') && class_exists('\ElementorPro\Modules\ThemeBuilder\Module');
    $block_theme = function_exists('wp_is_block_theme') && wp_is_block_theme();
    if ($wanted === 'elementor' || ($wanted === 'auto' && $elementor_pro)) {
        return $elementor_pro
            ? ['builder' => 'elementor', 'reason' => '']
            : ['builder' => '', 'reason' => 'An Elementor header is a Theme Builder template, which needs Elementor Pro. Without it the header is the theme\'s own.'];
    }
    if ($wanted === 'block-theme' || ($wanted === 'auto' && $block_theme)) {
        return $block_theme
            ? ['builder' => 'block-theme', 'reason' => '']
            : ['builder' => '', 'reason' => 'The active theme is not a block theme.'];
    }

    return ['builder' => '', 'reason' => 'This site has neither Elementor Pro nor a block theme, so its header is the classic theme\'s own: assign the menu to the theme\'s primary menu location (Appearance > Menus > Manage Locations) and set the logo in the Customizer.'];
}

/**
 * Text and accent colours from the active Elementor kit, when someone has set them. Elementor's
 * factory palette (light blue, grey, green) is nobody's brand, so it is ignored.
 *
 * @return array<string, string>
 */
function kit_palette(): array
{
    if (!class_exists('\Elementor\Plugin') || !isset(\Elementor\Plugin::$instance->kits_manager)) {
        return [];
    }
    $kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit_for_frontend();
    $system = is_object($kit) ? $kit->get_settings('system_colors') : null;
    $found = [];
    foreach (is_array($system) ? $system : [] as $row) {
        if (is_array($row) && isset($row['_id'], $row['color'])) {
            $found[(string) $row['_id']] = strtoupper((string) $row['color']);
        }
    }
    $factory = ['primary' => '#6EC1E4', 'secondary' => '#54595F', 'text' => '#7A7A7A', 'accent' => '#61CE70'];
    if ($found === [] || array_intersect_assoc($found, $factory) === $factory) {
        return [];
    }
    $palette = [];
    foreach (['text' => 'secondary', 'accent' => 'accent'] as $ours => $theirs) {
        $hex = sanitize_hex_color($found[$theirs] ?? '');
        if (is_string($hex) && $hex !== '') {
            $palette[$ours] = $hex;
        }
    }

    return $palette;
}

/**
 * The site's languages, default first, when a multilingual plugin manages them.
 *
 * @return array{plugin: string, default: string, languages: list<string>}
 */
function languages(): array
{
    if (function_exists('pll_languages_list') && function_exists('pll_default_language')) {
        $list = array_values(array_map('strval', (array) pll_languages_list(['fields' => 'slug'])));
        $default = (string) pll_default_language('slug');
        if (count($list) > 1) {
            usort($list, static fn(string $a, string $b): int => ($b === $default) <=> ($a === $default));

            return ['plugin' => 'polylang', 'default' => $default, 'languages' => $list];
        }
    }

    return ['plugin' => '', 'default' => '', 'languages' => []];
}

/** The language a request is in, as the multilingual plugin sees it. */
function current_language(): string
{
    return function_exists('pll_current_language') ? (string) pll_current_language('slug') : '';
}

/**
 * The menu for one language: given explicitly, else the one whose name or slug ends in the
 * language code, else (single-language sites) the menu with the most items.
 *
 * @param array<string, mixed> $input
 */
function pick_menu(array $input, string $language): ?\WP_Term
{
    $given = $language !== '' && is_array($input['menus'] ?? null) && isset($input['menus'][$language])
        ? $input['menus'][$language]
        : ($input['menu'] ?? null);
    if ($given !== null && $given !== '' && $given !== 0) {
        $menu = wp_get_nav_menu_object(is_numeric($given) ? (int) $given : (string) $given);

        return $menu instanceof \WP_Term ? $menu : null;
    }
    $candidates = [];
    foreach (wp_get_nav_menus() as $menu) {
        if ($menu->name === SWITCHER_MENU_NAME) {
            continue;
        }
        if ($language === '' || menu_is_for_language((string) $menu->name, $language)) {
            $candidates[] = $menu;
        }
    }
    // A menu named main, primary or header wins; then the longest.
    usort($candidates, static function (\WP_Term $a, \WP_Term $b): int {
        $main = static fn(\WP_Term $m): int => preg_match('/main|primary|header|главн|galven/iu', $m->name) === 1 ? 1 : 0;

        return [$main($b), $b->count] <=> [$main($a), $a->count];
    });

    return $candidates[0] ?? null;
}

/** "Main menu (EN)", "main-en", "Menu EN": the language code as the last word. */
function menu_is_for_language(string $name, string $language): bool
{
    return preg_match('/(^|[\s(_-])' . preg_quote($language, '/') . '\)?\s*$/iu', $name) === 1;
}

/**
 * Top-level items of a menu, as the width estimate needs them.
 *
 * @return list<array{title: string, children: bool}>
 */
function menu_top_level(\WP_Term $menu): array
{
    $items = wp_get_nav_menu_items($menu->term_id, ['update_post_term_cache' => false]);
    $items = is_array($items) ? $items : [];
    $parents = [];
    foreach ($items as $item) {
        $parents[(int) $item->menu_item_parent] = true;
    }
    $top = [];
    foreach ($items as $item) {
        if ((int) $item->menu_item_parent === 0) {
            $top[] = ['title' => wp_strip_all_tags((string) $item->title), 'children' => isset($parents[(int) $item->ID])];
        }
    }

    return $top;
}

/**
 * The menu holding Polylang's language switcher item, made once and reused.
 *
 * @return array{slug: string, id: int, created: bool}|WP_Error
 */
function ensure_switcher_menu(): array|WP_Error
{
    $existing = wp_get_nav_menu_object(SWITCHER_MENU_NAME);
    if ($existing instanceof \WP_Term) {
        return ['slug' => (string) $existing->slug, 'id' => (int) $existing->term_id, 'created' => false];
    }
    $id = wp_create_nav_menu(SWITCHER_MENU_NAME);
    if ($id instanceof WP_Error) {
        return $id;
    }
    $item = wp_update_nav_menu_item((int) $id, 0, [
        'menu-item-title' => __('Languages', domain: 'wppilot'),
        'menu-item-url' => '#pll_switcher',
        'menu-item-type' => 'custom',
        'menu-item-status' => 'publish',
    ]);
    if ($item instanceof WP_Error) {
        wp_delete_nav_menu((int) $id);

        return $item;
    }
    // Polylang's own menu-item options: the current language as the parent, the others under it.
    update_post_meta((int) $item, '_pll_menu_item', wp_slash([
        'hide_if_no_translation' => 0,
        'hide_current' => 0,
        'force_home' => 0,
        'show_flags' => 0,
        'show_names' => 1,
        'dropdown' => 1,
    ]));
    $menu = wp_get_nav_menu_object((int) $id);

    return ['slug' => $menu instanceof \WP_Term ? (string) $menu->slug : '', 'id' => (int) $id, 'created' => true];
}

/**
 * Header templates that currently have display conditions: the ones a new header replaces.
 *
 * @return array<int, list<string>>
 */
function active_headers(): array
{
    $found = [];
    $ids = get_posts([
        'post_type' => 'elementor_library',
        'post_status' => 'any',
        'posts_per_page' => 200,
        'fields' => 'ids',
        'meta_key' => '_elementor_template_type',
        'meta_value' => 'header',
        'suppress_filters' => true,
        'lang' => '',
    ]);
    foreach ($ids as $id) {
        $conditions = get_post_meta((int) $id, '_elementor_conditions', true);
        if (is_array($conditions) && $conditions !== []) {
            $found[(int) $id] = array_values(array_map('strval', $conditions));
        }
    }

    return $found;
}

/** @param list<string> $conditions */
function save_conditions(int $post_id, array $conditions): bool
{
    if (!class_exists(\ElementorPro\Modules\ThemeBuilder\Module::class)) {
        return false;
    }
    $manager = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager();
    $parts = [];
    foreach ($conditions as $condition) {
        // Elementor stores "include/singular/page/12"; save_conditions() wants it in parts.
        $parts[] = explode('/', $condition);
    }

    return (bool) $manager->save_conditions($post_id, $parts);
}

/**
 * Build the header.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function build(array $input): array|WP_Error
{
    $target = detect_builder((string) ($input['builder'] ?? 'auto'));
    if ($target['builder'] === 'block-theme') {
        return build_block_theme($input);
    }
    if ($target['builder'] !== 'elementor') {
        return new WP_Error('kit_site_header_unsupported', $target['reason'] !== '' ? $target['reason'] : 'No header builder is available on this site.', ['status' => 400]);
    }

    $langs = languages();
    $language_list = $langs['languages'] !== [] ? $langs['languages'] : [''];
    $switch = (string) ($input['show_language_switcher'] ?? 'auto');
    $want_switcher = $langs['plugin'] === 'polylang' && $switch !== 'no';
    if ($switch === 'yes' && $langs['plugin'] === '') {
        return new WP_Error('kit_site_header_no_languages', 'A language switcher needs Polylang with two or more languages; this site has none.', ['status' => 400]);
    }
    $cart_choice = (string) ($input['show_cart'] ?? 'auto');
    $woo = class_exists('WooCommerce');
    if ($cart_choice === 'yes' && !$woo) {
        return new WP_Error('kit_site_header_no_shop', 'A cart needs WooCommerce, which is not active.', ['status' => 400]);
    }
    $want_cart = $woo && $cart_choice !== 'no';

    $logo_id = (int) ($input['logo_id'] ?? 0);
    if ($logo_id === 0) {
        $logo_id = (int) get_theme_mod('custom_logo', 0);
    }
    $logo_url = $logo_id > 0 ? (string) wp_get_attachment_image_url($logo_id, 'full') : '';
    if ($logo_id > 0 && $logo_url === '') {
        return new WP_Error('kit_site_header_bad_logo', 'logo_id is not an image in the media library.', ['status' => 400]);
    }
    $title = array_key_exists('site_title', $input) ? trim((string) $input['site_title']) : (string) get_bloginfo('name');
    $cta = is_array($input['cta'] ?? null) ? $input['cta'] : [];
    $cta_label = trim((string) ($cta['label'] ?? ''));
    $cta_url = esc_url_raw((string) ($cta['url'] ?? ''));
    if ($cta_label !== '' && $cta_url === '') {
        return new WP_Error('kit_site_header_bad_cta', 'cta needs both label and url.', ['status' => 400]);
    }
    $colors = [];
    foreach (['background', 'text', 'accent'] as $key) {
        $value = is_array($input['colors'] ?? null) ? (string) ($input['colors'][$key] ?? '') : '';
        if ($value !== '') {
            $hex = sanitize_hex_color($value);
            if (!is_string($hex) || $hex === '') {
                return new WP_Error('kit_site_header_bad_color', sprintf('colors.%s must be a hex colour such as #1f2937.', $key), ['status' => 400]);
            }
            $colors[$key] = $hex;
        }
    }
    $colors += kit_palette();
    $fonts = [];
    foreach (['title', 'menu'] as $key) {
        $family = is_array($input['fonts'] ?? null) ? trim((string) ($input['fonts'][$key] ?? '')) : '';
        if ($family !== '') {
            if (preg_match('/^[\p{L}\p{N} _-]{1,60}$/u', $family) !== 1) {
                return new WP_Error('kit_site_header_bad_font', sprintf('fonts.%s must be a font family name such as "Source Serif 4".', $key), ['status' => 400]);
            }
            $fonts[$key] = $family;
        }
    }

    // One plan per language: menus differ in length, so does the layout.
    $per_language = [];
    $menus = [];
    foreach ($language_list as $language) {
        $menus[$language] = pick_menu($input, $language);
    }
    // A language without a menu of its own gets the longest one found for another, so its pages
    // still have navigation (in that menu's language, which the notes say).
    $fallback = null;
    foreach ($menus as $menu) {
        if ($menu instanceof \WP_Term && (!$fallback instanceof \WP_Term || $menu->count > $fallback->count)) {
            $fallback = $menu;
        }
    }
    $notes = [];
    foreach ($language_list as $language) {
        $menu = $menus[$language];
        if (!$menu instanceof \WP_Term && $language !== '' && $fallback instanceof \WP_Term) {
            $menu = $fallback;
            $notes[] = sprintf('No menu for language "%s" was found (one named like "Main menu (%s)"), so its header uses "%s". Pass menus.%s to choose another.', $language, strtoupper($language), $fallback->name, $language);
        }
        $nav = $menu instanceof \WP_Term ? menu_top_level($menu) : [];
        $per_language[$language] = [
            'menu' => $menu,
            'nav' => $nav,
            'plan' => plan([
                'title' => $title,
                'has_logo' => $logo_id > 0,
                'nav' => $nav,
                'language' => $want_switcher,
                'cart' => $want_cart,
                'cta' => $cta_label,
            ]),
        ];
    }
    foreach ($per_language as $language => $row) {
        if (!$row['menu'] instanceof \WP_Term) {
            $notes[] = $language === ''
                ? 'No menu was found, so the header has no navigation. Create one under Appearance > Menus, or pass menu.'
                : sprintf('No menu for language "%s" was found (a menu named like "Main menu (%s)"), so that header has no navigation. Pass menus.%s.', $language, strtoupper($language), $language);
        } elseif ($row['plan']['nav_layout'] === 'dropdown') {
            $notes[] = sprintf('The %s menu has %d top-level items, too many for one row beside the logo and the right-hand group, so it opens from a menu button at every width. Shorten it (move items into sub-menus) for a full-width menu.', $language !== '' ? strtoupper($language) : 'main', count($row['nav']));
        }
    }

    $summary = [
        'builder' => 'elementor',
        'languages' => $langs['languages'],
        'language_switcher' => $want_switcher,
        'cart' => $want_cart,
        'logo_id' => $logo_id,
        'site_title' => $title,
        'plans' => array_map(static fn(array $row): array => [
            'menu' => $row['menu'] instanceof \WP_Term ? ['id' => (int) $row['menu']->term_id, 'name' => (string) $row['menu']->name, 'top_level_items' => count($row['nav'])] : null,
        ] + $row['plan'], $per_language),
        'replaces' => array_keys(active_headers()),
        'notes' => $notes,
    ];
    if (!empty($input['dry_run'])) {
        return $summary + ['dry_run' => true];
    }

    $created = [];
    $created_menu = 0;
    $demoted = [];
    $undo_partial = static function () use (&$created, &$created_menu, &$demoted): void {
        foreach ($created as $id) {
            // Clear the display conditions first so Elementor Pro's conditions cache
            // never points at a deleted template.
            save_conditions($id, []);
            wp_delete_post($id, true);
        }
        foreach ($demoted as $id => $conditions) {
            save_conditions($id, $conditions);
        }
        if ($created_menu > 0) {
            wp_delete_nav_menu($created_menu);
        }
    };

    try {
        return write($input, $summary, $per_language, [
            'title' => $title, 'logo_id' => $logo_id, 'logo_url' => $logo_url, 'want_switcher' => $want_switcher,
            'want_cart' => $want_cart, 'cta_label' => $cta_label, 'cta_url' => $cta_url, 'colors' => $colors,
            'fonts' => $fonts,
        ], $created, $created_menu, $demoted, $undo_partial);
    } catch (\Throwable $e) {
        $undo_partial();

        return new WP_Error('kit_site_header_failed', 'Building the header failed and what it had made was removed: ' . $e->getMessage());
    }
}

/**
 * The writing half of build(). Anything that throws is undone by the caller's catch through
 * the same $undo the failed-check path uses.
 *
 * @param array<string, mixed> $input
 * @param array<string, mixed> $summary
 * @param array<string, array{menu: ?\WP_Term, nav: list<array{title: string, children: bool}>, plan: array<string, mixed>}> $per_language
 * @param array<string, mixed> $c
 * @param list<int> $created
 * @param array<int, list<string>> $demoted
 * @return array<string, mixed>|WP_Error
 */
function write(array $input, array $summary, array $per_language, array $c, array &$created, int &$created_menu, array &$demoted, callable $undo_partial): array|WP_Error
{
    $title = (string) $c['title'];
    $logo_id = (int) $c['logo_id'];
    $logo_url = (string) $c['logo_url'];
    $want_switcher = (bool) $c['want_switcher'];
    $want_cart = (bool) $c['want_cart'];
    $cta_label = (string) $c['cta_label'];
    $cta_url = (string) $c['cta_url'];
    $colors = (array) $c['colors'];
    $fonts = (array) $c['fonts'];

    $switcher = ['slug' => '', 'id' => 0, 'created' => false];
    if ($want_switcher) {
        $switcher = ensure_switcher_menu();
        if ($switcher instanceof WP_Error) {
            return $switcher;
        }
        $created_menu = $switcher['created'] ? $switcher['id'] : 0;
    }

    $templates = [];
    $counter = 0;
    $id = static function () use (&$counter): string {
        $counter++;

        return substr(md5(uniqid('', true) . $counter), 0, 7);
    };
    foreach ($per_language as $language => $row) {
        $home = $language !== '' && function_exists('pll_home_url') ? (string) pll_home_url($language) : home_url('/');
        $tree = elementor_tree($row['plan'], [
            'title' => $title,
            'home_url' => $home,
            'logo_id' => $logo_id,
            'logo_url' => $logo_url,
            'menu' => $row['menu'] instanceof \WP_Term ? (string) $row['menu']->slug : '',
            'switcher_menu' => $switcher['slug'],
            'cart' => $want_cart,
            'cta_label' => $cta_label,
            'cta_url' => $cta_url,
            'sticky' => !empty($input['sticky']),
            'colors' => $colors,
            'fonts' => $fonts,
        ], $id);
        $document = \Elementor\Plugin::$instance->documents->create('header', [
            'post_title' => $language !== '' ? sprintf('Site header (%s)', strtoupper($language)) : 'Site header',
            'post_status' => 'publish',
        ]);
        if ($document instanceof WP_Error || !is_object($document)) {
            $undo_partial();

            return $document instanceof WP_Error ? $document : new WP_Error('kit_site_header_create_failed', 'Elementor could not create the header template.');
        }
        $post_id = (int) $document->get_main_id();
        $created[] = $post_id;
        $document->save(['elements' => $tree, 'settings' => ['post_status' => 'publish']]);
        $templates[] = ['id' => $post_id, 'language' => $language, 'edit_url' => (string) $document->get_edit_url()];
    }

    // The new header takes over every page: the old ones keep their content, lose their conditions.
    foreach (active_headers() as $old_id => $conditions) {
        if (!in_array($old_id, $created, strict: true)) {
            $demoted[$old_id] = $conditions;
            save_conditions($old_id, []);
        }
    }
    $primary = $templates[0]['id'];
    save_conditions($primary, ['include/general']);
    if (count($templates) > 1) {
        $map = [];
        foreach ($templates as $template) {
            $map[$template['language']] = $template['id'];
        }
        update_post_meta($primary, TRANSLATIONS_META, wp_slash($map));
    }

    // Read every language's home page as a visitor gets it and check the header that came back.
    $checks = [];
    $passed = true;
    foreach ($templates as $template) {
        $language = $template['language'];
        $url = $language !== '' && function_exists('pll_home_url') ? (string) pll_home_url($language) : home_url('/');
        $page = Page::fetch(add_query_arg('wppilot-kit-header-check', (string) time(), $url));
        $html = is_array($page) ? $page['html'] : '';
        $check = check_served_header($html, [
            'template_id' => $template['id'],
            'nav_items' => count($per_language[$language]['nav']),
            'language' => $want_switcher,
            'cart' => $want_cart,
            'plan' => $per_language[$language]['plan'],
        ]);
        if (!is_array($page)) {
            $check['checks'][] = ['check' => 'served', 'passed' => false, 'detail' => $page->get_error_message()];
            $check['passed'] = false;
        }
        $checks[$language !== '' ? $language : 'site'] = ['url' => $url] + $check;
        $passed = $passed && $check['passed'];
    }
    if (!$passed) {
        // Never leave a header that did not check out: put everything back and say why.
        $undo_partial();

        return new WP_Error('kit_site_header_check_failed', 'The header was built but did not pass its own check on the served page, so it was removed again and the previous header restored. Failed: ' . failed_checks($checks), [
            'status' => 422,
            'checks' => $checks,
        ]);
    }

    return $summary + [
        'templates' => $templates,
        'created_ids' => $created,
        'created_menu_id' => $created_menu,
        'switcher_menu_id' => $switcher['id'],
        'demoted' => $demoted,
        'checks' => $checks,
        'look' => 'Structural checks passed. Screenshot each language at 1440, 768 and 390 px wide to see it; this check does not run a browser.',
    ];
}

/**
 * The failed checks as one line: MCP clients see an error's message, not its data.
 *
 * @param array<string, array{checks: list<array{check: string, passed: bool, detail: string}>}> $checks
 */
function failed_checks(array $checks): string
{
    $lines = [];
    foreach ($checks as $language => $result) {
        foreach ($result['checks'] as $check) {
            if (!$check['passed']) {
                $lines[] = sprintf('[%s] %s: %s', $language, $check['check'], $check['detail']);
            }
        }
    }

    return implode(' ', $lines);
}

/**
 * Swap the default-language header for its translation on a translated page.
 *
 * @param mixed $template_id
 * @return mixed
 */
function translated_template(mixed $template_id): mixed
{
    if (!is_numeric($template_id)) {
        return $template_id;
    }
    $map = get_post_meta((int) $template_id, TRANSLATIONS_META, true);
    if (!is_array($map) || $map === []) {
        return $template_id;
    }
    $language = current_language();
    $other = $language !== '' ? (int) ($map[$language] ?? 0) : 0;

    return $other > 0 && get_post_status($other) === 'publish' ? $other : $template_id;
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>|WP_Error
 */
function undo(array $payload): array|WP_Error
{
    if (($payload['kind'] ?? '') === 'block-theme') {
        return undo_block_theme($payload);
    }
    if (!class_exists('\ElementorPro\Modules\ThemeBuilder\Module')) {
        return new WP_Error('kit_site_header_undo_no_elementor', 'Elementor Pro is not active, so the header templates cannot be put back.');
    }
    $created = array_map('intval', (array) ($payload['created_ids'] ?? []));
    foreach ($created as $id) {
        $post = get_post($id);
        if ($post instanceof \WP_Post && $post->post_type === 'elementor_library') {
            save_conditions($id, []);
            wp_delete_post($id, true);
        }
    }
    $restored = [];
    foreach ((array) ($payload['demoted'] ?? []) as $id => $conditions) {
        save_conditions((int) $id, array_values(array_map('strval', (array) $conditions)));
        $restored[(int) $id] = get_post_meta((int) $id, '_elementor_conditions', true);
    }
    $menu = (int) ($payload['created_menu_id'] ?? 0);
    if ($menu > 0) {
        $term = wp_get_nav_menu_object($menu);
        if ($term instanceof \WP_Term && $term->name === SWITCHER_MENU_NAME) {
            wp_delete_nav_menu($menu);
        }
    }

    $verified = true;
    foreach ($created as $id) {
        $verified = $verified && !get_post($id) instanceof \WP_Post;
    }
    foreach ((array) ($payload['demoted'] ?? []) as $id => $conditions) {
        $verified = $verified && array_values((array) $restored[(int) $id]) === array_values((array) $conditions);
    }

    return ['deleted' => $created, 'conditions_restored' => array_keys($restored), 'verified' => $verified];
}

function register_ledger(Ledger $ledger): void
{
    $ledger->register_strategy(
        STRATEGY,
        static fn(array $payload): array|WP_Error => undo($payload),
        static function (array $before, mixed $result): array {
            if (is_array($result) && ($result['kind'] ?? '') === 'block-theme') {
                return [
                    'kind' => 'block-theme',
                    'template_part' => (string) ($result['template_part'] ?? ''),
                    'part_before' => (array) ($result['part_before'] ?? []),
                    'created_navigation_ids' => array_map('intval', (array) ($result['created_navigation_ids'] ?? [])),
                    'created_part_ids' => array_map('intval', (array) ($result['created_part_ids'] ?? [])),
                ];
            }
            if (!is_array($result) || !empty($result['dry_run']) || empty($result['created_ids'])) {
                return ['reversible' => false, 'reason' => 'Nothing was built (a dry run, or the build failed and put everything back itself).'];
            }

            return [
                'created_ids' => array_map('intval', (array) $result['created_ids']),
                'created_menu_id' => (int) ($result['created_menu_id'] ?? 0),
                'demoted' => (array) ($result['demoted'] ?? []),
            ];
        },
    );
    $ledger->capture_for('wppilot/build-site-header', static fn(array $input): array => ['type' => STRATEGY]);
}
