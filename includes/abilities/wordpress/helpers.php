<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Abilities\WordPress;

use WP_Error;
use WP_Post_Type;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Builder-content guard for the generic WP-core wrapper abilities.
 *
 * The wrappers must never write builder-managed content (owner policy): a
 * builder's layout belongs to its dedicated abilities, which validate the
 * structure and keep the page editable in that builder's editor. Agents that
 * never load a build-page skill sometimes bypass those abilities by writing
 * proprietary markup straight into post_content — on Divi that even destroyed
 * the page on the first Visual Builder open (the classic-content activation
 * wraps unmarked content in a divi/placeholder) — or by hand-writing a
 * builder's storage meta keys. Both are rejected with an error that names the
 * right ability, so the correction reaches the agent in-band.
 *
 * Markers and keys are storage facts verified against the builders this
 * plugin integrates: Divi 5 and Etch store block markup in post_content,
 * WPBakery stores shortcodes in post_content; Elementor, Bricks, Beaver
 * Builder, and Breakdance store their trees in postmeta.
 */

/**
 * Proprietary post_content markers.
 *
 * Block-based builders (Divi 5, Etch) are matched by a whitespace-aware regex,
 * not a literal, because WordPress's block grammar accepts any run of
 * whitespace (spaces, tabs, newlines) after `<!--` — a literal `<!-- wp:divi/`
 * would miss `<!--  wp:divi/` / `<!--\twp:divi/`, which still parse and render
 * as real builder blocks. Shortcode builders are matched by a plain substring
 * since their opening tag is prefix-stable.
 *
 * @var array<string, array{0: string, 1: string}>
 */
const WPPILOT_BUILDER_BLOCK_MARKERS = [
    'divi' => ['Divi 5', 'wppilot/divi-set-content'],
    'etch' => ['Etch', 'wppilot/etch-set-content'],
];

/**
 * @var array<string, array{0: string, 1: string}>
 */
const WPPILOT_BUILDER_SHORTCODE_MARKERS = [
    '[vc_row' => ['WPBakery', 'wppilot/wpbakery-set-content'],
    '[et_pb_section' => ['Divi (legacy shortcodes)', 'wppilot/divi-set-content'],
];

/**
 * Builder storage meta keys, mapped to the builder that owns them. Compared
 * case-insensitively (WordPress meta keys are stored verbatim but MySQL's
 * default collation matches them case-insensitively, so an uppercase variant
 * still pollutes "is this built with X" queries). List every key that stores a
 * builder's element tree, activation flag, or page settings — the keys are
 * conventionally lowercase, so keep them lowercase here. Ordinary post meta
 * must stay writable.
 *
 * @var array<string, string>
 */
const WPPILOT_BUILDER_STORAGE_META_KEYS = [
    // Element-tree / content storage.
    '_elementor_data' => 'Elementor',
    '_bricks_page_content_2' => 'Bricks',
    '_bricks_page_header_2' => 'Bricks',
    '_bricks_page_footer_2' => 'Bricks',
    '_fl_builder_data' => 'Beaver Builder',
    '_fl_builder_draft' => 'Beaver Builder',
    '_breakdance_data' => 'Breakdance',
    '_breakdance_template_settings' => 'Breakdance',
    // Activation flags (writing these flips a page into a builder without its tree).
    '_elementor_edit_mode' => 'Elementor',
    '_fl_builder_enabled' => 'Beaver Builder',
    '_bricks_editor_mode' => 'Bricks',
    '_et_pb_use_builder' => 'Divi',
    '_et_pb_use_divi_5' => 'Divi',
    '_et_pb_old_content' => 'Divi',
    '_et_pb_show_page_creation' => 'Divi',
    // Page/settings blobs.
    '_elementor_page_settings' => 'Elementor',
    '_elementor_css' => 'Elementor',
    '_bricks_page_settings' => 'Bricks',
    '_fl_builder_data_settings' => 'Beaver Builder',
    '_fl_builder_draft_settings' => 'Beaver Builder',
    // NOTE: _et_pb_page_layout is deliberately NOT here — it is Divi's per-post
    // theme layout (sidebar / full-width) on ordinary non-builder pages, not
    // element-tree storage, and there is no builder ability that owns it.
];

/**
 * Run both builder guards against a wrapper ability's input (after alias
 * normalization): the `content` field against the markup markers and the
 * `meta` map against the storage-key blocklist. Returns the rejection error,
 * or null when the write may proceed.
 *
 * @param array<string, mixed> $input
 */
function wordpress_builder_write_guard(array $input): ?WP_Error
{
    if (array_key_exists('content', $input)) {
        $guard = wordpress_builder_content_error((string) $input['content']);
        if ($guard !== null) {
            return $guard;
        }
    }

    if (is_array($input['meta'] ?? null)) {
        /** @var array<string, mixed> $meta_map */
        $meta_map = $input['meta'];
        return wordpress_builder_meta_error($meta_map);
    }

    return null;
}

/**
 * True when a normalized wrapper input carries a non-empty post_content write.
 *
 * @param array<string, mixed> $input
 */
function wordpress_has_nonempty_content(array $input): bool
{
    return array_key_exists('content', $input) && (string) $input['content'] !== '';
}

/**
 * True when the active runtime is Breakdance-capable.
 */
function wordpress_breakdance_active(): bool
{
    return defined('__BREAKDANCE_VERSION') && function_exists('Breakdance\\Data\\get_global_option');
}

/**
 * True when Breakdance owns the target post.
 */
function wordpress_breakdance_owns_post(int $post_id): bool
{
    if (!wordpress_breakdance_active() || !function_exists('Breakdance\\Admin\\get_mode')) {
        return false;
    }
    /** @var mixed $mode */
    $mode = \Breakdance\Admin\get_mode($post_id);
    return $mode === 'breakdance';
}

/**
 * Require explicit user confirmation before a generic wrapper writes non-empty
 * post_content on a Breakdance site (create) or to a Breakdance-owned post
 * (update). The opt-in is deliberately ability-specific so an agent cannot
 * infer permission from an unrelated code-write approval.
 *
 * @param array<string, mixed> $input Normalized wrapper input.
 */
function wordpress_breakdance_content_gate(array $input, ?int $post_id = null): ?WP_Error
{
    if (!wordpress_has_nonempty_content($input) || !wordpress_breakdance_active()) {
        return null;
    }
    if ($post_id !== null && !wordpress_breakdance_owns_post($post_id)) {
        return null;
    }
    if (($input['allow_raw_content_on_breakdance_post'] ?? false) === true) {
        return null;
    }

    $ownership = $post_id === null
        ? 'while Breakdance is active on this site'
        : sprintf('on Breakdance-owned post %d', $post_id);

    return new WP_Error(
        'breakdance_post_content_needs_confirmation',
        sprintf(
            'This write would store non-empty raw HTML/content in WordPress post_content %1$s. '
            . 'Breakdance renders its native element tree instead, so this content can bypass the editable '
            . 'builder canvas and may not render as intended. Leave post_content empty and build with '
            . 'wppilot/breakdance-set-content plus the wppilot/breakdance-* element abilities. Only if '
            . 'the user still wants raw WordPress content, re-call with '
            . 'allow_raw_content_on_breakdance_post: true only after the user has EXPLICITLY confirmed '
            . '— do not set that flag on your own.',
            $ownership,
        ),
        ['status' => 422],
    );
}

/**
 * Keep an in-band audit trace after a confirmed raw-content write.
 *
 * For creates, Breakdance is active but does not own the new post yet, so the
 * note stays softer than the warning attached to an owned-post update.
 *
 * @param array<string, mixed> $input Normalized wrapper input.
 * @return list<string>
 */
function wordpress_breakdance_content_warnings(array $input, ?int $post_id = null): array
{
    if (
        !wordpress_has_nonempty_content($input)
        || !wordpress_breakdance_active()
        || ($input['allow_raw_content_on_breakdance_post'] ?? false) !== true
    ) {
        return [];
    }

    if ($post_id === null) {
        return [
            'AUDIT NOTE — Breakdance is active on this site. After explicit confirmation, the initial content '
                . 'was saved as ordinary WordPress post_content. If this post is intended for Breakdance, build '
                . 'the canvas with wppilot/breakdance-set-content and the other wppilot/breakdance-* abilities.',
        ];
    }

    if (!wordpress_breakdance_owns_post($post_id)) {
        return [];
    }

    return [
        'AUDIT — Raw post_content was written to a Breakdance-owned post after explicit confirmation. It does '
            . 'not update the Breakdance element tree and may not render with the builder canvas. Prefer '
            . 'wppilot/breakdance-set-content and the other wppilot/breakdance-* abilities.',
    ];
}

/**
 * Reject post_content that carries a builder's proprietary markup. Returns
 * the rejection error, or null when the content is ordinary (plain HTML and
 * native Gutenberg blocks pass).
 */
function wordpress_builder_content_error(string $content): ?WP_Error
{
    foreach (WPPILOT_BUILDER_BLOCK_MARKERS as $slug => [$builder, $ability]) {
        // Mirror WordPress's block opening delimiter: "<!--", one-or-more
        // whitespace, an optional void-close "/", then the lowercase namespace.
        if (preg_match('#<!--\s+/?wp:' . preg_quote($slug, delimiter: '#') . '/#', $content) === 1) {
            return wordpress_builder_content_rejected($builder, $ability);
        }
    }
    foreach (WPPILOT_BUILDER_SHORTCODE_MARKERS as $marker => [$builder, $ability]) {
        // Anchor to the start of a line (optional leading whitespace): a real
        // shortcode-builder page BEGINS its content with the wrapper shortcode,
        // whereas prose that merely mentions "[vc_row]" in a sentence or a code
        // block has it mid-line — matching that would refuse to save a tutorial
        // ABOUT the builder. The brackets aren't HTML-escaped in a code block,
        // so a substring test can't tell the two apart; a line-start anchor can.
        if (preg_match('#^[ \t]*' . preg_quote($marker, delimiter: '#') . '#m', $content) === 1) {
            return wordpress_builder_content_rejected($builder, $ability);
        }
    }
    return null;
}

/**
 * The shared rejection error for builder markup in post_content.
 */
function wordpress_builder_content_rejected(string $builder, string $ability): WP_Error
{
    return new WP_Error(
        'builder_content_rejected',
        sprintf(
            'The content contains %1$s builder markup. Never write builder-managed content '
            . 'through this ability: %2$s, so the layout is validated and the page stays '
            . 'editable in the builder.',
            $builder,
            builder_remedy($ability, $builder),
        ),
        ['status' => 422],
    );
}

/**
 * Reject a meta map that hand-writes a builder's storage keys. Returns the
 * rejection error, or null when every key is ordinary post meta.
 *
 * @param array<string, mixed> $meta
 */
function wordpress_builder_meta_error(array $meta): ?WP_Error
{
    foreach (array_keys($meta) as $key) {
        $builder = WPPILOT_BUILDER_STORAGE_META_KEYS[strtolower($key)] ?? null;
        if ($builder === null) {
            continue;
        }
        return new WP_Error(
            'builder_meta_rejected',
            sprintf(
                '"%1$s" is %2$s builder storage. Never hand-write builder storage meta through '
                . 'this ability: use the %2$s-specific abilities instead, so the data stays '
                . 'consistent and the page stays editable in the builder.',
                $key,
                $builder,
            ),
            ['status' => 422],
        );
    }
    return null;
}

/**
 * Content statuses the core wrappers accept.
 *
 * @var list<string>
 */
const WPPILOT_CORE_CONTENT_STATUSES = ['publish', 'draft', 'pending', 'private', 'future'];

/**
 * Statuses that make content publicly reachable and therefore need `publish_posts`.
 *
 * `future` is included because a scheduled post publishes itself without a second
 * capability check, and `private` because it is a published post with a restricted
 * audience — WordPress maps both through the publish capability.
 *
 * @var list<string>
 */
const WPPILOT_CORE_PUBLISHING_STATUSES = ['publish', 'future', 'private'];

/**
 * Post types the generic content wrappers must never touch.
 *
 * Each is either an internal WordPress bookkeeping type or a type owned by a
 * dedicated ability that validates its structure. Writing them through the
 * generic wrapper produces rows the owning subsystem cannot read back.
 *
 * @var list<string>
 */
const WPPILOT_NON_AGENT_POST_TYPES = [
    'attachment',
    'revision',
    'nav_menu_item',
    'custom_css',
    'customize_changeset',
    'oembed_cache',
    'user_request',
    'wp_block',
    'wp_template',
    'wp_template_part',
    'wp_global_styles',
    'wp_navigation',
];

/**
 * Reject post types that are not appropriate for remote agent use.
 *
 * Two rules, in order: the explicit blocklist above, then a visibility test. A
 * type that is neither `public` nor `show_in_rest` is plugin-private storage —
 * exposing it through a generic wrapper leaks internal state and lets an agent
 * write rows the owning plugin never validates.
 */
function wordpress_post_type_is_agent_facing(string $post_type): ?WP_Error
{
    if (in_array($post_type, WPPILOT_NON_AGENT_POST_TYPES, strict: true)) {
        return new WP_Error(
            'post_type_not_agent_facing',
            sprintf(
                'Post type "%s" is internal WordPress storage or is owned by a dedicated ability. '
                . 'Use the ability built for it rather than the generic content wrapper.',
                $post_type,
            ),
            ['status' => 403],
        );
    }

    $object = get_post_type_object($post_type);
    if (!$object instanceof WP_Post_Type) {
        return new WP_Error(
            'invalid_post_type',
            sprintf('Post type "%s" is not registered.', $post_type),
            ['status' => 404],
        );
    }

    if ($object->public !== true && $object->show_in_rest !== true) {
        return new WP_Error(
            'post_type_not_public',
            sprintf(
                'Post type "%s" is registered as private (neither public nor show_in_rest), so it is not exposed to agents.',
                $post_type,
            ),
            ['status' => 403],
        );
    }

    return null;
}

/**
 * Resolve a requested status draft-first.
 *
 * Anything that is not an explicitly enumerated status — absent, null, blank,
 * wrong type, or an unrecognised string — becomes `draft`. The schema already
 * enumerates the valid values, but the wrappers are also reachable through the
 * REST shim and through rollback replay, so the invariant is enforced here too:
 * content is never published by omission or by a malformed value.
 */
function wordpress_resolve_create_status(mixed $status): string
{
    if (!is_string($status)) {
        return 'draft';
    }

    $normalized = strtolower(trim($status));

    return in_array($normalized, WPPILOT_CORE_CONTENT_STATUSES, strict: true) ? $normalized : 'draft';
}

/**
 * Enforce the post type's own capability object before a create.
 *
 * `edit_posts` is deliberately not assumed: a custom post type may declare an
 * entirely separate capability set, so every check reads from the registered
 * type's `cap` object. Publishing and author reassignment are checked separately
 * because they are distinct grants — an author-level account can create a draft
 * but may not publish it or attribute it to someone else.
 *
 * @param array<string, mixed> $input Normalized input, with `status` already resolved.
 */
function wordpress_create_capability_error(string $post_type, array $input): ?WP_Error
{
    $object = get_post_type_object($post_type);
    if (!$object instanceof WP_Post_Type) {
        return new WP_Error(
            'invalid_post_type',
            sprintf('Post type "%s" is not registered.', $post_type),
            ['status' => 404],
        );
    }

    $capabilities = $object->cap;

    if (!current_user_can((string) $capabilities->create_posts)) {
        return new WP_Error(
            'cannot_create_post',
            sprintf('You are not allowed to create content of type "%s".', $post_type),
            ['status' => 403],
        );
    }

    $status = wordpress_resolve_create_status($input['status'] ?? null);
    if (
        in_array($status, WPPILOT_CORE_PUBLISHING_STATUSES, strict: true)
        && !current_user_can((string) $capabilities->publish_posts)
    ) {
        return new WP_Error(
            'cannot_publish_post',
            sprintf(
                'You are not allowed to publish content of type "%s". Create it as a draft instead.',
                $post_type,
            ),
            ['status' => 403],
        );
    }

    $author = isset($input['author']) ? (int) $input['author'] : 0;
    if (
        $author > 0
        && $author !== get_current_user_id()
        && !current_user_can((string) $capabilities->edit_others_posts)
    ) {
        return new WP_Error(
            'cannot_assign_author',
            'You are not allowed to attribute content to another user.',
            ['status' => 403],
        );
    }

    return null;
}

/**
 * Register a WordPress-core ability, skipping names another plugin already owns.
 *
 * These abilities shipped in WPPilot Pro before this release. A site still running an
 * older Pro alongside this build would otherwise register each name twice, so the
 * first registration wins and the duplicate is dropped without a fatal or a
 * `_doing_it_wrong()` notice. Free registers on `wp_abilities_api_init` at priority
 * 20, ahead of Pro's integration loaders, so Free's definition is the surviving one.
 *
 * @param array<string, mixed> $args
 */
function register_core_ability(string $name, array $args): void
{
    if (function_exists('wp_has_ability') && wp_has_ability($name)) {
        return;
    }

    wp_register_ability($name, $args);
}

/**
 * Name the ability that owns a builder's content, but only when it is registered.
 *
 * The builder guards ship in Free, where the named Pro ability may not exist.
 * Pointing an agent at an ability it cannot call wastes a round trip, so the
 * fallback states the constraint instead of prescribing an unavailable tool.
 */
function builder_remedy(string $ability, string $builder): string
{
    if (function_exists('wp_has_ability') && wp_has_ability($ability)) {
        return sprintf('use %s (and that builder\'s other abilities) instead', $ability);
    }

    return sprintf(
        'edit this content in the %1$s editor instead — WPPilot Pro adds typed %1$s abilities that write it safely',
        $builder,
    );
}
