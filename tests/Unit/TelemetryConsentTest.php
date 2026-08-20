<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPPilot_Test_State;

use function WPPilot\Telemetry\decided;
use function WPPilot\Telemetry\enabled;
use function WPPilot\Telemetry\install_id;
use function WPPilot\Telemetry\payload;
use function WPPilot\Telemetry\set_enabled;

use const WPPilot\Telemetry\CRON_HOOK;

/**
 * Consent for anonymous usage reporting.
 *
 * Reporting is on by default, so "off" has to be reliable in a way that "on"
 * does not: a default that fails open is a nuisance, a switch-off that fails
 * open is a broken promise made in an admin notice.
 *
 * The case that matters most is the one that looks least interesting — turning
 * it off on a site that has never touched the setting. That is the first-run
 * notice path, and it is the exact combination WordPress's update_option()
 * silently refuses to write.
 */
final class TelemetryConsentTest extends TestCase
{
    protected function setUp(): void
    {
        WPPilot_Test_State::$options = [];
        WPPilot_Test_State::$cron = [];
        WPPilot_Test_State::$http_posts = [];
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = [];
        WPPilot_Test_State::$cron = [];
        WPPilot_Test_State::$http_posts = [];
    }

    public function test_reporting_is_on_before_anyone_chooses(): void
    {
        self::assertTrue(enabled(), 'Reporting should be on by default.');
        self::assertFalse(decided(), 'An untouched option is not a decision.');
    }

    /**
     * The regression this file exists for.
     *
     * update_option() reads the current value with get_option(), which answers
     * false for an option that does not exist, then returns early when the new
     * value equals the old one. Writing boolean false to a never-set option
     * therefore stores nothing at all, and enabled() keeps answering true from
     * its default — the notice says "turned off" and the site keeps reporting.
     */
    public function test_turning_it_off_sticks_when_nobody_has_ever_chosen(): void
    {
        self::assertFalse(decided(), 'Precondition: the option must not exist yet.');

        set_enabled(false);

        self::assertTrue(decided(), 'Turning it off must record a decision.');
        self::assertFalse(enabled(), 'Turning it off must actually turn it off.');
    }

    /** And it must still be off on the next request, once the value is a string. */
    public function test_turning_it_off_survives_a_request_boundary(): void
    {
        set_enabled(false);
        WPPilot_Test_State::next_request();

        self::assertFalse(enabled(), 'The choice must survive the database round trip.');
        self::assertTrue(decided());
    }

    public function test_turning_it_on_survives_a_request_boundary(): void
    {
        set_enabled(true);
        WPPilot_Test_State::next_request();

        self::assertTrue(enabled());
        self::assertTrue(decided(), 'Choosing to keep it on is still a decision.');
    }

    /**
     * A recorded "off" has to survive reactivation.
     *
     * Activation runs again on every reactivation and on every new site added
     * to a network, so anything that re-enables on activation would quietly
     * undo the operator's choice on a schedule.
     */
    public function test_activation_does_not_re_enable_a_site_that_opted_out(): void
    {
        set_enabled(false);
        WPPilot_Test_State::next_request();

        \wppilot_telemetry_on_activate();

        self::assertFalse(enabled());
        self::assertFalse(
            \wp_next_scheduled(CRON_HOOK),
            'Activation must not schedule reports for a site that turned them off.',
        );
    }

    public function test_activation_schedules_within_the_jitter_window(): void
    {
        \wppilot_telemetry_on_activate();

        $next = \wp_next_scheduled(CRON_HOOK);
        self::assertIsInt($next);

        $hours = ($next - time()) / 3600;
        self::assertGreaterThanOrEqual(1, $hours, 'The first report must not fire immediately.');
        self::assertLessThanOrEqual(7, $hours);
    }

    /**
     * Turning it off is a deletion request, not just a stop.
     *
     * Without the final message, everything already collected stays where it
     * is and the operator has been misled about what "off" means.
     */
    public function test_opting_out_sends_one_final_deletion_request(): void
    {
        set_enabled(false);

        self::assertCount(1, WPPilot_Test_State::$http_posts);

        $sent = json_decode((string) WPPilot_Test_State::$http_posts[0]['body'], associative: true);
        self::assertSame('opt-out', $sent['event']);
    }

    /** Opting out twice must not send twice; there is nothing left to delete. */
    public function test_opting_out_again_sends_nothing(): void
    {
        set_enabled(false);
        WPPilot_Test_State::next_request();
        WPPilot_Test_State::$http_posts = [];

        set_enabled(false);

        self::assertSame([], WPPilot_Test_State::$http_posts);
    }

    public function test_the_install_id_is_stable_and_not_derived_from_the_url(): void
    {
        $first = install_id();
        WPPilot_Test_State::next_request();

        self::assertSame($first, install_id(), 'The identifier must not change between requests.');
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $first,
        );
        self::assertStringNotContainsString(
            'example.test',
            $first,
            'A URL-derived identifier would split one site into two when it moves to https.',
        );
    }

    /**
     * The report carries no personal data.
     *
     * Asserted on the key set rather than on values, so that adding a field
     * that carries personal data fails here rather than in a privacy review.
     */
    public function test_the_report_contains_only_the_declared_fields(): void
    {
        self::assertSame(
            [
                'install_id', 'event', 'site', 'version', 'pro', 'pro_version',
                'wp', 'php', 'locale', 'multisite', 'profile', 'abilities_enabled', 'connections',
            ],
            array_keys(payload('ping')),
        );
    }

    public function test_a_disabled_site_sends_nothing_on_the_schedule(): void
    {
        set_enabled(false);
        WPPilot_Test_State::next_request();
        WPPilot_Test_State::$http_posts = [];

        \WPPilot\Telemetry\send('ping');

        self::assertSame([], WPPilot_Test_State::$http_posts);
    }
}
