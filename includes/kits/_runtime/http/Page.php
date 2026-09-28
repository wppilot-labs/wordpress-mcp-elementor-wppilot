<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\Runtime;

use DOMDocument;
use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Fetch a page of this site as a visitor receives it, and parse it.
 *
 * Audits read served HTML, not post content: what a builder, a theme and three plugins made of
 * the content is what a screen reader and a search engine see. Modelled on WPPilot's design
 * checker (includes/design/rendered.php) and kept separate from it, so a kit carries it into a
 * plugin that has no design checker.
 *
 * Only this site's own pages are fetched. An agent choosing the URL must not be able to point
 * the server at an internal address, and wp_safe_remote_get() re-checks every redirect hop.
 */
final class Page
{
    /**
     * @return array{url: string, status: int, html: string, bytes: int, elapsed_ms: int}|WP_Error
     */
    public static function fetch(string $url, int $timeout = 20): array|WP_Error
    {
        $url = esc_url_raw($url);
        if ($url === '' || !wp_http_validate_url($url) || !self::is_same_site($url)) {
            return new WP_Error('kit_page_bad_url', 'Only pages on this site can be fetched.');
        }
        $started = microtime(true);
        $response = wp_safe_remote_get($url, [
            'timeout' => max(5, min(30, $timeout)),
            'redirection' => 3,
            'user-agent' => 'WordPress kit audit (' . home_url('/') . ')',
        ]);
        $elapsed = (int) round((microtime(true) - $started) * 1000);
        if ($response instanceof WP_Error) {
            return new WP_Error('kit_page_unreachable', 'Could not fetch the page: ' . $response->get_error_message());
        }
        $html = (string) wp_remote_retrieve_body($response);
        return [
            'url' => $url,
            'status' => (int) wp_remote_retrieve_response_code($response),
            'html' => $html,
            'bytes' => strlen($html),
            'elapsed_ms' => $elapsed,
        ];
    }

    /** Parse HTML into a tree to walk, without letting libxml's complaints reach the response. */
    public static function parse(string $html): ?DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        // Real pages are not valid XML; the goal is a tree to walk, not a verdict on the markup.
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $loaded ? $document : null;
    }

    public static function is_same_site(string $url): bool
    {
        $target = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));
        return is_array($target) && is_array($home)
            && strtolower((string) ($target['host'] ?? '')) === strtolower((string) ($home['host'] ?? ''));
    }
}
