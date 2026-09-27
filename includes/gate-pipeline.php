<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Run every control an ability call must pass before WP_Ability::execute().
 *
 * Each execution path used to carry its own copy of this sequence: the modern MCP transport, the
 * REST shim, the core-run gate and Pro's approval replay each reproduced the profile check, the
 * confirmation contract and the confirm strip by hand, and the modern transport then called the
 * rate limiter and preview gate directly because it had no refusable filter. Chat carried none of
 * it and called execute() straight away, so a Chat write skipped the rate limit, the design and
 * preview gates and anything a companion plugin had added. Every copy was a place for the next
 * control to be forgotten, which is how Chat fell behind.
 *
 * The order is fixed:
 *
 * 1. Safety profile. Refused before anything else, so a call the profile forbids never reaches a
 *    rate bucket or an approval queue.
 * 2. Confirmation. A destructive or critical ability needs `confirm: true`, unless the caller says
 *    a human already approved this exact call in wp-admin (`human_approved`) — Chat's approve
 *    button and Pro's approval queue. A model can set `confirm` itself; it cannot set that context.
 * 3. Confirm strip. `confirm` is a transport control, not ability input, and is removed unless the
 *    ability declares its own property of that name, because schema validation rejects unknown
 *    members.
 * 4. `wppilot_pre_ability_execute`. Rate limit (6), design gate (7), preview gate (8) and Pro's
 *    approval and lapsed-licence holds (9) all attach here, on every transport.
 *
 * The ability's own permission callback is not called here: execute() runs it, and running it
 * twice would drift from core's contract the moment core changed it.
 *
 * @param string                     $transport `rest`, `mcp`, `chat`, `approval` or `nested` (an
 *                                              ability a kit ability runs on the caller's behalf, e.g.
 *                                              on another network site). Filters use it to decide
 *                                              what applies; the approval queue, for one, only holds
 *                                              `rest` and `mcp` calls.
 * @param array{human_approved?: bool} $context
 * @return mixed The input to pass to execute(), or a WP_Error explaining the refusal.
 */
function wppilot_gate_ability_call(WP_Ability $ability, mixed $input, string $transport, array $context = []): mixed
{
    $allowed = wppilot_safety_check_ability($ability);
    if ($allowed instanceof WP_Error) {
        return $allowed;
    }

    $values = is_array($input) ? $input : [];
    if (
        ($context['human_approved'] ?? false) !== true
        && wppilot_ability_requires_confirmation($ability)
        && ($values['confirm'] ?? null) !== true
    ) {
        return wppilot_confirmation_required_error($ability);
    }

    if (
        is_array($input)
        && array_key_exists(key: 'confirm', array: $input)
        && !wppilot_ability_schema_has_property($ability, property: 'confirm')
    ) {
        unset($input['confirm']);
    }

    /**
     * Give transport-neutral execution controls one final chance to refuse or transform the call.
     *
     * Runs on every WPPilot execution path — the REST shim, WordPress core's run endpoint, the
     * modern MCP transport, Chat and Pro's approval replay — after the safety profile and the
     * confirmation contract. The legacy MCP adapter keeps its own `mcp_adapter_pre_tool_call`,
     * because it receives an adapter envelope rather than ability input.
     *
     * @param mixed                        $input     Validated ability input, `confirm` removed.
     * @param WP_Ability                   $ability   Ability about to execute.
     * @param string                       $transport Execution transport identifier.
     * @param array{human_approved?: bool} $context   What the caller vouches for.
     */
    // @mago-expect analysis:mixed-assignment -- Filters preserve the Ability's declared input type or return WP_Error.
    // @mago-expect lint:literal-named-argument -- WordPress filter arguments after value are variadic.
    return apply_filters('wppilot_pre_ability_execute', $input, $ability, $transport, $context);
}
