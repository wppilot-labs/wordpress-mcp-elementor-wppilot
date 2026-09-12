<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Registry reads and exposure rules shared by policy, discovery, and the admin screens.
 *
 * WordPress 7.1 gave `wp_get_abilities()` a filter pipeline. `wp_get_abilities_item_include`
 * and `wp_get_abilities_result` now run on every call, the bare no-argument one included, so
 * that function answers "what this site publishes" rather than "what is registered". That is
 * the right source for anything that advertises abilities. It is the wrong source for anything
 * that enforces a rule across the registry: the ability policy unregisters what an
 * administrator switched off in the Abilities Hub and what the safety profile refuses, and
 * running that over a filtered list leaves an ability another plugin hid from discovery
 * registered and executable — the opposite of what hiding it was meant to achieve.
 *
 * Enforcement therefore reads the registry itself, and discovery keeps calling
 * `wp_get_abilities()` so a site's own filters still decide what it advertises.
 */

/**
 * Every registered ability, before any discovery filter has had a say.
 *
 * @return array<string, WP_Ability>
 */
function wppilot_registered_abilities(): array
{
    if (class_exists('WP_Abilities_Registry')) {
        /** @var mixed $registry */
        $registry = \WP_Abilities_Registry::get_instance();
        if (is_object($registry) && method_exists($registry, 'get_all_registered')) {
            /** @var mixed $all */
            $all = $registry->get_all_registered();
            if (is_array($all)) {
                /** @var array<string, WP_Ability> $all */
                return $all;
            }
        }
    }

    return function_exists('wp_get_abilities') ? wp_get_abilities() : [];
}

/**
 * The metadata of an ability, normalized to a string-keyed array.
 *
 * @return array<string, mixed>
 */
function wppilot_ability_meta(mixed $ability): array
{
    if (!is_object($ability) || !method_exists($ability, 'get_meta')) {
        return [];
    }

    /** @var mixed $meta */
    $meta = $ability->get_meta();

    return is_array($meta) ? $meta : [];
}

/**
 * Whether an ability is published to WPPilot's MCP surface.
 *
 * `mcp.public` is WPPilot's own flag and stays authoritative. WordPress 7.1 added a
 * channel-independent `public` flag that is resolved to a boolean for every ability whether or
 * not one was supplied at registration, and core's documented precedence for a single channel
 * is "the channel's own flag, then `public`, then false". Following that same order here means
 * an ability registered by someone who never heard of WPPilot, with nothing but
 * `'public' => true`, is reachable over MCP — while an ability that sets
 * `mcp.public => false` stays hidden even when it is public everywhere else.
 *
 * @param array<string, mixed> $meta
 */
function wppilot_ability_is_exposed(array $meta): bool
{
    /** @var mixed $mcp */
    $mcp = $meta['mcp'] ?? null;
    $mcp = is_array($mcp) ? $mcp : [];

    return ($mcp['public'] ?? $meta['public'] ?? false) === true;
}

/**
 * Which MCP primitive an ability is served as: `tool` (the default) or `prompt`.
 *
 * @param array<string, mixed> $meta
 */
function wppilot_ability_mcp_type(array $meta): string
{
    /** @var mixed $mcp */
    $mcp = $meta['mcp'] ?? null;
    $mcp = is_array($mcp) ? $mcp : [];

    /** @var mixed $type */
    $type = $mcp['type'] ?? null;

    return is_string($type) && $type !== '' ? $type : 'tool';
}
