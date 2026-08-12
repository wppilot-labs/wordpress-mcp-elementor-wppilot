<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Mcp;

use WP_Ability;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Modern-era request dispatcher.
 *
 * Hooks `rest_pre_dispatch` on the MCP endpoints. A request carrying modern
 * per-request `_meta` is answered here and never reaches the bundled adapter;
 * anything else is returned untouched so the adapter serves it under the legacy
 * revision exactly as before. That split is the whole compatibility strategy:
 * the adapter is a third-party package pinned in composer.json, so patching it
 * would be undone by the next `composer update`.
 *
 * Everything security-relevant is reused rather than reimplemented. Safety
 * profiles, rate limits, capability checks, and the change ledger all run
 * through the same functions the legacy path uses, so a modern client cannot
 * reach a weaker code path than a legacy one.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Priority of the dispatch hook.
 *
 * After the host guard (5) so a spoofed Host is rejected first, and after the
 * troubleshooting recorder (20) so a modern request still shows up on the
 * Diagnostics screen even though it short-circuits the adapter.
 */
const DISPATCH_PRIORITY = 25;

/**
 * Whether a REST route is one of the MCP endpoints.
 */
function is_mcp_route(string $route): bool
{
    foreach (['/mcp/wppilot', '/mcp/mcp-adapter-default-server'] as $base) {
        if ($route === $base || str_starts_with($route, $base . '/')) {
            return true;
        }
    }

    return false;
}

/**
 * Answer a modern request, or hand the request back for legacy handling.
 *
 * @return mixed A WP_REST_Response for modern traffic, or the untouched $result.
 */
function pre_dispatch(mixed $result, WP_REST_Server $server, WP_REST_Request $request): mixed
{
    if ($result !== null || !is_mcp_route($request->get_route())) {
        return $result;
    }

    // GET and DELETE are deliberately NOT answered with 405 here. The modern
    // revision drops the GET stream and the session lifecycle, but WPPilot is
    // dual-era: legacy clients still open the adapter's GET stream, and
    // refusing it would break every connection already in the field.
    if ($request->get_method() !== 'POST') {
        return $result;
    }

    $body = $request->get_json_params();
    if (!is_array($body) || !is_modern_request($body)) {
        return $result;
    }

    return respond(handle_modern($request, $body));
}

/**
 * Turn a dispatcher outcome into a REST response.
 *
 * `Mcp-Session-Id` is neither read nor minted on this path. The modern
 * transport says to ignore a session header rather than error on it, so a
 * half-migrated client sending one is served normally and simply never
 * receives a session back.
 *
 * @param array{status: int, body: array<string, mixed>} $outcome
 */
function respond(array $outcome): WP_REST_Response
{
    $response = new WP_REST_Response($outcome['body'], $outcome['status']);
    $response->header('Content-Type', 'application/json; charset=utf-8');
    $response->header('MCP-Protocol-Version', VERSION_MODERN);

    return $response;
}

/**
 * Validate and route one modern request.
 *
 * @param array<string, mixed> $body
 * @return array{status: int, body: array<string, mixed>}
 */
function handle_modern(WP_REST_Request $request, array $body): array
{
    $id = $body['id'] ?? null;
    $method = is_string($body['method'] ?? null) ? $body['method'] : '';

    $header_error = validate_modern_headers($request->get_headers(), $body);
    if ($header_error !== null) {
        return $header_error;
    }

    // A method the revision removed must not quietly work, or a client would
    // believe it had set a log level or kept a session alive when it had not.
    if (is_removed_in_modern($method)) {
        return removed_method_error($method, $id);
    }

    // The revision requires both _meta fields on every request. An empty
    // capabilities object is a valid declaration; an absent key is not, and the
    // server must not have to guess what the client can handle.
    if (!declares_client_capabilities($body)) {
        return error_response(
            ERROR_INVALID_PARAMS,
            sprintf('params._meta.%s is required on every request.', META_CLIENT_CAPABILITIES),
            400,
            $id,
        );
    }

    $params = is_array($body['params'] ?? null) ? $body['params'] : [];

    return match ($method) {
        'server/discover' => success(build_discover_result(
            runtime_capabilities(),
            function_exists('wppilot_get_safety_profile') ? \wppilot_get_safety_profile() : 'unknown',
        ), $id, $method),
        'tools/list' => success(['tools' => list_tools()], $id, $method),
        'tools/call' => call_tool($params, $id),
        'prompts/list' => success(['prompts' => list_prompts()], $id, $method),
        'prompts/get' => get_prompt($params, $id),
        default => method_not_found_error($method, $id),
    };
}

/**
 * Wrap a result in a decorated, successful JSON-RPC response.
 *
 * @param array<string, mixed> $result
 * @return array{status: int, body: array<string, mixed>}
 */
function success(array $result, mixed $id, string $method): array
{
    return [
        'status' => 200,
        'body' => modern_response($result, $id, $method, server_name(), server_version()),
    ];
}

function server_name(): string
{
    return 'WPPilot';
}

function server_version(): string
{
    return defined('WPPILOT_VERSION') ? (string) constant('WPPILOT_VERSION') : '0.0.0';
}

/**
 * Build the tool list from the abilities this connection may actually reach.
 *
 * Filtered by the ability's own permission callback and by the active safety
 * profile, so the list never advertises a tool the very next call would refuse.
 * Returned in a deterministic order so clients can cache it.
 *
 * @return list<array<string, mixed>>
 */
function list_tools(): array
{
    if (!function_exists('wp_get_abilities')) {
        return [];
    }

    $tools = [];

    /** @var mixed $ability */
    foreach (wp_get_abilities() as $ability) {
        if (!$ability instanceof WP_Ability) {
            continue;
        }

        $meta = $ability->get_meta();
        $meta = is_array($meta) ? $meta : [];
        $mcp = is_array($meta['mcp'] ?? null) ? $meta['mcp'] : [];
        if (($mcp['public'] ?? false) !== true) {
            continue;
        }

        // Prompt-type abilities belong to prompts/list. Listing one as a tool
        // would let a client call it through tools/call and receive prompt
        // messages where it expected a tool result.
        if (($mcp['type'] ?? 'tool') === 'prompt') {
            continue;
        }

        if (function_exists('wppilot_safety_profile_allows_ability') && !\wppilot_safety_profile_allows_ability(
            $ability,
        )) {
            continue;
        }

        $tools[] = [
            'name' => tool_name($ability->get_name()),
            'title' => (string) $ability->get_label(),
            'description' => (string) $ability->get_description(),
            'inputSchema' => normalize_schema($ability->get_input_schema()),
            'outputSchema' => normalize_schema($ability->get_output_schema()),
        ];
    }

    return sort_tools_deterministically($tools);
}

/**
 * Return the prompt-type abilities this connection may reach.
 *
 * WPPilot's prompts are its skills. They take no arguments, so `arguments` is
 * always an empty list rather than omitted — a client that treats a missing
 * key as "unknown" would otherwise prompt the user for input that does not
 * exist.
 *
 * @return list<array<string, mixed>>
 */
function list_prompts(): array
{
    $prompts = [];

    foreach (prompt_abilities() as $ability) {
        $prompts[] = [
            'name' => tool_name($ability->get_name()),
            'title' => (string) $ability->get_label(),
            'description' => (string) $ability->get_description(),
            'arguments' => [],
        ];
    }

    return sort_tools_deterministically($prompts);
}

/**
 * Every registered prompt-type ability that passes discovery and safety.
 *
 * @return list<WP_Ability>
 */
function prompt_abilities(): array
{
    if (!function_exists('wp_get_abilities')) {
        return [];
    }

    $prompts = [];

    /** @var mixed $ability */
    foreach (wp_get_abilities() as $ability) {
        if (!$ability instanceof WP_Ability) {
            continue;
        }
        $meta = $ability->get_meta();
        $meta = is_array($meta) ? $meta : [];
        $mcp = is_array($meta['mcp'] ?? null) ? $meta['mcp'] : [];

        if (($mcp['public'] ?? false) !== true || ($mcp['type'] ?? 'tool') !== 'prompt') {
            continue;
        }
        if (function_exists('wppilot_safety_profile_allows_ability') && !\wppilot_safety_profile_allows_ability(
            $ability,
        )) {
            continue;
        }

        $prompts[] = $ability;
    }

    return $prompts;
}

/**
 * Render one prompt.
 *
 * @param array<string, mixed> $params
 * @return array{status: int, body: array<string, mixed>}
 */
function get_prompt(array $params, mixed $id): array
{
    $name = is_string($params['name'] ?? null) ? $params['name'] : '';

    foreach (prompt_abilities() as $ability) {
        if (tool_name($ability->get_name()) !== $name) {
            continue;
        }

        // execute() runs the permission check itself and returns a WP_Error
        // when the caller may not read this prompt.
        $result = $ability->execute([]);
        if ($result instanceof WP_Error) {
            return error_response(ERROR_INVALID_PARAMS, $result->get_error_message(), 200, $id);
        }

        $messages = is_array($result) && is_array($result['messages'] ?? null) ? $result['messages'] : [];

        return success([
            'description' => (string) $ability->get_description(),
            'messages' => $messages,
        ], $id, 'prompts/get');
    }

    // Modern moved not-found onto -32602.
    return error_response(ERROR_INVALID_PARAMS, sprintf('Unknown prompt: %s', $name), 200, $id);
}

/**
 * Map an ability name to an MCP tool name.
 *
 * Ability names are namespaced with a slash, which is not in the character set
 * MCP tool names are constrained to, so the separator becomes an underscore —
 * the same transformation the bundled adapter applies, keeping tool names
 * identical across the two eras.
 */
function tool_name(string $ability_name): string
{
    return str_replace(['/', '-'], '_', $ability_name);
}

/**
 * Resolve an MCP tool name back to its ability.
 */
function ability_for_tool(string $tool): ?WP_Ability
{
    if (!function_exists('wp_get_abilities')) {
        return null;
    }

    /** @var mixed $ability */
    foreach (wp_get_abilities() as $ability) {
        if ($ability instanceof WP_Ability && tool_name($ability->get_name()) === $tool) {
            return $ability;
        }
    }

    return null;
}

/**
 * Coerce an ability schema into something JSON Schema 2020-12 clients accept.
 *
 * The revision loosened `inputSchema` to allow any 2020-12 keyword, so the
 * schema is passed through as authored. Only the outer shape is guaranteed,
 * because a tool whose schema is not an object breaks client-side validation.
 *
 * @return array<string, mixed>
 */
function normalize_schema(mixed $schema): array
{
    if (!is_array($schema) || $schema === []) {
        return ['type' => 'object'];
    }

    return $schema;
}

/**
 * Execute a tool call under the modern revision.
 *
 * The guard order matches the legacy path exactly: safety profile, then rate
 * limit, then the ability's own permission callback, then execution. The
 * change ledger needs no wiring here because it hooks
 * `wp_before_execute_ability` / `wp_after_execute_ability` inside execute().
 *
 * @param array<string, mixed> $params
 * @return array{status: int, body: array<string, mixed>}
 */
function call_tool(array $params, mixed $id): array
{
    $name = is_string($params['name'] ?? null) ? $params['name'] : '';
    $ability = $name === '' ? null : ability_for_tool($name);

    if ($ability === null) {
        // The modern revision moved not-found onto -32602.
        return error_response(ERROR_INVALID_PARAMS, sprintf('Unknown tool: %s', $name), 200, $id);
    }

    $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

    if (function_exists('wppilot_safety_check_ability')) {
        $allowed = \wppilot_safety_check_ability($ability);
        if ($allowed instanceof WP_Error) {
            return tool_error($allowed, $id);
        }
        if ($allowed === false) {
            return tool_error(
                new WP_Error('wppilot_safety_blocked', 'The active safety profile does not allow this operation.'),
                $id,
            );
        }
    }

    // The confirmation contract lives in the safety layer, not in each ability.
    // On the legacy path it is applied by wppilot_safety_pre_mcp_tool_call(),
    // which is keyed to the adapter's meta-tool and therefore never fires here.
    // Reproducing it is not optional: without it a destructive ability that
    // relies on the profile-level gate rather than its own `confirm` field
    // would execute unconfirmed under the modern revision only.
    if (function_exists('wppilot_ability_requires_confirmation') && \wppilot_ability_requires_confirmation($ability)) {
        if (($arguments['confirm'] ?? null) !== true) {
            return tool_error(\wppilot_confirmation_required_error($ability), $id);
        }
    }

    // `confirm` is a control field, not ability input. Abilities that declare
    // their own `confirm` property keep it; for the rest it is removed before
    // execute(), whose schema validation rejects unknown properties.
    if (
        array_key_exists('confirm', $arguments)
        && function_exists('wppilot_ability_schema_has_property')
        && !\wppilot_ability_schema_has_property($ability, 'confirm')
    ) {
        unset($arguments['confirm']);
    }

    if (function_exists('wppilot_rate_pre_ability_execute')) {
        $limited = \wppilot_rate_pre_ability_execute($arguments, $ability, 'mcp');
        if ($limited instanceof WP_Error) {
            return tool_error($limited, $id);
        }
        if (is_array($limited)) {
            $arguments = $limited;
        }
    }

    // No separate permission call: WP_Ability::execute() normalizes the input,
    // validates it against the schema, runs check_permissions(), fires the
    // ledger's before/after hooks, and validates the output. Duplicating the
    // permission check here would run it twice and, worse, would drift from
    // core's contract the moment core changed it.
    // An ability that declares no input schema rejects any input, including an
    // empty array, so a no-argument call must pass null rather than []. Core
    // then skips validation and applies its own defaults.
    $result = $ability->execute($arguments === [] ? null : $arguments);
    if ($result instanceof WP_Error) {
        return tool_error($result, $id);
    }

    return success([
        'content' => [['type' => 'text', 'text' => encode_result($result)]],
        'structuredContent' => $result,
        'isError' => false,
    ], $id, 'tools/call');
}

/**
 * Render an ability result as the text block MCP clients display.
 */
function encode_result(mixed $result): string
{
    if (is_string($result)) {
        return $result;
    }

    $encoded = wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return is_string($encoded) ? $encoded : '';
}

/**
 * Report a failed tool call.
 *
 * Returned as a successful JSON-RPC result with `isError`, not a JSON-RPC
 * error: a tool that refuses is a normal outcome the model should read and act
 * on, whereas a JSON-RPC error means the call itself was malformed. Only the
 * error code and message cross the boundary, never the WP_Error data payload,
 * which can carry internal paths and query details.
 *
 * @return array{status: int, body: array<string, mixed>}
 */
function tool_error(WP_Error $error, mixed $id): array
{
    return success([
        'content' => [['type' => 'text', 'text' => $error->get_error_message()]],
        'structuredContent' => [
            'error' => $error->get_error_code(),
            'message' => $error->get_error_message(),
        ],
        'isError' => true,
    ], $id, 'tools/call');
}

/**
 * Register the dispatcher.
 */
function register_modern_transport(): void
{
    add_filter('rest_pre_dispatch', __NAMESPACE__ . '\\pre_dispatch', DISPATCH_PRIORITY, 3);
}
