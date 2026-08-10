<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Troubleshoot;

use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/checks.php';
require_once __DIR__ . '/client-ids.php';
require_once __DIR__ . '/rest.php';
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/notice.php';
require_once __DIR__ . '/admin.php';

/**
 * Record the moment an authenticated request reaches any WPPilot MCP route, per auth method.
 * The Troubleshoot page reads this to auto-detect which connection method to diagnose: a token
 * exchange is not proof the connection works end to end (MCP calls can still be blocked
 * upstream), the first authenticated MCP request is. Throttled to one option write per method
 * per minute.
 */
function record_mcp_request(mixed $result, mixed $server, WP_REST_Request $request): mixed
{
    if (!is_user_logged_in()) {
        return $result;
    }
    $method = mcp_route_method($request->get_route());
    if ($method === null) {
        return $result;
    }

    // Per-credential record for the Overview screen. Unthrottled on purpose: it
    // is a single upsert, and the request count is only useful if it counts.
    \wppilot_record_connection($method, get_current_user_id(), \wppilot_connection_client($request));

    // @mago-expect analysis:mixed-assignment
    $stored = get_option('wppilot_mcp_last_request', default_value: []);
    if (!is_array($stored)) {
        $stored = [];
    }
    // @mago-expect analysis:mixed-assignment
    $previous = $stored[$method] ?? 0;
    if (!is_int($previous)) {
        $previous = 0;
    }
    $now = time();
    if (($now - $previous) < MINUTE_IN_SECONDS) {
        return $result;
    }
    $stored[$method] = $now;
    update_option('wppilot_mcp_last_request', $stored, autoload: false);
    return $result;
}

/**
 * Map a REST route to the WPPilot auth method it implies: the dedicated OAuth server route,
 * or the canonical + legacy-alias routes used by Application Password clients. Non-MCP routes
 * return null. Matching mirrors WPPilot\OAuth\Middleware\is_mcp_route (exact slug or slash
 * suffix — `/mcp/wppilot-oauth` must never be treated as a `/mcp/wppilot` prefix match).
 *
 * @return 'oauth'|'password'|null
 */
function mcp_route_method(string $route): ?string
{
    if ($route === '/mcp/wppilot-oauth' || str_starts_with($route, '/mcp/wppilot-oauth/')) {
        return 'oauth';
    }
    if ($route === '/mcp/wppilot' || str_starts_with($route, '/mcp/wppilot/')) {
        return 'password';
    }
    if ($route === '/mcp/mcp-adapter-default-server' || str_starts_with($route, '/mcp/mcp-adapter-default-server/')) {
        return 'password';
    }
    return null;
}

// Priority 20: after the OAuth challenge filter (priority 10) so an unauthenticated OAuth
// request that got its 401 challenge never counts, and after core auth has set the user.
add_filter('rest_pre_dispatch', __NAMESPACE__ . '\\record_mcp_request', priority: 20, accepted_args: 3);
add_action('rest_api_init', __NAMESPACE__ . '\\Rest\\register_routes');
// Priority 20 places Troubleshoot right after the Connect group (Configuration + Abilities Hub,
// both at 10) and before Context (30). Diagnostics belong next to the connection they check.
add_action('admin_menu', __NAMESPACE__ . '\\Admin\\register_menu', priority: 20);
add_action('admin_notices', __NAMESPACE__ . '\\Notice\\maybe_render');
add_action('admin_init', __NAMESPACE__ . '\\Notice\\handle_dismiss');
