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
    return null;
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
    return ['reversible' => false, 'reason' => 'No rollback strategy is registered for this change.'];
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
