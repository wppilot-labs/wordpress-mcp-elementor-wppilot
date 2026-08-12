<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function WPPilot\Mcp\ability_for_tool;
use function WPPilot\Mcp\is_mcp_route;
use function WPPilot\Mcp\normalize_schema;
use function WPPilot\Mcp\tool_name;

/**
 * Routing and naming decisions the modern dispatcher makes before any I/O.
 */
final class TransportTest extends TestCase
{
    #[DataProvider('mcpRoutes')]
    public function testMcpRoutesAreClaimed(string $route): void
    {
        self::assertTrue(is_mcp_route($route));
    }

    /** @return array<string, array{0: string}> */
    public static function mcpRoutes(): array
    {
        return [
            'wppilot endpoint' => ['/mcp/wppilot'],
            'wppilot subpath' => ['/mcp/wppilot/messages'],
            'adapter default' => ['/mcp/mcp-adapter-default-server'],
            'adapter subpath' => ['/mcp/mcp-adapter-default-server/x'],
        ];
    }

    /**
     * The dispatcher must not intercept WPPilot's own REST surface or the OAuth
     * endpoints — those are ordinary routes with their own handlers.
     */
    #[DataProvider('nonMcpRoutes')]
    public function testUnrelatedRoutesAreLeftAlone(string $route): void
    {
        self::assertFalse(is_mcp_route($route));
    }

    /** @return array<string, array{0: string}> */
    public static function nonMcpRoutes(): array
    {
        return [
            'oauth' => ['/mcp/wppilot-oauth'],
            'oauth subpath' => ['/mcp/wppilot-oauth/token'],
            'wppilot rest' => ['/wppilot/v1/run'],
            'wp core' => ['/wp/v2/posts'],
            'prefix lookalike' => ['/mcp/wppilot-other'],
            'root' => ['/'],
        ];
    }

    /**
     * Tool names must match what the bundled adapter produces, or the same
     * ability would be called one name under legacy and another under modern.
     */
    #[DataProvider('toolNames')]
    public function testAbilityNamesMapToToolNames(string $ability, string $expected): void
    {
        self::assertSame($expected, tool_name($ability));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function toolNames(): array
    {
        return [
            'namespaced' => ['wppilot/create-post', 'wppilot_create_post'],
            'multi hyphen' => ['wppilot/list-menu-locations', 'wppilot_list_menu_locations'],
            'adapter meta tool' => ['mcp-adapter/discover-abilities', 'mcp_adapter_discover_abilities'],
        ];
    }

    public function testUnknownToolResolvesToNothing(): void
    {
        self::assertNull(ability_for_tool('definitely_not_a_tool'));
    }

    /**
     * A tool whose schema is not an object breaks client-side validation, so the
     * outer shape is guaranteed even when an ability declares nothing.
     */
    #[DataProvider('emptySchemas')]
    public function testEmptySchemaBecomesAnObjectSchema(mixed $schema): void
    {
        self::assertSame(['type' => 'object'], normalize_schema($schema));
    }

    /** @return array<string, array{0: mixed}> */
    public static function emptySchemas(): array
    {
        return [
            'null' => [null],
            'empty array' => [[]],
            'string' => ['nonsense'],
            'boolean' => [true],
        ];
    }

    /**
     * The revision loosened inputSchema to any JSON Schema 2020-12 keyword, so
     * an authored schema passes through unchanged rather than being filtered to
     * a known keyword list.
     */
    public function testAuthoredSchemaPassesThroughUnchanged(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
            'additionalProperties' => false,
            '$defs' => ['thing' => ['type' => 'string']],
            'unevaluatedProperties' => false,
        ];

        self::assertSame($schema, normalize_schema($schema));
    }
}
