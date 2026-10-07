<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Backup summary for the Cloud dashboard, §4.
 *
 * Read through the backup-status kit's own ability rather than its functions:
 * the kit loads only when a backup plugin is active, and an ability call keeps
 * the kit boundary intact. The answer covers what the Cloud needs to show
 * "last backup" per site and to wait for a backup it asked for: which backup
 * plugins are active, the newest successful backup, and whether one is running.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * @return array{
 *     providers: list<array{provider: string, label: string, readable: bool}>,
 *     newest: array{provider: string, timestamp: int}|null,
 *     running: bool
 * }|null Null when no supported backup plugin is active (the kit is not loaded).
 */
function wppilot_cloud_backup_summary(): ?array
{
    if (!function_exists('wp_get_ability')) {
        return null;
    }
    $ability = wp_get_ability('wppilot/backup-status');
    if ($ability === null) {
        return null;
    }

    $status = $ability->execute([]);
    if (!is_array($status) || !is_array($status['providers'] ?? null)) {
        return null;
    }

    $providers = [];
    $running = false;
    foreach ($status['providers'] as $provider) {
        if (!is_array($provider) || !is_string($provider['provider'] ?? null)) {
            continue;
        }
        $providers[] = [
            'provider' => $provider['provider'],
            'label' => is_string($provider['label'] ?? null) ? $provider['label'] : $provider['provider'],
            'readable' => ($provider['readable'] ?? false) === true,
        ];
        if (!empty($provider['running'])) {
            $running = true;
        }
    }

    $newest = $status['newest_successful_backup'] ?? null;

    return [
        'providers' => $providers,
        'newest' => is_array($newest) && is_string($newest['provider'] ?? null) && is_numeric($newest['timestamp'] ?? null)
            ? ['provider' => $newest['provider'], 'timestamp' => (int) $newest['timestamp']]
            : null,
        'running' => $running,
    ];
}
