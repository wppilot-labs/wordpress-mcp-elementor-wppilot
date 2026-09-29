<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * UpdraftPlus, Duplicator 5 and BackWPup stubbed down to the calls the kit makes (shapes as read
 * from UpdraftPlus 1.26.8, Duplicator 5.0.4 and BackWPup 5.7.6), and the WordPress functions the
 * kit calls, defined in the kit's own namespace so the rest of the suite is untouched.
 *
 * The vendor classes are global because the kit detects them with class_exists(); each is guarded.
 * The Duplicator package class lives in the test's namespace and is aliased to the vendor's name.
 */

namespace WPPilot\Tests\Unit\Kits\BackupStatus {
    final class Vendors
    {
        public static int $now = 1790640000; // 2026-09-29T00:00:00Z

        /** @var array<string, mixed> UpdraftPlus's options, read through UpdraftPlus_Options. */
        public static array $updraft = [];

        /** @var array<string, mixed> */
        public static array $siteOptions = [];

        /** @var array<string, mixed> */
        public static array $options = [];

        /** @var array<int, array<string, array<string, array<string, mixed>>>> */
        public static array $cron = [];

        /** @var list<Duplicator\DupPackage> */
        public static array $dupRows = [];

        /** @var array<int, array<string, mixed>> */
        public static array $bwpJobs = [];

        /** @var array<string, array<string, mixed>> log file name => header */
        public static array $bwpHeaders = [];

        public static string $bwpLogdir = '';

        /** @var array<string, string> */
        public static array $bwpPaths = [];

        public static object|false $bwpWorking = false;

        public static bool $updraftBroken = false;

        public static function reset(): void
        {
            self::$updraft = [];
            self::$siteOptions = [];
            self::$options = ['gmt_offset' => 0];
            self::$cron = [];
            self::$dupRows = [];
            self::$bwpJobs = [];
            self::$bwpHeaders = [];
            self::$bwpPaths = [];
            self::$bwpWorking = false;
            self::$updraftBroken = false;
        }
    }
}

namespace WPPilot\Tests\Unit\Kits\BackupStatus\Duplicator {
    class PackageArchive
    {
        public int $Size = 0;
    }

    class DefaultLocalStorage
    {
        public function getName(): string
        {
            return 'Default';
        }

        public static function getStypeName(): string
        {
            return 'Default Local';
        }
    }

    class DupPackage
    {
        public PackageArchive $Archive;

        /** @var list<string> */
        public array $components = [];

        /**
         * @param list<string> $components
         * @param array<int, array<string, float>> $state_times
         */
        public function __construct(public int $id, public int $status, public string $created, array $components, int $size, public bool $db_only = false, public array $state_times = [])
        {
            $this->components = $components;
            $this->Archive = new PackageArchive();
            $this->Archive->Size = $size;
        }

        /** @return list<self> */
        public static function dbSelect(string $where, int $limit = 0, int $offset = 0, string $orderBy = '', string $type = 'objs'): array
        {
            $rows = array_filter(\WPPilot\Tests\Unit\Kits\BackupStatus\Vendors::$dupRows, static function (DupPackage $p) use ($where): bool {
                if ($where === '`status` = 100') {
                    return $p->status === 100;
                }
                if ($where === '`status` >= 0 AND `status` < 100') {
                    return $p->status >= 0 && $p->status < 100;
                }
                return true;
            });
            usort($rows, static fn(DupPackage $a, DupPackage $b): int => $b->id <=> $a->id);
            return array_slice($rows, 0, $limit > 0 ? $limit : PHP_INT_MAX);
        }

        public function getId(): int
        {
            return $this->id;
        }

        public function getStatus(): int
        {
            return $this->status;
        }

        public function getCreated(): string
        {
            return $this->created;
        }

        public function getName(): string
        {
            return 'pkg' . $this->id;
        }

        public function isDBOnly(): bool
        {
            return $this->db_only;
        }

        public function isDBExcluded(): bool
        {
            return false;
        }

        /** @return array<int, array<string, float>> */
        public function getStateTimes(): array
        {
            return $this->state_times;
        }

        /** @return list<DefaultLocalStorage> */
        public function getStorages(): array
        {
            return [new DefaultLocalStorage()];
        }
    }

    if (!class_exists('Duplicator\\Package\\DupPackage')) {
        class_alias(DupPackage::class, 'Duplicator\\Package\\DupPackage');
    }
}

namespace {
    use WPPilot\Tests\Unit\Kits\BackupStatus\Vendors;

    if (!defined('UPDRAFTPLUS_DIR')) {
        define('UPDRAFTPLUS_DIR', '/nonexistent/updraftplus');
    }
    if (!defined('DUPLICATOR_VERSION')) {
        define('DUPLICATOR_VERSION', '5.0.4');
    }
    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }

    if (!class_exists('UpdraftPlus')) {
        class UpdraftPlus
        {
            public string $version = '1.26.8';

            /** @var array<string, string> */
            public array $backup_methods = ['s3' => 'Amazon S3', 'dropbox' => 'Dropbox'];
        }
    }

    if (!class_exists('UpdraftPlus_Options')) {
        class UpdraftPlus_Options
        {
            public static function get_updraft_option(string $name, mixed $default = null): mixed
            {
                if (Vendors::$updraftBroken) {
                    throw new \RuntimeException('Cannot read /var/www/html/wp-content/updraft/secret.log');
                }
                return Vendors::$updraft[$name] ?? $default;
            }
        }
    }

    if (!class_exists('UpdraftPlus_Backup_History')) {
        class UpdraftPlus_Backup_History
        {
            /** @return array<int, array<string, mixed>> */
            public static function get_history(): array
            {
                $history = Vendors::$updraft['updraft_backup_history'] ?? [];
                krsort($history);
                return $history;
            }
        }
    }

    if (!class_exists('BackWPup')) {
        class BackWPup
        {
            /** @return array<string, array<string, array<string, string>>> */
            public static function get_registered_destinations(): array
            {
                return ['FOLDER' => ['info' => ['name' => 'Folder']], 'S3' => ['info' => ['name' => 'Amazon S3']]];
            }

            public static function get_plugin_data(string $name): string
            {
                return '5.7.6';
            }
        }
    }

    if (!class_exists('BackWPup_Option')) {
        class BackWPup_Option
        {
            /** @return list<int> */
            public static function get_job_ids(): array
            {
                return array_keys(Vendors::$bwpJobs);
            }

            /** @return array<string, mixed> */
            public static function get_job(int $id): array
            {
                return Vendors::$bwpJobs[$id];
            }
        }
    }

    if (!class_exists('BackWPup_Job')) {
        class BackWPup_Job
        {
            /** @return array<string, mixed> */
            public static function read_logheader(string $file): array
            {
                return Vendors::$bwpHeaders[basename($file)];
            }

            public static function get_working_data(): object|false
            {
                return Vendors::$bwpWorking;
            }
        }
    }

    if (!class_exists('BackWPup_File')) {
        class BackWPup_File
        {
            public static function get_absolute_path(string $path): string
            {
                // A job's backupdir maps through bwpPaths; anything else is the logs folder.
                return Vendors::$bwpPaths[$path] ?? Vendors::$bwpLogdir;
            }
        }
    }
}

namespace WPPilot\Kits\BackupStatus {
    use WPPilot\Tests\Unit\Kits\BackupStatus\Vendors;

    if (!function_exists(__NAMESPACE__ . '\\time')) {
        function time(): int
        {
            return Vendors::$now;
        }

        function get_site_option(string $name, mixed $default = false): mixed
        {
            return Vendors::$siteOptions[$name] ?? $default;
        }

        function get_option(string $name, mixed $default = false): mixed
        {
            return Vendors::$options[$name] ?? $default;
        }

        function wp_date(string $format, int $timestamp): string
        {
            return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('Asia/Karachi'))->format($format);
        }

        /** @return array<int, array<string, array<string, array<string, mixed>>>> */
        function _get_cron_array(): array
        {
            return Vendors::$cron;
        }

        /** @param array<array-key, mixed> $args */
        function wp_next_scheduled(string $hook, array $args = []): int|false
        {
            foreach (Vendors::$cron as $time => $hooks) {
                foreach ($hooks[$hook] ?? [] as $event) {
                    if ($event['args'] === $args) {
                        return (int) $time;
                    }
                }
            }
            return false;
        }

        function trailingslashit(string $path): string
        {
            return rtrim($path, '/\\') . '/';
        }
    }
}
