<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\Runtime\Hosts;

use WP_Error;
use WPPilot\Kits\Runtime\Host;
use WPPilot\Kits\Runtime\Jobs;
use WPPilot\Kits\Runtime\Ledger;
use WPPilot\Kits\Runtime\ProfileGate;
use WPPilot\Kits\Runtime\Runner;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The kit host inside WPPilot: its switch, capability, safety profile and change ledger.
 *
 * This is the one file in includes/kits allowed to name a wppilot_* function
 * (scripts/check-kit-boundaries.php enforces it). The exporter does not ship it: a kit carried
 * into another plugin runs on hosts/standalone.php instead.
 */
final class WPPilotHost implements Host, ProfileGate
{
    private ?WPPilotLedger $ledger = null;

    private ?Runner $jobs = null;

    public function id(): string
    {
        return 'wppilot';
    }

    public function can_manage(): bool
    {
        return \wppilot_permission_callback() === true;
    }

    public function is_enabled(): bool
    {
        return \wppilot_is_enabled();
    }

    public function safety_profile(): string
    {
        return \wppilot_get_safety_profile();
    }

    public function ledger(): Ledger
    {
        return $this->ledger ??= new WPPilotLedger();
    }

    public function jobs(): Jobs
    {
        return $this->jobs ??= new Runner();
    }

    /**
     * Pro, and anything else, offers extension points through this filter:
     * `add_filter('wppilot_kit_extension', fn($ext, $point) => $point === 'seo-providers' ? $registry : $ext, 10, 2)`.
     */
    public function extension(string $point): mixed
    {
        return apply_filters('wppilot_kit_extension', null, $point);
    }

    public function admin_parent_slug(): string
    {
        return 'wppilot-connect';
    }

    /**
     * WPPilot's own profile rule, so a kit ability answers exactly as the Abilities Hub and the
     * MCP transports do: min_profile first, then the risk class the profile allows.
     */
    public function profile_allows(string $ability_name): bool|WP_Error
    {
        $ability = wp_get_ability($ability_name);
        if (!$ability instanceof \WP_Ability) {
            return new WP_Error(
                'kit_ability_unknown',
                sprintf('Ability "%s" is not registered, so its safety policy cannot be checked.', $ability_name),
                ['status' => 403],
            );
        }
        return \wppilot_safety_check_ability($ability);
    }

    /**
     * wppilot_gate_ability_call() demanded and stripped `confirm` before execute() ran.
     */
    public function confirm_guard(string $ability_name, array $input): bool|WP_Error
    {
        return true;
    }
}

final class WPPilotLedger implements Ledger
{
    /** @var array<string, callable> */
    private array $captures = [];

    public function capture_for(string $ability_name, callable $capture): void
    {
        if ($this->captures === []) {
            add_filter('wppilot_capture_before_image', [$this, 'supply_before_image'], 10, 3);
        }
        $this->captures[$ability_name] = $capture;
    }

    /**
     * @param mixed $before
     * @param mixed $input
     * @return mixed
     */
    public function supply_before_image(mixed $before, string $ability_name, mixed $input): mixed
    {
        if ($before !== null || !isset($this->captures[$ability_name])) {
            return $before;
        }
        return ($this->captures[$ability_name])(is_array($input) ? $input : []);
    }

    public function record_items(string $ability_name, array $items, ?string $group = null): array
    {
        return \wppilot_ledger_record_items($ability_name, $items, $group);
    }

    public function register_strategy(string $type, callable $restore, ?callable $build = null): bool
    {
        return \wppilot_register_rollback_strategy($type, $restore, $build);
    }

    public function query(array $filters = []): array
    {
        return \wppilot_query_change_log($filters);
    }

    public function export_row(array $entry): array
    {
        return \wppilot_change_export_row($entry);
    }

    public function snapshot_budget(): int
    {
        return WPPILOT_CHANGE_BULK_SNAPSHOT_BUDGET_BYTES;
    }

    public function download_url(): string
    {
        return admin_url('admin.php?page=wppilot-changes');
    }
}
