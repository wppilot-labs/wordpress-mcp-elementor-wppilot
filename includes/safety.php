<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_SAFETY_PROFILE_OPTION = 'wppilot_safety_profile';

const WPPILOT_SAFETY_POLICY_VERSION_OPTION = 'wppilot_safety_policy_version';

const WPPILOT_SAFETY_POLICY_VERSION = 1;

/**
 * The valid safety profile ids.
 *
 * Kept separate from wppilot_safety_profiles() because validation runs long
 * before the display labels are needed — wppilot_safety_maybe_install() fires on
 * plugins_loaded, and wppilot_get_safety_profile() runs on nearly every request.
 * Calling __() there would load the text domain before `init` and make
 * WordPress emit a _load_textdomain_just_in_time notice on every page.
 *
 * @return list<string>
 */
function wppilot_safety_profile_ids(): array
{
    return ['production', 'readonly', 'developer'];
}

/**
 * Whether a value names a safety profile. Translation-free: safe to call early.
 */
function wppilot_is_safety_profile(mixed $profile): bool
{
    return is_string($profile) && in_array($profile, wppilot_safety_profile_ids(), strict: true);
}

/**
 * Safety profiles are enforced at registry, MCP-dispatch, and REST-run boundaries.
 *
 * The labels and descriptions here are for display only. Anything that merely
 * validates a stored value must use wppilot_is_safety_profile() instead, so it
 * does not pull in translations before `init`.
 *
 * @return array<string, array{label: string, description: string}>
 */
function wppilot_safety_profiles(): array
{
    return [
        'production' => [
            'label' => __('Production Safe', domain: 'wppilot'),
            'description' => __(
                'Allows normal content, design, SEO, form, and commerce work while blocking raw code execution, server filesystem access, WP-CLI, temporary administrator access, and similarly critical operations.',
                domain: 'wppilot',
            ),
        ],
        'readonly' => [
            'label' => __('Read Only', domain: 'wppilot'),
            'description' => __(
                'Allows discovery and inspection abilities only. Every ability that can change site state is blocked.',
                domain: 'wppilot',
            ),
        ],
        'developer' => [
            'label' => __('Developer Full Access', domain: 'wppilot'),
            'description' => __(
                'Allows every enabled ability, including PHP execution, WP-CLI, filesystem operations, and administrator access. Critical calls still require an explicit confirmation flag.',
                domain: 'wppilot',
            ),
        ],
    ];
}

function wppilot_get_safety_profile(): string
{
    /** @var mixed $stored */
    $stored = get_option(WPPILOT_SAFETY_PROFILE_OPTION, default_value: 'production');
    $profile = is_string($stored) ? sanitize_key($stored) : 'production';

    return wppilot_is_safety_profile($profile) ? $profile : 'production';
}

function wppilot_update_safety_profile(string $profile): bool
{
    $profile = sanitize_key($profile);
    if (!wppilot_is_safety_profile($profile)) {
        return false;
    }

    update_option(WPPILOT_SAFETY_PROFILE_OPTION, $profile, autoload: true);
    return true;
}

/**
 * Install or migrate the safety policy without silently granting an existing site more access.
 */
function wppilot_safety_maybe_install(): void
{
    /** @var mixed $stored */
    $stored = get_option(WPPILOT_SAFETY_PROFILE_OPTION, default_value: null);
    if (!wppilot_is_safety_profile($stored)) {
        // @mago-expect analysis:mixed-assignment -- Legacy option values are compared strictly below.
        $legacy_enabled = get_option('wppilot_ai_abilities_enabled', default_value: false);
        $profile = ($legacy_enabled === '1' || $legacy_enabled === true) && !wppilot_looks_like_production()
            ? 'developer'
            : 'production';
        update_option(WPPILOT_SAFETY_PROFILE_OPTION, $profile, autoload: true);
    }

    update_option(WPPILOT_SAFETY_POLICY_VERSION_OPTION, (string) WPPILOT_SAFETY_POLICY_VERSION, autoload: false);
}

function wppilot_ability_is_readonly(WP_Ability $ability): bool
{
    $meta = $ability->get_meta();
    $mcp = is_array($meta['mcp'] ?? null) ? $meta['mcp'] : [];
    $type = is_string($mcp['type'] ?? null) ? $mcp['type'] : 'tool';
    if ($type === 'resource' || $type === 'prompt') {
        return true;
    }

    $annotations = is_array($meta['annotations'] ?? null) ? $meta['annotations'] : [];
    return ($annotations['readonly'] ?? null) === true;
}

/**
 * Return a stable risk class used by profiles, confirmations, audit logs, and the UI.
 *
 * @return 'read'|'write'|'destructive'|'critical'
 */
function wppilot_ability_risk(WP_Ability $ability): string
{
    if (wppilot_ability_is_readonly($ability)) {
        return 'read';
    }

    $name = strtolower($ability->get_name());
    $category = strtolower($ability->get_category());
    if (
        in_array(
            $category,
            [
                'code-execution',
                'code-snippets',
                'dynamic-shortcodes',
                'filesystem',
                'admin-access',
                'database',
                'wp-cli',
            ],
            strict: true,
        )
        || wppilot_ability_name_is_critical($name)
    ) {
        return 'critical';
    }

    $meta = $ability->get_meta();
    $annotations = is_array($meta['annotations'] ?? null) ? $meta['annotations'] : [];
    if (($annotations['destructive'] ?? null) === true || wppilot_ability_name_is_destructive($name)) {
        return 'destructive';
    }

    return 'write';
}

function wppilot_ability_name_is_critical(string $name): bool
{
    $critical = [
        'execute-php',
        'run-wp-cli',
        'write-file',
        'edit-file',
        'delete-file',
        'read-file',
        'list-directory',
        'create-upload-link',
        'create-admin-access-link',
        'enable-file',
        'disable-file',
        'install-plugin',
        'delete-plugin',
        'install-theme',
        'delete-theme',
        'database-query',
        'insert-row',
        'update-rows',
        'delete-rows',
        'php-snippet',
        'code-snippets',
        'dynamic-shortcodes',
        'create-user',
        'update-user',
        'delete-user',
    ];

    foreach ($critical as $fragment) {
        if (str_contains($name, $fragment)) {
            return true;
        }
    }

    return false;
}

/**
 * Names that read as ordinary writes but are not.
 *
 * The extension-lifecycle fragments are listed individually rather than as a
 * bare `update-` or `activate-` prefix: those would sweep in update-post,
 * update-media, update-term, activate-design and every other routine content
 * write, and confirmation-gating those would make normal editing unusable.
 * `activate-plugin` covers `deactivate-plugin` as a substring.
 */
function wppilot_ability_name_is_destructive(string $name): bool
{
    foreach ([
        'delete-',
        'remove-',
        'refund',
        'cancel-subscription',
        'reset-',
        'restore-',
        'apply-site',
        'uninstall',
        // Extension lifecycle: no code is fetched or written, so these stay out
        // of the critical list and remain usable on Production Safe — but each
        // one can take a live site down, so none of them runs unconfirmed.
        'activate-plugin',
        'update-plugin',
        'update-theme',
        'switch-theme',
    ] as $fragment) {
        if (str_contains($name, $fragment)) {
            return true;
        }
    }

    return false;
}

function wppilot_safety_profile_allows_ability(WP_Ability $ability): bool
{
    if (wppilot_ability_is_hub_protected($ability->get_name())) {
        return true;
    }

    return match (wppilot_get_safety_profile()) {
        'developer' => true,
        'readonly' => wppilot_ability_is_readonly($ability),
        default => wppilot_ability_risk($ability) !== 'critical',
    };
}

function wppilot_ability_requires_confirmation(WP_Ability $ability): bool
{
    $risk = wppilot_ability_risk($ability);
    return $risk === 'critical' || $risk === 'destructive';
}

/**
 * Enforce the safety profile and explicit confirmation on MCP ability dispatch.
 *
 * The MCP Adapter's public surface is the execute-ability meta-tool. A `confirm:true`
 * member may be included in its `parameters` object. It is removed before the target
 * ability validates input unless that ability declares its own confirm property.
 *
 * @param array<string, mixed> $args
 * @return array<string, mixed>|WP_Error
 */
function wppilot_safety_pre_mcp_tool_call(array $args, string $tool_name): array|WP_Error
{
    if ($tool_name !== 'mcp-adapter-execute-ability') {
        return $args;
    }

    $ability_name = is_string($args['ability_name'] ?? null) ? $args['ability_name'] : '';
    $ability = $ability_name !== '' && function_exists('wp_get_ability') ? wp_get_ability($ability_name) : null;
    if (!$ability instanceof WP_Ability) {
        return $args;
    }

    $allowed = wppilot_safety_check_ability($ability);
    if ($allowed instanceof WP_Error) {
        return $allowed;
    }

    $parameters = is_array($args['parameters'] ?? null) ? $args['parameters'] : [];
    if (wppilot_ability_requires_confirmation($ability) && ($parameters['confirm'] ?? null) !== true) {
        return wppilot_confirmation_required_error($ability);
    }

    if (
        array_key_exists(key: 'confirm', array: $parameters)
        && !wppilot_ability_schema_has_property($ability, property: 'confirm')
    ) {
        unset($parameters['confirm']);
        $args['parameters'] = $parameters;
    }

    return $args;
}

function wppilot_safety_check_ability(WP_Ability $ability): bool|WP_Error
{
    if (wppilot_safety_profile_allows_ability($ability)) {
        return true;
    }

    $profile = wppilot_get_safety_profile();
    $profiles = wppilot_safety_profiles();
    return new WP_Error(
        'wppilot_safety_profile_blocked',
        sprintf(
            /* translators: 1: ability name, 2: safety profile label */
            __(
                'Ability "%1$s" is blocked by the %2$s safety profile. Change the profile in WPPilot Configuration only if this access is intentional.',
                domain: 'wppilot',
            ),
            $ability->get_name(),
            $profiles[$profile]['label'],
        ),
        ['status' => 403, 'ability' => $ability->get_name(), 'profile' => $profile],
    );
}

function wppilot_confirmation_required_error(WP_Ability $ability): WP_Error
{
    return new WP_Error(
        'wppilot_confirmation_required',
        sprintf(
            /* translators: %s: ability name */
            __(
                'Ability "%s" is destructive or critical. Obtain explicit user approval, then retry with confirm=true inside the ability parameters.',
                domain: 'wppilot',
            ),
            $ability->get_name(),
        ),
        ['status' => 409, 'ability' => $ability->get_name(), 'risk' => wppilot_ability_risk($ability)],
    );
}

function wppilot_ability_schema_has_property(WP_Ability $ability, string $property): bool
{
    $schema = $ability->get_input_schema();
    $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
    return array_key_exists($property, $properties);
}

/**
 * Validate and prepare a direct REST ability invocation.
 */
function wppilot_safety_prepare_rest_input(WP_Ability $ability, mixed $input): mixed
{
    $allowed = wppilot_safety_check_ability($ability);
    if ($allowed instanceof WP_Error) {
        return $allowed;
    }

    $values = is_array($input) ? $input : [];
    if (wppilot_ability_requires_confirmation($ability) && ($values['confirm'] ?? null) !== true) {
        return wppilot_confirmation_required_error($ability);
    }

    if (
        is_array($input)
        && array_key_exists(key: 'confirm', array: $input)
        && !wppilot_ability_schema_has_property($ability, property: 'confirm')
    ) {
        unset($input['confirm']);
    }

    return $input;
}
