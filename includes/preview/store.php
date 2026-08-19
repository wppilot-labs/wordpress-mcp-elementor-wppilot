<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Preview\Store;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Storage for pending previews.
 *
 * One autoloaded-off option per preview plus a capped index, rather than the
 * custom post type the Gutenberg queue uses. That queue is a CPT because its
 * items are long-lived, human-visible work-queue entries needing post_parent
 * batching; a preview is one short-lived record with no children. A CPT would
 * have cost a register_post_type block, a retention cron, an entry in
 * wppilot_deactivate_current_site(), and another in wppilot_uninstall_post_types()
 * — for storage that a bounded option already provides. The bound-by-count-then-
 * by-bytes discipline is the one wppilot_store_change() already uses.
 *
 * Transients were rejected for the same feature: with a persistent object cache
 * a transient can be evicted before it expires, and a human review window is
 * measured in hours. Expiry is enforced on read instead.
 */

const OPTION_PREFIX = 'wppilot_preview_';
const LOCK_PREFIX = 'wppilot_preview_lock_';
const INDEX_OPTION = 'wppilot_preview_index';

/** How long a preview stays applicable. An agent proposes; a person reviews later. */
const TTL_SECONDS = 86_400;

/** Hard ceiling on live previews. Oldest are pruned first. */
const MAX_LIVE = 50;

/** A single stored record. Beyond this the diff was already truncated. */
const MAX_RECORD_BYTES = 262_144;

const STATUS_PENDING = 'pending';
const STATUS_APPLYING = 'applying';
const STATUS_APPLIED = 'applied';
const STATUS_DISCARDED = 'discarded';
const STATUS_CONFLICTED = 'conflicted';
const STATUS_FAILED = 'failed';
const STATUS_EXPIRED = 'expired';

function option_name(string $id): string
{
    return OPTION_PREFIX . $id;
}

/**
 * The id index, newest first.
 *
 * @return list<string>
 */
function index(): array
{
    /** @var mixed $raw */
    $raw = get_option(INDEX_OPTION, default_value: []);
    if (!is_array($raw)) {
        return [];
    }
    $ids = [];
    foreach ($raw as $id) {
        if (is_string($id) && $id !== '') {
            $ids[] = $id;
        }
    }
    return $ids;
}

/** @param list<string> $ids */
function write_index(array $ids): void
{
    update_option(INDEX_OPTION, array_values($ids), autoload: false);
}

/**
 * Store a new preview and return its id.
 *
 * @param array<string, mixed> $record
 */
function create(array $record): string
{
    $id = wp_generate_uuid4();
    $record['preview_id'] = $id;
    $record['status'] = STATUS_PENDING;

    $encoded = wp_json_encode($record);
    if (is_string($encoded) && strlen($encoded) > MAX_RECORD_BYTES) {
        // The diff is the only unbounded part, and it is already capped. If a
        // record still exceeds the budget, drop the entries rather than refuse
        // the preview: the caller keeps the counts and the fingerprints.
        $record['diff']['entries'] = [];
        $record['diff']['truncated'] = true;
        $record['diff']['dropped_for_size'] = true;
    }

    update_option(option_name($id), $record, autoload: false);
    write_index([$id, ...index()]);
    prune();

    return $id;
}

/**
 * Read a preview, marking it expired once its window has passed.
 *
 * @return array<string, mixed>|null
 */
function get(string $id): ?array
{
    if (!is_valid_id($id)) {
        return null;
    }
    /** @var mixed $record */
    $record = get_option(option_name($id), default_value: null);
    if (!is_array($record)) {
        return null;
    }

    if (($record['status'] ?? '') === STATUS_PENDING && is_expired($record)) {
        $record['status'] = STATUS_EXPIRED;
        update_option(option_name($id), $record, autoload: false);
    }

    return $record;
}

/** @param array<string, mixed> $record */
function is_expired(array $record): bool
{
    $expires = (string) ($record['expires_at'] ?? '');
    if ($expires === '') {
        return false;
    }
    return strtotime($expires) < time();
}

/**
 * Every live preview, newest first.
 *
 * @return list<array<string, mixed>>
 */
function all(): array
{
    $records = [];
    foreach (index() as $id) {
        $record = get($id);
        if ($record !== null) {
            $records[] = $record;
        }
    }
    return $records;
}

/**
 * Merge fields into a stored preview.
 *
 * @param array<string, mixed> $changes
 */
function update(string $id, array $changes): bool
{
    $record = get($id);
    if ($record === null) {
        return false;
    }
    update_option(option_name($id), [...$record, ...$changes], autoload: false);
    return true;
}

function delete(string $id): void
{
    if (!is_valid_id($id)) {
        return;
    }
    delete_option(option_name($id));
    write_index(array_values(array_filter(index(), static fn(string $known): bool => $known !== $id)));
}

/**
 * Drop expired and over-cap records.
 *
 * Runs on every create rather than on a cron, so the bound holds without another
 * scheduled hook to register, unschedule on deactivation, and reason about.
 */
function prune(): void
{
    $kept = [];
    foreach (index() as $id) {
        /** @var mixed $record */
        $record = get_option(option_name($id), default_value: null);
        if (!is_array($record)) {
            continue;
        }
        if (is_expired($record) && ($record['status'] ?? '') !== STATUS_APPLIED) {
            delete_option(option_name($id));
            continue;
        }
        $kept[] = $id;
    }

    if (count($kept) > MAX_LIVE) {
        foreach (array_slice($kept, offset: MAX_LIVE) as $id) {
            delete_option(option_name($id));
        }
        $kept = array_slice($kept, offset: 0, length: MAX_LIVE);
    }

    write_index($kept);
}

/**
 * Claim a preview for application.
 *
 * add_option() returns false when the row already exists, which makes it a
 * compare-and-set without a transaction — the idiom the Gutenberg queue already
 * relies on in status_transition_lock(). Two administrators clicking Apply on
 * the same preview cannot both proceed.
 */
function claim_lock(string $id): bool
{
    if (!is_valid_id($id)) {
        return false;
    }
    return add_option(LOCK_PREFIX . $id, (string) time(), deprecated: '', autoload: 'no');
}

function release_lock(string $id): void
{
    if (is_valid_id($id)) {
        delete_option(LOCK_PREFIX . $id);
    }
}

/**
 * Reject anything that is not a UUID before it reaches an option name.
 */
function is_valid_id(string $id): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1;
}
