<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\SiteHeader;

use PHPUnit\Framework\TestCase;
use WPPilot\Kits\SiteHeader;

/**
 * site-header: the layout plan, the Elementor tree it produces, and the check on the served page.
 */
final class LayoutTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/kits/site-header/src/layout.php';
    }

    /** @return list<array{title: string, children: bool}> */
    private static function nav(string ...$titles): array
    {
        return array_map(static fn(string $t): array => ['title' => $t, 'children' => $t === 'Services'], $titles);
    }

    /** @return array{title: string, has_logo: bool, nav: list<array{title: string, children: bool}>, language: bool, cart: bool, cta: string} */
    private static function parts(array $over = []): array
    {
        return $over + [
            'title' => 'Northwind Bakery',
            'has_logo' => true,
            'nav' => self::nav('Home', 'About', 'Services', 'Shop', 'Blog', 'Contact'),
            'language' => true,
            'cart' => true,
            'cta' => '',
        ];
    }

    public function test_a_six_item_menu_fits_one_row(): void
    {
        $plan = SiteHeader\plan(self::parts());

        $this->assertSame('horizontal', $plan['nav_layout']);
        $this->assertLessThanOrEqual($plan['estimate']['nav_available_px'], $plan['estimate']['nav_needed_px']);
        $this->assertFalse($plan['hide_title_on_phone']);
    }

    public function test_a_menu_too_long_for_one_row_becomes_a_menu_button(): void
    {
        $long = self::nav(...array_map(static fn(int $i): string => 'Our wonderful section ' . $i, range(1, 12)));
        $plan = SiteHeader\plan(self::parts(['nav' => $long]));

        $this->assertSame('dropdown', $plan['nav_layout']);
    }

    public function test_a_tighter_menu_is_tried_before_giving_up_on_one_row(): void
    {
        $nine = self::nav('Home', 'About us', 'Services', 'Catering', 'Custom cakes', 'Classes', 'Shop', 'Blog', 'Contact');
        $plan = SiteHeader\plan(self::parts(['nav' => $nine, 'title' => 'Northwind Artisan Bakery & Coffee Roasters']));

        $this->assertContains($plan['nav_layout'], ['horizontal', 'dropdown']);
        if ($plan['nav_layout'] === 'horizontal') {
            $this->assertLessThanOrEqual($plan['estimate']['nav_available_px'], $plan['estimate']['nav_needed_px']);
        }
    }

    public function test_a_long_title_gives_way_to_the_logo_on_phones_but_not_without_one(): void
    {
        $title = 'Northwind Artisanal-Sourdough Bakery';
        $with_logo = SiteHeader\plan(self::parts(['title' => $title]));
        $without = SiteHeader\plan(self::parts(['title' => $title, 'has_logo' => false]));

        $this->assertTrue($with_logo['hide_title_on_phone']);
        $this->assertFalse($without['hide_title_on_phone']);
        $this->assertSame(14, $without['phone_title_px']);
    }

    public function test_no_menu_means_no_menu_widget(): void
    {
        $this->assertSame('none', SiteHeader\plan(self::parts(['nav' => []]))['nav_layout']);
    }

    /** @return array<string, mixed> */
    private static function content(array $over = []): array
    {
        return $over + [
            'title' => 'Northwind Bakery',
            'home_url' => 'https://example.test/',
            'logo_id' => 42,
            'logo_url' => 'https://example.test/logo.svg',
            'menu' => 'main-menu-en',
            'switcher_menu' => 'language-switcher-header',
            'cart' => true,
            'cta_label' => '',
            'cta_url' => '',
            'sticky' => false,
            'colors' => [],
            'fonts' => [],
        ];
    }

    private static function ids(): \Closure
    {
        $n = 0;

        return static function () use (&$n): string {
            $n++;

            return sprintf('%07x', $n);
        };
    }

    public function test_the_tree_is_one_flat_row_with_the_menu_growing_and_last_on_small_screens(): void
    {
        $tree = SiteHeader\elementor_tree(SiteHeader\plan(self::parts()), self::content(), self::ids());

        $this->assertCount(1, $tree);
        $row = $tree[0];
        $this->assertSame('container', $row['elType']);
        $this->assertSame('row', $row['settings']['flex_direction']);
        $this->assertSame('nowrap', $row['settings']['flex_wrap_mobile']);
        $types = array_map(static fn(array $e): string => $e['elType'] === 'widget' ? $e['widgetType'] : $e['elType'], $row['elements']);
        $this->assertSame(['image', 'heading', 'nav-menu', 'nav-menu', 'woocommerce-menu-cart'], $types, 'no inner containers');

        $menu = $row['elements'][2]['settings'];
        $this->assertSame('main-menu-en', $menu['menu']);
        $this->assertSame('horizontal', $menu['layout']);
        $this->assertSame('tablet', $menu['dropdown']);
        $this->assertSame('grow', $menu['_flex_size']);
        $this->assertSame('end', $menu['_flex_order_mobile']);

        $switcher = $row['elements'][3]['settings'];
        $this->assertSame('language-switcher-header', $switcher['menu']);
        $this->assertSame('none', $switcher['dropdown'], 'the switcher stays a dropdown on phones, never a menu button');

        foreach ([0, 3, 4] as $fixed) {
            $this->assertSame('auto', $row['elements'][$fixed]['settings']['_element_width'], 'content-sized, not zero wide');
        }
        $this->assertSame(1, $row['elements'][1]['settings']['_flex_shrink_mobile'], 'the title may wrap on phones');
        $this->assertSame('', $row['elements'][4]['settings']['show_subtotal']);
    }

    public function test_options_leave_out_what_is_not_wanted_and_hide_the_title_when_planned(): void
    {
        $plan = SiteHeader\plan(self::parts(['title' => 'Northwind Artisanal-Sourdough Bakery', 'cart' => false, 'language' => false]));
        $tree = SiteHeader\elementor_tree($plan, self::content(['title' => 'Northwind Artisanal-Sourdough Bakery', 'switcher_menu' => '', 'cart' => false, 'cta_label' => 'Order', 'cta_url' => '/shop/', 'sticky' => true, 'colors' => ['text' => '#211b12']]), self::ids());
        $row = $tree[0];
        $types = array_map(static fn(array $e): string => $e['widgetType'], $row['elements']);

        $this->assertSame(['image', 'heading', 'nav-menu', 'button'], $types);
        $this->assertSame('top', $row['settings']['sticky']);
        $this->assertSame('#211b12', $row['elements'][1]['settings']['title_color']);
        $this->assertSame('hidden-mobile', $row['elements'][3]['settings']['hide_mobile'], 'the call to action is desktop only');
        if ($plan['hide_title_on_phone']) {
            $this->assertSame('hidden-mobile', $row['elements'][1]['settings']['hide_mobile']);
        }
    }

    private static function served(string $header): string
    {
        return '<html><body><div data-elementor-type="header" data-elementor-id="77">' . $header . '</div><main>page</main></body></html>';
    }

    private const NAV = '<nav class="elementor-nav-menu--main"><ul class="elementor-nav-menu"><li><a>Home</a></li><li><a>About</a></li></ul></nav><div class="elementor-menu-toggle"></div>';
    private const SWITCHER = '<nav class="elementor-nav-menu--main"><ul class="elementor-nav-menu"><li class="menu-item-has-children"><a>English</a><ul class="sub-menu"><li class="lang-item"><a>Latviešu</a></li><li class="lang-item"><a>English</a></li></ul></li></ul></nav>';
    private const CART = '<div class="elementor-menu-cart__toggle_button"><svg></svg></div>';

    /** @return array<string, mixed> */
    private static function expect(array $over = []): array
    {
        return $over + ['template_id' => 77, 'nav_items' => 2, 'language' => true, 'cart' => true, 'plan' => SiteHeader\plan(self::parts())];
    }

    public function test_a_good_header_passes_every_check(): void
    {
        $result = SiteHeader\check_served_header(self::served(self::NAV . self::SWITCHER . self::CART), self::expect());

        $this->assertTrue($result['passed'], json_encode($result['checks']) ?: '');
        $this->assertSame(['header_served', 'menu_one_row', 'menu_button_on_small_screens', 'language_switcher_styled', 'cart_icon', 'phone_row_fits'], array_column($result['checks'], 'check'));
    }

    public function test_a_bare_language_list_an_iconless_cart_and_another_header_fail(): void
    {
        $bare = '<div class="elementor-widget-wp-widget-polylang"><ul><li class="lang-item"><a>Latviešu</a></li></ul></div>';
        $result = SiteHeader\check_served_header(self::served(self::NAV . $bare . '<div class="elementor-menu-cart__toggle_button"></div>'), self::expect());
        $failed = array_column(array_filter($result['checks'], static fn(array $c): bool => !$c['passed']), 'check');

        $this->assertFalse($result['passed']);
        $this->assertSame(['language_switcher_styled', 'cart_icon'], array_values($failed));

        $other = SiteHeader\check_served_header(self::served(self::NAV), self::expect(['template_id' => 78]));
        $this->assertFalse($other['passed']);
        $this->assertSame('header_served', $other['checks'][0]['check']);
    }

    public function test_a_menu_without_a_button_for_small_screens_fails(): void
    {
        $nav = '<nav class="elementor-nav-menu--main"><ul class="elementor-nav-menu"><li><a>Home</a></li></ul></nav>';
        $result = SiteHeader\check_served_header(self::served($nav . self::SWITCHER . self::CART), self::expect());

        $this->assertFalse($result['passed']);
    }
}
