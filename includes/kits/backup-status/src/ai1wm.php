<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BackupStatus;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * All-in-One WP Migration ("All-in-One WP Migration and Backup", verified against 7.112).
 *
 * It keeps no backup history: a backup is a `.wpress` file in its backups folder
 * (AI1WM_BACKUPS_PATH, wp-content/ai1wm-backups unless the `ai1wm_backups_path` option moves
 * it), listed by `Ai1wm_Backups::get_files()` with its file time and size. An export is built in
 * a job folder under AI1WM_STORAGE_PATH and renamed into the backups folder only once its last
 * block is written (Ai1wm_Export_Download), so a file there is a finished export and its file
 * time is when that export finished. Failed exports leave nothing there; the plugin records no
 * verdict for them that this reads.
 *
 * Anything that can write a file can also drop a `.wpress` there, and a backup that counts here
 * lets safe updates and the fresh-backup hold through. So a file counts as a backup only when it
 * sits in the folder itself (exports are never renamed into a subfolder), is at least
 * AI1WM_MIN_BYTES, is not dated in the future, and ends in the archive's end-of-file block as
 * checked by the plugin's own Ai1wm_Extractor::is_valid(), the test its import and restore run.
 * Anything else is listed as `kind: "unverified"`, `result: "unknown"`, and never counts.
 *
 * The file name is never returned. It carries a random suffix that is the only thing guarding a
 * backup on a server that ignores the folder's .htaccess (nginx, OpenLiteSpeed), so it is as good
 * as a download link; a backup's id is a hash of it instead.
 *
 * A running export or import is a job folder (named by uniqid(), 13 hex characters) whose files
 * changed in the last AI1WM_ACTIVE_SECONDS: the archive grows with every step. A folder left by an
 * abandoned run stops changing and stops counting; the plugin's daily cron deletes it after a day.
 *
 * The free plugin has no schedule. Since 7.106 it has a REST API whose POST /ai1wm/v1/exports
 * starts a full export on the server, continued by loopback requests; `trigger.supported` says
 * whether that route's controller is loaded, for WPPilot Pro's wppilot/backup-trigger to use. No
 * version is compared: whether the install can be started is read from what it has.
 */

const AI1WM_BACKUPS = 'Ai1wm_Backups';

const AI1WM_REST_CONTROLLER = 'Ai1wm_Rest_Controller';

const AI1WM_EXPORT_ROUTE = '/ai1wm/v1/exports';

/** Smallest file counted as a backup: a finished export holds at least the database and its package.json. */
const AI1WM_MIN_BYTES = 1048576;

/** How far a file time may run ahead of the clock (a skewed NFS mount) before it is not believed. */
const AI1WM_CLOCK_SKEW = 300;

/** How recently a job folder must have changed to count as a run in progress. */
const AI1WM_ACTIVE_SECONDS = 900;

const AI1WM_JOB_FOLDER = '/^[a-f0-9]{13,40}$/';

function ai1wm_active(): bool
{
    return defined('AI1WM_PLUGIN_NAME')
        && class_exists(AI1WM_BACKUPS)
        && method_exists(AI1WM_BACKUPS, 'get_files');
}

function ai1wm_version(): ?string
{
    return defined('AI1WM_VERSION') ? (string) constant('AI1WM_VERSION') : null;
}

/**
 * A backup id that names the file without giving its name away.
 */
function ai1wm_id(string $filename): string
{
    return substr(hash('sha256', $filename), 0, 16);
}

/**
 * Whether a listed file is a finished export this can vouch for (see the header).
 *
 * @param array<string, mixed> $file A row of Ai1wm_Backups::get_files().
 */
function ai1wm_verified(array $file): bool
{
    $name = (string) $file['filename'];
    if (($file['path'] ?? '') !== '' || basename($name) !== $name || (int) $file['mtime'] > time() + AI1WM_CLOCK_SKEW) {
        return false;
    }
    // A null size is a file too large for this PHP to measure, never a small one.
    $size = bytes($file['size'] ?? null);
    if ($size !== null && $size < AI1WM_MIN_BYTES) {
        return false;
    }
    $folder = defined('AI1WM_BACKUPS_PATH') ? (string) constant('AI1WM_BACKUPS_PATH') : '';
    if ($folder === '' || !class_exists('Ai1wm_Extractor') || !method_exists('Ai1wm_Extractor', 'is_valid')) {
        return false;
    }
    try {
        $archive = new \Ai1wm_Extractor($folder . DIRECTORY_SEPARATOR . $name);
        $valid = $archive->is_valid() === true;
        if (method_exists($archive, 'close')) {
            $archive->close();
        }

        return $valid;
    } catch (\Throwable $error) {
        return false;
    }
}

/**
 * @return list<array<string, mixed>> Newest first.
 */
function ai1wm_records(): array
{
    /** @var mixed $files */
    $files = call_user_func([AI1WM_BACKUPS, 'get_files']);
    /** @var mixed $labels */
    $labels = method_exists(AI1WM_BACKUPS, 'get_labels') ? call_user_func([AI1WM_BACKUPS, 'get_labels']) : [];
    $labels = is_array($labels) ? $labels : [];

    $records = [];
    foreach (is_array($files) ? $files : [] as $file) {
        // A file the plugin could not stat has no time; it cannot be dated, so it is not listed.
        if (!is_array($file) || !is_string($file['filename'] ?? null) || !is_numeric($file['mtime'] ?? null)) {
            continue;
        }
        $label = $labels[$file['filename']] ?? null;
        $verified = ai1wm_verified($file);
        $records[] = record('ai1wm', [
            'id' => ai1wm_id($file['filename']),
            'timestamp' => (int) $file['mtime'],
            'result' => $verified ? 'success' : 'unknown',
            'size_bytes' => bytes($file['size'] ?? null),
            'storage' => ['Local (web server)'],
            'label' => is_string($label) ? mb_substr($label, 0, 255) : null,
            'kind' => $verified ? 'backup' : 'unverified',
        ]);
    }

    return newest_first($records, PHP_INT_MAX);
}

/**
 * @return list<array<string, mixed>>
 */
function ai1wm_list(int $limit): array
{
    return newest_first(ai1wm_records(), $limit);
}

/**
 * @return array{running: bool, jobs: list<array<string, mixed>>}
 */
function ai1wm_running(): array
{
    $jobs = [];
    $storage = defined('AI1WM_STORAGE_PATH') ? (string) constant('AI1WM_STORAGE_PATH') : '';
    $entries = $storage !== '' && is_dir($storage) ? scandir($storage) : false;
    foreach (is_array($entries) ? $entries : [] as $entry) {
        if (!is_string($entry) || preg_match(AI1WM_JOB_FOLDER, $entry) !== 1 || !is_dir($storage . '/' . $entry)) {
            continue;
        }
        $changed = 0;
        foreach (glob($storage . '/' . $entry . '/*') ?: [] as $file) {
            $changed = max($changed, (int) @filemtime($file));
        }
        if ($changed > 0 && time() - $changed <= AI1WM_ACTIVE_SECONDS) {
            $jobs[] = ['state' => 'running', 'what' => 'export or import', 'last_activity' => times($changed)];
        }
    }

    return ['running' => $jobs !== [], 'jobs' => $jobs];
}

/**
 * Whether WPPilot Pro's wppilot/backup-trigger can start an export here.
 *
 * @return array{supported: bool, reason?: string, scopes?: list<string>, how?: string}
 */
function ai1wm_trigger_support(): array
{
    // A capability check, not a version check: the runner calls the plugin's REST export
    // controller, so the answer is whether that controller is loaded here.
    if (!class_exists(AI1WM_REST_CONTROLLER) || !method_exists(AI1WM_REST_CONTROLLER, 'create_export')) {
        return [
            'supported' => false,
            'reason' => "this All-in-One WP Migration install can't be started by this plugin; make the backup from its Export screen in wp-admin.",
        ];
    }

    return [
        'supported' => true,
        'scopes' => [],
        'how' => 'All-in-One WP Migration\'s REST API (POST /ai1wm/v1/exports) starts a full export to its backups folder (database, media, plugins and themes), continued by loopback requests to admin-ajax.php.',
    ];
}

/**
 * @return array<string, mixed>
 */
function ai1wm_status(): array
{
    $records = ai1wm_records();
    $verified = array_values(array_filter($records, static fn(array $record): bool => $record['kind'] === 'backup'));

    return [
        'version' => ai1wm_version(),
        'last_backup' => $records[0] ?? null,
        'last_successful_backup' => $verified[0] ?? null,
        'backups' => count($verified),
        'unverified_files' => count($records) - count($verified),
        'running' => ai1wm_running(),
        'next_scheduled' => [],
        'schedule' => null,
        'storage' => ['Local (web server)'],
        'trigger' => ai1wm_trigger_support(),
        'notes' => [
            'All-in-One WP Migration (free) has no backup schedule; scheduled backups are a paid extension.',
            'Only finished exports are kept: a failed export leaves no file and is not listed. A backup\'s time is when its export finished.',
            'A .wpress file counts only when it is in the backups folder itself, at least 1 MB, not dated in the future and ends in a complete archive block; any other is listed as kind "unverified" and is not a backup.',
        ],
    ];
}
