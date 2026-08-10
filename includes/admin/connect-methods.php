<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// Standalone-testable: allow require under PHP CLI without a WordPress bootstrap.
if (!defined('ABSPATH')) {
    exit();
}

/**
 * True when an IPv4/IPv6 literal is in a private, loopback, link-local, or reserved range.
 */
function wppilot_ip_is_private_or_loopback(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        // Fails validation (=== false) exactly when the address is private or reserved.
        return (
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            === false
        );
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $lower = strtolower($ip);
        return (
            $lower === '::1'
            || str_starts_with($lower, 'fe80:')
            || str_starts_with($lower, 'fc')
            || str_starts_with($lower, 'fd')
        );
    }
    return false;
}

/**
 * True when a host is only reachable from the local machine and never from the public
 * internet: single-label hosts, private/loopback IP literals, and local-dev suffixes.
 * Expects a bare host without a port.
 */
function wppilot_host_is_local_only(string $host): bool
{
    $host = strtolower(trim($host));
    $host = trim($host, characters: '[]');
    if ($host === '') {
        return false;
    }

    // IP literal → private/loopback ranges are local-only.
    $ip = filter_var($host, FILTER_VALIDATE_IP);
    if ($ip !== false) {
        return wppilot_ip_is_private_or_loopback($ip);
    }

    // Single-label host (no dot), e.g. "localhost" or "wordpress".
    if (!str_contains($host, '.')) {
        return true;
    }

    /** @var array<int, string> $suffixes */
    $suffixes = apply_filters('wppilot_local_only_host_patterns', [
        '.local',
        '.test',
        '.localhost',
        '.ddev.site',
        '.lndo.site',
    ]);
    foreach ($suffixes as $suffix) {
        if ($suffix !== '' && str_ends_with($host, $suffix)) {
            return true;
        }
    }

    return false;
}

/**
 * True when this site's host cannot be reached from Anthropic's cloud, so a native remote
 * connector will not work and the OAuth flow must run through a local stdio bridge.
 */
function wppilot_host_unreachable_from_cloud(): bool
{
    $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    return wppilot_host_is_local_only($host);
}

/**
 * True when it is safe to run the OAuth flow: the site is served over HTTPS, or WordPress reports a
 * local environment. On a plain-HTTP site the authorization code and the bearer tokens travel in
 * cleartext (RFC 6749/6750 require TLS), and "not reachable from the public internet" is not enough
 * on its own: a private-IP or *.local/*.test host still shares a LAN wire another device can sniff.
 * So HTTP is allowed only when wp_get_environment_type() is 'local' — the same transport policy
 * wp_is_application_passwords_supported() applies. Keyed off the canonical home_url() scheme, not
 * is_ssl(), so a TLS-terminating reverse proxy (https to the public, http to PHP) is not misjudged.
 */
function wppilot_oauth_transport_allowed(): bool
{
    if (str_starts_with(strtolower(home_url()), 'https://')) {
        return true;
    }
    return wp_get_environment_type() === 'local';
}

/**
 * Build the Claude "add custom connector" prefill link. Opens the dialog with the name and
 * URL pre-filled; the user still reviews and confirms. No secret is embedded.
 */
function wppilot_build_connector_install_link(string $mcp_url, string $connector_name): string
{
    return (
        'https://claude.ai/customize/connectors?modal=add-custom-connector'
        . '&connectorName='
        . rawurlencode($connector_name)
        . '&connectorUrl='
        . rawurlencode($mcp_url)
    );
}

/**
 * Human-readable connector name shown in Claude. Empty site name falls back to "WPPilot".
 */
function wppilot_build_connector_display_name(string $site_name): string
{
    $site_name = trim($site_name);
    return $site_name !== '' ? 'WPPilot - ' . $site_name : 'WPPilot';
}

/**
 * npx mcp-remote stdio bridge server object. The bridge runs the OAuth browser flow on behalf
 * of clients that do not perform it natively. $env carries the self-signed TLS bypass on local
 * sites and is empty on public ones.
 *
 * @param array<string, string> $env
 * @return array<string, mixed>
 */
function wppilot_oauth_bridge_server(string $mcp_url, array $env): array
{
    $server = ['command' => 'npx', 'args' => ['-y', 'mcp-remote', $mcp_url]];
    if ($env !== []) {
        $server['env'] = $env;
    }
    return $server;
}

/**
 * Wrap a per-client server object in its JSON envelope (mcpServers or servers).
 *
 * @param array<string, mixed> $server
 */
function wppilot_oauth_json(string $wrapper, string $mcp_name, array $server): string
{
    return (string) json_encode([$wrapper => [$mcp_name => $server]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/**
 * Build a plain code-snippet client entry (no shell, no connector button). The optional note is
 * extra HTML rendered under the snippet (e.g. a CLI alternative); empty means none.
 *
 * @param array<string, string> $paths
 * @return array<string, mixed>
 */
function wppilot_oauth_code_entry(string $code, string $hint, array $paths, string $note = ''): array
{
    return ['kind' => 'code', 'code' => $code, 'hint' => $hint, 'paths' => $paths, 'isShell' => false, 'note' => $note];
}

/**
 * "Add to <code>file</code>." hint, shared by the client config entries.
 */
function wppilot_oauth_add_to(string $file): string
{
    /* translators: %s: config file name wrapped in <code> tags */
    return sprintf(__('Add to %s.', domain: 'wppilot'), '<code>' . $file . '</code>');
}

/**
 * Codex config.toml snippet for the mcp-remote bridge (stdio launch, optional env block).
 *
 * @param array<string, string> $env
 */
function wppilot_oauth_bridge_codex(string $mcp_name, string $mcp_url, array $env): string
{
    $tq = static fn(string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    $lines = [
        '[mcp_servers.' . $mcp_name . ']',
        'command = "npx"',
        'args = ["-y", "mcp-remote", ' . $tq($mcp_url) . ']',
    ];
    if ($env !== []) {
        $lines[] = '';
        $lines[] = '[mcp_servers.' . $mcp_name . '.env]';
        foreach ($env as $key => $value) {
            $lines[] = $key . ' = ' . $tq($value);
        }
    }
    return implode("\n", $lines);
}

/**
 * `claude mcp add` command for the mcp-remote bridge (stdio launch, optional --env flags).
 *
 * @param array<string, string> $env
 */
function wppilot_oauth_bridge_claude_code(string $mcp_name, string $mcp_url, array $env): string
{
    $sq = static fn(string $v): string => "'" . str_replace(search: "'", replace: "'\\''", subject: $v) . "'";
    $parts = ['claude mcp add ' . $sq($mcp_name)];
    foreach ($env as $key => $value) {
        $parts[] = '--env ' . $key . '=' . $sq($value);
    }
    $parts[] = '-- npx -y mcp-remote ' . $sq($mcp_url);
    return implode(" \\\n  ", $parts);
}

/**
 * Steps for ChatGPT's developer-mode plugin: turn on developer mode, create a plugin, paste the
 * OAuth server URL. ChatGPT reaches the server from OpenAI's cloud, so this is only offered on a
 * publicly reachable site (see wppilot_build_oauth_configs()).
 *
 * @return list<array<string, string>>
 */
function wppilot_oauth_chatgpt_steps(string $mcp_name, string $mcp_url): array
{
    return [
        [
            'title' => __('Enable developer mode', domain: 'wppilot'),
            'body' => __(
                'In ChatGPT, open Settings, go to Security and login, and turn on Developer mode. This is the only way to add plugins that OpenAI has not reviewed, and yours will always be one of those: it connects directly to your own site, so it can never be published as a reviewed plugin. The warning ChatGPT shows is expected.',
                domain: 'wppilot',
            ),
        ],
        [
            'title' => __('Create a plugin', domain: 'wppilot'),
            'body' => __(
                'In Settings, open Plugins and press the button in the top right to create a new plugin. Give it this name, or one you’ll recognize with "WPPilot" in it:',
                domain: 'wppilot',
            ),
            'copy' => $mcp_name,
        ],
        [
            'title' => __('Enter the server URL', domain: 'wppilot'),
            'body' => __(
                'Paste the URL below as the Server URL, keep Authentication set to OAuth, tick "I understand and want to continue", and press Create. Then sign in when the browser opens.',
                domain: 'wppilot',
            ),
            'copy' => $mcp_url,
        ],
    ];
}

/**
 * A message-only client entry: no config, just an explanation. Used for a cloud client on a local
 * site, where the client's servers cannot reach the site so no working config exists.
 *
 * @return array<string, string>
 */
function wppilot_oauth_cloud_only_notice(string $client_label): array
{
    return [
        'kind' => 'notice',
        'message' => sprintf(
            /* translators: %s: the AI client name, e.g. ChatGPT */
            __(
                '%s connects to your site from its own servers, so it can only reach a site that is available over the public internet, not one that runs only on your local machine.',
                domain: 'wppilot',
            ),
            $client_label,
        ),
    ];
}

/**
 * OAuth per-client connection configs, mirroring the app-password client list.
 *
 * On a publicly reachable site each client gets its native remote-MCP form (a URL the client
 * connects to and runs OAuth against itself), except a tail of clients whose native OAuth flow
 * is not verified, which fall back to the mcp-remote stdio bridge. On a local site every client
 * uses the bridge (which also carries the self-signed TLS bypass), and the browser-only
 * Claude.ai target is omitted because a local URL is unreachable for it.
 *
 * ChatGPT is cloud-only like Claude.ai but always kept in the list: publicly it gets the
 * developer-mode plugin steps, locally a notice explaining it needs a public site.
 *
 * @return array<string, array<string, mixed>>
 */
function wppilot_build_oauth_configs(string $mcp_url, string $mcp_name): array
{
    // The TLS-verification bypass rides along only for a self-signed local certificate, the same
    // condition the app-password flow uses; a plain-HTTP local site or a trusted cert needs no flag.
    $env = wppilot_likely_self_signed_https() ? ['NODE_TLS_REJECT_UNAUTHORIZED' => '0'] : [];
    if (wppilot_host_unreachable_from_cloud()) {
        return (
            wppilot_build_oauth_bridge_configs($mcp_url, $mcp_name, $env)
            + ['chatgpt' => wppilot_oauth_cloud_only_notice('ChatGPT')]
        );
    }
    return (
        wppilot_build_oauth_public_configs($mcp_url, $mcp_name)
        + [
            'chatgpt' => [
                'kind' => 'code',
                'code' => '',
                'hint' => '',
                'paths' => [],
                'isShell' => false,
                'steps' => wppilot_oauth_chatgpt_steps($mcp_name, $mcp_url),
            ],
        ]
    );
}

/**
 * Public site: native form per client, with the mcp-remote bridge as the fallback for the tail
 * of clients whose native OAuth flow is not verified. A public host has a trusted certificate,
 * so the bridge needs no TLS bypass.
 *
 * @return array<string, array<string, mixed>>
 */
function wppilot_build_oauth_public_configs(string $mcp_url, string $mcp_name): array
{
    $bridge = wppilot_build_oauth_bridge_configs($mcp_url, $mcp_name, []);
    $tail = ['cline', 'roo-code', 'amazon-q', 'zed', 'kilo-code', 'opencode'];

    return array_merge(
        array_intersect_key($bridge, array_flip($tail)),
        wppilot_build_oauth_native_configs($mcp_url, $mcp_name),
    );
}

/**
 * Manual walkthrough for the Claude connector clients, which add remote MCP servers from the
 * Connectors UI rather than a config file. The server name keeps the placeholder that the page
 * script swaps for the live name.
 *
 * @return list<array<string, string>>
 */
function wppilot_oauth_connector_steps(string $app_label, string $mcp_name, string $mcp_url): array
{
    return [
        [
            'title' => __('Open Connectors', domain: 'wppilot'),
            /* translators: %s: the client name, e.g. Claude Desktop */
            'body' => sprintf(__('In %s, open Settings and go to Connectors.', domain: 'wppilot'), $app_label),
        ],
        [
            'title' => __('Add a custom connector', domain: 'wppilot'),
            'body' => __(
                'Click "Add custom connector" and give it this name, or one you’ll recognize with "WPPilot" in it:',
                domain: 'wppilot',
            ),
            'copy' => $mcp_name,
        ],
        [
            'title' => __('Enter the server URL', domain: 'wppilot'),
            'body' => __(
                'Paste the URL below and save. Leave the OAuth Client ID and Secret (under Advanced settings) empty, then sign in when the browser opens.',
                domain: 'wppilot',
            ),
            'copy' => $mcp_url,
        ],
    ];
}

/**
 * CLI alternative shown under the Codex config.toml snippet, for users who prefer the terminal to
 * editing the file. The server name is the placeholder swapped in client-side; both it and the URL
 * are HTML-escaped here because the note is rendered as raw markup, not through the escaping step
 * the config snippets pass through.
 */
function wppilot_oauth_codex_cli_note(string $mcp_name, string $mcp_url): string
{
    return sprintf(
        /* translators: 1: codex mcp add command, 2: codex mcp login command, both in <code> tags */
        __('Prefer the terminal? Run %1$s, then %2$s.', domain: 'wppilot'),
        '<code>codex mcp add ' . esc_html($mcp_name) . ' --url ' . esc_html($mcp_url) . '</code>',
        '<code>codex mcp login ' . esc_html($mcp_name) . '</code>',
    );
}

/**
 * CLI alternative shown under the local (bridge) Codex config.toml snippet. Unlike the public form,
 * this adds the stdio bridge command (npx mcp-remote), carrying the same TLS-bypass env the snippet
 * above uses, and needs no separate login step because the bridge runs the OAuth flow itself.
 *
 * @param array<string, string> $env
 */
function wppilot_oauth_codex_bridge_cli_note(string $mcp_name, string $mcp_url, array $env): string
{
    $cmd = 'codex mcp add ' . esc_html($mcp_name);
    foreach ($env as $key => $value) {
        $cmd .= ' --env ' . esc_html($key) . '=' . esc_html($value);
    }
    $cmd .= ' -- npx -y mcp-remote ' . esc_html($mcp_url);

    /* translators: %s: codex mcp add command in <code> tags */
    return sprintf(__('Prefer the terminal? Run %s.', domain: 'wppilot'), '<code>' . $cmd . '</code>');
}

/**
 * Antigravity CLI and IDE share the same current remote MCP schema and config locations.
 *
 * @return array<string, mixed>
 */
function wppilot_build_antigravity_oauth_entry(string $mcp_url, string $mcp_name): array
{
    return wppilot_oauth_code_entry(
        wppilot_oauth_json('mcpServers', $mcp_name, ['serverUrl' => $mcp_url]),
        wppilot_oauth_add_to('mcp_config.json'),
        [
            __('Global', domain: 'wppilot') => '~/.gemini/config/mcp_config.json',
            __('Workspace', domain: 'wppilot') => '.agents/mcp_config.json',
        ],
    );
}

/**
 * Native remote configs for clients that run the OAuth flow themselves. Claude Desktop and
 * Claude.ai share the custom-connector prefill (a button, not a snippet); Claude.ai is
 * public-only, which is why it never appears in the local (bridge) set.
 *
 * @return array<string, array<string, mixed>>
 */
function wppilot_build_oauth_native_configs(string $mcp_url, string $mcp_name): array
{
    $tq = static fn(string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    $connector = wppilot_build_connector_install_link(
        $mcp_url,
        wppilot_build_connector_display_name(get_bloginfo('name')),
    );
    $deeplink =
        'cursor://anysphere.cursor-deeplink/mcp/install?name='
        . $mcp_name
        . '&config='
        . base64_encode((string) json_encode(['url' => $mcp_url]));

    $connector_entry = static fn(string $hint): array => [
        'kind' => 'connector',
        'code' => '',
        'connector' => $connector,
        'hint' => $hint,
        'paths' => [],
        'isShell' => false,
    ];
    $antigravity_entry = wppilot_build_antigravity_oauth_entry($mcp_url, $mcp_name);

    return [
        'claude-code' => [
            'kind' => 'code',
            'code' => 'claude mcp add ' . $mcp_name . ' --transport http ' . $mcp_url,
            'hint' => __('Run in your terminal, then sign in when your browser opens.', domain: 'wppilot'),
            'paths' => [],
            'isShell' => true,
        ],
        // Claude Desktop adds remote servers from its own Connectors UI. The one-click button goes
        // through claude.ai (account level) and only reaches Desktop after a sync, so the in-app
        // walkthrough is the primary here rather than that button.
        'claude-desktop' => [
            'kind' => 'code',
            'code' => '',
            'hint' => '',
            'paths' => [],
            'isShell' => false,
            'steps' => wppilot_oauth_connector_steps('Claude Desktop', $mcp_name, $mcp_url),
        ],
        'claude-ai' => array_merge(
            $connector_entry(__(
                'Add it as a custom connector on claude.ai, then sign in. Works in the browser and in Claude Desktop.',
                domain: 'wppilot',
            )),
            ['steps' => wppilot_oauth_connector_steps('claude.ai', $mcp_name, $mcp_url)],
        ),
        'codex' => wppilot_oauth_code_entry(
            "[mcp_servers.{$mcp_name}]\nurl = " . $tq($mcp_url),
            sprintf(
                /* translators: 1: config.toml file name, 2: codex mcp login command, both in <code> tags */
                __('Add to %1$s. Then sign in with %2$s.', domain: 'wppilot'),
                '<code>config.toml</code>',
                '<code>codex mcp login</code>',
            ),
            ['macOS / Linux' => '~/.codex/config.toml', 'Windows' => '%USERPROFILE%\\.codex\\config.toml'],
            wppilot_oauth_codex_cli_note($mcp_name, $mcp_url),
        ),
        'cursor' => array_merge(
            wppilot_oauth_code_entry(
                wppilot_oauth_json('mcpServers', $mcp_name, ['url' => $mcp_url]),
                /* translators: %s: the config file name, wrapped in a code tag */
                sprintf(__('Use the one-click button, or add to %s.', domain: 'wppilot'), '<code>mcp.json</code>'),
                [
                    __('Global', domain: 'wppilot') => '~/.cursor/mcp.json',
                    __('Project', domain: 'wppilot') => '.cursor/mcp.json',
                ],
            ),
            ['deeplink' => $deeplink],
        ),
        'vscode' => wppilot_oauth_code_entry(
            wppilot_oauth_json('servers', $mcp_name, ['type' => 'http', 'url' => $mcp_url]),
            wppilot_oauth_add_to('mcp.json'),
            [
                __('Workspace', domain: 'wppilot') => '.vscode/mcp.json',
                __('User', domain: 'wppilot') => __(
                    'Run: MCP: Open User Configuration (command palette)',
                    domain: 'wppilot',
                ),
            ],
        ),
        'github-copilot' => wppilot_oauth_code_entry(
            wppilot_oauth_json('servers', $mcp_name, ['type' => 'http', 'url' => $mcp_url]),
            wppilot_oauth_add_to('mcp.json'),
            [__('Project', domain: 'wppilot') => '.github/copilot/mcp.json'],
        ),
        'antigravity-cli' => $antigravity_entry,
        'antigravity-ide' => $antigravity_entry,
        'windsurf' => wppilot_oauth_code_entry(
            wppilot_oauth_json('mcpServers', $mcp_name, ['serverUrl' => $mcp_url]),
            wppilot_oauth_add_to('mcp_config.json'),
            [
                'macOS / Linux' => '~/.codeium/windsurf/mcp_config.json',
                'Windows' => '%USERPROFILE%\\.codeium\\windsurf\\mcp_config.json',
            ],
        ),
    ];
}

/**
 * mcp-remote bridge configs for every client except the browser-only Claude.ai. Used for the
 * whole list on local sites and for the unverified tail on public sites. File paths mirror
 * wppilot_build_configs()'s locations, kept here so the OAuth flow stays isolated from the
 * proven app-password renderer.
 *
 * @param array<string, string> $env
 * @return array<string, array<string, mixed>>
 */
function wppilot_build_oauth_bridge_configs(string $mcp_url, string $mcp_name, array $env): array
{
    $server = wppilot_oauth_bridge_server($mcp_url, $env);

    return array_merge(
        wppilot_build_oauth_bridge_special($mcp_url, $mcp_name, $env, $server),
        wppilot_build_oauth_bridge_standard(
            wppilot_oauth_json('mcpServers', $mcp_name, $server),
            wppilot_oauth_json('servers', $mcp_name, $server),
        ),
    );
}

/**
 * Bridge entries whose format is unique to the client (shell command, TOML, or a bespoke JSON
 * envelope) rather than the shared mcpServers/servers payload.
 *
 * @param array<string, string> $env
 * @param array<string, mixed>  $server
 * @return array<string, array<string, mixed>>
 */
function wppilot_build_oauth_bridge_special(string $mcp_url, string $mcp_name, array $env, array $server): array
{
    $opts = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

    $zed_json = (string) json_encode([
        'context_servers' => [$mcp_name => array_merge(['source' => 'custom', 'enabled' => true], $server)],
    ], $opts);

    $opencode_server = ['type' => 'local', 'command' => ['npx', '-y', 'mcp-remote', $mcp_url]];
    if ($env !== []) {
        $opencode_server['environment'] = $env;
    }
    $opencode_json = (string) json_encode(['mcp' => [$mcp_name => $opencode_server]], $opts);

    return [
        'claude-code' => [
            'kind' => 'code',
            'code' => wppilot_oauth_bridge_claude_code($mcp_name, $mcp_url, $env),
            'hint' => __('Run in your terminal, then sign in when your browser opens.', domain: 'wppilot'),
            'paths' => [],
            'isShell' => true,
        ],
        'codex' => wppilot_oauth_code_entry(
            wppilot_oauth_bridge_codex($mcp_name, $mcp_url, $env),
            wppilot_oauth_add_to('config.toml'),
            ['macOS / Linux' => '~/.codex/config.toml', 'Windows' => '%USERPROFILE%\\.codex\\config.toml'],
            wppilot_oauth_codex_bridge_cli_note($mcp_name, $mcp_url, $env),
        ),
        'zed' => wppilot_oauth_code_entry($zed_json, wppilot_oauth_add_to('settings.json'), [
            'macOS / Linux' => '~/.config/zed/settings.json',
        ]),
        'opencode' => wppilot_oauth_code_entry($opencode_json, wppilot_oauth_add_to('opencode.json'), [
            __('Project', domain: 'wppilot') => 'opencode.json',
            __('Global', domain: 'wppilot') => '~/.config/opencode/opencode.json',
        ]),
    ];
}

/**
 * Bridge entries that reuse the shared mcpServers/servers JSON payloads. File paths mirror
 * wppilot_build_configs()'s locations.
 *
 * @return array<string, array<string, mixed>>
 */
function wppilot_build_oauth_bridge_standard(string $mcp_servers_json, string $servers_json): array
{
    return [
        'claude-desktop' => wppilot_oauth_code_entry(
            $mcp_servers_json,
            wppilot_oauth_add_to('claude_desktop_config.json'),
            [
                'macOS' => '~/Library/Application Support/Claude/claude_desktop_config.json',
                'Windows' => '%APPDATA%\\Claude\\claude_desktop_config.json',
            ],
        ),
        'antigravity-cli' => wppilot_oauth_code_entry($mcp_servers_json, wppilot_oauth_add_to('mcp_config.json'), [
            __('Global', domain: 'wppilot') => '~/.gemini/config/mcp_config.json',
            __('Workspace', domain: 'wppilot') => '.agents/mcp_config.json',
        ]),
        'antigravity-ide' => wppilot_oauth_code_entry($mcp_servers_json, wppilot_oauth_add_to('mcp_config.json'), [
            __('Global', domain: 'wppilot') => '~/.gemini/config/mcp_config.json',
            __('Workspace', domain: 'wppilot') => '.agents/mcp_config.json',
        ]),
        'cursor' => wppilot_oauth_code_entry($mcp_servers_json, wppilot_oauth_add_to('mcp.json'), [
            __('Global', domain: 'wppilot') => '~/.cursor/mcp.json',
            __('Project', domain: 'wppilot') => '.cursor/mcp.json',
        ]),
        'vscode' => wppilot_oauth_code_entry($servers_json, wppilot_oauth_add_to('mcp.json'), [
            __('Workspace', domain: 'wppilot') => '.vscode/mcp.json',
            __('User', domain: 'wppilot') => __(
                'Run: MCP: Open User Configuration (command palette)',
                domain: 'wppilot',
            ),
        ]),
        'github-copilot' => wppilot_oauth_code_entry($servers_json, wppilot_oauth_add_to('mcp.json'), [
            __('Project', domain: 'wppilot') => '.github/copilot/mcp.json',
        ]),
        'windsurf' => wppilot_oauth_code_entry($mcp_servers_json, wppilot_oauth_add_to('mcp_config.json'), [
            'macOS / Linux' => '~/.codeium/windsurf/mcp_config.json',
            'Windows' => '%USERPROFILE%\\.codeium\\windsurf\\mcp_config.json',
        ]),
        'cline' => wppilot_oauth_code_entry($mcp_servers_json, wppilot_oauth_add_to('cline_mcp_settings.json'), [
            __('Via UI', domain: 'wppilot') => __(
                'Cline sidebar → MCP Servers → Configure MCP Servers',
                domain: 'wppilot',
            ),
        ]),
        'roo-code' => wppilot_oauth_code_entry($mcp_servers_json, wppilot_oauth_add_to('mcp.json'), [
            __('Project', domain: 'wppilot') => '.roo/mcp.json',
            __('Via UI', domain: 'wppilot') => __(
                'Roo Code sidebar → MCP Servers → Configure MCP Servers',
                domain: 'wppilot',
            ),
        ]),
        'amazon-q' => wppilot_oauth_code_entry($mcp_servers_json, wppilot_oauth_add_to('mcp.json'), [
            __('Global', domain: 'wppilot') => '~/.aws/amazonq/mcp.json',
            __('Project', domain: 'wppilot') => '.amazonq/mcp.json',
        ]),
        'kilo-code' => wppilot_oauth_code_entry($mcp_servers_json, wppilot_oauth_add_to('mcp.json'), [
            __('Project', domain: 'wppilot') => '.kilocode/mcp.json',
            __('Via UI', domain: 'wppilot') => __(
                'Kilo Code sidebar → MCP Servers → Configure MCP Servers',
                domain: 'wppilot',
            ),
        ]),
    ];
}
