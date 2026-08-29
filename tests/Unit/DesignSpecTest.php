<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPPilot\Design\Spec;

/**
 * A design document says what a site should feel like. A spec says what it
 * should measure. The distinction is the whole feature: asked to match a
 * reference with only a direction to go on, a model gets the palette right
 * because a screenshot gives colour away, and invents the section order, the
 * container, the scale and the weight. Nothing then notices, because every
 * other rule asks whether the page is any good rather than whether it is the
 * page that was asked for.
 *
 * So these tests defend two things: that a spec refuses a guess rather than
 * storing one, and that the comparison is capable of saying no. A grader that
 * only ever returns a pass is worse than no grader, because it converts "we
 * never checked" into "we checked and it was fine".
 */
final class DesignSpecTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function spec(array $overrides = []): array
    {
        return [
            'source' => 'url',
            'reference' => 'https://example.test/',
            'container' => 1380,
            'section_padding' => 144,
            'surfaces' => ['paper' => '#f3f0e6', 'ink' => '#142017', 'lime' => '#d9ff63'],
            'type' => [
                'h1' => ['family' => 'Archivo', 'size' => 87, 'weight' => '900', 'line_height' => 0.92, 'tracking' => -0.035],
                'body' => ['family' => 'Work Sans', 'size' => 20, 'weight' => '400', 'line_height' => 1.625],
            ],
            'sections' => [
                ['surface' => 'paper', 'layout' => 'split-2'],
                ['surface' => 'ink', 'layout' => 'stack'],
                ['surface' => 'lime', 'layout' => 'stack'],
            ],
            ...$overrides,
        ];
    }

    // ------------------------------------------------------------ refusals

    public function test_a_spec_without_surfaces_is_refused(): void
    {
        $result = Spec\normalize(self::spec(['surfaces' => []]));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('wppilot_spec_surfaces', $result->get_error_code());
    }

    public function test_a_spec_without_a_type_scale_is_refused(): void
    {
        $result = Spec\normalize(self::spec(['type' => []]));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('wppilot_spec_type', $result->get_error_code());
    }

    /**
     * The section sequence is the part that gets invented when it is not
     * stated, so a spec that omits it is not a spec.
     */
    public function test_a_spec_without_sections_is_refused(): void
    {
        $result = Spec\normalize(self::spec(['sections' => []]));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('wppilot_spec_sections', $result->get_error_code());
    }

    /**
     * A section naming a surface nobody declared would otherwise compile to a
     * class with no background, which reads as a styling bug rather than as the
     * typo it is.
     */
    public function test_a_section_on_an_undeclared_surface_is_refused(): void
    {
        $result = Spec\normalize(self::spec([
            'sections' => [['surface' => 'midnight', 'layout' => 'stack']],
        ]));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('wppilot_spec_section_surface', $result->get_error_code());
    }

    public function test_an_unknown_layout_is_refused_with_the_valid_names(): void
    {
        $result = Spec\normalize(self::spec([
            'sections' => [['surface' => 'paper', 'layout' => 'masonry']],
        ]));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertStringContainsString('split-3', $result->get_error_message());
    }

    /**
     * A type role with no size cannot be compiled into anything, and defaulting
     * one would put a number nobody chose into a class that is then graded
     * against as though somebody had.
     */
    public function test_a_type_role_without_a_size_is_refused(): void
    {
        $result = Spec\normalize(self::spec([
            'type' => ['h1' => ['family' => 'Archivo', 'weight' => '900']],
        ]));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('wppilot_spec_type_size', $result->get_error_code());
    }

    public function test_a_valid_spec_normalizes(): void
    {
        $result = Spec\normalize(self::spec());

        self::assertIsArray($result);
        self::assertSame(1380.0, $result['container']);
        self::assertSame('#f3f0e6', $result['surfaces']['paper']);
        self::assertCount(3, $result['sections']);
    }

    // -------------------------------------------------------------- roles

    /**
     * The roles are what a builder turns into classes, so every surface, every
     * layout and every type role on every surface has to be present. Generating
     * them from the spec is the thing that stops a matched design being sixteen
     * classes somebody typed out by hand and could not reproduce.
     */
    public function test_every_surface_layout_and_type_role_is_generated(): void
    {
        $spec = Spec\normalize(self::spec());
        self::assertIsArray($spec);
        $roles = Spec\roles($spec);

        self::assertArrayHasKey('surface-paper', $roles);
        self::assertArrayHasKey('surface-ink', $roles);
        self::assertArrayHasKey('container', $roles);
        self::assertArrayHasKey('layout-split-3', $roles);
        self::assertArrayHasKey('h1-on-ink', $roles);
        self::assertArrayHasKey('body-on-lime', $roles);
    }

    /**
     * Tracking is stored in em because it is the only unit that survives a size
     * change, and a spec is applied at more than one size the moment it has a
     * mobile variant.
     */
    public function test_type_styles_carry_weight_and_tracking(): void
    {
        $spec = Spec\normalize(self::spec());
        self::assertIsArray($spec);
        $styles = Spec\roles($spec)['h1-on-paper']['styles'];

        self::assertSame('87px', $styles['font-size']);
        self::assertSame('900', $styles['font-weight']);
        self::assertSame('-0.035em', $styles['letter-spacing']);
    }

    /**
     * A design that chose a near-black green and a warm off-white chose them for
     * each other. Dropping pure white onto its dark sections is the detail that
     * makes an otherwise faithful copy look slightly wrong, so the foreground is
     * picked from the spec's own surfaces.
     */
    public function test_text_on_a_surface_uses_a_declared_colour(): void
    {
        $spec = Spec\normalize(self::spec());
        self::assertIsArray($spec);
        $roles = Spec\roles($spec);

        self::assertSame('#f3f0e6', $roles['h1-on-ink']['styles']['color']);
        self::assertSame('#142017', $roles['h1-on-lime']['styles']['color']);
    }

    /**
     * Every multi-column layout collapses on a phone. Leaving that to each
     * section would get it stated wrongly exactly once.
     */
    public function test_multi_column_layouts_collapse_on_mobile(): void
    {
        $spec = Spec\normalize(self::spec());
        self::assertIsArray($spec);
        $roles = Spec\roles($spec);

        self::assertSame('1fr', $roles['layout-split-3']['mobile']['grid-template-columns']);
    }

    // ---------------------------------------------------------- comparison

    public function test_a_matching_page_scores_one(): void
    {
        $spec = Spec\normalize(self::spec());
        self::assertIsArray($spec);

        $result = Spec\compare($spec, [
            'surfaces' => ['#f3f0e6', '#142017', '#d9ff63'],
            'container' => 1380,
            'section_padding' => 144,
            'type' => ['h1' => ['size' => 87, 'weight' => '900'], 'body' => ['size' => 20, 'weight' => '400']],
        ]);

        self::assertSame(1.0, $result['score']);
        self::assertSame([], $result['diffs']);
    }

    /**
     * The failure this whole feature exists for: a page that shares the palette
     * and matches nothing else. It has to score badly, and it has to say why.
     */
    public function test_a_page_that_shares_only_the_palette_scores_badly(): void
    {
        $spec = Spec\normalize(self::spec());
        self::assertIsArray($spec);

        $result = Spec\compare($spec, [
            // Same three colours, wrong order, wrong everything else.
            'surfaces' => ['#142017', '#d9ff63', '#f3f0e6'],
            'container' => 1200,
            'section_padding' => 112,
            'type' => ['h1' => ['size' => 72, 'weight' => '700'], 'body' => ['size' => 19, 'weight' => '400']],
        ]);

        self::assertLessThan(0.7, $result['score']);
        $properties = array_column($result['diffs'], 'property');
        self::assertContains('h1 weight', $properties);
        self::assertContains('container', $properties);
    }

    /**
     * Compared as a sequence, not a set. The same grounds in a different order
     * is a different page, and a set comparison would call it perfect.
     */
    public function test_the_surface_order_is_compared_position_by_position(): void
    {
        $spec = Spec\normalize(self::spec());
        self::assertIsArray($spec);

        $result = Spec\compare($spec, [
            'surfaces' => ['#142017', '#f3f0e6', '#d9ff63'],
            'container' => 1380,
            'section_padding' => 144,
            'type' => ['h1' => ['size' => 87, 'weight' => '900'], 'body' => ['size' => 20, 'weight' => '400']],
        ]);

        $properties = array_column($result['diffs'], 'property');
        self::assertContains('section 0 surface', $properties);
        self::assertContains('section 1 surface', $properties);
    }

    /**
     * Weight decides whether a page reads as the same design at all, so it is
     * graded without tolerance even though size is not.
     */
    public function test_weight_has_no_tolerance_but_size_does(): void
    {
        $spec = Spec\normalize(self::spec());
        self::assertIsArray($spec);

        $near = Spec\compare($spec, [
            'surfaces' => ['#f3f0e6', '#142017', '#d9ff63'],
            'container' => 1380,
            'section_padding' => 144,
            // 87.44 is the same decision as 87; 800 is not the same as 900.
            'type' => ['h1' => ['size' => 87.44, 'weight' => '800'], 'body' => ['size' => 20, 'weight' => '400']],
        ]);

        $properties = array_column($near['diffs'], 'property');
        self::assertNotContains('h1 size', $properties);
        self::assertContains('h1 weight', $properties);
    }
}
