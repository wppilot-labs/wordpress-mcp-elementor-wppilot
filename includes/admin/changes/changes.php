<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Admin\Changes;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The Changes screen: every write an agent made, and the way back from each one.
 *
 * The ledger has recorded before-images since 1.0, and code and skills have long told people to
 * "undo it from the Changes screen" — but the only way back was the rollback-change ability, run
 * by the same agent that made the mistake. This is the person's own view: search the ledger,
 * read one row, undo one change, one bulk batch, or everything a filter shows (all of one
 * agent's work today, say), and download what was found.
 */

const PAGE_SLUG = 'wppilot-changes';
const NOTICE_TRANSIENT = 'wppilot_changes_admin_notice_';
const PER_PAGE = 50;

/** Most rows one "undo everything shown" may touch, so one click cannot run for minutes. */
const UNDO_MATCHING_MAX = 200;

/** The query-string keys the screen and its downloads accept as filters. */
const FILTER_KEYS = ['kind', 'ability', 'user_id', 'agent', 'group', 'status', 'since', 'until'];

function current_user_can_manage(): bool
{
    return \wppilot_current_user_can_manage();
}

function register_menu(): void
{
    add_submenu_page(
        parent_slug: 'wppilot-connect',
        page_title: \wppilot_nav_label(PAGE_SLUG, fallback: __('Changes', domain: 'wppilot')),
        menu_title: \wppilot_nav_label(PAGE_SLUG, fallback: __('Changes', domain: 'wppilot')),
        capability: \wppilot_manage_capability(),
        menu_slug: PAGE_SLUG,
        callback: __NAMESPACE__ . '\\render_page',
    );
}

/**
 * @param mixed $map
 * @return mixed
 */
function register_nav(mixed $map): mixed
{
    if (!is_array($map)) {
        return $map;
    }
    $map[PAGE_SLUG] = ['label' => __('Changes', domain: 'wppilot'), 'group' => 'agent'];
    return $map;
}

function register_post_handlers(): void
{
    add_action('admin_post_wppilot_changes_undo', __NAMESPACE__ . '\\handle_undo');
    add_action('admin_post_wppilot_changes_undo_group', __NAMESPACE__ . '\\handle_undo_group');
    add_action('admin_post_wppilot_changes_undo_matching', __NAMESPACE__ . '\\handle_undo_matching');
    add_action('admin_post_wppilot_changes_export', __NAMESPACE__ . '\\handle_export');
}

function render_page(): void
{
    if (!current_user_can_manage()) {
        wp_die(esc_html__('Not allowed.', domain: 'wppilot'), title: '', args: ['response' => 403]);
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing between two views.
    $requested = is_string($_GET['change'] ?? null) ? sanitize_text_field(wp_unslash($_GET['change'])) : '';
    if ($requested !== '') {
        $entry = \wppilot_get_change($requested);
        if ($entry !== null) {
            require __DIR__ . '/templates/detail.php';
            return;
        }
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Filters only narrow a read.
    $filters = read_filters($_GET);
    require __DIR__ . '/templates/list.php';
}

/**
 * Normalise filter input from a request, dropping anything not a filter.
 *
 * @param array<array-key, mixed> $source
 * @return array<string, string|int>
 */
function read_filters(array $source): array
{
    $filters = [];
    foreach (FILTER_KEYS as $key) {
        $raw = $source[$key] ?? null;
        if (!is_string($raw) && !is_int($raw)) {
            continue;
        }
        $value = sanitize_text_field(wp_unslash((string) $raw));
        if ($value === '') {
            continue;
        }
        $filters[$key] = $key === 'user_id' ? max(0, (int) $value) : $value;
    }
    if (isset($filters['kind']) && !in_array($filters['kind'], ['change', 'audit-read'], strict: true)) {
        unset($filters['kind']);
    }
    if (
        isset($filters['status'])
        && !in_array($filters['status'], ['undoable', 'rolled-back', 'not-reversible'], strict: true)
    ) {
        unset($filters['status']);
    }
    return $filters;
}

function handle_undo(): void
{
    require_capability_and_nonce('wppilot_changes_undo');
    $id = posted('change_id');
    $result = \wppilot_rollback_change($id);
    if ($result instanceof WP_Error) {
        redirect_with_notice('error', $result->get_error_message(), ['change' => $id]);
    }
    redirect_with_notice('success', __('The change was undone and the result verified.', domain: 'wppilot'), ['change' => $id]);
}

function handle_undo_group(): void
{
    require_capability_and_nonce('wppilot_changes_undo_group');
    $group = posted('group');
    $result = \wppilot_rollback_group($group);
    if ($result instanceof WP_Error) {
        redirect_with_notice('error', $result->get_error_message(), ['group' => $group]);
    }
    redirect_with_notice(summary_notice_type($result), summary_message($result), ['group' => $group]);
}

function handle_undo_matching(): void
{
    require_capability_and_nonce('wppilot_changes_undo_matching');
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in require_capability_and_nonce().
    $filters = read_filters($_POST);
    $filters['status'] = 'undoable';
    $rows = \wppilot_query_change_log($filters);
    if ($rows === []) {
        redirect_with_notice('info', __('Nothing matching those filters can be undone.', domain: 'wppilot'), $filters);
    }
    if (count($rows) > UNDO_MATCHING_MAX) {
        redirect_with_notice('error', sprintf(
            /* translators: 1: number of matching changes, 2: the most one action may undo. */
            __('%1$d changes match. One action undoes at most %2$d; narrow the filters and try again.', domain: 'wppilot'),
            count($rows),
            UNDO_MATCHING_MAX,
        ), $filters);
    }
    $result = \wppilot_rollback_changes(array_map(static fn(array $row): string => (string) ($row['id'] ?? ''), $rows));
    unset($filters['status']);
    redirect_with_notice(summary_notice_type($result), summary_message($result), $filters);
}

/**
 * Download what the filters show, as CSV or JSON.
 */
function handle_export(): void
{
    require_capability_and_nonce('wppilot_changes_export');
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in require_capability_and_nonce().
    $filters = read_filters($_POST);
    $format = posted('format') === 'json' ? 'json' : 'csv';
    $rows = array_map('wppilot_change_export_row', \wppilot_query_change_log($filters));
    $filename = sprintf('wppilot-changes-%s.%s', gmdate('Ymd-His'), $format);

    nocache_headers();
    header('Content-Type: ' . ($format === 'json' ? 'application/json' : 'text/csv') . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    if ($format === 'json') {
        echo (string) wp_json_encode(
            ['site' => home_url('/'), 'exported_at' => gmdate('c'), 'filters' => $filters, 'changes' => $rows],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        exit();
    }

    echo csv_document($rows); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A CSV download, escaped by csv_cell().
    exit();
}

/**
 * @param list<array<string, mixed>> $rows
 */
function csv_document(array $rows): string
{
    $columns = [
        'id', 'recorded_at', 'kind', 'ability', 'risk', 'user_id', 'user_login', 'agent_method',
        'agent_label', 'agent_client', 'group', 'status', 'rollback_reason', 'rolled_back_at', 'confirmation', 'input',
    ];
    $lines = [implode(',', array_map(__NAMESPACE__ . '\\csv_cell', $columns))];
    foreach ($rows as $row) {
        $cells = [];
        foreach ($columns as $column) {
            $value = $row[$column] ?? '';
            $cells[] = csv_cell(is_array($value) ? (string) wp_json_encode($value) : (string) $value);
        }
        $lines[] = implode(',', $cells);
    }
    // A byte-order mark so Excel reads the file as UTF-8 rather than the system code page.
    return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
}

/**
 * One CSV cell, quoted, and defused against formula injection.
 *
 * Ability input is agent-written text. A value beginning with =, +, -, @, tab or carriage return
 * is run as a formula by Excel, LibreOffice and Sheets when the file is opened, which turns an
 * export into a way for whatever wrote the input to reach the person reading the report. Such a
 * value is prefixed with an apostrophe, which every one of them treats as "this is text".
 */
function csv_cell(string $value): string
{
    if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
        $value = "'" . $value;
    }
    return '"' . str_replace('"', '""', $value) . '"';
}

/**
 * @param array{rolled_back: int, failed: int, skipped: int} $result
 */
function summary_message(array $result): string
{
    return sprintf(
        /* translators: 1: changes undone, 2: changes that failed, 3: changes skipped */
        __('Undone and verified: %1$d. Failed: %2$d. Skipped (already undone or not reversible): %3$d.', domain: 'wppilot'),
        $result['rolled_back'],
        $result['failed'],
        $result['skipped'],
    );
}

/**
 * @param array{rolled_back: int, failed: int, skipped: int} $result
 */
function summary_notice_type(array $result): string
{
    if ($result['failed'] > 0) {
        return $result['rolled_back'] > 0 ? 'warning' : 'error';
    }
    return $result['rolled_back'] > 0 ? 'success' : 'info';
}

function posted(string $key): string
{
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Every caller checks the nonce first.
    $raw = $_POST[$key] ?? '';
    return is_string($raw) ? sanitize_text_field(wp_unslash($raw)) : '';
}

function require_capability_and_nonce(string $nonce_action): void
{
    if (!current_user_can_manage()) {
        wp_die(esc_html__('Not allowed.', domain: 'wppilot'), title: '', args: ['response' => 403]);
    }
    check_admin_referer($nonce_action);
}

/**
 * Store a notice for the next page load and redirect there. Never returns.
 *
 * @param array<string, string|int> $args
 */
function redirect_with_notice(string $type, string $message, array $args = []): void
{
    set_transient(
        NOTICE_TRANSIENT . get_current_user_id(),
        ['type' => $type, 'message' => $message],
        expiration: 30,
    );
    wp_safe_redirect(add_query_arg(array_merge(['page' => PAGE_SLUG], $args), admin_url('admin.php')));
    exit();
}

function render_notice(): void
{
    if (!current_user_can_manage()) {
        return;
    }
    /** @var mixed $notice */
    $notice = get_transient(NOTICE_TRANSIENT . get_current_user_id());
    if (!is_array($notice)) {
        return;
    }
    delete_transient(NOTICE_TRANSIENT . get_current_user_id());

    wp_admin_notice((string) ($notice['message'] ?? ''), [
        'type' => (string) ($notice['type'] ?? 'info'),
        'dismissible' => true,
    ]);
}

function enqueue_assets(string $hook): void
{
    if ($hook !== 'wppilot_page_' . PAGE_SLUG) {
        return;
    }
    wp_enqueue_style(
        'wppilot-changes-admin',
        (string) WPPILOT_PLUGIN_URL . 'includes/admin/changes/assets/changes.css',
        ['wppilot-admin'],
        WPPILOT_VERSION,
    );
}

/**
 * The URL of the list with some filters applied.
 *
 * @param array<string, string|int> $filters
 */
function list_url(array $filters = [], int $paged = 1): string
{
    $args = array_merge(['page' => PAGE_SLUG], $filters);
    if ($paged > 1) {
        $args['paged'] = $paged;
    }
    return add_query_arg(array_map('rawurlencode', array_map('strval', $args)), admin_url('admin.php'));
}

function detail_url(string $id): string
{
    return add_query_arg(['page' => PAGE_SLUG, 'change' => rawurlencode($id)], admin_url('admin.php'));
}

/**
 * Who did it, for one line of the table.
 *
 * @param array<string, mixed> $entry
 */
function actor_label(array $entry): string
{
    $agent = is_array($entry['agent'] ?? null) ? $entry['agent'] : [];
    $user = is_array($entry['user'] ?? null) ? $entry['user'] : [];
    $who = trim((string) ($agent['label'] ?? ''));
    if ($who === '') {
        $who = trim((string) ($agent['client'] ?? ''));
    }
    $login = (string) ($user['login'] ?? '');
    if ($who === '') {
        return $login !== '' ? $login : __('Unknown', domain: 'wppilot');
    }
    return $login !== '' ? sprintf('%s (%s)', $who, $login) : $who;
}

/**
 * Hidden fields that carry the current filters into a POST form.
 *
 * @param array<string, string|int> $filters
 */
function render_filter_fields(array $filters): void
{
    foreach ($filters as $key => $value) {
        printf('<input type="hidden" name="%s" value="%s">', esc_attr($key), esc_attr((string) $value));
    }
}

function status_label(string $status): string
{
    return match ($status) {
        'undoable' => __('Can be undone', domain: 'wppilot'),
        'rolled-back' => __('Undone', domain: 'wppilot'),
        default => __('Not reversible', domain: 'wppilot'),
    };
}

add_action('admin_menu', __NAMESPACE__ . '\\register_menu', priority: 36);
add_action('admin_init', __NAMESPACE__ . '\\register_post_handlers');
add_action('admin_notices', __NAMESPACE__ . '\\render_notice');
add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets');
add_filter('wppilot_nav_map', __NAMESPACE__ . '\\register_nav');
