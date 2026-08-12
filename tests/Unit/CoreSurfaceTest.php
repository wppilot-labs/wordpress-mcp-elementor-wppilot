<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_Post;
use WPPilot_Test_State;

use function WPPilot\Abilities\WordPress\wordpress_core_permission;
use function WPPilot\Abilities\WordPress\wordpress_core_permission_for;
use function WPPilot\Abilities\WordPress\wordpress_revision_is_autosave;
use function WPPilot\Abilities\WordPress\wordpress_taxonomy_is_agent_facing;
use function WPPilot\Abilities\WordPress\wordpress_unsafe_url_error;
use function WPPilot\Abilities\WordPress\wordpress_update_status_capability_error;

/**
 * The WordPress-core surface: what registers, and the gates around it.
 */
final class CoreSurfaceTest extends TestCase
{
    protected function setUp(): void
    {
        WPPilot_Test_State::reset();
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::reset();
    }

    // ------------------------------------------------------------ registration

    /**
     * The module came up during bootstrap against WordPress doubles only. No Pro
     * class, licence check, or entitlement service exists in that environment,
     * so a successful load is itself the proof that Free stands alone.
     */
    public function testCoreSurfaceRegistersWithFreeAlone(): void
    {
        self::assertNotEmpty(WPPILOT_TEST_BOOT_ABILITIES);
        self::assertCount(50, WPPILOT_TEST_BOOT_ABILITIES);
    }

    public function testNoAbilityIsRegisteredTwice(): void
    {
        $names = WPPILOT_TEST_BOOT_ABILITIES;

        self::assertSame(
            array_values(array_unique($names)),
            array_values($names),
            'Duplicate ability registration: ' . implode(
                ', ',
                array_keys(array_filter(array_count_values($names), static fn(int $n): bool => $n > 1)),
            ),
        );
    }

    /**
     * Every domain the WordPress-core requirement covers must be present.
     */
    #[DataProvider('requiredAbilities')]
    public function testRequiredAbilityIsRegistered(string $ability): void
    {
        self::assertContains($ability, WPPILOT_TEST_BOOT_ABILITIES);
    }

    /** @return array<string, array{0: string}> */
    public static function requiredAbilities(): array
    {
        $names = [
            // Content.
            'wppilot/list-content', 'wppilot/get-content', 'wppilot/search-content',
            'wppilot/create-post', 'wppilot/update-post', 'wppilot/delete-post', 'wppilot/restore-post',
            // Taxonomies.
            'wppilot/list-taxonomies', 'wppilot/list-terms', 'wppilot/get-term',
            'wppilot/create-term', 'wppilot/update-term', 'wppilot/delete-term', 'wppilot/assign-terms',
            // Media.
            'wppilot/list-media', 'wppilot/get-media', 'wppilot/import-media-url',
            'wppilot/update-media', 'wppilot/delete-media',
            'wppilot/set-featured-image', 'wppilot/remove-featured-image',
            'wppilot/attach-media', 'wppilot/detach-media',
            // Comments.
            'wppilot/list-comments', 'wppilot/get-comment', 'wppilot/create-comment',
            'wppilot/update-comment', 'wppilot/moderate-comment', 'wppilot/delete-comment',
            // Menus.
            'wppilot/list-menus', 'wppilot/list-menu-items', 'wppilot/upsert-menu-item',
            'wppilot/delete-menu-item', 'wppilot/create-menu', 'wppilot/update-menu',
            'wppilot/delete-menu', 'wppilot/list-menu-locations', 'wppilot/assign-menu-location',
            'wppilot/reorder-menu-items',
            // Revisions.
            'wppilot/list-revisions', 'wppilot/get-revision', 'wppilot/restore-revision',
            // Users and settings.
            'wppilot/list-users', 'wppilot/get-user',
            'wppilot/get-site-settings', 'wppilot/update-site-settings',
        ];

        return array_combine($names, array_map(static fn(string $n): array => [$n], $names));
    }

    /**
     * Every registration must name the shared category, or it lands ungrouped in
     * the Abilities screen and cannot be switched off with the rest of the module.
     */
    public function testEveryCoreAbilityUsesTheWordPressCategory(): void
    {
        // Re-run one registration to inspect the shape the module produces.
        $wrong = [];
        foreach (WPPilot_Test_State::$registrations as $registration) {
            if (($registration['args']['category'] ?? null) !== 'wordpress') {
                $wrong[] = $registration['name'];
            }
        }

        self::assertSame([], $wrong);
    }

    // -------------------------------------------------------- permission gate

    public function testCorePermissionRequiresLoginAndEnabledPlugin(): void
    {
        self::assertTrue(wordpress_core_permission());
    }

    /**
     * Switching WPPilot off must close the surface. Without this the abilities
     * would keep executing after an administrator disables the plugin.
     */
    public function testCorePermissionIsClosedWhenWppilotIsDisabled(): void
    {
        WPPilot_Test_State::$wppilot_enabled = false;

        self::assertFalse(wordpress_core_permission());
    }

    public function testCorePermissionIsClosedWhenLoggedOut(): void
    {
        WPPilot_Test_State::$logged_in = false;

        self::assertFalse(wordpress_core_permission());
    }

    public function testCapabilityScopedPermissionStillHonoursTheDisabledSwitch(): void
    {
        WPPilot_Test_State::$capabilities = ['moderate_comments'];
        self::assertTrue(wordpress_core_permission_for('moderate_comments'));

        WPPilot_Test_State::$wppilot_enabled = false;
        self::assertFalse(wordpress_core_permission_for('moderate_comments'));
    }

    public function testCapabilityScopedPermissionRefusesMissingCapability(): void
    {
        self::assertFalse(wordpress_core_permission_for('moderate_comments'));
    }

    // ------------------------------------------------------- taxonomy exposure

    public function testPublicTaxonomyIsAgentFacing(): void
    {
        WPPilot_Test_State::add_taxonomy('category');

        self::assertNull(wordpress_taxonomy_is_agent_facing('category'));
    }

    #[DataProvider('internalTaxonomies')]
    public function testInternalTaxonomiesAreRejected(string $taxonomy): void
    {
        WPPilot_Test_State::add_taxonomy($taxonomy);

        $error = wordpress_taxonomy_is_agent_facing($taxonomy);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('taxonomy_not_agent_facing', $error->get_error_code());
    }

    /** @return array<string, array{0: string}> */
    public static function internalTaxonomies(): array
    {
        return [
            'nav_menu' => ['nav_menu'],
            'link_category' => ['link_category'],
            'wp_theme' => ['wp_theme'],
            'wp_template_part_area' => ['wp_template_part_area'],
            'wp_pattern_category' => ['wp_pattern_category'],
        ];
    }

    public function testPrivateTaxonomyIsRejected(): void
    {
        WPPilot_Test_State::add_taxonomy('acme_private', public: false, show_in_rest: false);

        $error = wordpress_taxonomy_is_agent_facing('acme_private');

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('taxonomy_not_public', $error->get_error_code());
    }

    public function testUnregisteredTaxonomyIsRejected(): void
    {
        $error = wordpress_taxonomy_is_agent_facing('nope');

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('invalid_taxonomy', $error->get_error_code());
    }

    // --------------------------------------------------------- URL safety

    /**
     * A menu item's URL is rendered into an href, so a script-bearing scheme
     * there is stored XSS.
     */
    #[DataProvider('unsafeUrls')]
    public function testUnsafeUrlSchemesAreRejected(string $url): void
    {
        $error = wordpress_unsafe_url_error($url);

        self::assertInstanceOf(WP_Error::class, $error, sprintf('Expected "%s" to be refused.', $url));
        self::assertSame(422, $error->get_error_data()['status']);
    }

    /** @return array<string, array{0: string}> */
    public static function unsafeUrls(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'javascript uppercase' => ['JavaScript:alert(1)'],
            'javascript mixed case' => ['JaVaScRiPt:alert(document.cookie)'],
            'data uri' => ['data:text/html;base64,PHNjcmlwdD4='],
            'vbscript' => ['vbscript:msgbox(1)'],
            'file' => ['file:///etc/passwd'],
        ];
    }

    #[DataProvider('safeUrls')]
    public function testSafeUrlsAreAccepted(string $url): void
    {
        self::assertNull(wordpress_unsafe_url_error($url), sprintf('Expected "%s" to be accepted.', $url));
    }

    /** @return array<string, array{0: string}> */
    public static function safeUrls(): array
    {
        return [
            'https' => ['https://example.com/page'],
            'http' => ['http://example.com/'],
            'mailto' => ['mailto:hello@example.com'],
            'tel' => ['tel:+15551234'],
            'root relative' => ['/about-us'],
            'relative' => ['about-us'],
            'anchor' => ['#section'],
            'empty' => [''],
        ];
    }

    // ------------------------------------------------ update publish gating

    private function post(string $status = 'draft', string $type = 'post'): WP_Post
    {
        $post = new WP_Post();
        $post->ID = 12;
        $post->post_type = $type;
        $post->post_status = $status;

        return $post;
    }

    public function testUpdateWithoutStatusChangeNeedsNoPublishCapability(): void
    {
        WPPilot_Test_State::add_post_type('post');
        WPPilot_Test_State::$capabilities = ['edit_posts'];

        self::assertNull(wordpress_update_status_capability_error($this->post(), ['title' => 'New title']));
    }

    public function testPublishingAnExistingDraftNeedsPublishCapability(): void
    {
        WPPilot_Test_State::add_post_type('post');
        WPPilot_Test_State::$capabilities = ['edit_posts'];

        $error = wordpress_update_status_capability_error($this->post(), ['status' => 'publish']);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('cannot_publish_post', $error->get_error_code());
    }

    public function testPublishingIsAllowedWithTheCapability(): void
    {
        WPPilot_Test_State::add_post_type('post');
        WPPilot_Test_State::$capabilities = ['edit_posts', 'publish_posts'];

        self::assertNull(wordpress_update_status_capability_error($this->post(), ['status' => 'publish']));
    }

    /**
     * Unpublishing is an ordinary edit, already covered by the edit_post check.
     */
    public function testUnpublishingDoesNotNeedPublishCapability(): void
    {
        WPPilot_Test_State::add_post_type('post');
        WPPilot_Test_State::$capabilities = ['edit_posts'];

        self::assertNull(wordpress_update_status_capability_error($this->post('publish'), ['status' => 'draft']));
    }

    public function testEditingAPublishedPostInPlaceNeedsNoPublishCapability(): void
    {
        WPPilot_Test_State::add_post_type('post');
        WPPilot_Test_State::$capabilities = ['edit_posts'];

        self::assertNull(wordpress_update_status_capability_error($this->post('publish'), ['status' => 'publish']));
    }

    public function testMalformedStatusOnUpdateCannotPublish(): void
    {
        WPPilot_Test_State::add_post_type('post');
        WPPilot_Test_State::$capabilities = ['edit_posts'];

        // Resolves to draft, which is not a publishing status, so no refusal and
        // no publish either.
        self::assertNull(wordpress_update_status_capability_error($this->post(), ['status' => 'PUBLISH NOW']));
    }

    public function testCustomTypePublishCapabilityIsUsed(): void
    {
        WPPilot_Test_State::add_post_type('portfolio', capabilities: ['publish_posts' => 'publish_portfolios']);
        WPPilot_Test_State::$capabilities = ['edit_posts', 'publish_posts'];

        $error = wordpress_update_status_capability_error(
            $this->post(type: 'portfolio'),
            ['status' => 'publish'],
        );

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('cannot_publish_post', $error->get_error_code());
    }

    // ----------------------------------------------------------- revisions

    public function testAutosaveIsIdentified(): void
    {
        $revision = new WP_Post();
        $revision->post_name = '12-autosave-v1';

        self::assertTrue(wordpress_revision_is_autosave($revision));
    }

    public function testOrdinaryRevisionIsNotAnAutosave(): void
    {
        $revision = new WP_Post();
        $revision->post_name = '12-revision-v1';

        self::assertFalse(wordpress_revision_is_autosave($revision));
    }
}
