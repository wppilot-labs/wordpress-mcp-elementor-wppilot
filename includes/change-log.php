<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_CHANGE_LOG_OPTION = 'wppilot_change_log';

const WPPILOT_CHANGE_LOG_MAX = 500;

const WPPILOT_CHANGE_LOG_MAX_BYTES = 4_194_304;

const WPPILOT_CHANGE_SNAPSHOT_MAX_BYTES = 524_288;

/** @return list<array<string, mixed>> */
function wppilot_get_change_log(): array
{
    /** @var mixed $stored */
    $stored = get_option(WPPILOT_CHANGE_LOG_OPTION, default_value: []);
    if (!is_array($stored)) {
        return [];
    }
    $log = [];
    // @mago-expect analysis:mixed-assignment -- Stored option rows are normalized below.
    foreach ($stored as $entry) {
        if (is_array($entry)) {
            $log[] = wppilot_string_keyed_array($entry);
        }
    }
    return $log;
}

/** @param array<string, mixed> $entry */
function wppilot_store_change(array $entry): void
{
    $log = wppilot_get_change_log();
    $log[] = $entry;
    if (count($log) > WPPILOT_CHANGE_LOG_MAX) {
        $log = array_slice(array: $log, offset: -WPPILOT_CHANGE_LOG_MAX);
    }
    while (count($log) > 1) {
        $encoded = wp_json_encode($log);
        if (is_string($encoded) && strlen($encoded) <= WPPILOT_CHANGE_LOG_MAX_BYTES) {
            break;
        }
        array_shift($log);
    }
    update_option(WPPILOT_CHANGE_LOG_OPTION, $log, autoload: false);
}

/** @return array<string, mixed>|null */
function wppilot_get_change(string $id): ?array
{
    foreach (array_reverse(wppilot_get_change_log()) as $entry) {
        if (($entry['id'] ?? null) === $id) {
            return $entry;
        }
    }
    return null;
}

/** @param array<string, mixed> $replacement */
function wppilot_replace_change(string $id, array $replacement): bool
{
    $log = wppilot_get_change_log();
    foreach ($log as $index => $entry) {
        if (($entry['id'] ?? null) !== $id) {
            continue;
        }
        $log[$index] = $replacement;
        update_option(WPPILOT_CHANGE_LOG_OPTION, $log, autoload: false);
        return true;
    }
    return false;
}

/**
 * Capture a before-image for known reversible WordPress operations.
 */
function wppilot_change_before(string $ability_name, mixed $input): void
{
    if (wppilot_change_is_suppressed() || str_starts_with($ability_name, 'wppilot/rollback-change')) {
        return;
    }
    $ability = function_exists('wp_get_ability') ? wp_get_ability($ability_name) : null;
    if ($ability instanceof WP_Ability && wppilot_ability_is_readonly($ability)) {
        return;
    }
    $values = wppilot_string_keyed_array($input);
    wppilot_change_pending($ability_name, [
        'started_at' => microtime(true),
        'input_summary' => wppilot_redact_for_log($values),
        'input_sha256' => hash('sha256', (string) wp_json_encode($values)),
        'before' => wppilot_capture_before_image($ability_name, $values),
    ]);
}

/**
 * Record a completed mutation. WordPress fires this only after output validation succeeds.
 */
function wppilot_change_after(string $ability_name, mixed $input, mixed $result): void
{
    if (wppilot_change_is_suppressed()) {
        return;
    }
    $pending = wppilot_change_pending($ability_name);
    if ($pending === null) {
        return;
    }
    wppilot_change_pending($ability_name, value: null, clear: true);
    // @mago-expect analysis:mixed-assignment -- Pending data is internal and normalized below.
    $before_value = $pending['before'] ?? null;
    $before = is_array($before_value) ? wppilot_string_keyed_array($before_value) : null;
    $rollback = wppilot_build_rollback_payload($ability_name, $before, $result);
    $ability = function_exists('wp_get_ability') ? wp_get_ability($ability_name) : null;
    $risk = $ability instanceof WP_Ability ? wppilot_ability_risk($ability) : 'write';
    $user = wp_get_current_user();

    wppilot_store_change([
        'id' => wp_generate_uuid4(),
        'ability' => $ability_name,
        'risk' => $risk,
        'recorded_at' => gmdate('c'),
        // @mago-expect analysis:invalid-type-cast -- Pending timestamps are stored internally as floats.
        'duration_ms' => round(
            num: (microtime(true) - (float) ($pending['started_at'] ?? microtime(true))) * 1000,
            precision: 2,
        ),
        'user' => ['id' => (int) $user->ID, 'login' => (string) $user->user_login],
        // Which agent, not just which WordPress user. Several agents share one
        // administrator on most sites, so the user alone cannot attribute a write.
        'agent' => function_exists('wppilot_current_agent') ? wppilot_current_agent() : [],
        'input' => $pending['input_summary'] ?? [],
        'input_sha256' => (string) ($pending['input_sha256'] ?? ''),
        'result' => wppilot_result_summary($result),
        'rollback' => $rollback,
        'rolled_back' => false,
    ]);
}

/**
 * @param array<string, mixed>|null $value
 * @return array<string, mixed>|null
 */
function wppilot_change_pending(string $ability_name, ?array $value = null, bool $clear = false): ?array
{
    /** @var array<string, array<string, mixed>> $pending */
    static $pending = [];
    if ($clear) {
        $existing = $pending[$ability_name] ?? null;
        unset($pending[$ability_name]);
        return $existing;
    }
    if ($value !== null) {
        $pending[$ability_name] = $value;
    }
    return $pending[$ability_name] ?? null;
}

function wppilot_change_is_suppressed(?bool $set = null): bool
{
    static $suppressed = false;
    if ($set !== null) {
        $suppressed = $set;
    }
    return $suppressed;
}

/** @param array<string, mixed> $input @return array<string, mixed>|null */
// @mago-expect lint:cyclomatic-complexity -- Central snapshot routing keeps mutation coverage auditable.
// @mago-expect lint:halstead -- The explicit operation map is intentionally kept in one reviewable place.
function wppilot_capture_before_image(string $ability_name, array $input): ?array
{
    if (in_array($ability_name, ['wppilot/update-post', 'wppilot/delete-post'], strict: true)) {
        return wppilot_snapshot_post((int) ($input['post_id'] ?? $input['id'] ?? 0));
    }
    if ($ability_name === 'wppilot/create-post') {
        return ['type' => 'post-create'];
    }
    if (in_array($ability_name, ['wppilot/update-media', 'wppilot/delete-media'], strict: true)) {
        return wppilot_snapshot_post((int) ($input['attachment_id'] ?? 0));
    }
    if ($ability_name === 'wppilot/update-site-settings') {
        $values = [];
        foreach (array_keys($input) as $key) {
            $values[$key] = get_option($key);
        }
        return ['type' => 'settings', 'values' => $values, 'fingerprint' => wppilot_snapshot_fingerprint($values)];
    }
    if ($ability_name === 'wppilot/upsert-menu-item') {
        $item_id = (int) ($input['item_id'] ?? 0);
        return $item_id > 0 ? wppilot_snapshot_post($item_id) : ['type' => 'menu-create'];
    }
    if ($ability_name === 'wppilot/delete-menu-item') {
        return wppilot_snapshot_post((int) ($input['item_id'] ?? 0));
    }
    if ($ability_name === 'wppilot/woocommerce-update-order-status' && function_exists('wc_get_order')) {
        $order = wc_get_order((int) ($input['order_id'] ?? 0));
        if ($order instanceof WC_Order) {
            $values = ['order_id' => $order->get_id(), 'status' => $order->get_status()];
            return [
                'type' => 'order-status',
                'values' => $values,
                'fingerprint' => wppilot_snapshot_fingerprint($values),
            ];
        }
    }
    if ($ability_name === 'wppilot/woocommerce-create-coupon') {
        return ['type' => 'coupon-create'];
    }
    if ($ability_name === 'wppilot/woocommerce-update-coupon') {
        return wppilot_snapshot_post((int) ($input['coupon_id'] ?? 0));
    }
    if (in_array(
        $ability_name,
        ['wppilot/woocommerce-moderate-review', 'wppilot/woocommerce-delete-review'],
        strict: true,
    )) {
        // @mago-expect analysis:mixed-assignment -- WordPress returns WP_Comment|null for OBJECT output.
        $comment = get_comment((int) ($input['review_id'] ?? 0));
        if ($comment instanceof WP_Comment) {
            $values = ['comment_id' => (int) $comment->comment_ID, 'status' => wp_get_comment_status($comment)];
            return [
                'type' => 'comment-status',
                'values' => $values,
                'fingerprint' => wppilot_snapshot_fingerprint($values),
            ];
        }
    }
    if ($ability_name === 'wppilot/woocommerce-add-order-note') {
        return ['type' => 'order-note-create'];
    }
    return wppilot_capture_core_before_image($ability_name, $input);
}

/**
 * Before-images for the WordPress-core abilities.
 *
 * Split from wppilot_capture_before_image() so the core surface can grow without
 * pushing that function past its complexity budget. Anything whose effect lives
 * on a post row — terms, featured image, attachment parent, revision restore —
 * reuses wppilot_snapshot_post(), which already captures post fields, meta, and
 * every taxonomy assignment together.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|null
 */
// @mago-expect lint:cyclomatic-complexity -- One explicit branch per ability is the safety contract.
function wppilot_capture_core_before_image(string $ability_name, array $input): ?array
{
    if (in_array($ability_name, [
        'wppilot/assign-terms',
        'wppilot/set-featured-image',
        'wppilot/remove-featured-image',
        'wppilot/restore-post',
    ], strict: true)) {
        return wppilot_snapshot_post((int) ($input['post_id'] ?? 0));
    }
    if (in_array($ability_name, ['wppilot/attach-media', 'wppilot/detach-media'], strict: true)) {
        return wppilot_snapshot_post((int) ($input['attachment_id'] ?? 0));
    }
    if ($ability_name === 'wppilot/restore-revision') {
        // The write lands on the parent post, so that is what has to be captured.
        // wp_get_post_revision() takes its first parameter by reference, so the id
        // has to reach it as a variable: handing it the cast expression directly
        // raised "Only variables should be passed by reference" on every capture.
        $revision_id = (int) ($input['revision_id'] ?? 0);
        $revision = wp_get_post_revision($revision_id);
        return $revision instanceof WP_Post ? wppilot_snapshot_post((int) $revision->post_parent) : null;
    }
    if (in_array($ability_name, ['wppilot/update-term', 'wppilot/delete-term'], strict: true)) {
        return wppilot_snapshot_term((int) ($input['term_id'] ?? 0), (string) ($input['taxonomy'] ?? ''));
    }
    if ($ability_name === 'wppilot/update-menu') {
        return wppilot_snapshot_term((int) ($input['menu_id'] ?? 0), 'nav_menu');
    }
    if ($ability_name === 'wppilot/delete-menu') {
        return ['type' => 'menu-delete', 'menu_id' => (int) ($input['menu_id'] ?? 0)];
    }
    if ($ability_name === 'wppilot/create-term' || $ability_name === 'wppilot/create-menu') {
        return ['type' => 'term-create', 'taxonomy' => (string) ($input['taxonomy'] ?? 'nav_menu')];
    }
    if ($ability_name === 'wppilot/create-comment') {
        return ['type' => 'comment-create'];
    }
    if ($ability_name === 'wppilot/update-comment') {
        return wppilot_snapshot_comment((int) ($input['comment_id'] ?? 0));
    }
    if (in_array($ability_name, ['wppilot/moderate-comment', 'wppilot/delete-comment'], strict: true)) {
        // @mago-expect analysis:mixed-assignment -- WordPress returns WP_Comment|null for OBJECT output.
        $comment = get_comment((int) ($input['comment_id'] ?? 0));
        if ($comment instanceof WP_Comment) {
            $values = ['comment_id' => (int) $comment->comment_ID, 'status' => wp_get_comment_status($comment)];
            return [
                'type' => 'comment-status',
                'values' => $values,
                'fingerprint' => wppilot_snapshot_fingerprint($values),
            ];
        }
        return null;
    }
    if ($ability_name === 'wppilot/assign-menu-location') {
        $values = array_map('intval', (array) get_nav_menu_locations());
        return [
            'type' => 'menu-locations',
            'values' => $values,
            'fingerprint' => wppilot_snapshot_fingerprint($values),
        ];
    }
    if ($ability_name === 'wppilot/reorder-menu-items') {
        return wppilot_snapshot_menu_order((int) ($input['menu_id'] ?? 0));
    }
    if (in_array($ability_name, ['wppilot/activate-plugin', 'wppilot/deactivate-plugin'], strict: true)) {
        return wppilot_snapshot_plugin_state((string) ($input['file'] ?? ''));
    }
    if ($ability_name === 'wppilot/switch-theme') {
        $values = ['stylesheet' => get_stylesheet(), 'template' => get_template()];
        return [
            'type' => 'active-theme',
            'values' => $values,
            'fingerprint' => wppilot_snapshot_fingerprint($values),
        ];
    }
    if (in_array($ability_name, [
        'wppilot/install-plugin',
        'wppilot/install-theme',
        'wppilot/update-plugin',
        'wppilot/update-theme',
        'wppilot/delete-plugin',
        'wppilot/delete-theme',
    ], strict: true)) {
        // No before-image is possible — these move files on disk — but the
        // marker still reaches the rollback builder, which then states the real
        // reason instead of the generic "no strategy registered".
        return ['type' => 'extension-files'];
    }
    return null;
}

/**
 * Capture whether a plugin is active, per-site and network-wide.
 *
 * Activation state is the whole before-image: the files are untouched by
 * activate/deactivate, so restoring the flag restores the change.
 *
 * @return array<string, mixed>|null
 */
function wppilot_snapshot_plugin_state(string $file): ?array
{
    if ($file === '') {
        return null;
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $values = [
        'file' => $file,
        'active' => is_plugin_active($file),
        'network_active' => is_plugin_active_for_network($file),
    ];

    return ['type' => 'plugin-state', 'values' => $values, 'fingerprint' => wppilot_snapshot_fingerprint($values)];
}

/**
 * Capture a term's editable fields, scoped to the taxonomy the caller named.
 *
 * @return array<string, mixed>|null
 */
function wppilot_snapshot_term(int $term_id, string $taxonomy): ?array
{
    if ($term_id <= 0 || $taxonomy === '') {
        return null;
    }

    // @mago-expect analysis:mixed-assignment -- get_term returns WP_Term|WP_Error|null.
    $term = get_term($term_id, $taxonomy);
    if (!$term instanceof WP_Term) {
        return null;
    }

    $values = [
        'term_id' => (int) $term->term_id,
        'taxonomy' => (string) $term->taxonomy,
        'name' => (string) $term->name,
        'slug' => (string) $term->slug,
        'description' => (string) $term->description,
        'parent' => (int) $term->parent,
    ];

    return ['type' => 'term', 'values' => $values, 'fingerprint' => wppilot_snapshot_fingerprint($values)];
}

/**
 * Capture a comment's editable fields.
 *
 * The commenter's email and IP are deliberately excluded: the ledger is readable
 * by any WPPilot administrator, and restoring a comment's text never needs them.
 *
 * @return array<string, mixed>|null
 */
function wppilot_snapshot_comment(int $comment_id): ?array
{
    if ($comment_id <= 0) {
        return null;
    }

    // @mago-expect analysis:mixed-assignment -- WordPress returns WP_Comment|null for OBJECT output.
    $comment = get_comment($comment_id);
    if (!$comment instanceof WP_Comment) {
        return null;
    }

    $values = [
        'comment_id' => (int) $comment->comment_ID,
        'content' => (string) $comment->comment_content,
        'author' => (string) $comment->comment_author,
    ];

    return ['type' => 'comment', 'values' => $values, 'fingerprint' => wppilot_snapshot_fingerprint($values)];
}

/**
 * Capture a menu's item order and nesting so a reorder can be undone exactly.
 *
 * @return array<string, mixed>|null
 */
function wppilot_snapshot_menu_order(int $menu_id): ?array
{
    if ($menu_id <= 0) {
        return null;
    }

    $items = wp_get_nav_menu_items($menu_id);
    if (!is_array($items)) {
        return null;
    }

    $values = [];
    foreach ($items as $item) {
        $values[] = [
            'item_id' => (int) $item->ID,
            'position' => (int) $item->menu_order,
            'parent_id' => (int) $item->menu_item_parent,
        ];
    }

    return [
        'type' => 'menu-order',
        'menu_id' => $menu_id,
        'values' => $values,
        'fingerprint' => wppilot_snapshot_fingerprint($values),
    ];
}

/** @return array<string, mixed>|null */
function wppilot_snapshot_post(int $post_id): ?array
{
    // @mago-expect analysis:mixed-assignment -- ARRAY_A is validated immediately below.
    $post = get_post($post_id, output: 'ARRAY_A');
    if (!is_array($post)) {
        return null;
    }
    // @mago-expect analysis:mixed-assignment -- Full post meta is normalized before iteration.
    $all_meta_value = get_post_meta($post_id);
    $all_meta = is_array($all_meta_value) ? $all_meta_value : [];
    $meta = [];
    $excluded_meta_keys = [];
    // @mago-expect analysis:mixed-assignment -- WordPress post meta values are intentionally opaque.
    foreach ($all_meta as $key => $values) {
        if (wppilot_change_key_is_sensitive((string) $key)) {
            $excluded_meta_keys[] = (string) $key;
            continue;
        }
        $meta[(string) $key] = $values;
    }
    $terms = [];
    $taxonomies = get_object_taxonomies((string) $post['post_type'], output: 'names');
    foreach ($taxonomies as $taxonomy) {
        // @mago-expect analysis:mixed-assignment -- fields=ids may return a list or WP_Error and is checked below.
        $ids = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
        if (is_array($ids)) {
            $terms[$taxonomy] = array_map('intval', $ids);
        }
    }
    $snapshot = [
        'type' => 'post',
        'post' => $post,
        'meta' => $meta,
        'excluded_meta_keys' => $excluded_meta_keys,
        'terms' => $terms,
    ];
    $encoded = wp_json_encode($snapshot);
    if (!is_string($encoded) || strlen($encoded) > WPPILOT_CHANGE_SNAPSHOT_MAX_BYTES) {
        return ['type' => 'oversize', 'post_id' => $post_id, 'bytes' => is_string($encoded) ? strlen($encoded) : 0];
    }
    $snapshot['fingerprint'] = wppilot_post_snapshot_fingerprint($snapshot);
    return $snapshot;
}

/** @param array<string, mixed>|null $before @return array<string, mixed> */
// @mago-expect lint:cyclomatic-complexity -- Central rollback routing must cover each mutation explicitly.
// @mago-expect lint:halstead -- Explicit non-reversible reasons are part of the safety contract.
function wppilot_build_rollback_payload(string $ability_name, ?array $before, mixed $result): array
{
    if ($before === null || ($before['type'] ?? null) === 'oversize') {
        return [
            'reversible' => false,
            'reason' => $before === null
                ? 'No supported before-image.'
                : 'Before-image exceeded the safe storage limit.',
        ];
    }
    if ($ability_name === 'wppilot/delete-media') {
        return ['reversible' => false, 'reason' => 'The attachment file was permanently deleted.'];
    }
    if ($ability_name === 'wppilot/delete-menu-item') {
        return ['reversible' => false, 'reason' => 'The menu item was permanently deleted.'];
    }
    if (in_array(
        $ability_name,
        ['wppilot/woocommerce-delete-coupon', 'wppilot/woocommerce-create-refund'],
        strict: true,
    )) {
        return [
            'reversible' => false,
            'reason' => 'This commerce operation has external or permanent effects and is not automatically reversible.',
        ];
    }
    if (
        $ability_name === 'wppilot/woocommerce-delete-review'
        && is_array($result)
        && ($result['permanent'] ?? false) === true
    ) {
        return ['reversible' => false, 'reason' => 'The review was permanently deleted.'];
    }
    if ($ability_name === 'wppilot/delete-post' && is_array($result) && ($result['result'] ?? null) === 'deleted') {
        return ['reversible' => false, 'reason' => 'The post was permanently deleted.'];
    }
    if (($before['type'] ?? null) === 'post-create') {
        $post_id = is_array($result) ? (int) ($result['post_id'] ?? 0) : 0;
        return (
            $post_id > 0
                ? ['reversible' => true, 'type' => 'delete-created-post', 'post_id' => $post_id]
                : ['reversible' => false, 'reason' => 'Created post ID was not returned.']
        );
    }
    if (($before['type'] ?? null) === 'menu-create') {
        $item_id = is_array($result) ? (int) ($result['id'] ?? 0) : 0;
        return (
            $item_id > 0
                ? ['reversible' => true, 'type' => 'delete-created-post', 'post_id' => $item_id]
                : ['reversible' => false, 'reason' => 'Created menu item ID was not returned.']
        );
    }
    if (($before['type'] ?? null) === 'coupon-create') {
        $coupon_id = is_array($result) ? (int) ($result['id'] ?? 0) : 0;
        return (
            $coupon_id > 0
                ? ['reversible' => true, 'type' => 'delete-created-post', 'post_id' => $coupon_id]
                : ['reversible' => false, 'reason' => 'Created coupon ID was not returned.']
        );
    }
    if (($before['type'] ?? null) === 'order-note-create') {
        $note_id = is_array($result) ? (int) ($result['note_id'] ?? 0) : 0;
        return (
            $note_id > 0
                ? ['reversible' => true, 'type' => 'delete-created-comment', 'comment_id' => $note_id]
                : ['reversible' => false, 'reason' => 'Created order note ID was not returned.']
        );
    }
    if (($before['type'] ?? null) === 'post') {
        return ['reversible' => true, 'type' => 'restore-post', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'settings') {
        return ['reversible' => true, 'type' => 'restore-settings', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'order-status') {
        return ['reversible' => true, 'type' => 'restore-order-status', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'comment-status') {
        return ['reversible' => true, 'type' => 'restore-comment-status', 'snapshot' => $before];
    }
    return wppilot_build_core_rollback_payload($ability_name, $before, $result);
}

/**
 * Rollback strategies for the WordPress-core abilities.
 *
 * Permanent deletions are declared non-reversible explicitly rather than falling
 * through to the generic "no strategy registered" message, so the ledger states
 * the actual reason a change cannot be undone.
 *
 * @param array<string, mixed> $before
 * @return array<string, mixed>
 */
// @mago-expect lint:cyclomatic-complexity -- Explicit non-reversible reasons are part of the safety contract.
function wppilot_build_core_rollback_payload(string $ability_name, array $before, mixed $result): array
{
    if ($ability_name === 'wppilot/delete-term') {
        return [
            'reversible' => false,
            'reason' => 'WordPress has no trash for terms, so the term was permanently deleted.',
        ];
    }
    if ($ability_name === 'wppilot/delete-menu') {
        return [
            'reversible' => false,
            'reason' => 'The menu and all of its items were permanently deleted.',
        ];
    }
    if ($ability_name === 'wppilot/delete-comment') {
        return ['reversible' => false, 'reason' => 'The comment was permanently deleted.'];
    }
    if (($before['type'] ?? null) === 'term-create') {
        $term_id = is_array($result) ? (int) ($result['term_id'] ?? $result['menu_id'] ?? 0) : 0;
        return (
            $term_id > 0
                ? [
                    'reversible' => true,
                    'type' => 'delete-created-term',
                    'term_id' => $term_id,
                    'taxonomy' => (string) ($before['taxonomy'] ?? ''),
                ]
                : ['reversible' => false, 'reason' => 'Created term ID was not returned.']
        );
    }
    if (($before['type'] ?? null) === 'comment-create') {
        $comment_id = is_array($result) ? (int) ($result['comment_id'] ?? 0) : 0;
        return (
            $comment_id > 0
                ? ['reversible' => true, 'type' => 'delete-created-comment', 'comment_id' => $comment_id]
                : ['reversible' => false, 'reason' => 'Created comment ID was not returned.']
        );
    }
    if (($before['type'] ?? null) === 'term') {
        return ['reversible' => true, 'type' => 'restore-term', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'comment') {
        return ['reversible' => true, 'type' => 'restore-comment', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'menu-locations') {
        return ['reversible' => true, 'type' => 'restore-menu-locations', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'menu-order') {
        return ['reversible' => true, 'type' => 'restore-menu-order', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'plugin-state') {
        return ['reversible' => true, 'type' => 'restore-plugin-state', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'active-theme') {
        return ['reversible' => true, 'type' => 'restore-active-theme', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'extension-files') {
        return ['reversible' => false, 'reason' => wppilot_extension_files_rollback_reason($ability_name)];
    }
    return ['reversible' => false, 'reason' => 'No rollback strategy is registered for this change.'];
}

/**
 * Why a plugin/theme file operation cannot be undone from the ledger.
 *
 * Each reason names the manual path back, because "not reversible" alone tells
 * an operator nothing about what to do next.
 */
function wppilot_extension_files_rollback_reason(string $ability_name): string
{
    return match ($ability_name) {
        'wppilot/install-plugin' => 'Files were written to the server. Remove the plugin with wppilot/delete-plugin.',
        'wppilot/install-theme' => 'Files were written to the server. Remove the theme with wppilot/delete-theme.',
        'wppilot/update-plugin', 'wppilot/update-theme' => 'The previous version was overwritten and is not retained. Reinstall the earlier release from its own source.',
        'wppilot/delete-plugin' => 'The plugin files were permanently deleted. Reinstall it with wppilot/install-plugin.',
        'wppilot/delete-theme' => 'The theme files were permanently deleted. Reinstall it with wppilot/install-theme.',
        default => 'This operation changed files on the server and is not automatically reversible.',
    };
}

/**
 * Delete a term created by a rolled-back change.
 *
 * @return array<string, mixed>|WP_Error
 */
function wppilot_rollback_created_term(int $term_id, string $taxonomy): array|WP_Error
{
    if ($term_id <= 0 || $taxonomy === '') {
        return new WP_Error('wppilot_rollback_invalid_term', __('Created term is unknown.', domain: 'wppilot'));
    }
    // @mago-expect analysis:mixed-assignment -- get_term returns WP_Term|WP_Error|null.
    $term = get_term($term_id, $taxonomy);
    if (!$term instanceof WP_Term) {
        return ['result' => 'already-absent', 'term_id' => $term_id];
    }

    $deleted = wp_delete_term($term_id, $taxonomy);
    if (is_wp_error($deleted)) {
        return $deleted;
    }
    if ($deleted !== true) {
        return new WP_Error('wppilot_rollback_term_failed', __('The term could not be deleted.', domain: 'wppilot'));
    }

    return ['result' => 'deleted', 'term_id' => $term_id, 'taxonomy' => $taxonomy];
}

/**
 * Restore a term's captured fields, verifying the write landed.
 *
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>|WP_Error
 */
function wppilot_restore_term_snapshot(array $snapshot): array|WP_Error
{
    $values = wppilot_string_keyed_array($snapshot['values'] ?? null);
    $term_id = (int) ($values['term_id'] ?? 0);
    $taxonomy = (string) ($values['taxonomy'] ?? '');
    if ($term_id <= 0 || $taxonomy === '') {
        return new WP_Error('wppilot_rollback_invalid_term', __('Term snapshot is incomplete.', domain: 'wppilot'));
    }

    $updated = wp_update_term($term_id, $taxonomy, [
        'name' => (string) ($values['name'] ?? ''),
        'slug' => (string) ($values['slug'] ?? ''),
        'description' => (string) ($values['description'] ?? ''),
        'parent' => (int) ($values['parent'] ?? 0),
    ]);
    if (is_wp_error($updated)) {
        return $updated;
    }

    $observed = wppilot_snapshot_term($term_id, $taxonomy);
    if ($observed === null) {
        return new WP_Error(
            'wppilot_rollback_unverified',
            __('The term could not be re-read after the restore.', domain: 'wppilot'),
        );
    }

    return ['result' => 'restored', 'term_id' => $term_id, 'fingerprint' => $observed['fingerprint'] ?? ''];
}

/**
 * Restore a comment's captured content.
 *
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>|WP_Error
 */
function wppilot_restore_comment_snapshot(array $snapshot): array|WP_Error
{
    $values = wppilot_string_keyed_array($snapshot['values'] ?? null);
    $comment_id = (int) ($values['comment_id'] ?? 0);
    if ($comment_id <= 0) {
        return new WP_Error(
            'wppilot_rollback_invalid_comment',
            __('Comment snapshot is incomplete.', domain: 'wppilot'),
        );
    }

    $updated = wp_update_comment([
        'comment_ID' => $comment_id,
        'comment_content' => (string) ($values['content'] ?? ''),
        'comment_author' => (string) ($values['author'] ?? ''),
    ], wp_error: true);
    if (is_wp_error($updated)) {
        return $updated;
    }

    return ['result' => 'restored', 'comment_id' => $comment_id];
}

/**
 * Restore the theme's navigation-location assignments.
 *
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>
 */
function wppilot_restore_menu_locations_snapshot(array $snapshot): array
{
    $values = array_map('intval', wppilot_string_keyed_array($snapshot['values'] ?? null));
    set_theme_mod('nav_menu_locations', $values);

    return ['result' => 'restored', 'locations' => count($values)];
}

/**
 * Put a plugin back into its captured activation state.
 *
 * Reactivation runs the plugin's activation hooks again, which is what the
 * original activation did too — the alternative, a silent flag flip, leaves a
 * plugin marked active with none of its setup performed.
 *
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>|WP_Error
 */
function wppilot_restore_plugin_state_snapshot(array $snapshot): array|WP_Error
{
    $values = wppilot_string_keyed_array($snapshot['values'] ?? null);
    $file = (string) ($values['file'] ?? '');
    if ($file === '') {
        return new WP_Error('wppilot_rollback_invalid_plugin', __(
            'The captured plugin file is missing from the change record.',
            domain: 'wppilot',
        ));
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    if (!array_key_exists($file, get_plugins())) {
        return new WP_Error('wppilot_rollback_target_missing', __(
            'The plugin is no longer installed, so its activation state cannot be restored.',
            domain: 'wppilot',
        ));
    }

    $network_active = ($values['network_active'] ?? false) === true;
    $was_active = ($values['active'] ?? false) === true || $network_active;

    if ($was_active) {
        $activated = activate_plugin($file, redirect: '', network_wide: $network_active, silent: false);
        if (is_wp_error($activated)) {
            return $activated;
        }
    } else {
        deactivate_plugins([$file], silent: false, network_wide: $network_active ? true : null);
    }

    $now_active = is_plugin_active($file) || is_plugin_active_for_network($file);

    return [
        'file' => $file,
        'active' => $now_active,
        'verified' => $now_active === $was_active,
    ];
}

/**
 * Switch back to the theme that was active before the change.
 *
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>|WP_Error
 */
function wppilot_restore_active_theme_snapshot(array $snapshot): array|WP_Error
{
    $values = wppilot_string_keyed_array($snapshot['values'] ?? null);
    $stylesheet = (string) ($values['stylesheet'] ?? '');
    if ($stylesheet === '') {
        return new WP_Error('wppilot_rollback_invalid_theme', __(
            'The captured theme is missing from the change record.',
            domain: 'wppilot',
        ));
    }
    if (!wp_get_theme($stylesheet)->exists()) {
        return new WP_Error('wppilot_rollback_target_missing', __(
            'The previously active theme is no longer installed, so it cannot be restored.',
            domain: 'wppilot',
        ));
    }

    switch_theme($stylesheet);

    return [
        'stylesheet' => $stylesheet,
        'active' => get_stylesheet(),
        'verified' => get_stylesheet() === $stylesheet,
    ];
}

/**
 * Restore a menu's captured item order and nesting.
 *
 * Reports a partial failure honestly rather than claiming a clean rollback: a
 * half-restored menu order is worse than a reported one, because the operator
 * would otherwise believe the original order is back.
 *
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>|WP_Error
 */
function wppilot_restore_menu_order_snapshot(array $snapshot): array|WP_Error
{
    $menu_id = (int) ($snapshot['menu_id'] ?? 0);
    if ($menu_id <= 0 || wp_get_nav_menu_object($menu_id) === false) {
        return new WP_Error(
            'wppilot_rollback_menu_missing',
            __('The menu no longer exists, so its order cannot be restored.', domain: 'wppilot'),
        );
    }

    $restored = 0;
    /** @var mixed $entry */
    foreach (wppilot_string_list_of_arrays($snapshot['values'] ?? null) as $entry) {
        $result = wp_update_nav_menu_item($menu_id, (int) ($entry['item_id'] ?? 0), [
            'menu-item-position' => (int) ($entry['position'] ?? 0),
            'menu-item-parent-id' => (int) ($entry['parent_id'] ?? 0),
        ]);
        if (is_wp_error($result)) {
            return new WP_Error('wppilot_rollback_partial', sprintf(
                /* translators: 1: number of items restored, 2: the underlying error */
                __(
                    'The menu order was only partially restored: %1$d items were moved before the failure (%2$s). Re-read the menu before relying on its order.',
                    domain: 'wppilot',
                ),
                $restored,
                $result->get_error_message(),
            ));
        }
        ++$restored;
    }

    return ['result' => 'restored', 'menu_id' => $menu_id, 'items' => $restored];
}

/**
 * Normalize a stored list of associative arrays.
 *
 * @return list<array<string, mixed>>
 */
function wppilot_string_list_of_arrays(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $rows = [];
    /** @var mixed $row */
    foreach ($value as $row) {
        if (is_array($row)) {
            $rows[] = wppilot_string_keyed_array($row);
        }
    }

    return $rows;
}

/** @return array<string, mixed>|WP_Error */
// @mago-expect lint:cyclomatic-complexity -- The rollback transaction validates every terminal state.
function wppilot_rollback_change(string $id): array|WP_Error
{
    $entry = wppilot_get_change($id);
    if ($entry === null) {
        return new WP_Error('wppilot_change_not_found', __('Change record not found.', domain: 'wppilot'));
    }
    if (($entry['rolled_back'] ?? false) === true) {
        return new WP_Error('wppilot_change_already_rolled_back', __(
            'This change was already rolled back.',
            domain: 'wppilot',
        ));
    }
    $rollback = is_array($entry['rollback'] ?? null) ? $entry['rollback'] : [];
    if (($rollback['reversible'] ?? false) !== true) {
        return new WP_Error(
            'wppilot_change_not_reversible',
            (string) ($rollback['reason'] ?? __('This change is not reversible.', domain: 'wppilot')),
        );
    }

    wppilot_change_is_suppressed(true);
    try {
        $result = match ($rollback['type'] ?? '') {
            'delete-created-post' => wppilot_rollback_created_post((int) ($rollback['post_id'] ?? 0)),
            'restore-post' => wppilot_restore_post_snapshot(wppilot_string_keyed_array($rollback['snapshot'] ?? null)),
            'restore-settings' => wppilot_restore_settings_snapshot(wppilot_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            'restore-order-status' => wppilot_restore_order_status(wppilot_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            'restore-comment-status' => wppilot_restore_comment_status(wppilot_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            'delete-created-comment' => wppilot_rollback_created_comment((int) ($rollback['comment_id'] ?? 0)),
            'delete-created-term' => wppilot_rollback_created_term(
                (int) ($rollback['term_id'] ?? 0),
                (string) ($rollback['taxonomy'] ?? ''),
            ),
            'restore-term' => wppilot_restore_term_snapshot(wppilot_string_keyed_array($rollback['snapshot'] ?? null)),
            'restore-comment' => wppilot_restore_comment_snapshot(wppilot_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            'restore-menu-locations' => wppilot_restore_menu_locations_snapshot(wppilot_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            'restore-menu-order' => wppilot_restore_menu_order_snapshot(wppilot_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            'restore-plugin-state' => wppilot_restore_plugin_state_snapshot(wppilot_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            'restore-active-theme' => wppilot_restore_active_theme_snapshot(wppilot_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            default => new WP_Error('wppilot_rollback_unknown', __('Unknown rollback strategy.', domain: 'wppilot')),
        };
    } catch (Throwable $error) {
        $result = new WP_Error('wppilot_rollback_failed', $error->getMessage());
    } finally {
        wppilot_change_is_suppressed(false);
    }
    if ($result instanceof WP_Error) {
        return $result;
    }
    if (($result['verified'] ?? false) !== true) {
        return new WP_Error(
            'wppilot_rollback_unverified',
            __('Rollback ran but the observed state did not match the before-image.', domain: 'wppilot'),
            $result,
        );
    }
    $entry['rolled_back'] = true;
    $entry['rolled_back_at'] = gmdate('c');
    $entry['rollback_result'] = $result;
    wppilot_replace_change($id, $entry);
    return ['change_id' => $id, 'rolled_back' => true, 'verified' => true, 'details' => $result];
}

/** @return array<string, mixed>|WP_Error */
function wppilot_rollback_created_post(int $post_id): array|WP_Error
{
    if ($post_id <= 0 || !get_post($post_id)) {
        return new WP_Error('wppilot_rollback_target_missing', __('Created post no longer exists.', domain: 'wppilot'));
    }
    if (wp_trash_post($post_id) === false) {
        return new WP_Error('wppilot_rollback_delete_failed', __(
            'Could not move the created post to trash.',
            domain: 'wppilot',
        ));
    }
    return [
        'post_id' => $post_id,
        'status' => get_post_status($post_id),
        'verified' => get_post_status($post_id) === 'trash',
    ];
}

/** @param array<string, mixed> $snapshot @return array<string, mixed>|WP_Error */
// @mago-expect lint:cyclomatic-complexity -- Restoring posts requires independent post, meta, and taxonomy steps.
function wppilot_restore_post_snapshot(array $snapshot): array|WP_Error
{
    $post = wppilot_string_keyed_array($snapshot['post'] ?? null);
    $post_id = (int) ($post['ID'] ?? 0);
    if ($post_id <= 0 || !get_post($post_id)) {
        return new WP_Error('wppilot_rollback_target_missing', __(
            'The post required for rollback no longer exists.',
            domain: 'wppilot',
        ));
    }
    $allowed = [
        'ID',
        'post_author',
        'post_date',
        'post_date_gmt',
        'post_content',
        'post_title',
        'post_excerpt',
        'post_status',
        'comment_status',
        'ping_status',
        'post_password',
        'post_name',
        'post_parent',
        'menu_order',
        'post_type',
        'post_mime_type',
    ];
    $postarr = array_intersect_key($post, array_fill_keys(keys: $allowed, value: true));
    foreach (['post_content', 'post_title', 'post_excerpt', 'post_name'] as $key) {
        if (array_key_exists($key, $postarr)) {
            $postarr[$key] = wp_slash((string) $postarr[$key]);
        }
    }
    // @mago-expect analysis:possibly-invalid-argument -- Keys are restricted to WP's post update allowlist above.
    $updated = wp_update_post($postarr, wp_error: true);
    if (is_wp_error($updated)) {
        return $updated;
    }
    $excluded_meta_keys = wppilot_string_list($snapshot['excluded_meta_keys'] ?? []);
    // @mago-expect analysis:mixed-assignment -- Full post meta is normalized before reading its keys.
    $current_meta_value = get_post_meta($post_id);
    $current_meta = is_array($current_meta_value) ? $current_meta_value : [];
    foreach (array_keys($current_meta) as $key) {
        if (in_array((string) $key, $excluded_meta_keys, strict: true)) {
            continue;
        }
        delete_post_meta($post_id, (string) $key);
    }
    // @mago-expect analysis:mixed-assignment -- Snapshot values are validated at each nesting level.
    foreach (wppilot_string_keyed_array($snapshot['meta'] ?? []) as $key => $values) {
        // @mago-expect analysis:mixed-assignment -- Individual post-meta values are intentionally opaque.
        foreach (is_array($values) ? $values : [] as $value) {
            add_post_meta($post_id, $key, $value);
        }
    }
    // @mago-expect analysis:mixed-assignment -- Term lists are normalized to integers below.
    foreach (wppilot_string_keyed_array($snapshot['terms'] ?? []) as $taxonomy => $term_ids) {
        wp_set_object_terms(
            $post_id,
            array_map('intval', is_array($term_ids) ? $term_ids : []),
            $taxonomy,
            append: false,
        );
    }
    $observed = wppilot_snapshot_post($post_id);
    $expected = (string) ($snapshot['fingerprint'] ?? '');
    $actual = is_array($observed) ? (string) ($observed['fingerprint'] ?? '') : '';
    return [
        'post_id' => $post_id,
        'expected_fingerprint' => $expected,
        'observed_fingerprint' => $actual,
        'verified' => $expected !== '' && hash_equals($expected, $actual),
    ];
}

/** @param array<string, mixed> $snapshot @return array<string, mixed> */
function wppilot_restore_settings_snapshot(array $snapshot): array
{
    $values = wppilot_string_keyed_array($snapshot['values'] ?? null);
    // @mago-expect analysis:mixed-assignment -- Option snapshots preserve their original value types.
    foreach ($values as $key => $value) {
        update_option((string) $key, $value);
    }
    $observed = [];
    foreach (array_keys($values) as $key) {
        // @mago-expect analysis:mixed-assignment -- Option values are fingerprinted without interpretation.
        $observed[$key] = get_option((string) $key);
    }
    $expected = (string) ($snapshot['fingerprint'] ?? '');
    $actual = wppilot_snapshot_fingerprint($observed);
    return [
        'settings' => array_keys($values),
        'expected_fingerprint' => $expected,
        'observed_fingerprint' => $actual,
        'verified' => $expected !== '' && hash_equals($expected, $actual),
    ];
}

/** @param array<string, mixed> $snapshot @return array<string, mixed>|WP_Error */
function wppilot_restore_order_status(array $snapshot): array|WP_Error
{
    if (!function_exists('wc_get_order')) {
        return new WP_Error('wppilot_rollback_dependency_missing', __(
            'WooCommerce is required to restore this order status.',
            domain: 'wppilot',
        ));
    }
    $values = wppilot_string_keyed_array($snapshot['values'] ?? null);
    $order = wc_get_order((int) ($values['order_id'] ?? 0));
    if (!$order instanceof WC_Order) {
        return new WP_Error('wppilot_rollback_target_missing', __(
            'The order required for rollback no longer exists.',
            domain: 'wppilot',
        ));
    }
    $status = sanitize_key((string) ($values['status'] ?? ''));
    $order->update_status($status, __('Order status restored by WPPilot rollback.', domain: 'wppilot'), true);
    $observed = ['order_id' => $order->get_id(), 'status' => $order->get_status()];
    $expected = (string) ($snapshot['fingerprint'] ?? '');
    $actual = wppilot_snapshot_fingerprint($observed);
    return [
        'order_id' => $order->get_id(),
        'status' => $order->get_status(),
        'verified' => $expected !== '' && hash_equals($expected, $actual),
    ];
}

/** @param array<string, mixed> $snapshot @return array<string, mixed>|WP_Error */
function wppilot_restore_comment_status(array $snapshot): array|WP_Error
{
    $values = wppilot_string_keyed_array($snapshot['values'] ?? null);
    $comment_id = (int) ($values['comment_id'] ?? 0);
    if (!$comment_id || !get_comment($comment_id)) {
        return new WP_Error('wppilot_rollback_target_missing', __(
            'The comment required for rollback no longer exists.',
            domain: 'wppilot',
        ));
    }
    $status = match ((string) ($values['status'] ?? 'hold')) {
        'approve' => 'approve',
        'spam' => 'spam',
        'trash' => 'trash',
        default => 'hold',
    };
    $updated = wp_set_comment_status($comment_id, $status, wp_error: true);
    if ($updated instanceof WP_Error) {
        return $updated;
    }
    $observed = ['comment_id' => $comment_id, 'status' => wp_get_comment_status($comment_id)];
    $expected = (string) ($snapshot['fingerprint'] ?? '');
    $actual = wppilot_snapshot_fingerprint($observed);
    return [
        'comment_id' => $comment_id,
        'status' => $observed['status'],
        'verified' => $expected !== '' && hash_equals($expected, $actual),
    ];
}

/** @return array<string, mixed>|WP_Error */
function wppilot_rollback_created_comment(int $comment_id): array|WP_Error
{
    if ($comment_id <= 0 || !get_comment($comment_id)) {
        return new WP_Error('wppilot_rollback_target_missing', __(
            'The created comment no longer exists.',
            domain: 'wppilot',
        ));
    }
    if (!wp_delete_comment($comment_id, force_delete: true)) {
        return new WP_Error('wppilot_rollback_delete_failed', __(
            'The created comment could not be deleted.',
            domain: 'wppilot',
        ));
    }
    return ['comment_id' => $comment_id, 'verified' => get_comment($comment_id) === null];
}

/** @param array<string, mixed> $snapshot */
function wppilot_post_snapshot_fingerprint(array $snapshot): string
{
    $post = is_array($snapshot['post'] ?? null) ? $snapshot['post'] : [];
    unset($post['post_modified'], $post['post_modified_gmt'], $post['guid'], $post['filter']);
    return wppilot_snapshot_fingerprint([
        'post' => $post,
        'meta' => $snapshot['meta'] ?? [],
        'terms' => $snapshot['terms'] ?? [],
    ]);
}

function wppilot_snapshot_fingerprint(mixed $value): string
{
    return hash('sha256', (string) wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/** @return array<string, mixed> */
function wppilot_result_summary(mixed $result): array
{
    if (is_array($result)) {
        // @mago-expect analysis:mixed-assignment -- Recursive redaction deliberately preserves safe scalar types.
        $safe = wppilot_redact_for_log($result);
        return [
            'type' => 'array',
            'keys' => array_slice(array: array_map('strval', array_keys($result)), offset: 0, length: 50),
            'summary' => $safe,
        ];
    }
    if (is_object($result)) {
        return ['type' => get_class($result)];
    }
    return [
        'type' => gettype($result),
        'value' => is_scalar($result) ? mb_substr((string) $result, start: 0, length: 200) : null,
    ];
}

/** @return mixed */
function wppilot_redact_for_log(mixed $value, int $depth = 0): mixed
{
    if ($depth > 4) {
        return '[depth-limited]';
    }
    if (!is_array($value)) {
        return is_string($value) && strlen($value) > 500 ? mb_substr($value, start: 0, length: 500) . '...' : $value;
    }
    $result = [];
    foreach (array_slice(array: $value, offset: 0, length: 100, preserve_keys: true) as $key => $item) {
        if (wppilot_change_key_is_sensitive((string) $key)) {
            $result[$key] = '[redacted]';
            continue;
        }
        // @mago-expect analysis:mixed-assignment -- Recursive redaction deliberately preserves safe scalar types.
        $result[$key] = wppilot_redact_for_log($item, $depth + 1);
    }
    if (count($value) > 100) {
        $result['__truncated_items'] = count($value) - 100;
    }
    return $result;
}

function wppilot_change_key_is_sensitive(string $key): bool
{
    return (
        preg_match(
            '/password|passwd|secret|token|authorization|api[_-]?key|private[_-]?key|license|cookie|credential|php[_-]?code|source[_-]?code/',
            strtolower($key),
        ) === 1
    );
}

/** @return array<string, mixed> */
function wppilot_string_keyed_array(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $result = [];
    foreach ($value as $key => $item) {
        if (is_string($key)) {
            $result[$key] = $item;
        }
    }
    return $result;
}

/** @return list<string> */
function wppilot_string_list(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $result = [];
    // @mago-expect analysis:mixed-assignment -- Only string members are retained.
    foreach ($value as $item) {
        if (is_string($item)) {
            $result[] = $item;
        }
    }
    return $result;
}
