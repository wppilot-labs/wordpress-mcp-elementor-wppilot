<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Prove an exported copy of the kits can run on the same site as WPPilot without either one
 * touching the other.
 *
 *   php scripts/test-kit-coexistence.php [--pro-src=../wppilot-pro]
 *
 * The failure this exists for is quiet. Two plugins carrying the same kit under names that did
 * not get rewritten do not fatal — unless a symbol clashes — they share: one option holds both
 * ledgers, one cron hook runs both job queues, the second registration of an ability or category
 * is refused with a notice, and every one of those looks like the other plugin's bug.
 *
 * It exports every Free kit (and Pro's, with --pro-src) as prefix `kitprobe`, namespace
 * `KitProbe\Kits`, into a temp directory. Then, in one child PHP process on recording WordPress
 * doubles (scripts/lib/coexistence-doubles.php, over tests/doubles/wordpress.php), it boots
 * WPPilot's side exactly as the plugin does — includes/kits/loader.php on its real WPPilot host,
 * with the few wppilot_* functions that host calls stubbed — and requires the export's load.php
 * beside it, fires plugins_loaded and the Abilities API init actions, runs every read-only
 * ability each copy registered, writes a ledger row and a background job through each copy's
 * runtime, and ticks each copy's cron hook.
 *
 * It fails when the process fatals (a redeclared symbol), when WordPress would have refused or
 * complained about a registration, when ticking one copy's cron runs the other copy's job, or
 * when both copies name the same ability, ability category, option, transient, cron event, REST
 * route, post type, script/style handle, nonce action or admin page — recorded at run time and
 * read statically from each tree's source. Hooks are compared too, but only the ones a kit owns:
 * one it fires itself or one named after a kit. Both copies listening to plugins_loaded is the
 * point, not a collision.
 *
 * Development-only: package.sh excludes scripts/.
 */

namespace WPPilot\Scripts\Kits\Coexistence;

use function WPPilot\Scripts\Kits\argument;
use function WPPilot\Scripts\Kits\call_args;
use function WPPilot\Scripts\Kits\called_function;
use function WPPilot\Scripts\Kits\files_under;
use function WPPilot\Scripts\Kits\kit_dirs;
use function WPPilot\Scripts\Kits\next_significant;
use function WPPilot\Scripts\Kits\static_string;
use function WPPilot\Scripts\Kits\string_constants;
use function WPPilot\Scripts\Kits\tokens;

require_once __DIR__ . '/lib/kit-tools.php';

const PREFIX = 'kitprobe';
const NS = 'KitProbe\\Kits';

/** Call => [kind, argument position, argument name]. */
const NAMING_CALLS = [
    'get_option' => ['option', 0, 'option'], 'add_option' => ['option', 0, 'option'],
    'update_option' => ['option', 0, 'option'], 'delete_option' => ['option', 0, 'option'],
    'get_site_option' => ['option', 0, 'option'], 'update_site_option' => ['option', 0, 'option'],
    'get_transient' => ['transient', 0, 'transient'], 'set_transient' => ['transient', 0, 'transient'],
    'delete_transient' => ['transient', 0, 'transient'], 'get_site_transient' => ['transient', 0, 'transient'],
    'set_site_transient' => ['transient', 0, 'transient'],
    'add_action' => ['hook', 0, 'hook_name'], 'add_filter' => ['hook', 0, 'hook_name'],
    'do_action' => ['fired', 0, 'hook_name'], 'apply_filters' => ['fired', 0, 'hook_name'],
    'wp_next_scheduled' => ['cron', 0, 'hook'], 'wp_clear_scheduled_hook' => ['cron', 0, 'hook'],
    'wp_schedule_single_event' => ['cron', 1, 'hook'], 'wp_schedule_event' => ['cron', 2, 'hook'],
    'register_rest_route' => ['rest_namespace', 0, 'route_namespace'],
    'register_post_type' => ['post_type', 0, 'post_type'],
    'wp_register_script' => ['handle', 0, 'handle'], 'wp_enqueue_script' => ['handle', 0, 'handle'],
    'wp_register_style' => ['handle', 0, 'handle'], 'wp_enqueue_style' => ['handle', 0, 'handle'],
    'wp_create_nonce' => ['nonce', 0, 'action'], 'wp_verify_nonce' => ['nonce', 1, 'action'],
    'check_ajax_referer' => ['nonce', 0, 'action'], 'check_admin_referer' => ['nonce', 0, 'action'],
    'add_menu_page' => ['admin_page', 3, 'menu_slug'], 'add_submenu_page' => ['admin_page', 4, 'menu_slug'],
    'wp_register_ability' => ['ability', 0, 'name'], 'wp_register_ability_category' => ['category', 0, 'slug'],
];

/**
 * Names each kind of shared resource a tree's PHP source spells out, statically.
 *
 * @param list<string> $files
 * @return array<string, array<string, true>>
 */
function static_names(array $files): array
{
    $names = [];
    foreach ($files as $file) {
        if (basename($file) === 'kit.json') {
            // A category a kit declares is registered only when the host has not already, so at
            // run time the second copy just skips it — and WPPilot's own unconditional
            // registration of the same slug is what then fails. Compare the declarations.
            $manifest = json_decode((string) file_get_contents($file), true);
            foreach (array_keys(is_array($manifest['categories'] ?? null) ? $manifest['categories'] : []) as $slug) {
                $names['category'][(string) $slug] = true;
            }
            continue;
        }
        if (!str_ends_with($file, '.php')) {
            continue;
        }
        $tokens = tokens((string) file_get_contents($file));
        $constants = string_constants($tokens);
        foreach ($constants as $name => $value) {
            // Storage keys the runtime completes at run time (OPTION_PREFIX . $id) are compared
            // as prefixes below.
            // WordPress's own keys (_wp_attachment_image_alt, _wp_attached_file) are shared by
            // every plugin that edits media; both copies writing them is the point, not a clash.
            if (str_starts_with($value, '_wp_')) {
                continue;
            }
            if (preg_match('/OPTION|TRANSIENT|META|KEY/', $name) === 1) {
                $names['option'][$value] = true;
            } elseif (preg_match('/HOOK|EVENT/', $name) === 1) {
                $names['cron'][$value] = true;
            }
        }
        foreach ($tokens as $i => $token) {
            $called = called_function($tokens, $i);
            if ($called === null || !isset(NAMING_CALLS[$called])) {
                continue;
            }
            [$kind, $position, $parameter] = NAMING_CALLS[$called];
            $value = static_string($tokens, argument($tokens, call_args($tokens, next_significant($tokens, $i)), $position, $parameter), $constants);
            if ($value !== null) {
                $names[$kind][$value] = true;
            }
        }
    }
    return $names;
}

function run_export(string $free, string $pro, string $out): void
{
    $kits = array_keys(kit_dirs($free . '/includes/kits'));
    if ($pro !== '') {
        $kits = array_merge($kits, array_keys(kit_dirs($pro . '/includes/kits')));
    }
    if ($kits === []) {
        throw new \RuntimeException('there are no kits to export');
    }
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/export-kit.php')
        . ' --kits=' . escapeshellarg(implode(',', $kits))
        . ($pro !== '' ? ' --pro-src=' . escapeshellarg($pro) : '')
        . ' --namespace=' . escapeshellarg(NS) . ' --prefix=' . PREFIX . ' --text-domain=' . PREFIX
        . ' --out=' . escapeshellarg($out) . ' 2>&1';
    exec($command, $output, $code);
    if ($code !== 0) {
        throw new \RuntimeException("export-kit.php failed:\n" . implode("\n", $output));
    }
}

function remove_tree(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (is_dir($path) ? (array) scandir($path) : [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            remove_tree($path . '/' . $entry);
        }
    }
    if (is_dir($path)) {
        rmdir($path);
    }
}

/**
 * The child process: both copies, one PHP runtime.
 *
 * @return list<string> Problems.
 */
function load_both(string $free, string $pro, string $export): array
{
    define('ABSPATH', $free . '/');
    define('WPPILOT_CHANGE_BULK_SNAPSHOT_BUDGET_BYTES', 1_048_576);
    require_once __DIR__ . '/lib/coexistence-doubles.php';
    require_once $free . '/tests/doubles/wordpress.php';

    // What WPPilotHost calls; the rest of WPPilot is not loaded here.
    $stubs = [
        'wppilot_permission_callback' => static fn(): bool => true,
        'wppilot_get_safety_profile' => static fn(): string => 'production',
        'wppilot_register_rollback_strategy' => static fn(): bool => true,
        'wppilot_ledger_record_items' => static fn(string $ability, array $items, ?string $group = null): array => ['group' => (string) $group, 'change_ids' => [], 'without_before_image' => 0],
        'wppilot_query_change_log' => static fn(array $filters = [], int $limit = 0, int $offset = 0): array => [],
        'wppilot_count_change_log' => static fn(array $filters = []): int => 0,
        'wppilot_change_export_row' => static fn(array $entry): array => $entry,
    ];
    $GLOBALS['kit_coexistence_stubs'] = $stubs;
    foreach (array_keys($stubs) as $function) {
        if (!function_exists($function)) {
            eval("function {$function}(...\$args) { return (\$GLOBALS['kit_coexistence_stubs']['{$function}'])(...\$args); }");
        }
    }
    foreach (['esc_html__', 'esc_attr__', '_x', 'esc_html_x', 'esc_attr_x'] as $function) {
        if (!function_exists($function)) {
            eval("function {$function}(string \$text, ...\$rest): string { return \$text; }");
        }
    }

    // A kit that needs a vendor plugin (woo-reports: WooCommerce) would be skipped here, and a
    // skipped kit proves nothing about clashes. Its required classes and functions get empty
    // stand-ins so it loads and registers; its abilities are not executed below, because the
    // stand-ins have nothing behind them.
    $vendor_bound = [];
    foreach (array_merge(glob($free . '/includes/kits/*/kit.json') ?: [], $pro !== '' ? (glob($pro . '/includes/kits/*/kit.json') ?: []) : []) as $kit_json) {
        $kit = json_decode((string) file_get_contents($kit_json), true);
        $requires = is_array($kit['requires'] ?? null) ? $kit['requires'] : [];
        $classes = array_values(array_filter((array) ($requires['classes'] ?? []), 'is_string'));
        $functions = array_values(array_filter((array) ($requires['functions'] ?? []), 'is_string'));
        if ($classes === [] && $functions === []) {
            continue;
        }
        foreach ($classes as $class) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class) === 1 && !class_exists($class)) {
                eval("class {$class} {}");
            }
        }
        foreach ($functions as $function) {
            if (preg_match('/^[a-z_][a-z0-9_]*$/', $function) === 1 && !function_exists($function)) {
                eval("function {$function}(...\$args) { return null; }");
            }
        }
        foreach ((array) ($kit['abilities'] ?? []) as $ability) {
            $name = (string) ($ability['name'] ?? '');
            $vendor_bound[$name] = true;
            $vendor_bound[PREFIX . substr($name, strlen('wppilot'))] = true;
        }
    }

    \Kit_Coexistence::$owners = ['wppilot' => $free . '/includes/kits/', 'export' => $export . '/'];
    if ($pro !== '') {
        \Kit_Coexistence::$owners['wppilot-pro'] = $pro . '/includes/kits/';
    }

    // WPPilot's side, as wppilot.php boots it; then the export's, as the other plugin does.
    require_once $free . '/includes/kits/loader.php';
    if ($pro !== '') {
        do_action('plugins_loaded');
        \WPPilot\Kits\load_kits($pro . '/includes/kits');
    }
    require_once $export . '/load.php';
    if ($pro === '') {
        do_action('plugins_loaded');
    }
    do_action('wp_abilities_api_categories_init');
    do_action('wp_abilities_api_init');
    do_action('init');
    do_action('rest_api_init');
    do_action('admin_menu');

    $problems = [];
    $wppilot_host = \WPPilot\Kits\Runtime\host();
    $export_host = ('\\' . NS . '\\Runtime\\host')();
    if ($wppilot_host->id() !== 'wppilot' || $export_host->id() !== PREFIX) {
        $problems[] = sprintf('hosts are %s and %s; expected wppilot and %s', $wppilot_host->id(), $export_host->id(), PREFIX);
    }
    $wppilot_report = \WPPilot\Kits\Runtime\registry();
    $export_report = ('\\' . NS . '\\Runtime\\registry')();
    foreach (['WPPilot' => $wppilot_report, 'the export' => $export_report] as $side => $report) {
        foreach ($report['skipped'] as $slug => $reason) {
            $problems[] = "{$side} skipped kit {$slug}: {$reason}";
        }
    }
    if (count($export_report['loaded']) !== count($wppilot_report['loaded'])) {
        $problems[] = sprintf('WPPilot loaded %d kits, the export %d', count($wppilot_report['loaded']), count($export_report['loaded']));
    }

    // Run what is safe to run: every read-only ability, as an agent would.
    foreach (\Kit_Coexistence::$abilities as $name => $args) {
        $annotations = $args['meta']['annotations'] ?? [];
        if (($annotations['readonly'] ?? false) !== true || isset($vendor_bound[$name])) {
            continue;
        }
        if (($args['permission_callback'])([]) !== true) {
            $problems[] = "{$name}: permission refused for an administrator";
            continue;
        }
        $result = ($args['execute_callback'])([]);
        // A read that needs input (a search string, a job id, an attachment) refuses an empty call
        // with a 4xx WP_Error; that still proves it loaded and ran on its own runtime. Anything
        // else (a 5xx, a non-array) is a real failure.
        $data = $result instanceof \WP_Error ? $result->get_error_data() : null;
        $status = is_array($data) ? (int) ($data['status'] ?? 0) : 0;
        $refused_input = $result instanceof \WP_Error && ($status === 0 || ($status >= 400 && $status < 500));
        if (!is_array($result) && !$refused_input) {
            $problems[] = "{$name}: execute returned " . get_debug_type($result);
        }
    }

    // Each runtime's own storage: a ledger row and a background job.
    $wppilot_ledger = new \WPPilot\Kits\Runtime\MiniLedger();
    $export_ledger_class = '\\' . NS . '\\Runtime\\MiniLedger';
    $export_ledger = new $export_ledger_class();
    $wppilot_ledger->record_items('wppilot/coexistence-probe', [['input' => ['side' => 'wppilot']]]);
    $export_ledger->record_items(PREFIX . '/coexistence-probe', [['input' => ['side' => 'export']]]);
    if (count($wppilot_ledger->all()) !== 1 || count($export_ledger->all()) !== 1) {
        $problems[] = sprintf('ledgers share storage: WPPilot sees %d rows, the export %d', count($wppilot_ledger->all()), count($export_ledger->all()));
    }

    $step = static fn(array $payload, array $state): array => ['state' => [], 'done' => true];
    $wppilot_host->jobs()->register('coexistence-probe', $step);
    $export_host->jobs()->register('coexistence-probe', $step);
    $wppilot_job = $wppilot_host->jobs()->enqueue('coexistence-probe', []);
    $export_job = $export_host->jobs()->enqueue('coexistence-probe', []);
    $export_runner = '\\' . NS . '\\Runtime\\Runner';
    if (\WPPilot\Kits\Runtime\Runner::CRON_HOOK === $export_runner::CRON_HOOK) {
        $problems[] = 'both runners tick on ' . $export_runner::CRON_HOOK;
    }
    do_action(\WPPilot\Kits\Runtime\Runner::CRON_HOOK);
    $wppilot_status = is_string($wppilot_job) ? ($wppilot_host->jobs()->get($wppilot_job)['status'] ?? 'missing') : 'not queued';
    $export_status = is_string($export_job) ? ($export_host->jobs()->get($export_job)['status'] ?? 'missing') : 'not queued';
    if ($wppilot_status !== 'done' || $export_status !== 'queued') {
        $problems[] = "ticking WPPilot's job runner left WPPilot's job {$wppilot_status} and the export's {$export_status}; expected done and queued";
    }
    do_action($export_runner::CRON_HOOK);
    $export_status = is_string($export_job) ? ($export_host->jobs()->get($export_job)['status'] ?? 'missing') : 'not queued';
    if ($export_status !== 'done') {
        $problems[] = "ticking the export's job runner left its job {$export_status}";
    }

    foreach (array_unique(\Kit_Coexistence::$notices) as $notice) {
        $problems[] = 'WordPress would complain: ' . $notice;
    }

    // Overlaps: what each copy named at run time, plus what its source names statically.
    $wppilot_files = files_under($free . '/includes/kits');
    if ($pro !== '') {
        $wppilot_files = array_merge($wppilot_files, files_under($pro . '/includes/kits'));
    }
    $export_files = array_merge(files_under($export . '/runtime'), files_under($export . '/kits'), [$export . '/load.php']);
    $sides = ['wppilot' => static_names($wppilot_files), 'export' => static_names($export_files)];
    foreach (\Kit_Coexistence::$names as $kind => $names) {
        foreach ($names as $name => $owners) {
            foreach (array_keys($owners) as $owner) {
                $side = $owner === 'wppilot-pro' ? 'wppilot' : $owner;
                if (isset($sides[$side])) {
                    $sides[$side][$kind][(string) $name] = true;
                }
            }
        }
    }

    $fired = ($sides['wppilot']['fired'] ?? []) + ($sides['export']['fired'] ?? []);
    foreach (['ability', 'category', 'option', 'transient', 'cron', 'rest_namespace', 'rest_route', 'post_type', 'handle', 'nonce', 'admin_page', 'hook'] as $kind) {
        $ours = $sides['wppilot'][$kind] ?? [];
        $theirs = $sides['export'][$kind] ?? [];
        foreach (array_keys($ours) as $name) {
            $name = (string) $name;
            $clash = isset($theirs[$name]);
            if (!$clash && $kind === 'option' && str_ends_with($name, '_')) {
                foreach (array_keys($theirs) as $other) {
                    $clash = $clash || str_starts_with((string) $other, $name);
                }
            }
            if (!$clash) {
                continue;
            }
            if ($kind === 'hook' && !isset($fired[$name]) && preg_match('/wppilot|' . PREFIX . '|kit/i', $name) !== 1) {
                continue;
            }
            $problems[] = "both copies use the {$kind} {$name}";
        }
    }

    $counts = [];
    foreach (['ability', 'option', 'cron', 'hook'] as $kind) {
        $counts[] = sprintf('%s %d/%d', $kind, count($sides['wppilot'][$kind] ?? []), count($sides['export'][$kind] ?? []));
    }
    echo 'Compared (WPPilot/export): ', implode(', ', $counts), "\n";
    echo 'Abilities registered: ', implode(', ', array_keys(\Kit_Coexistence::$abilities)), "\n";
    return $problems;
}

$options = getopt('', ['pro-src:', 'phase:', 'export:']);
$options = is_array($options) ? $options : [];
$free = str_replace('\\', '/', dirname(__DIR__));
$pro = isset($options['pro-src']) ? rtrim(str_replace('\\', '/', (string) realpath((string) $options['pro-src'])), '/') : '';
if (isset($options['pro-src']) && ($pro === '' || !is_dir($pro . '/includes/kits'))) {
    fwrite(STDERR, "--pro-src has no includes/kits\n");
    exit(1);
}

if (($options['phase'] ?? '') === 'load') {
    $problems = load_both($free, $pro, str_replace('\\', '/', (string) $options['export']));
    if ($problems !== []) {
        fwrite(STDERR, "The exported kits do not coexist with WPPilot's:\n\n  - " . implode("\n  - ", $problems) . "\n");
        exit(1);
    }
    echo "COEXIST-OK\n";
    exit(0);
}

$export = str_replace('\\', '/', sys_get_temp_dir()) . '/kit-coexistence-' . bin2hex(random_bytes(6));
try {
    run_export($free, $pro, $export);
    $command = escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d error_reporting=-1 ' . escapeshellarg(__FILE__)
        . ' --phase=load --export=' . escapeshellarg($export)
        . ($pro !== '' ? ' --pro-src=' . escapeshellarg($pro) : '') . ' 2>&1';
    exec($command, $output, $code);
    echo implode("\n", $output), "\n";
    // A notice or warning is a failure too: a real site logs it on every request.
    $noisy = preg_grep('/^(PHP )?(Fatal error|Warning|Notice|Deprecated)/m', $output);
    if ($code !== 0 || !in_array('COEXIST-OK', $output, true) || $noisy !== []) {
        fwrite(STDERR, "Kit coexistence failed.\n");
        exit(1);
    }
    echo "WPPilot's kits and an exported copy coexist.\n";
} catch (\RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} finally {
    remove_tree($export);
}
