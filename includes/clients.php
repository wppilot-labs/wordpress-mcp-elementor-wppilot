<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The AI clients WPPilot knows about, and how each one connects.
 *
 * Three separate places used to hold a partial answer to "which AI clients does
 * this support": the config-snippet builder knew fourteen clients but nothing
 * about how they authenticate, the OAuth pre-registration registry knew one, and
 * the connections table recorded credentials without ever naming the software
 * holding them. So the Overview screen could say a connection existed but not
 * what it was, and the Connect screen could only ever offer one way in.
 *
 * This is the single registry. Each entry answers three questions:
 *
 *   - Identity: what does this client call itself, so a live connection can be
 *     labelled "Claude Code" instead of a credential UUID? MCP clients send
 *     `clientInfo.name` on initialize, which is the authoritative signal; the
 *     User-Agent is the fallback for anything that does not.
 *
 *   - OAuth: can it run the browser sign-in itself? Clients differ, and the
 *     difference is not cosmetic. Clients without a verified native flow are
 *     routed through mcp-remote, which performs the OAuth dance out of process
 *     and speaks stdio to the client. Every client can therefore reach OAuth;
 *     only the route differs.
 *
 *   - Credentials: which of the three connection methods — OAuth, application
 *     password, access token — are actually available, so the Connect screen
 *     offers each client the ones that work rather than a generic list the user
 *     has to filter themselves. A client is listed for `token` only where it can
 *     send a static Authorization header — including three of the hosted web UIs,
 *     which now store one per connector. ChatGPT, Manus and the Codex desktop app
 *     have nowhere to put one and stay OAuth-only.
 *
 * The registry is split by that OAuth route rather than listed flat, so no entry
 * has to restate it and no entry can contradict the group it sits in. A client
 * is listed as running OAuth natively only where that has been verified;
 * otherwise it goes in the proxied group and is given the route that always
 * works. Being wrong in that direction costs a few seconds of npx startup; being
 * wrong in the other costs a failed connection with no explanation.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Clients that run the OAuth browser flow themselves against a remote MCP URL.
 *
 * @return array<string, array<string, mixed>>
 */
function wppilot_clients_native_oauth(): array
{
    return [
        'claude-code' => [
            'label' => 'Claude Code',
            'match' => ['claude-code', 'claude code'],
            'methods' => ['oauth', 'token', 'password'],
            'note' => __(
                'Add the server, then run /mcp inside Claude Code to complete the browser sign-in. With an access token there is no sign-in step, but /mcp still reports the server as needing authentication — a display bug that does not stop the tools working.',
                domain: 'wppilot',
            ),
        ],
        'claude-desktop' => [
            'label' => 'Claude Desktop',
            'match' => ['claude-ai', 'claude-desktop', 'claude desktop'],
            'methods' => ['oauth', 'bundle', 'token', 'password'],
            'note' => __(
                'Settings, then Connectors, then Add custom connector. The .mcpb bundle is the no-typing alternative.',
                domain: 'wppilot',
            ),
        ],
    ];
}

/**
 * The hosted web UIs.
 *
 * Split from the list above because they answer a different question. An editor
 * is told where its configuration file is; these are told which menu to open,
 * and they disagree — Customize, Settings, Apps, Connectors — often enough that
 * the wrong one is the whole reason a setup fails. They also all connect from
 * their own servers, so none of them can reach a site that is only up on the
 * operator's machine.
 *
 * @return array<string, array<string, mixed>>
 */
function wppilot_clients_web_ui(): array
{
    return [
        // Not all OAuth-only any more: claude.ai, Le Chat and Perplexity each
        // store a fixed Authorization header per connector, which is what makes
        // an access token usable from a browser at all. ChatGPT's developer mode
        // offers OAuth or no authentication and has no header field, and Manus
        // and the Codex app take OAuth client credentials rather than a header,
        // so those three stay OAuth-only.
        'claude-web' => [
            'label' => __('Claude (web)', domain: 'wppilot'),
            'match' => ['claude-web'],
            'methods' => ['oauth', 'token'],
            'note' => __(
                'Customize, then Connectors, then Add custom connector. Requires a public HTTPS site — claude.ai cannot reach localhost.',
                domain: 'wppilot',
            ),
        ],
        'chatgpt' => [
            'label' => 'ChatGPT',
            'match' => ['chatgpt', 'openai-chatgpt'],
            'methods' => ['oauth'],
            'note' => __(
                'Settings, Apps, Advanced settings, Developer mode — then Create app. Web only, and the site must be reachable over public HTTPS.',
                domain: 'wppilot',
            ),
        ],
        // Le Chat's connector dialog also accepts Basic, so an application
        // password would work there in principle. It is not listed: the
        // application-password method generates config files for local clients
        // and has no walkthrough form, so claiming it here would advertise a tab
        // that does not exist. Bearer is the shorter route to the same place.
        'mistral-lechat' => [
            'label' => 'Mistral Le Chat',
            'match' => ['mistral', 'le-chat', 'lechat'],
            'methods' => ['oauth', 'token'],
            'note' => __(
                'Connectors, Add Connector, Custom MCP Connector. The name is an identifier, so it takes no spaces.',
                domain: 'wppilot',
            ),
        ],
        'manus' => [
            'label' => 'Manus',
            'match' => ['manus'],
            'methods' => ['oauth'],
            'note' => __(
                'Settings, Connectors, Add connectors, Custom MCP, Direct configuration. Manus connects from its own servers, so this site must be reachable over public HTTPS.',
                domain: 'wppilot',
            ),
        ],
        'perplexity' => [
            'label' => 'Perplexity',
            'match' => ['perplexity', 'comet'],
            'methods' => ['oauth', 'token'],
            'note' => __(
                'Settings, then Connectors. Custom remote connectors need Pro, Max or Enterprise, and an HTTPS URL.',
                domain: 'wppilot',
            ),
        ],
        // The desktop app is the entry point OpenAI now recommends, and it adds
        // remote Streamable HTTP servers from a settings screen rather than a
        // config file — so it gets no snippet tab, like the other UI-driven
        // clients. It is listed separately from the CLI because the two are set
        // up in completely different places and a user following config.toml
        // instructions inside the app finds nothing to edit.
        'codex-app' => [
            'label' => __('Codex (desktop app)', domain: 'wppilot'),
            'match' => ['codex-app', 'codex desktop', 'codex-desktop'],
            'methods' => ['oauth'],
            'note' => __(
                'In the Codex desktop app open Settings from your account menu, add WPPilot as a remote MCP server, and approve the browser sign-in.',
                domain: 'wppilot',
            ),
        ],
    ];
}

/**
 * The rest of the natively-authenticating clients.
 *
 * @return array<string, array<string, mixed>>
 */
function wppilot_clients_native_oauth_editors(): array
{
    return [
        'codex' => [
            'label' => __('Codex CLI', domain: 'wppilot'),
            'match' => ['codex'],
            'methods' => ['oauth', 'token', 'password'],
            'note' => __('After adding the server, run codex mcp login to authorise it.', domain: 'wppilot'),
        ],
        'cursor' => [
            'label' => 'Cursor',
            'match' => ['cursor'],
            'methods' => ['oauth', 'token', 'password'],
            'note' => __(
                'Cursor marks the server "needs login" until you complete the browser sign-in.',
                domain: 'wppilot',
            ),
        ],
        'vscode' => [
            'label' => 'VS Code',
            'match' => ['visual studio code', 'vscode'],
            'methods' => ['oauth', 'token', 'password'],
            'note' => __('Requires VS Code 1.101 or later for remote MCP with OAuth.', domain: 'wppilot'),
        ],
        // Factory's Droid CLI discovers the authorization server, registers via
        // Dynamic Client Registration, and uses Factory's published client
        // metadata where the server supports it — so it reaches WPPilot's OAuth
        // natively. Servers are configured in ~/.factory/mcp.json, or per
        // project in .factory/mcp.json.
        'factory-droid' => [
            'label' => 'Factory Droid',
            'match' => ['factory', 'droid', 'factory-droid'],
            'methods' => ['oauth', 'token', 'password'],
            'note' => __('Add the server with /mcp inside Droid, or put it in ~/.factory/mcp.json.', domain: 'wppilot'),
        ],
        // Kimi Code CLI takes a whole server in one command, like Claude Code,
        // and stores it in a standard mcpServers file.
        'kimi-cli' => [
            'label' => __('Kimi Code CLI', domain: 'wppilot'),
            'match' => ['kimi', 'kimi-cli', 'kimi code'],
            'methods' => ['oauth', 'token', 'password'],
            'note' => __('Servers live in ~/.kimi/mcp.json, or add one with kimi mcp add.', domain: 'wppilot'),
        ],
        // Qwen Code and Gemini CLI share a lineage and a quirk: a remote server's
        // URL goes in `httpUrl`, not `url`. A snippet copied from any other
        // client's documentation therefore fails silently on both.
        'qwen-code' => [
            'label' => __('Qwen Code', domain: 'wppilot'),
            'match' => ['qwen-code', 'qwen code', 'qwencode'],
            'methods' => ['oauth', 'token', 'password'],
            'note' => __('Add to settings.json. Remote servers use "httpUrl", not "url".', domain: 'wppilot'),
        ],
        'gemini-cli' => [
            'label' => __('Gemini CLI', domain: 'wppilot'),
            'match' => ['gemini-cli', 'gemini cli'],
            'methods' => ['oauth', 'token', 'password'],
            'note' => __('Add to settings.json. Remote servers use "httpUrl", not "url".', domain: 'wppilot'),
        ],
        'zcode' => [
            'label' => __('ZCode (GLM)', domain: 'wppilot'),
            'match' => ['zcode', 'z.ai', 'zai', 'glm'],
            'methods' => ['oauth', 'token', 'password'],
            'note' => __(
                'Added through ZCode\'s own MCP server manager, which accepts stdio, HTTP and SSE.',
                domain: 'wppilot',
            ),
        ],
        'github-copilot' => [
            'label' => 'GitHub Copilot',
            'match' => ['github copilot', 'copilot'],
            'methods' => ['oauth', 'token', 'password'],
            'note' => __('Uses the VS Code MCP configuration.', domain: 'wppilot'),
        ],
        'antigravity-cli' => [
            'label' => 'Antigravity CLI',
            'match' => ['antigravity-cli', 'antigravity cli'],
            'methods' => ['oauth', 'token', 'password'],
            'note' => __('Add the server with /mcp, then complete the browser sign-in.', domain: 'wppilot'),
        ],
        'antigravity-ide' => [
            'label' => 'Antigravity IDE',
            'match' => ['antigravity-ide', 'antigravity ide', 'antigravity'],
            'methods' => ['oauth', 'token', 'password'],
            'note' => __(
                'Open MCP Servers in the IDE, add the server, then complete the browser sign-in.',
                domain: 'wppilot',
            ),
        ],
    ];
}

/**
 * Clients that reach OAuth through the mcp-remote wrapper.
 *
 * Either the client has no OAuth flow of its own, or it has not been verified to
 * have one. The wrapper performs the exchange out of process and speaks stdio to
 * the client, so the route works regardless.
 *
 * @return array<string, array<string, mixed>>
 */
function wppilot_clients_proxied_oauth(): array
{
    return [
        // Cognition rebranded Windsurf to Devin Desktop on 2 June 2026 and
        // redirected windsurf.com to devin.ai/desktop. Existing installs keep
        // identifying themselves as "windsurf" (and older Codeium builds as
        // "codeium"), and the rebrand ported MCP connections rather than
        // resetting them, so both old identifiers stay matched — dropping them
        // would turn every already-connected editor into an unlabelled row on
        // the Overview screen.
        // The registry key stays `windsurf`: it is the lookup key for the
        // generated config snippet, the OAuth panel, and the pre-registered
        // OAuth client allowlist, and it is recorded against existing
        // connections. Renaming it would orphan all of those. Only what the
        // rebrand actually changed — the display name and the identifiers the
        // client reports — moves.
        'windsurf' => [
            'label' => 'Devin Desktop (Windsurf)',
            'match' => ['devin-desktop', 'devin desktop', 'devin', 'windsurf', 'codeium'],
            'methods' => ['oauth', 'token', 'password'],
            'note' => __(
                'MCP configuration still lives at the Windsurf path, and remote servers use "serverUrl" rather than "url".',
                domain: 'wppilot',
            ),
        ],
        'zed' => [
            'label' => 'Zed',
            'match' => ['zed'],
            'methods' => ['oauth', 'token', 'password'],
            'note' => __('Configured as a context server.', domain: 'wppilot'),
        ],
        'cline' => [
            'label' => 'Cline',
            'match' => ['cline'],
            'methods' => ['oauth', 'token', 'password'],
            'note' => '',
        ],
        // Self-hosted Node agent with native MCP client support since early
        // 2026. It runs on the operator's own machine or server, so the
        // mcp-remote proxy this group uses is available to it.
        'openclaw' => [
            'label' => 'OpenClaw',
            'match' => ['openclaw', 'open-claw'],
            'methods' => ['oauth', 'token', 'password'],
            'note' => __('Add the server to your OpenClaw MCP configuration and restart the agent.', domain: 'wppilot'),
        ],
        // The extension was discontinued on 15 May 2026 and its repository
        // archived, with Cline named as the migration target. It stays in the
        // registry because installs already in the field keep working and keep
        // identifying themselves — dropping the entry would turn a live
        // connection into an unlabelled row — but the note says so, since anyone
        // reading this screen to set up a new client should not start here.
        'roo-code' => [
            'label' => 'Roo Code',
            'match' => ['roo-code', 'roo code', 'roocode'],
            'methods' => ['oauth', 'token', 'password'],
            'note' => __(
                'Discontinued in May 2026. Existing installs still connect; for a new setup use Cline.',
                domain: 'wppilot',
            ),
        ],
        'kilo-code' => [
            'label' => 'Kilo Code',
            'match' => ['kilo-code', 'kilo code', 'kilocode'],
            'methods' => ['oauth', 'token', 'password'],
            'note' => '',
        ],
        'amazon-q' => [
            'label' => 'Amazon Q',
            'match' => ['amazon-q', 'amazon q', 'amazonq'],
            'methods' => ['oauth', 'token', 'password'],
            'note' => '',
        ],
        'opencode' => [
            'label' => 'OpenCode',
            'match' => ['opencode'],
            'methods' => ['oauth', 'token', 'password'],
            'note' => '',
        ],
    ];
}

/**
 * Not clients but connection shapes.
 *
 * A proxy introduces itself under its own name, so without an entry a connection
 * routed through one would show as unidentified. They are hidden from the
 * pick-a-client lists because nobody chooses to "connect the proxy" — the proxy
 * is an implementation detail of connecting the real client.
 *
 * @return array<string, array<string, mixed>>
 */
function wppilot_client_proxy_shapes(): array
{
    return [
        'mcp-remote' => [
            'label' => __('mcp-remote proxy', domain: 'wppilot'),
            'match' => ['mcp-remote'],
            'oauth' => 'native',
            'methods' => ['oauth'],
            'note' => __('An AI client connecting through the OAuth wrapper.', domain: 'wppilot'),
            'hidden' => true,
        ],
        'wordpress-remote' => [
            'label' => __('WordPress MCP proxy', domain: 'wppilot'),
            'match' => ['mcp-wordpress-remote', 'wordpress-remote'],
            'oauth' => 'proxy',
            'methods' => ['password'],
            'note' => __('An AI client connecting with an application password.', domain: 'wppilot'),
            'hidden' => true,
        ],
    ];
}

/**
 * Stamp a group of clients with the OAuth route they all share.
 *
 * The route lives on the group rather than on each entry, so an entry cannot
 * claim a capability that contradicts the list it was put in.
 *
 * @param array<string, array<string, mixed>> $group
 * @return array<string, array<string, mixed>>
 */
function wppilot_clients_with_oauth_route(array $group, string $oauth): array
{
    $stamped = [];
    foreach ($group as $key => $client) {
        $client['oauth'] = $oauth;
        $stamped[(string) $key] = $client;
    }

    return $stamped;
}

/**
 * Every AI client WPPilot can describe a connection for.
 *
 * @return array<string, array<array-key, mixed>>
 */
function wppilot_clients(): array
{
    $clients = array_merge(
        wppilot_clients_with_oauth_route(wppilot_clients_native_oauth(), oauth: 'native'),
        wppilot_clients_with_oauth_route(wppilot_clients_web_ui(), oauth: 'native'),
        wppilot_clients_with_oauth_route(wppilot_clients_native_oauth_editors(), oauth: 'native'),
        wppilot_clients_with_oauth_route(wppilot_clients_proxied_oauth(), oauth: 'proxy'),
        wppilot_client_proxy_shapes(),
    );

    /**
     * Filter the AI client registry.
     *
     * @param array<string, array<string, mixed>> $clients Client key to definition.
     */
    /** @var mixed $filtered */
    $filtered = apply_filters('wppilot_clients', $clients);
    if (!is_array($filtered)) {
        return $clients;
    }

    // Rebuilt rather than returned as-is: a filter may hand back anything, and
    // every caller here assumes string keys mapping to arrays.
    $safe = [];
    /** @var mixed $client */
    foreach ($filtered as $key => $client) {
        if (is_array($client)) {
            $safe[(string) $key] = $client;
        }
    }

    return $safe;
}

/**
 * The clients a user can pick from, in registry order.
 *
 * @return array<string, array<array-key, mixed>>
 */
function wppilot_selectable_clients(): array
{
    return array_filter(wppilot_clients(), static fn(array $client): bool => ($client['hidden'] ?? false) !== true);
}

/**
 * Every (needle, client key) pair, longest needle first.
 *
 * Longest-first matters: "cursor-vscode" must be claimed by Cursor rather than
 * by the shorter "vscode" needle.
 *
 * @return list<array{needle: string, key: string}>
 */
function wppilot_client_needles(): array
{
    /** @var list<array{needle: string, key: string}> $needles */
    $needles = [];

    foreach (wppilot_clients() as $key => $client) {
        /** @var mixed $matches */
        $matches = $client['match'] ?? [];
        if (!is_array($matches)) {
            continue;
        }
        /** @var mixed $needle */
        foreach ($matches as $needle) {
            $needles[] = ['needle' => strtolower((string) $needle), 'key' => (string) $key];
        }
    }

    usort($needles, static fn(array $a, array $b): int => strlen($b['needle']) <=> strlen($a['needle']));

    return $needles;
}

/**
 * Resolve a self-reported client name to a registry key.
 *
 * Matching is substring and case-insensitive because clients report themselves
 * inconsistently — "claude-ai", "Claude Code", "cursor-vscode" — and version
 * suffixes are common.
 */
function wppilot_client_key(string $reported): ?string
{
    $haystack = strtolower(trim($reported));
    if ($haystack === '') {
        return null;
    }

    foreach (wppilot_client_needles() as $candidate) {
        if (str_contains($haystack, $candidate['needle'])) {
            return $candidate['key'];
        }
    }

    return null;
}

/**
 * The display name for a self-reported client.
 *
 * An unrecognised client keeps the name it gave rather than being flattened to
 * "Unknown": a new AI client should show up as itself the day it appears, not
 * wait for this registry to learn about it.
 */
function wppilot_client_label(string $reported): string
{
    $key = wppilot_client_key($reported);
    if ($key !== null) {
        return (string) (wppilot_clients()[$key]['label'] ?? $reported);
    }

    $trimmed = trim($reported);

    return $trimmed !== '' ? $trimmed : __('Unidentified client', domain: 'wppilot');
}
