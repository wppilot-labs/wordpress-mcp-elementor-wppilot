<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Rendered;

use DOMDocument;
use DOMElement;
use DOMXPath;
use WP_Error;

/**
 * Read a page as it is actually served.
 *
 * Every other check in this plugin reads what an agent *wrote*. This reads what
 * the visitor *gets*, which is a different thing on every real site: the theme
 * adds its own CSS, a plugin injects markup, a cache serves something stale, and
 * a page that was written correctly can still render wrong. An agent that never
 * looks at the served page is reporting on its own intentions.
 *
 * WHAT THIS IS NOT
 *
 * Not a headless browser. No JavaScript runs, so anything a script paints is
 * invisible here, and the report says so rather than implying full coverage.
 * That limitation buys a great deal: no binary to install, no Chromium to keep
 * patched, no per-page second of CPU, and it works on shared hosting where a
 * browser could never run. The checks below are the ones honestly answerable
 * from served HTML, and the list of what was not checked ships with every
 * result.
 *
 * Inline styles and style blocks are parsed for colours and fonts, which covers
 * how page builders actually emit design — Elementor writes a per-post
 * stylesheet, Flatsome writes inline attributes, Gutenberg writes inline style
 * objects. A colour that only exists in an external stylesheet is not seen, and
 * that is listed too.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** Ceiling on fetched bytes. A page past this is pathological, not a page. */
const MAX_BYTES = 3000000;

/** Checks this module performs, reported so a caller knows the shape of a pass. */
const CHECKED = [
    'reachable',
    'heading-outline',
    'image-alt',
    'empty-elements',
    'inline-colors',
    'inline-fonts',
    'render-errors',
    'page-weight',
];

/** Checks a served-HTML reader cannot make, reported so a pass is not overread. */
const NOT_CHECKED = [
    'javascript-rendered-content',
    'external-stylesheet-colors',
    'computed-cascade',
    'layout-overflow',
    'visual-regression',
    'responsive-breakpoints',
];

/**
 * Fetch and analyse one URL.
 *
 * @return array<string, mixed>|WP_Error
 */
function inspect(string $url, int $timeout = 20): array|WP_Error
{
    $url = esc_url_raw($url);
    if ($url === '' || !wp_http_validate_url($url)) {
        return new WP_Error(
            'wppilot_rendered_bad_url',
            __('That is not a URL this site is allowed to fetch.', domain: 'wppilot'),
        );
    }

    $started = microtime(true);
    $response = wp_remote_get($url, [
        'timeout' => max(5, min(30, $timeout)),
        'redirection' => 3,
        'sslverify' => !\wppilot_likely_self_signed_https(),
        'user-agent' => 'WPPilot/' . (defined('WPPILOT_VERSION') ? WPPILOT_VERSION : 'dev') . ' (rendered-check)',
    ]);
    $elapsed = (int) round((microtime(true) - $started) * 1000);

    if (is_wp_error($response)) {
        return new WP_Error('wppilot_rendered_unreachable', sprintf(
            /* translators: %s: transport error. */
            __('Could not fetch the page: %s', domain: 'wppilot'),
            $response->get_error_message(),
        ));
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $bytes = strlen($body);

    if ($status >= 400) {
        return [
            'url' => $url,
            'status' => $status,
            'reachable' => false,
            'findings' => [[
                'check' => 'reachable',
                'severity' => 'fail',
                'message' => sprintf(
                    /* translators: %d: HTTP status. */
                    __('The page answered HTTP %d, so nothing was rendered to check.', domain: 'wppilot'),
                    $status,
                ),
                'evidence' => (string) $status,
            ]],
            'checked' => ['reachable'],
            'not_checked' => NOT_CHECKED,
        ];
    }
    if ($bytes > MAX_BYTES) {
        return new WP_Error('wppilot_rendered_too_large', sprintf(
            /* translators: 1: page size, 2: the limit. */
            __('The page is %1$d bytes, past the %2$d byte limit for this check.', domain: 'wppilot'),
            $bytes,
            MAX_BYTES,
        ));
    }

    $document = parse($body);
    $findings = [];
    $summary = [];

    if ($document === null) {
        $findings[] = [
            'check' => 'render-errors',
            'severity' => 'fail',
            'message' => __('The served HTML could not be parsed at all.', domain: 'wppilot'),
            'evidence' => '',
        ];
    } else {
        $xpath = new DOMXPath($document);
        array_push($findings, ...headings($xpath, $summary));
        array_push($findings, ...images($xpath, $summary));
        array_push($findings, ...empty_elements($xpath, $summary));
        array_push($findings, ...render_errors($body));
    }

    $colors = colors_in($body);
    $fonts = fonts_in($body);
    $summary['colors_found'] = count($colors);
    $summary['fonts_found'] = count($fonts);

    array_push($findings, ...weight($bytes, $elapsed));

    return [
        'url' => $url,
        'status' => $status,
        'reachable' => true,
        'bytes' => $bytes,
        'fetch_ms' => $elapsed,
        'colors' => array_values($colors),
        'fonts' => array_values($fonts),
        'summary' => $summary,
        'findings' => $findings,
        'ok' => !array_filter($findings, static fn(array $f): bool => $f['severity'] === 'fail'),
        'checked' => CHECKED,
        'not_checked' => NOT_CHECKED,
    ];
}

/** Parse HTML without letting libxml's complaints reach the response. */
function parse(string $html): ?DOMDocument
{
    if (trim($html) === '') {
        return null;
    }
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    // Real pages are not valid XML and never will be; the goal is a tree to walk,
    // not a verdict on their markup.
    $loaded = $document->loadHTML(
        '<?xml encoding="UTF-8">' . $html,
        LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET,
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return $loaded ? $document : null;
}

/**
 * Heading structure: exactly one h1, and no skipped levels.
 *
 * @param array<string, mixed> $summary
 * @return list<array<string, mixed>>
 */
function headings(DOMXPath $xpath, array &$summary): array
{
    $nodes = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');
    $levels = [];
    $outline = [];
    if ($nodes !== false) {
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $level = (int) substr($node->nodeName, offset: 1);
            $levels[] = $level;
            if (count($outline) < 25) {
                $outline[] = [
                    'level' => $level,
                    'text' => trim(preg_replace('/\s+/', ' ', $node->textContent) ?? ''),
                ];
            }
        }
    }
    $summary['headings'] = count($levels);
    $summary['outline'] = $outline;

    $findings = [];
    $h1 = count(array_filter($levels, static fn(int $l): bool => $l === 1));
    if ($h1 === 0 && $levels !== []) {
        $findings[] = [
            'check' => 'heading-outline',
            'severity' => 'warn',
            'message' => __('The page has headings but no h1.', domain: 'wppilot'),
            'evidence' => '',
        ];
    }
    if ($h1 > 1) {
        $findings[] = [
            'check' => 'heading-outline',
            'severity' => 'warn',
            'message' => sprintf(
                /* translators: %d: number of h1 elements. */
                __('The page has %d h1 elements; one is the convention.', domain: 'wppilot'),
                $h1,
            ),
            'evidence' => (string) $h1,
        ];
    }
    $previous = 0;
    foreach ($levels as $level) {
        if ($previous !== 0 && $level > $previous + 1) {
            $findings[] = [
                'check' => 'heading-outline',
                'severity' => 'warn',
                'message' => __('The heading outline skips a level, which screen readers announce as a gap.', domain: 'wppilot'),
                'evidence' => sprintf('h%d -> h%d', $previous, $level),
            ];
            break;
        }
        $previous = $level;
    }

    return $findings;
}

/**
 * Images without alt text, and images with no source at all.
 *
 * @param array<string, mixed> $summary
 * @return list<array<string, mixed>>
 */
function images(DOMXPath $xpath, array &$summary): array
{
    $nodes = $xpath->query('//img');
    $total = 0;
    $missing_alt = [];
    $missing_src = 0;
    if ($nodes !== false) {
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $total++;
            $src = trim($node->getAttribute('src'));
            $lazy = trim($node->getAttribute('data-src')) . trim($node->getAttribute('srcset'));
            if ($src === '' && $lazy === '') {
                $missing_src++;
            }
            // A present-but-empty alt is deliberate: it marks the image
            // decorative. A missing attribute is the defect.
            if (!$node->hasAttribute('alt') && count($missing_alt) < 10) {
                $missing_alt[] = $src === '' ? '(no src)' : basename(parse_url($src, PHP_URL_PATH) ?? $src);
            }
        }
    }
    $summary['images'] = $total;

    $findings = [];
    if ($missing_alt !== []) {
        $findings[] = [
            'check' => 'image-alt',
            'severity' => 'warn',
            'message' => __(
                'Images have no alt attribute. An empty alt is fine and marks an image decorative; a missing one is not.',
                domain: 'wppilot',
            ),
            'evidence' => implode(', ', $missing_alt),
        ];
    }
    if ($missing_src > 0) {
        $findings[] = [
            'check' => 'image-alt',
            'severity' => 'fail',
            'message' => sprintf(
                /* translators: %d: number of images. */
                __('%d image elements render with no source, so they show as broken.', domain: 'wppilot'),
                $missing_src,
            ),
            'evidence' => (string) $missing_src,
        ];
    }

    return $findings;
}

/**
 * Containers a builder wrote that ended up with nothing in them.
 *
 * The characteristic failure of an agent-built page: the structure is right,
 * a step that should have filled a column silently did not, and the page ships
 * with a hole in it that reads as a styling bug rather than missing content.
 *
 * @param array<string, mixed> $summary
 * @return list<array<string, mixed>>
 */
function empty_elements(DOMXPath $xpath, array &$summary): array
{
    $nodes = $xpath->query(
        '//section|//article'
        . '|//div[contains(@class,"col")]'
        . '|//div[contains(@class,"elementor-widget")]'
        . '|//div[contains(@class,"wp-block")]',
    );
    $empty = 0;
    $examples = [];
    if ($nodes !== false) {
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            if (trim($node->textContent) !== '') {
                continue;
            }
            // Text is not the only content: an image, an embed or an svg makes
            // a container legitimately textless.
            $inner = new DOMXPath($node->ownerDocument ?? new DOMDocument());
            $media = $inner->query('.//img|.//svg|.//iframe|.//video|.//canvas|.//input|.//button', $node);
            if ($media !== false && $media->length > 0) {
                continue;
            }
            $empty++;
            if (count($examples) < 6) {
                $class = trim($node->getAttribute('class'));
                $examples[] = $node->nodeName . ($class === '' ? '' : '.' . strtok($class, ' '));
            }
        }
    }
    $summary['empty_containers'] = $empty;

    if ($empty === 0) {
        return [];
    }

    return [[
        'check' => 'empty-elements',
        'severity' => $empty > 3 ? 'fail' : 'warn',
        'message' => sprintf(
            /* translators: %d: number of empty containers. */
            __(
                '%d containers rendered with no text and no media. On an agent-built page this usually means a step that should have filled one did not.',
                domain: 'wppilot',
            ),
            $empty,
        ),
        'evidence' => implode(', ', $examples),
    ]];
}

/**
 * PHP notices, unresolved shortcodes and template placeholders in the output.
 *
 * @return list<array<string, mixed>>
 */
function render_errors(string $html): array
{
    $findings = [];
    $patterns = [
        'php-error' => '/\b(Fatal error|Parse error|Warning|Notice|Deprecated):\s.{0,80}\bin\b.{0,80}\bon line\b/i',
        'unrendered-shortcode' => '/\[(?:vc_row|et_pb_section|section|row|col|ux_banner|fusion_builder_row)[^\]]{0,120}\]/i',
        'template-placeholder' => '/\{\{\s*[a-z_.]+\s*\}\}/i',
    ];
    foreach ($patterns as $check => $pattern) {
        if (preg_match($pattern, $html, $match) !== 1) {
            continue;
        }
        $findings[] = [
            'check' => 'render-errors',
            'severity' => $check === 'php-error' ? 'fail' : 'warn',
            'message' => match ($check) {
                'php-error' => __('A PHP error is being printed into the served page.', domain: 'wppilot'),
                'unrendered-shortcode' => __(
                    'A builder shortcode reached the visitor unrendered, which means the plugin or theme that owns it is not handling it here.',
                    domain: 'wppilot',
                ),
                default => __('A template placeholder was served without being substituted.', domain: 'wppilot'),
            },
            'evidence' => trim(substr($match[0], offset: 0, length: 120)),
        ];
    }

    return $findings;
}

/**
 * Page weight and fetch time, reported without inventing a speed score.
 *
 * @return list<array<string, mixed>>
 */
function weight(int $bytes, int $elapsed): array
{
    if ($bytes < 500000) {
        return [];
    }

    return [[
        'check' => 'page-weight',
        'severity' => 'warn',
        'message' => sprintf(
            /* translators: 1: kilobytes of HTML. */
            __(
                'The HTML document alone is %1$dKB before any image, script or stylesheet. That is worth a look, though it is not a speed measurement.',
                domain: 'wppilot',
            ),
            (int) round($bytes / 1024),
        ),
        'evidence' => $elapsed . 'ms',
    ]];
}

/**
 * Distinct colours in inline styles and style blocks, normalised to hex.
 *
 * @return array<string, string>
 */
function colors_in(string $html): array
{
    $found = [];
    if (preg_match_all('/#([0-9a-f]{6}|[0-9a-f]{3})\b/i', $html, $matches) !== false) {
        foreach ($matches[0] as $hex) {
            $normal = strtolower($hex);
            if (strlen($normal) === 4) {
                $normal = '#' . $normal[1] . $normal[1] . $normal[2] . $normal[2] . $normal[3] . $normal[3];
            }
            $found[$normal] = $normal;
        }
    }
    if (preg_match_all('/rgba?\(\s*(\d{1,3})\s*[,\s]\s*(\d{1,3})\s*[,\s]\s*(\d{1,3})/i', $html, $rgb, PREG_SET_ORDER) !== false) {
        foreach ($rgb as $match) {
            if ((int) $match[1] > 255 || (int) $match[2] > 255 || (int) $match[3] > 255) {
                continue;
            }
            $hex = sprintf('#%02x%02x%02x', (int) $match[1], (int) $match[2], (int) $match[3]);
            $found[$hex] = $hex;
        }
    }

    return array_slice($found, offset: 0, length: 200, preserve_keys: true);
}

/**
 * Distinct font families named in inline styles and style blocks.
 *
 * @return array<string, string>
 */
function fonts_in(string $html): array
{
    $found = [];
    if (preg_match_all('/font-family\s*:\s*([^;"\'}<]+)/i', $html, $matches) === false) {
        return $found;
    }
    foreach ($matches[1] as $stack) {
        foreach (explode(',', $stack) as $family) {
            $family = strtolower(trim($family, " \t\n\r\0\x0B\"'"));
            if ($family === '' || str_starts_with($family, 'var(')) {
                continue;
            }
            $found[$family] = $family;
        }
    }

    return array_slice($found, offset: 0, length: 60, preserve_keys: true);
}
