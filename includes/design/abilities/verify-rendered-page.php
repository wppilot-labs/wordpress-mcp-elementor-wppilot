<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Abilities\VerifyRendered;

use WP_Error;
use WP_Post;
use WPPilot\Design\Abilities;
use WPPilot\Design\Rendered;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('wppilot/verify-rendered-page', [
        'label' => __('Verify Rendered Page', domain: 'wppilot'),
        'description' => __(
            'Fetches a page as a visitor gets it and reports what is actually there: the heading outline, images with no alt text or no source, containers that rendered empty, PHP errors or unrendered shortcodes reaching the visitor, the colours and fonts the served HTML carries, and the document weight. Call this after building or editing a page — every other check in WPPilot reads what you wrote, and this reads what the site served, which is a different thing once a theme, a plugin and a cache have had their turn. Pass `post_id` for a page on this site or `url` for any address the site can reach. No JavaScript is executed, so anything a script paints is not seen; `not_checked` lists that and the other limits with every result.',
            domain: 'wppilot',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'default' => [],
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'A post or page on this site. Its permalink is fetched.',
                ],
                'url' => [
                    'type' => 'string',
                    'description' => 'A URL to fetch instead. Use for archives, taxonomy pages, or another site.',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'minimum' => 5,
                    'maximum' => 30,
                    'default' => 20,
                ],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static function (array $input): array|WP_Error {
            $post_id = (int) ($input['post_id'] ?? 0);
            $url = trim((string) ($input['url'] ?? ''));

            if ($post_id > 0 && $url !== '') {
                return new WP_Error(
                    'wppilot_rendered_ambiguous',
                    __('Pass post_id or url, not both.', domain: 'wppilot'),
                );
            }
            if ($post_id > 0) {
                $post = get_post($post_id);
                if (!$post instanceof WP_Post) {
                    return new WP_Error('wppilot_rendered_no_post', __('No such post.', domain: 'wppilot'));
                }
                // A draft has no public permalink, and fetching one anonymously
                // returns the 404 rather than the page — which would report as a
                // broken page when the real answer is "it is not published yet".
                if ($post->post_status !== 'publish') {
                    return new WP_Error('wppilot_rendered_not_public', sprintf(
                        /* translators: %s: post status. */
                        __(
                            'That post is %s, so it has no public URL to fetch. Publish it, or pass a preview URL as `url`.',
                            domain: 'wppilot',
                        ),
                        $post->post_status,
                    ));
                }
                $url = (string) get_permalink($post);
            }
            if ($url === '') {
                return new WP_Error(
                    'wppilot_rendered_no_target',
                    __('Pass either post_id or url.', domain: 'wppilot'),
                );
            }

            $result = Rendered\inspect($url, (int) ($input['timeout'] ?? 20));
            if ($result instanceof WP_Error) {
                return $result;
            }
            if ($post_id > 0) {
                $result['post_id'] = $post_id;
            }

            return $result;
        },
        'permission_callback' => 'wppilot_permission_callback',
        'meta' => [
            'annotations' => [
                'readonly' => true,
                'destructive' => false,
                'idempotent' => true,
            ],
            'mcp' => ['public' => true, 'type' => 'tool'],
        ],
    ]);
}
