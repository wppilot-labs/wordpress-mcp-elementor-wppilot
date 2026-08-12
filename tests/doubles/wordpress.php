<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * WordPress function and class doubles for the unit suite.
 *
 * Each double reproduces only the behaviour the code under test depends on, and
 * every piece of mutable state lives on WPPilot_Test_State so a test can set up
 * a scenario and reset it in tearDown().
 */

/**
 * Mutable WordPress state for the doubles.
 */
final class WPPilot_Test_State
{
    /** @var array<string, WP_Post_Type> */
    public static array $post_types = [];

    /** @var list<string> */
    public static array $capabilities = [];

    public static int $current_user_id = 0;

    /** @var list<string> */
    public static array $registered_abilities = [];

    /** @var list<array{name: string, args: array<string, mixed>}> */
    public static array $registrations = [];

    /** @var array<string, object> */
    public static array $taxonomies = [];

    public static bool $logged_in = true;

    public static bool $wppilot_enabled = true;

    public static function reset(): void
    {
        self::$post_types = [];
        self::$capabilities = [];
        self::$current_user_id = 0;
        self::$registered_abilities = [];
        self::$registrations = [];
        self::$taxonomies = [];
        self::$logged_in = true;
        self::$wppilot_enabled = true;
    }

    /**
     * Register a taxonomy double.
     *
     * @param array<string, string> $capabilities Capability-object overrides.
     */
    public static function add_taxonomy(
        string $name,
        bool $public = true,
        bool $show_in_rest = true,
        bool $hierarchical = false,
        array $capabilities = [],
    ): void {
        self::$taxonomies[$name] = (object) [
            'name' => $name,
            'label' => ucfirst($name),
            'public' => $public,
            'show_in_rest' => $show_in_rest,
            'hierarchical' => $hierarchical,
            'object_type' => ['post'],
            'cap' => (object) array_merge([
                'manage_terms' => 'manage_categories',
                'edit_terms' => 'manage_categories',
                'delete_terms' => 'manage_categories',
                'assign_terms' => 'edit_posts',
            ], $capabilities),
        ];
    }

    /**
     * Register a post type double.
     *
     * @param array<string, mixed> $capabilities Capability-object overrides.
     */
    public static function add_post_type(
        string $name,
        bool $public = true,
        bool $show_in_rest = true,
        array $capabilities = [],
    ): void {
        $type = new WP_Post_Type();
        $type->name = $name;
        $type->public = $public;
        $type->show_in_rest = $show_in_rest;
        $type->cap = (object) array_merge([
            'create_posts' => 'edit_posts',
            'publish_posts' => 'publish_posts',
            'edit_others_posts' => 'edit_others_posts',
            'edit_post' => 'edit_post',
            'delete_post' => 'delete_post',
        ], $capabilities);

        self::$post_types[$name] = $type;
    }
}

if (!class_exists('WP_Post_Type')) {
    /**
     * Minimal stand-in for WordPress's registered post-type object.
     */
    class WP_Post_Type
    {
        public string $name = '';

        public bool $public = false;

        public bool $show_in_rest = false;

        public object $cap;

        public function __construct()
        {
            $this->cap = (object) [];
        }
    }
}

if (!class_exists('WP_Post')) {
    /**
     * Minimal stand-in for a WordPress post row.
     */
    class WP_Post
    {
        public int $ID = 0;

        public int $post_parent = 0;

        public string $post_type = 'post';

        public string $post_status = 'draft';

        public string $post_name = '';

        public string $post_title = '';

        public string $post_content = '';

        public string $post_excerpt = '';

        public string $post_modified_gmt = '';

        public int $post_author = 0;
    }
}

if (!class_exists('WP_Error')) {
    /**
     * Minimal stand-in for WordPress's error object.
     */
    class WP_Error
    {
        /** @param array<string, mixed> $data */
        public function __construct(
            private string $code = '',
            private string $message = '',
            private array $data = [],
        ) {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        /** @return array<string, mixed> */
        public function get_error_data(): array
        {
            return $this->data;
        }
    }
}

if (!function_exists('get_post_type_object')) {
    function get_post_type_object(string $post_type): ?WP_Post_Type
    {
        return WPPilot_Test_State::$post_types[$post_type] ?? null;
    }
}

if (!function_exists('post_type_exists')) {
    function post_type_exists(string $post_type): bool
    {
        return isset(WPPilot_Test_State::$post_types[$post_type]);
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return in_array($capability, WPPilot_Test_State::$capabilities, true);
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return WPPilot_Test_State::$current_user_id;
    }
}

if (!function_exists('wp_has_ability')) {
    function wp_has_ability(string $name): bool
    {
        return in_array($name, WPPilot_Test_State::$registered_abilities, true);
    }
}

if (!function_exists('wp_register_ability')) {
    /** @param array<string, mixed> $args */
    function wp_register_ability(string $name, array $args): void
    {
        WPPilot_Test_State::$registered_abilities[] = $name;
        WPPilot_Test_State::$registrations[] = ['name' => $name, 'args' => $args];
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return WPPilot_Test_State::$logged_in;
    }
}

if (!function_exists('wppilot_is_enabled')) {
    function wppilot_is_enabled(): bool
    {
        return WPPilot_Test_State::$wppilot_enabled;
    }
}

if (!function_exists('get_taxonomy')) {
    function get_taxonomy(string $taxonomy): object|false
    {
        return WPPilot_Test_State::$taxonomies[$taxonomy] ?? false;
    }
}

if (!function_exists('wp_allowed_protocols')) {
    /** @return list<string> */
    function wp_allowed_protocols(): array
    {
        return ['http', 'https', 'mailto', 'tel'];
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed
    {
        return parse_url($url, $component);
    }
}

if (!function_exists('esc_url_raw')) {
    /**
     * Scheme-stripping stand-in for WordPress's URL sanitizer.
     *
     * The real function returns an empty string for a URL whose scheme is not in
     * wp_allowed_protocols(); this reproduces that specific behaviour, which is
     * the property wordpress_unsafe_url_error() is written against.
     */
    function esc_url_raw(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme) || $scheme === '') {
            return $url;
        }

        return in_array(strtolower($scheme), wp_allowed_protocols(), true) ? $url : '';
    }
}
