<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Every state-changing request on this screen verifies a nonce via check_admin_referer() before acting; the sniff cannot trace that across function boundaries. Reads are type-checked, whitelist-compared, and escaped on output.

/**
 * Configuration page: the Access token connection method.
 *
 * Minting, listing, and revoking the long-lived bearer tokens that let a caller
 * with no browser and no interactive session reach this site's MCP endpoint. The
 * secret is shown once, at creation, and only its digest is kept.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Expiry choices offered when minting, in days. 0 is "no expiry".
 *
 * @return array<int, string>
 */
function wppilot_token_expiry_choices(): array
{
    return [
        30 => __('30 days', domain: 'wppilot'),
        90 => __('90 days', domain: 'wppilot'),
        365 => __('1 year', domain: 'wppilot'),
        0 => __('No expiry', domain: 'wppilot'),
    ];
}

/**
 * Handle the create-token form submission.
 *
 * Returns the plaintext secret on success, a WP_Error on failure, or null when
 * there was no submission.
 *
 * @return string|WP_Error|null
 */
function wppilot_handle_create_token()
{
    if (($_POST['wppilot_create_token'] ?? null) === null) {
        return null;
    }

    if (!wppilot_current_user_can_manage()) {
        return new WP_Error('forbidden', __('You do not have permission to create access tokens.', domain: 'wppilot'));
    }

    check_admin_referer('wppilot_create_token');

    $raw_name = $_POST['wppilot_token_name'] ?? '';
    $name = is_string($raw_name) ? trim($raw_name) : '';

    $raw_ttl = $_POST['wppilot_token_ttl'] ?? '';
    $ttl = is_string($raw_ttl) || is_int($raw_ttl) ? (int) $raw_ttl : 0;
    // Whitelisted rather than clamped: an arbitrary posted number would let the
    // form mint a token with a lifetime the screen never offered.
    if (!array_key_exists($ttl, wppilot_token_expiry_choices())) {
        $ttl = 90;
    }

    $created = wppilot_token_create(get_current_user_id(), $name, $ttl);
    if ($created instanceof WP_Error) {
        return $created;
    }

    return $created['secret'];
}

/**
 * Handle the revoke-token form submission. Redirects on success.
 *
 * Called from admin_init, so headers have not been sent yet.
 */
function wppilot_handle_revoke_token(): void
{
    if (($_POST['wppilot_revoke_token'] ?? null) === null) {
        return;
    }

    if (!wppilot_current_user_can_manage()) {
        return;
    }

    $raw_id = $_POST['wppilot_revoke_token_id'] ?? '';
    $token_id = is_string($raw_id) || is_int($raw_id) ? (int) $raw_id : 0;
    if ($token_id <= 0) {
        return;
    }

    check_admin_referer('wppilot_revoke_token_' . $token_id);

    wppilot_token_revoke($token_id, get_current_user_id());

    wp_safe_redirect(admin_url('admin.php?page=wppilot-connect&wppilot_result=token_revoked'));
    exit();
}

/**
 * Whether the access-token method is pre-selected on load.
 *
 * True only when something happened on this method during this request, matching
 * how the application-password panel decides. A token that merely exists does not
 * pre-select the method: OAuth stays the recommendation.
 */
function wppilot_token_method_preselected(?string $new_token, ?WP_Error $token_error): bool
{
    return $new_token !== null || $token_error !== null;
}

/**
 * Render the access-token method panel: what it is for, the mint form, and the
 * one-time reveal of a freshly created secret.
 */
function wppilot_render_token_step(?string $new_token, ?WP_Error $token_error = null): void
{
    $has_tokens = wppilot_tokens_for_user(get_current_user_id()) !== [];
    ?>
    <p class="description" style="margin:0 0 12px;">
        <?php esc_html_e(
            'A long-lived bearer token for callers that cannot sign in through a browser: the Claude Messages API MCP connector, the OpenAI Responses API, a cron job, an automation platform, or any client that takes a URL and one header.',
            domain: 'wppilot',
        ); ?>
    </p>

    <?php if ($token_error !== null): ?>
        <div class="notice notice-error inline" style="margin:8px 0 16px;">
            <p style="margin:0;"><?php echo esc_html($token_error->get_error_message()); ?></p>
        </div>
    <?php endif; ?>

    <?php if ($new_token !== null): ?>
        <div class="notice notice-success inline" id="wppilot-token-created" style="margin:8px 0 16px;">
            <p style="margin:0 0 8px;"><strong><?php esc_html_e(
                'Copy this token now. It is shown once and cannot be recovered — only its digest is stored.',
                domain: 'wppilot',
            ); ?></strong></p>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <code id="wppilot-new-token-value" style="font-size:13px; font-weight:600; padding:6px 10px; background:#fff; border:1px solid #c3c4c7; border-radius:3px; word-break:break-all;"><?php echo
                    esc_html($new_token)
                ; ?></code>
                <button type="button" class="button button-small" onclick="wppilotCopy('wppilot-new-token-value', this)">
                    <?php esc_html_e('Copy token', domain: 'wppilot'); ?>
                </button>
            </div>
            <p style="margin:8px 0 0; color:#d63638; font-size:13px;">
                <?php esc_html_e(
                    'Anyone holding this token has the same access to this site as your account. Store it in a secret manager, never in a repository.',
                    domain: 'wppilot',
                ); ?>
            </p>
        </div>

        <?php

        /*
         * What to do with the token, said here rather than left to be found.
         *
         * The configuration for every client is generated further down the page,
         * and a token is only shown once — so someone who copies the value,
         * closes the page and goes looking for instructions has already lost the
         * one thing they needed. The three steps and the jump link are what turn
         * the reveal into the start of a setup rather than the end of a form.
         */
        ?>
        <div class="wppilot-token-next" style="margin:0 0 20px; padding:14px 16px; border:1px solid #c3c4c7; border-left:4px solid #2271b1; background:#fff;">
            <p style="margin:0 0 8px;"><strong><?php esc_html_e(
                'How to connect with this token',
                domain: 'wppilot',
            ); ?></strong></p>
            <ol style="margin:0 0 10px 18px; padding:0;">
                <li><?php printf(
                    /* translators: %s: the MCP endpoint URL, in a <code> tag */
                    esc_html__('Point your client at %s.', domain: 'wppilot'),
                    '<code>' . esc_html(rest_url('mcp/wppilot')) . '</code>',
                ); ?></li>
                <li><?php printf(
                    /* translators: %s: the Authorization header, in a <code> tag */
                    esc_html__('Send the token as %s on every request.', domain: 'wppilot'),
                    '<code>Authorization: Bearer ' . esc_html($new_token) . '</code>',
                ); ?></li>
                <li><?php esc_html_e(
                    'Restart the client. Most read their configuration only at startup.',
                    domain: 'wppilot',
                ); ?></li>
            </ol>
            <p style="margin:0;">
                <button type="button" class="button button-primary" onclick="wppilotJumpToTokenSnippets()"><?php esc_html_e(
                    'Show the ready-made configuration for my client',
                    domain: 'wppilot',
                ); ?></button>
                <span class="description" style="margin-left:8px;"><?php esc_html_e(
                    'Claude Code, Claude Desktop, Codex, Cursor, VS Code, the Claude and OpenAI APIs, curl, and more — each with the token already filled in.',
                    domain: 'wppilot',
                ); ?></span>
            </p>
        </div>
    <?php endif; ?>

    <form method="post" style="margin:0;">
        <?php wp_nonce_field('wppilot_create_token'); ?>
        <div style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end; margin:0 0 12px;">
            <div>
                <label for="wppilot-token-name" style="display:block; margin-bottom:4px;">
                    <strong><?php esc_html_e('Name', domain: 'wppilot'); ?></strong>
                </label>
                <input
                    type="text"
                    id="wppilot-token-name"
                    name="wppilot_token_name"
                    placeholder="<?php esc_attr_e('e.g. Messages API, nightly audit job', domain: 'wppilot'); ?>"
                    style="width:300px;"
                    class="regular-text"
                    maxlength="70"
                />
            </div>
            <div>
                <label for="wppilot-token-ttl" style="display:block; margin-bottom:4px;">
                    <strong><?php esc_html_e('Expires', domain: 'wppilot'); ?></strong>
                </label>
                <select id="wppilot-token-ttl" name="wppilot_token_ttl">
                    <?php foreach (wppilot_token_expiry_choices() as $days => $label): ?>
                        <option value="<?php echo esc_attr((string) $days); ?>"<?php echo
                            $days === 90 ? ' selected' : ''
                        ; ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button type="submit" name="wppilot_create_token" class="button button-primary">
            <?php echo
                $has_tokens
                    ? esc_html__('Create another access token', domain: 'wppilot')
                    : esc_html__('Create access token', domain: 'wppilot')
            ; ?>
        </button>
    </form>
    <?php
}

/**
 * Render the "Manage access tokens" collapsible section.
 *
 * Shown only when the current user holds at least one. Tokens are per-user by
 * design — the digest is bound to the account whose capabilities it borrows — so
 * this lists the current user's, exactly as the application-password section does.
 */
function wppilot_render_manage_tokens_section(): void
{
    $tokens = wppilot_tokens_for_user(get_current_user_id());
    if ($tokens === []) {
        return;
    }

    $dt_format = wppilot_get_datetime_format('Y-m-d H:i');
    $count = count($tokens);
    $summary = sprintf(
        /* translators: %d: count of existing access tokens */
        _n(single: 'Manage access token (%d)', plural: 'Manage access tokens (%d)', number: $count, domain: 'wppilot'),
        $count,
    );
    ?>
    <details class="wppilot-manage-passwords"<?php echo $count <= 3 ? ' open' : ''; ?>>
        <summary class="wppilot-manage-passwords-summary"><?php echo esc_html($summary); ?></summary>
        <div class="wppilot-manage-passwords-body">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Name', domain: 'wppilot'); ?></th>
                        <th style="width:110px;"><?php esc_html_e('Token', domain: 'wppilot'); ?></th>
                        <th style="width:150px;"><?php esc_html_e('Created', domain: 'wppilot'); ?></th>
                        <th style="width:150px;"><?php esc_html_e('Last Used', domain: 'wppilot'); ?></th>
                        <th style="width:150px;"><?php esc_html_e('Expires', domain: 'wppilot'); ?></th>
                        <th style="width:80px;"><?php esc_html_e('Actions', domain: 'wppilot'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tokens as $token): ?>
                        <?php wppilot_render_token_row($token, $dt_format); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
    <?php
}

/**
 * Render the client picker and config snippet for the access-token method.
 *
 * Every element id here is namespaced `wppilot-token-*`: the application-password
 * panel renders its own tab strip and code block on the same page, and a shared id
 * would make one panel's copy button read the other panel's snippet.
 */
function wppilot_render_token_config_section(string $url, ?string $token): void
{
    $default_name = wppilot_get_mcp_server_name_default();
    $name_placeholder = '__WPPILOT_MCP_NAME__';
    $display_token = $token ?? WPPILOT_TOKEN_PLACEHOLDER;
    $token_is_placeholder = $token === null;
    $configs = wppilot_build_token_configs($url, $name_placeholder, $display_token);

    // Labels come from the client registry so a client added there reaches this
    // strip too, with the API-side callers appended — they are not clients and
    // have no registry entry, but from here they are the same question.
    $labels = [];
    foreach (wppilot_selectable_clients() as $key => $client) {
        if (array_key_exists((string) $key, $configs)) {
            $labels[(string) $key] = (string) ($client['label'] ?? $key);
        }
    }
    foreach (wppilot_token_api_client_labels() as $key => $label) {
        if (array_key_exists($key, $configs)) {
            $labels[$key] = $label;
        }
    }

    $copied_label = __('Copied!', domain: 'wppilot');
    ?>
    <h2 class="wppilot-step-heading" id="wppilot-token-snippets">
        <span class="wppilot-step-badge">2</span>
        <?php esc_html_e('Connect Your AI Client', domain: 'wppilot'); ?>
    </h2>

    <?php if ($token_is_placeholder): ?>
        <div class="notice notice-warning inline" style="margin:12px 0;">
            <p style="margin:0;">
                <strong><?php esc_html_e('These snippets are not ready to use yet.', domain: 'wppilot'); ?></strong>
                <?php esc_html_e(
                    'They carry a placeholder because no access token has been created. Create one above and every snippet fills itself in.',
                    domain: 'wppilot',
                ); ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="wppilot-client-tabs" style="gap:8px; margin-top:16px; margin-bottom:0;">
    <?php foreach ($labels as $key => $label): ?>
        <button
            type="button"
            class="wppilot-client-tab wppilot-token-client-tab"
            data-client="<?php echo esc_attr($key); ?>"
            onclick="wppilotSetTokenClient('<?php echo esc_js($key); ?>', this)"
        ><?php echo esc_html($label); ?></button>
    <?php endforeach; ?>
    </div>

    <div id="wppilot-token-connect-content" style="display:none; margin-top:16px;">
        <ol id="wppilot-token-config-steps" style="display:none; margin:0 0 14px 20px; padding:0;"></ol>

        <div class="wppilot-tab-content" id="wppilot-token-config-tab" style="border-radius:4px;">
            <div class="wppilot-config-block">
                <pre id="wppilot-token-config-code"></pre>
                <button
                    type="button"
                    class="button wppilot-copy-btn"
                    onclick="wppilotCopy('wppilot-token-config-code', this)"
                ><?php esc_html_e('Copy', domain: 'wppilot'); ?></button>
            </div>
            <div id="wppilot-token-config-footer" style="font-size:13px; color:#666; border-top:1px solid #c3c4c7;">
                <div id="wppilot-token-config-merge-note" style="padding:10px 16px 0;">
                    <?php esc_html_e(
                        'If your config file already has content, merge this into it instead of replacing it.',
                        domain: 'wppilot',
                    ); ?>
                </div>
                <div id="wppilot-token-config-hint" style="padding:10px 16px;"></div>
                <div id="wppilot-token-config-paths" style="padding:0 16px 10px;"></div>
            </div>
        </div>

        <?php wppilot_render_agent_prompt_block('wppilot-token', method: 'token'); ?>

        <p style="margin:10px 0 0;">
            <label for="wppilot-token-mcp-name"><?php esc_html_e('Server name', domain: 'wppilot'); ?></label>
            <input
                type="text"
                id="wppilot-token-mcp-name"
                value="<?php echo esc_attr($default_name); ?>"
                maxlength="25"
                style="width:220px; margin-left:6px;"
                oninput="wppilotUpdateTokenName(this.value)"
            >
        </p>
    </div>

    <?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Every value emitted in this block goes through wppilot_script_json(), which hex-escapes <, >, & and quotes for <script> context. ?>
    <script>
    (function () {
        var configs = <?php echo wppilot_script_json($configs); ?>;
        var namePlaceholder = <?php echo wppilot_script_json($name_placeholder); ?>;
        var defaultName = <?php echo wppilot_script_json($default_name); ?>;
        var placeholderToken = <?php echo wppilot_script_json(WPPILOT_TOKEN_PLACEHOLDER); ?>;
        var isPlaceholder = <?php echo wppilot_script_json($token_is_placeholder); ?>;
        var mcpName = defaultName;
        var client = '';
        var labels = <?php echo wppilot_script_json($labels); ?>;
        var endpointUrl = <?php echo wppilot_script_json($url); ?>;
        var authLine = <?php echo wppilot_script_json(wppilot_agent_prompt_auth_line('token')); ?>;
        var tokenNotes = <?php echo wppilot_script_json(wppilot_agent_prompt_token_notes()); ?>;

        function renderSteps(steps) {
            var list = document.getElementById('wppilot-token-config-steps');
            list.innerHTML = '';
            steps.forEach(function (step) {
                var li = document.createElement('li');
                li.style.margin = '0 0 6px';
                // A step that is nothing but a URL, a token or a header value is
                // something to copy rather than something to read, so it is set
                // as code — which also stops a long token wrapping mid-word.
                if (/^(https?:\/\/|wpp_|Bearer )/.test(step)) {
                    var code = document.createElement('code');
                    code.style.wordBreak = 'break-all';
                    code.textContent = step;
                    li.appendChild(code);
                } else {
                    li.textContent = step;
                }
                list.appendChild(li);
            });
            list.style.display = '';
        }

        function render() {
            if (!client || !configs[client]) { return; }
            var cfg = configs[client];

            // A hosted web UI has no file to write, so it shows the click-through
            // steps instead of a snippet. Everything below the fork — the hint,
            // the agent prompt — is common to both.
            var hasSteps = !!(cfg.steps && cfg.steps.length);
            document.getElementById('wppilot-token-config-tab').style.display = hasSteps ? 'none' : '';
            document.getElementById('wppilot-token-config-steps').style.display = hasSteps ? '' : 'none';
            if (hasSteps) {
                renderSteps(cfg.steps);
                document.getElementById('wppilot-token-config-hint').innerHTML = cfg.hint;
                window.wppilotAgentPrompt('wppilot-token', {
                    clientLabel: labels[client] || client,
                    serverName: mcpName,
                    url: endpointUrl,
                    authLine: authLine,
                    code: '',
                    paths: {},
                    isShell: false,
                    steps: cfg.steps,
                    hasSecret: true,
                    notes: tokenNotes
                });
                return;
            }

            var codeEl = document.getElementById('wppilot-token-config-code');
            codeEl.textContent = cfg.code.split(namePlaceholder).join(mcpName);
            if (isPlaceholder) {
                codeEl.innerHTML = codeEl.innerHTML.split(placeholderToken).join(
                    '<span class="wppilot-placeholder">' + placeholderToken + '</span>'
                );
            }
            document.getElementById('wppilot-token-config-hint').innerHTML = cfg.hint;

            var mergeNote = document.getElementById('wppilot-token-config-merge-note');
            if (mergeNote) { mergeNote.style.display = cfg.isShell ? 'none' : ''; }

            // Same values the snippet above was just built from, so the prompt
            // cannot describe a different client than the one on screen.
            window.wppilotAgentPrompt('wppilot-token', {
                clientLabel: labels[client] || client,
                serverName: mcpName,
                url: endpointUrl,
                authLine: authLine,
                code: cfg.code.split(namePlaceholder).join(mcpName),
                paths: cfg.paths,
                isShell: cfg.isShell,
                hasSecret: true,
                notes: tokenNotes
            });

            var pathsEl = document.getElementById('wppilot-token-config-paths');
            var keys = Object.keys(cfg.paths);
            if (keys.length === 0) {
                pathsEl.innerHTML = '';
                pathsEl.style.display = 'none';
                return;
            }
            var html = '<ul style="margin:4px 0 0; padding-left:20px;">';
            keys.forEach(function (label) {
                html += '<li><strong>' + label + '</strong>: <code>' + cfg.paths[label] + '</code></li>';
            });
            pathsEl.innerHTML = html + '</ul>';
            pathsEl.style.display = '';
        }

        window.wppilotSetTokenClient = function (key, btn) {
            client = key;
            document.querySelectorAll('.wppilot-token-client-tab').forEach(function (tab) {
                tab.classList.remove('active');
            });
            btn.classList.add('active');
            var content = document.getElementById('wppilot-token-connect-content');
            if (content) { content.style.display = ''; }
            render();
        };

        window.wppilotUpdateTokenName = function (value) {
            mcpName = value.trim() || defaultName;
            render();
        };

        // Open the snippet area on the first client rather than waiting for a
        // click. An empty panel under a "Connect Your AI Client" heading reads as
        // "nothing here", which is how the configuration went unnoticed.
        var first = document.querySelector('.wppilot-token-client-tab');
        if (first) {
            window.wppilotSetTokenClient(first.getAttribute('data-client'), first);
        }

        // Called from the reveal box above, which is rendered in a different
        // container and cannot reach this scope any other way.
        window.wppilotJumpToTokenSnippets = function () {
            var heading = document.getElementById('wppilot-token-snippets');
            if (heading) {
                heading.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        };
    }());
    </script>
    <?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <?php
}

/**
 * Render one row of the access-token table.
 *
 * @param array{id: int, name: string, last_four: string, created: string, last_used: string, expires: string} $token
 */
function wppilot_render_token_row(array $token, string $dt_format): void
{
    $revoke_nonce = (string) wp_create_nonce('wppilot_revoke_token_' . $token['id']);
    $format = static function (string $stored, string $empty) use ($dt_format): string {
        if ($stored === '') {
            return $empty;
        }
        $timestamp = strtotime($stored . ' UTC');
        if ($timestamp === false) {
            return $empty;
        }
        $formatted = wp_date($dt_format, $timestamp);

        return $formatted !== false ? $formatted : $empty;
    };
    $expires = $token['expires'];
    $has_expired = $expires !== '' && (int) strtotime($expires . ' UTC') < time();
    ?>
    <tr>
        <td><strong><?php echo esc_html($token['name']); ?></strong></td>
        <td class="wppilot-mono">…<?php echo esc_html($token['last_four']); ?></td>
        <td><?php echo esc_html($format($token['created'], __('Unknown', domain: 'wppilot'))); ?></td>
        <td><?php echo esc_html($format($token['last_used'], __('Never', domain: 'wppilot'))); ?></td>
        <td>
            <?php echo esc_html($format($expires, __('Never', domain: 'wppilot'))); ?>
            <?php if ($has_expired): ?>
                <strong><?php esc_html_e('(expired)', domain: 'wppilot'); ?></strong>
            <?php endif; ?>
        </td>
        <td>
            <form method="post" style="margin:0;" onsubmit="return confirm('<?php echo
                esc_js(__('Revoke this token? Any caller using it will lose access.', domain: 'wppilot'))
            ; ?>');">
                <input type="hidden" name="wppilot_revoke_token_id" value="<?php echo
                    esc_attr((string) $token['id'])
                ; ?>" />
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($revoke_nonce); ?>" />
                <button type="submit" name="wppilot_revoke_token" class="button button-small wppilot-revoke-btn"><?php esc_html_e(
                    'Revoke',
                    domain: 'wppilot',
                ); ?></button>
            </form>
        </td>
    </tr>
    <?php
}
