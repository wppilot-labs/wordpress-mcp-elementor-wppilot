<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Spec;

use WP_Error;
use WPPilot\Design\Contrast;
use WPPilot\Design\Preflight;
use WPPilot\Design\Store;

/**
 * A reproduction spec: the measurements of a design somebody is asking for.
 *
 * DESIGN.md is a direction. It names a palette, two faces and a set of dials,
 * deliberately leaves layout open, and asks "is this page in the spirit of the
 * brand". That is the right shape for inventing a site and the wrong shape
 * entirely for "build the page in this screenshot", because nothing in it can
 * carry the one thing reproduction needs: the actual numbers, in order.
 *
 * Asked to match a reference without them, a model infers. It gets the palette
 * right, because colour is the part a screenshot gives away, and it invents the
 * rest: the section sequence, the container width, the type scale, the weight.
 * The result shares a palette with the target and matches nothing else, and
 * there is no check anywhere that notices, because every existing rule asks
 * whether the page is any good rather than whether it is the page that was
 * asked for.
 *
 * So a spec is measurements, and it is measurements the caller supplies. The
 * plugin deliberately does not try to see the design: a screenshot, a Figma
 * file and a rendered URL are three different extraction problems, all of them
 * better solved by the agent that already has vision and a browser than by PHP
 * guessing at a JPEG. What the plugin owns is the part an agent is bad at —
 * holding the numbers exactly, turning them into real builder primitives with
 * no hand-made classes, and saying afterwards how far off the result landed.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** Where a spec lives, alongside the design document it belongs to. */
const META_KEY = '_wppilot_design_spec';

/** How a spec was arrived at, recorded because it changes how much to trust it. */
const SOURCES = ['url', 'image', 'figma', 'prompt'];

/**
 * The layouts a section can use, and the columns each one means.
 *
 * Named rather than free-form so a spec stays comparable: two specs both saying
 * `split-2` can be checked against each other, where two specs saying
 * `1.04fr 1fr` and `1fr 1fr` cannot. The set covers what real marketing pages
 * are actually built from, including the three-column row that the layout
 * grammars have no equivalent for.
 *
 * @return array<string, string>
 */
function layouts(): array
{
    return [
        'stack' => '',
        'split-2' => '1fr 1fr',
        'split-3' => '1fr 1fr 1fr',
        'split-4' => '1fr 1fr 1fr 1fr',
        'split-wide-left' => '7fr 5fr',
        'split-wide-right' => '5fr 7fr',
        'split-major-left' => '65fr 35fr',
        'split-minor-left' => '35fr 65fr',
    ];
}

/**
 * Type roles a spec may declare. A role is a job on the page, not a size, so a
 * design with no separate card heading simply omits `card`.
 *
 * @return list<string>
 */
function type_roles(): array
{
    return ['display', 'h1', 'h2', 'h3', 'card', 'body', 'small', 'label'];
}

/**
 * Validate and normalize a raw spec.
 *
 * Fails rather than repairs. A reproduction spec with a guessed value in it is
 * worse than no spec, because the guess is then enforced and measured against
 * as though somebody had chosen it.
 *
 * @param  array<string, mixed> $raw
 * @return array<string, mixed>|WP_Error
 */
function normalize(array $raw): array|WP_Error
{
    $source = strtolower(trim((string) ($raw['source'] ?? 'prompt')));
    if (!in_array($source, SOURCES, strict: true)) {
        return new WP_Error(
            'wppilot_spec_source',
            sprintf(
                /* translators: 1: the supplied source, 2: comma-separated valid sources */
                __('"%1$s" is not a spec source. Use one of: %2$s.', domain: 'wppilot'),
                $source,
                implode(', ', SOURCES),
            ),
            ['status' => 422],
        );
    }

    $surfaces = [];
    /** @var mixed $raw_surfaces */
    $raw_surfaces = $raw['surfaces'] ?? [];
    if (!is_array($raw_surfaces) || $raw_surfaces === []) {
        return new WP_Error(
            'wppilot_spec_surfaces',
            __(
                'A spec needs at least one surface: the named backgrounds its sections sit on, as name => hex. The sequence of these is most of what makes a page recognisable.',
                domain: 'wppilot',
            ),
            ['status' => 422],
        );
    }
    foreach ($raw_surfaces as $name => $value) {
        $hex = Preflight\normalize_hex((string) $value);
        if ($hex === '') {
            return new WP_Error(
                'wppilot_spec_surface_color',
                sprintf(
                    /* translators: 1: surface name, 2: the unusable value */
                    __('Surface "%1$s" is set to "%2$s", which is not a colour.', domain: 'wppilot'),
                    (string) $name,
                    (string) $value,
                ),
                ['status' => 422],
            );
        }
        $surfaces[sanitize_key((string) $name)] = $hex;
    }

    $type = normalize_type(is_array($raw['type'] ?? null) ? $raw['type'] : []);
    if ($type instanceof WP_Error) {
        return $type;
    }

    $sections = normalize_sections(
        is_array($raw['sections'] ?? null) ? $raw['sections'] : [],
        array_keys($surfaces),
    );
    if ($sections instanceof WP_Error) {
        return $sections;
    }

    $radius = [];
    /** @var mixed $raw_radius */
    $raw_radius = $raw['radius'] ?? [];
    if (is_array($raw_radius)) {
        foreach ($raw_radius as $name => $value) {
            $radius[sanitize_key((string) $name)] = max(0.0, (float) $value);
        }
    }

    return [
        'source' => $source,
        'reference' => esc_url_raw((string) ($raw['reference'] ?? '')),
        'container' => max(0.0, (float) ($raw['container'] ?? 1200)),
        'section_padding' => max(0.0, (float) ($raw['section_padding'] ?? 96)),
        'section_padding_mobile' => max(0.0, (float) ($raw['section_padding_mobile'] ?? 0)),
        'gap' => max(0.0, (float) ($raw['gap'] ?? 32)),
        'surfaces' => $surfaces,
        'radius' => $radius,
        'type' => $type,
        'sections' => $sections,
    ];
}

/**
 * @param  array<string, mixed> $raw
 * @return array<string, array<string, mixed>>|WP_Error
 */
function normalize_type(array $raw): array|WP_Error
{
    if ($raw === []) {
        return new WP_Error(
            'wppilot_spec_type',
            sprintf(
                /* translators: %s: comma-separated role names */
                __(
                    'A spec needs a type scale. Declare at least the roles the page uses, from: %s. Weight and tracking matter as much as size; a scale copied at the right sizes and the wrong weight does not read as the same design.',
                    domain: 'wppilot',
                ),
                implode(', ', type_roles()),
            ),
            ['status' => 422],
        );
    }

    $out = [];
    foreach ($raw as $role => $props) {
        $role = sanitize_key((string) $role);
        if (!in_array($role, type_roles(), strict: true)) {
            return new WP_Error(
                'wppilot_spec_type_role',
                sprintf(
                    /* translators: 1: the supplied role, 2: comma-separated valid roles */
                    __('"%1$s" is not a type role. Use one of: %2$s.', domain: 'wppilot'),
                    $role,
                    implode(', ', type_roles()),
                ),
                ['status' => 422],
            );
        }
        if (!is_array($props)) {
            continue;
        }

        $size = (float) ($props['size'] ?? 0);
        if ($size <= 0) {
            return new WP_Error(
                'wppilot_spec_type_size',
                sprintf(
                    /* translators: %s: the type role */
                    __('Type role "%s" has no size.', domain: 'wppilot'),
                    $role,
                ),
                ['status' => 422],
            );
        }

        $entry = [
            'family' => trim((string) ($props['family'] ?? '')),
            'size' => $size,
            'size_mobile' => max(0.0, (float) ($props['size_mobile'] ?? 0)),
            'weight' => (string) ($props['weight'] ?? '400'),
            'line_height' => (float) ($props['line_height'] ?? 1.4),
            // Tracking is stored in em because that is the only unit that
            // survives a size change, and a spec is applied at more than one
            // size the moment it has a mobile variant.
            'tracking' => (float) ($props['tracking'] ?? 0),
            'color' => sanitize_key((string) ($props['color'] ?? 'ink')),
            'measure' => max(0.0, (float) ($props['measure'] ?? 0)),
        ];
        $out[$role] = $entry;
    }

    return $out;
}

/**
 * @param  list<mixed>  $raw
 * @param  list<string> $surface_names
 * @return list<array<string, mixed>>|WP_Error
 */
function normalize_sections(array $raw, array $surface_names): array|WP_Error
{
    if ($raw === []) {
        return new WP_Error(
            'wppilot_spec_sections',
            __(
                'A spec needs its sections in order. The sequence of surfaces down a page is the thing a reader recognises before any single section, and it is the part that gets invented when it is not stated.',
                domain: 'wppilot',
            ),
            ['status' => 422],
        );
    }

    $known = layouts();
    $out = [];
    foreach (array_values($raw) as $index => $section) {
        if (!is_array($section)) {
            continue;
        }
        $surface = sanitize_key((string) ($section['surface'] ?? ''));
        if (!in_array($surface, $surface_names, strict: true)) {
            return new WP_Error(
                'wppilot_spec_section_surface',
                sprintf(
                    /* translators: 1: section index, 2: the surface named, 3: comma-separated declared surfaces */
                    __('Section %1$d names surface "%2$s", which the spec does not declare. Declared: %3$s.', domain: 'wppilot'),
                    $index,
                    $surface,
                    implode(', ', $surface_names),
                ),
                ['status' => 422],
            );
        }

        $layout = sanitize_key((string) ($section['layout'] ?? 'stack'));
        if (!array_key_exists($layout, $known)) {
            return new WP_Error(
                'wppilot_spec_section_layout',
                sprintf(
                    /* translators: 1: section index, 2: the layout named, 3: comma-separated valid layouts */
                    __('Section %1$d names layout "%2$s". Use one of: %3$s.', domain: 'wppilot'),
                    $index,
                    $layout,
                    implode(', ', array_keys($known)),
                ),
                ['status' => 422],
            );
        }

        $blocks = normalize_blocks(is_array($section['blocks'] ?? null) ? $section['blocks'] : [], $index);
        if ($blocks instanceof WP_Error) {
            return $blocks;
        }

        $out[] = [
            'surface' => $surface,
            'layout' => $layout,
            'gap' => max(0.0, (float) ($section['gap'] ?? 0)),
            'inset' => (bool) ($section['inset'] ?? false),
            'styles' => is_array($section['styles'] ?? null) ? $section['styles'] : [],
            'note' => trim((string) ($section['note'] ?? '')),
            'blocks' => $blocks,
        ];
    }

    return $out;
}

/**
 * The blocks a section can be made of.
 *
 * Surfaces and a type scale describe how a page is coloured and set. They do
 * not describe what is on it, and a spec that stops there produces a page with
 * the right palette, the right sizes, and none of the things the reference
 * actually contains: no console panel in the hero, no seven-item brief grid, no
 * accordion, no stat row. That page matches on every measurement and looks
 * nothing like the target, which is the trap in grading a reproduction by
 * tokens alone.
 *
 * So the vocabulary is components, not markup. A caller says "a card grid of
 * seven, each with an eyebrow, a title and a line" and the builder decides what
 * a card is on its side. Naming the component rather than the element tree is
 * what keeps a spec portable between builders, and what stops a page
 * description from being a hand-built tree that only its author can maintain.
 *
 * @param  list<mixed> $raw
 * @return list<array<string, mixed>>|WP_Error
 */
function normalize_blocks(array $raw, int $section_index): array|WP_Error
{
    $known = block_types();
    $out = [];
    foreach (array_values($raw) as $position => $block) {
        if (!is_array($block)) {
            continue;
        }
        $type = sanitize_key((string) ($block['type'] ?? ''));
        if (!in_array($type, $known, strict: true)) {
            return new WP_Error(
                'wppilot_spec_block_type',
                sprintf(
                    /* translators: 1: section index, 2: block position, 3: the type named, 4: comma-separated valid types */
                    __('Section %1$d block %2$d is type "%3$s". Use one of: %4$s.', domain: 'wppilot'),
                    $section_index,
                    $position,
                    $type,
                    implode(', ', $known),
                ),
                ['status' => 422],
            );
        }

        /** @var list<array<string, mixed>> $items */
        $items = [];
        /** @var mixed $raw_items */
        $raw_items = $block['items'] ?? [];
        if (is_array($raw_items)) {
            foreach (array_values($raw_items) as $item) {
                if (is_string($item)) {
                    $items[] = ['text' => $item];
                    continue;
                }
                if (!is_array($item)) {
                    continue;
                }
                $items[] = [
                    'icon' => trim((string) ($item['icon'] ?? '')),
                    'src' => esc_url_raw((string) ($item['src'] ?? '')),
                    'eyebrow' => trim((string) ($item['eyebrow'] ?? '')),
                    'title' => trim((string) ($item['title'] ?? '')),
                    'text' => trim((string) ($item['text'] ?? '')),
                    'value' => trim((string) ($item['value'] ?? '')),
                    'surface' => sanitize_key((string) ($item['surface'] ?? '')),
                    'style' => sanitize_key((string) ($item['style'] ?? '')),
                ];
            }
        }

        // Anything the vocabulary does not name. A closed component list keeps a
        // spec portable and gradeable, and on its own it also makes every
        // section that is not one of eleven shapes impossible to express, which
        // is the complaint a closed list always earns. Styles ride alongside:
        // the component decides the structure, and this decides everything
        // else, so a caller is never stuck asking for a shape nobody
        // anticipated. Values are passed to the builder as-is and validated
        // there against the real style schema, so a wrong one is refused with a
        // reason rather than silently dropped.
        /** @var array<string, mixed> $styles */
        $styles = is_array($block['styles'] ?? null) ? $block['styles'] : [];

        $out[] = [
            'type' => $type,
            'styles' => $styles,
            'src' => esc_url_raw((string) ($block['src'] ?? '')),
            'query' => trim((string) ($block['query'] ?? '')),
            'alt' => trim((string) ($block['alt'] ?? '')),
            'align' => sanitize_key((string) ($block['align'] ?? '')),
            // Which column of a multi-column section this block belongs to.
            // Without it a section's blocks fall into the grid in reading order
            // and a hero of heading, text, buttons and a panel comes out as two
            // columns of two, which is never what the reference did.
            'column' => max(0, (int) ($block['column'] ?? 0)),
            'role' => sanitize_key((string) ($block['role'] ?? '')),
            'text' => trim((string) ($block['text'] ?? '')),
            'surface' => sanitize_key((string) ($block['surface'] ?? '')),
            'columns' => max(0, (int) ($block['columns'] ?? 0)),
            'items' => $items,
        ];
    }

    return $out;
}

/**
 * Component types a section may contain.
 *
 * Deliberately a closed list. An open one turns into free-form markup within a
 * week, and free-form markup is the thing that cannot be graded, cannot be
 * rebuilt on another builder, and cannot be changed centrally when the spec
 * changes.
 *
 * @return list<string>
 */
function block_types(): array
{
    return [
        'eyebrow',
        'heading',
        'text',
        'buttons',
        'cards',
        'panel',
        'list',
        'stats',
        'accordion',
        'logos',
        'media',
        'image',
        'quote',
        'divider',
        'spacer',
    ];
}

/**
 * The style of every named role in a spec, as a flat CSS property map.
 *
 * This is the whole contract between the spec and a builder. A builder asks for
 * the roles, turns each one into whatever a class is on its side, and never has
 * to know what a spec is; the spec never has to know what Elementor is. It also
 * means the classes a page is built from are generated from the measurements
 * rather than typed out by hand once per page, which is what made two earlier
 * attempts unreproducible.
 *
 * @param  array<string, mixed> $spec
 * @return array<string, array<string, mixed>>
 */
function roles(array $spec): array
{
    /** @var array<string, array<string, mixed>> $out */
    $out = [];

    /** @var array<string, string> $surfaces */
    $surfaces = $spec['surfaces'];
    $pad = (float) $spec['section_padding'];
    $pad_mobile = (float) $spec['section_padding_mobile'];
    $pad_mobile = $pad_mobile > 0 ? $pad_mobile : max(40.0, round($pad * 0.45));

    foreach ($surfaces as $name => $hex) {
        $out['surface-' . $name] = [
            'styles' => [
                'background' => ['color' => $hex],
                'padding' => [
                    'block-start' => $pad . 'px',
                    'block-end' => $pad . 'px',
                    'inline-start' => '24px',
                    'inline-end' => '24px',
                ],
            ],
            'mobile' => [
                'padding' => [
                    'block-start' => $pad_mobile . 'px',
                    'block-end' => $pad_mobile . 'px',
                    'inline-start' => '20px',
                    'inline-end' => '20px',
                ],
            ],
        ];
    }

    $out['container'] = [
        'styles' => [
            'max-width' => (float) $spec['container'] . 'px',
            'width' => '100%',
            'margin' => ['inline-start' => 'auto', 'inline-end' => 'auto'],
            'display' => 'flex',
            'flex-direction' => 'column',
            'gap' => (float) $spec['gap'] . 'px',
            'align-items' => 'flex-start',
        ],
        'mobile' => [],
    ];

    foreach (layouts() as $name => $columns) {
        if ($columns === '') {
            continue;
        }
        $out['layout-' . $name] = [
            'styles' => [
                'display' => 'grid',
                'grid-template-columns' => $columns,
                'gap' => (float) $spec['gap'] . 'px',
                'width' => '100%',
                'align-items' => 'start',
            ],
            // Every multi-column layout becomes one column on a phone. A spec
            // that had to state this per section would state it wrongly once.
            'mobile' => ['grid-template-columns' => '1fr'],
        ];
    }

    /** @var array<string, array<string, mixed>> $type */
    $type = $spec['type'];
    foreach ($type as $role => $props) {
        foreach ($surfaces as $surface_name => $surface_hex) {
            // A role is declared once and needed in every colour the page sets
            // it in, so each one is emitted per surface with a readable
            // foreground rather than leaving the caller to invent one.
            $ink = readable_on($surface_hex, $surfaces);
            $key = $role . '-on-' . $surface_name;
            $out[$key] = ['styles' => type_styles($props, $ink), 'mobile' => type_mobile($props)];
        }
        $out[$role] = [
            'styles' => type_styles($props, named_color($surfaces, (string) $props['color'])),
            'mobile' => type_mobile($props),
        ];
    }

    // Components. A spec that stopped at type and surfaces could colour a page
    // correctly and still contain none of the things the reference is made of,
    // so the filled blocks a page is actually built from are generated here
    // from the same numbers rather than hand-written per page.
    $radius = is_array($spec['radius'] ?? null) ? $spec['radius'] : [];
    $card_radius = (float) ($radius['md'] ?? $radius['sm'] ?? 16);
    $pill = (float) ($radius['pill'] ?? 999);
    $gap = (float) $spec['gap'];

    foreach ($surfaces as $name => $hex) {
        $ink = readable_on($hex, $surfaces);

        $out['card-' . $name] = [
            'styles' => [
                'background' => ['color' => $hex],
                'border-radius' => $card_radius . 'px',
                'padding' => [
                    'block-start' => '32px',
                    'block-end' => '32px',
                    'inline-start' => '32px',
                    'inline-end' => '32px',
                ],
                'display' => 'flex',
                'flex-direction' => 'column',
                'gap' => '12px',
            ],
            'mobile' => [],
        ];

        // A button on a ground is that ground's strongest counterpart filled
        // in, with the ground itself as the label. Derived rather than declared
        // because a spec that had to name a button colour per surface would
        // name one of them wrong.
        $out['button-' . $name] = [
            'styles' => [
                'background' => ['color' => $ink],
                'color' => $hex,
                'font-weight' => '600',
                'padding' => [
                    'block-start' => '18px',
                    'block-end' => '18px',
                    'inline-start' => '32px',
                    'inline-end' => '32px',
                ],
                'border-radius' => $pill . 'px',
                'border-width' => '0px',
                'align-self' => 'flex-start',
                'cursor' => 'pointer',
            ],
            'mobile' => [],
        ];
    }

    $out['row'] = [
        'styles' => [
            'display' => 'flex',
            'flex-direction' => 'row',
            'gap' => '16px',
            'flex-wrap' => 'wrap',
            'align-items' => 'center',
        ],
        'mobile' => [],
    ];

    $out['stack'] = [
        'styles' => [
            'display' => 'flex',
            'flex-direction' => 'column',
            'gap' => $gap . 'px',
            'align-items' => 'flex-start',
            'width' => '100%',
        ],
        'mobile' => [],
    ];

    return $out;
}

/**
 * @param  array<string, mixed> $props
 * @return array<string, mixed>
 */
function type_styles(array $props, string $color): array
{
    $styles = [
        'font-size' => (float) $props['size'] . 'px',
        'font-weight' => (string) $props['weight'],
        'line-height' => ['$$type' => 'size', 'value' => ['size' => (string) $props['line_height'], 'unit' => 'custom']],
        'color' => $color,
    ];
    if ((string) $props['family'] !== '') {
        $styles['font-family'] = (string) $props['family'];
    }
    if ((float) $props['tracking'] !== 0.0) {
        $styles['letter-spacing'] = (float) $props['tracking'] . 'em';
    }
    if ((float) $props['measure'] > 0) {
        $styles['max-width'] = (float) $props['measure'] . 'ch';
    }
    return $styles;
}

/**
 * @param  array<string, mixed> $props
 * @return array<string, mixed>
 */
function type_mobile(array $props): array
{
    $mobile = [];
    if ((float) $props['size_mobile'] > 0) {
        $mobile['font-size'] = (float) $props['size_mobile'] . 'px';
    }
    if ((float) $props['measure'] > 0) {
        $mobile['max-width'] = '100%';
    }
    return $mobile;
}

/** A named surface's hex, or the name itself when it is already one. */
function named_color(array $surfaces, string $name): string
{
    if (array_key_exists($name, $surfaces)) {
        return (string) $surfaces[$name];
    }
    $hex = Preflight\normalize_hex($name);
    return $hex !== '' ? $hex : '#000000';
}

/**
 * The declared surface that should carry text on a given ground.
 *
 * Picked from the spec's own surfaces rather than defaulting to black or white,
 * because a design that chose a near-black green and a warm off-white chose
 * them for each other, and dropping pure white onto its dark sections is the
 * detail that makes a faithful copy look slightly wrong.
 *
 * Not simply the highest contrast, which was the first answer and the wrong
 * one: on a near-black ground an acid lime out-contrasts the cream, so every
 * paragraph on every dark section came out in the accent. That is the single
 * loudest mistake available with a palette like this, and the spec would have
 * compiled it into forty classes. Among the surfaces that clear readability the
 * quietest wins, and contrast only decides it when nothing clears the bar.
 *
 * @param array<string, string> $surfaces
 */
function readable_on(string $ground, array $surfaces): string
{
    $readable = '';
    $readable_saturation = 2.0;
    $fallback = '';
    $fallback_ratio = 0.0;

    foreach ($surfaces as $hex) {
        $ratio = (float) (Contrast\ratio($hex, $ground) ?? 0.0);
        if ($ratio > $fallback_ratio) {
            $fallback_ratio = $ratio;
            $fallback = $hex;
        }
        if ($ratio < 4.5) {
            continue;
        }
        $hsl = Preflight\hex_to_hsl($hex);
        $saturation = $hsl === null ? 1.0 : (float) $hsl[1];
        if ($saturation < $readable_saturation) {
            $readable_saturation = $saturation;
            $readable = $hex;
        }
    }

    if ($readable !== '') {
        return $readable;
    }

    return $fallback !== '' ? $fallback : '#000000';
}

/**
 * Store a spec against a saved design.
 *
 * @param  array<string, mixed> $spec
 * @return array<string, mixed>|WP_Error
 */
function save(string $slug, array $spec): array|WP_Error
{
    $normalized = normalize($spec);
    if ($normalized instanceof WP_Error) {
        return $normalized;
    }

    $post = Store\find_user_post($slug);
    if ($post === null) {
        return new WP_Error(
            'wppilot_spec_no_design',
            sprintf(
                /* translators: %s: the slug asked for */
                __('No design "%s" exists to attach a spec to. Save the design first.', domain: 'wppilot'),
                $slug,
            ),
            ['status' => 404],
        );
    }

    update_post_meta($post->ID, META_KEY, wp_slash(wp_json_encode($normalized) ?: '{}'));

    return $normalized;
}

/**
 * Read a spec, or null when the design has none.
 *
 * @return array<string, mixed>|null
 */
function get(string $slug): ?array
{
    $post = Store\find_user_post($slug);
    if ($post === null) {
        return null;
    }
    /** @var mixed $raw */
    $raw = get_post_meta($post->ID, META_KEY, single: true);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    /** @var mixed $decoded */
    $decoded = json_decode($raw, associative: true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * How closely a built page matches the spec it was built from.
 *
 * The measurements come from the caller, because reading a rendered page means
 * a real browser resolving a real cascade, and PHP fetching HTML cannot do it.
 * What matters is that the comparison itself is not the caller's to make: an
 * agent asked to grade its own work grades it generously, and the whole reason
 * two earlier attempts shipped at a fifth of the target was that nothing
 * anywhere produced a number.
 *
 * Surfaces are weighted hardest. The sequence of grounds down a page is what a
 * person recognises before they read a word, and it is the first thing that
 * gets invented when a reference is described rather than measured.
 *
 * @param  array<string, mixed> $spec
 * @param  array<string, mixed> $actual
 * @return array{score: float, matched: int, checked: int, diffs: list<array<string, string>>}
 */
function compare(array $spec, array $actual): array
{
    /** @var list<array<string, string>> $diffs */
    $diffs = [];
    $weighted = 0.0;
    $total = 0.0;

    $check = static function (
        string $label,
        mixed $want,
        mixed $got,
        float $weight,
        float $tolerance = 0.0,
    ) use (&$diffs, &$weighted, &$total): void {
        $total += $weight;
        $ok = is_numeric($want) && is_numeric($got)
            ? abs((float) $want - (float) $got) <= $tolerance
            : (string) $want === (string) $got;
        if ($ok) {
            $weighted += $weight;
            return;
        }
        $diffs[] = [
            'property' => $label,
            'expected' => is_scalar($want) ? (string) $want : (string) (wp_json_encode($want) ?: ''),
            'actual' => is_scalar($got) ? (string) $got : (string) (wp_json_encode($got) ?: ''),
        ];
    };

    // The surface sequence, position by position. Compared as a sequence rather
    // than as a set: the same five grounds in a different order is a different
    // page, and a set comparison would call it a perfect match.
    /** @var list<array<string, mixed>> $sections */
    $sections = $spec['sections'];
    /** @var mixed $actual_surfaces */
    $actual_surfaces = $actual['surfaces'] ?? [];
    $actual_surfaces = is_array($actual_surfaces) ? array_values($actual_surfaces) : [];
    /** @var array<string, string> $surfaces */
    $surfaces = $spec['surfaces'];

    $check('section count', count($sections), count($actual_surfaces), weight: 3.0);
    foreach ($sections as $index => $section) {
        $want = (string) ($surfaces[(string) $section['surface']] ?? '');
        $got = Preflight\normalize_hex((string) ($actual_surfaces[$index] ?? ''));
        $check('section ' . $index . ' surface', $want, $got, weight: 3.0);
    }

    $check('container', $spec['container'], $actual['container'] ?? null, weight: 2.0, tolerance: 2.0);
    $check(
        'section padding',
        $spec['section_padding'],
        $actual['section_padding'] ?? null,
        weight: 2.0,
        tolerance: 2.0,
    );

    /** @var array<string, array<string, mixed>> $type */
    $type = $spec['type'];
    /** @var mixed $actual_type */
    $actual_type = $actual['type'] ?? [];
    $actual_type = is_array($actual_type) ? $actual_type : [];
    foreach ($type as $role => $props) {
        /** @var mixed $got */
        $got = $actual_type[$role] ?? [];
        $got = is_array($got) ? $got : [];
        // A point either way on size: a spec says 73 and a browser reports
        // 73.44 because the reference used a viewport-relative step.
        $check($role . ' size', $props['size'], $got['size'] ?? null, weight: 2.0, tolerance: 1.0);
        // Weight is unforgiving on purpose. It is the single property that
        // decides whether a page reads as the same design, and it is the one an
        // inference gets wrong most often.
        $check($role . ' weight', $props['weight'], $got['weight'] ?? null, weight: 2.0);
    }

    $score = $total > 0.0 ? round($weighted / $total, 3) : 0.0;

    return ['score' => $score, 'matched' => count($diffs) === 0 ? 1 : 0, 'checked' => (int) $total, 'diffs' => $diffs];
}
