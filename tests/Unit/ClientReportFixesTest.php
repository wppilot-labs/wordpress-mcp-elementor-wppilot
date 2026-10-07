<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

use function WPPilot\Design\Rendered\content_roots;
use function WPPilot\Design\Rendered\empty_elements;
use function WPPilot\Design\Rendered\headings;
use function WPPilot\Elementor\el_element_widget_type;
use function WPPilot\Elementor\el_validate_control_value;
use function WPPilot\Elementor\extract_control_options;

// The Elementor module and the rendered-page reader are not part of the suite
// bootstrap; only their pure layers are needed here. agent-context.php is
// global functions with no file-scope side effects.
foreach ([
    'includes/elementor/wppilot-schema-extractor.php',
    'includes/elementor/helpers/wppilot-tree.php',
    'includes/elementor/helpers/wppilot-settings-validation.php',
    'includes/design/rendered.php',
    'includes/agent-context.php',
] as $wppilot_client_report_file) {
    require_once dirname(__DIR__, 2) . '/' . $wppilot_client_report_file;
}

/**
 * Regressions from a client report against 1.17.3 (WordPress 7.1, Elementor
 * 4.3, Polylang Pro 3.8).
 */
final class ClientReportFixesTest extends TestCase
{
    public function test_classic_section_and_column_resolve_to_their_own_schema_keys(): void
    {
        // They used to fall through to the atomic-widget branch and then fail
        // the widget lookup as `unknown widget_type="section"`; now the
        // extractor resolves them through elements_manager like the container.
        $this->assertSame('section', el_element_widget_type(['elType' => 'section']));
        $this->assertSame('column', el_element_widget_type(['elType' => 'column']));
        $this->assertSame(['section', 'column'], \WPPilot\Elementor\WPPILOT_LEGACY_LAYOUT_TYPES);
    }

    public function test_a_select_with_only_a_placeholder_option_publishes_no_enum(): void
    {
        // Elementor Pro's form widget declares Reply-To as options ['' => ''] and
        // fills the real choices (the form's field IDs) in the editor.
        $this->assertSame([], extract_control_options(['type' => 'select', 'options' => ['' => '']]));
        $this->assertSame(
            ['opts' => ['', 'a', 'b']],
            extract_control_options(['type' => 'select', 'options' => ['' => 'None', 'a' => 'A', 'b' => 'B']]),
        );
    }

    public function test_a_form_field_id_passes_validation_for_a_placeholder_only_select(): void
    {
        $control = ['t' => 'select'] + extract_control_options(['options' => ['' => '']]);

        $this->assertNull(el_validate_control_value('email_reply_to', 'field_9ef17d4', $control));
        // A real enum is still enforced.
        $this->assertNotNull(el_validate_control_value('x', 'nope', ['t' => 'select', 'opts' => ['', 'a']]));
    }

    public function test_polylang_is_reported_as_polylang_even_though_it_defines_the_wpml_api(): void
    {
        require_once __DIR__ . '/fixtures/polylang-with-wpml-compat.php';

        $detected = wppilot_get_active_languages();

        $this->assertNotNull($detected);
        $this->assertSame('Polylang', $detected['plugin']);
        $this->assertSame(['lv', 'ru'], $detected['languages']);
    }

    public function test_rendered_checks_ignore_empty_theme_markup_and_tag_heading_sources(): void
    {
        $html = <<<'HTML'
            <html><body class="page-template-default page page-id-42">
            <header><h2>Site name</h2></header>
            <main>
              <div data-elementor-type="wp-page" data-elementor-id="42">
                <h1>Page title</h1>
                <h2>Section</h2>
                <div class="elementor-field-group elementor-column elementor-col-100"><textarea name="m"></textarea></div>
                <div class="elementor-field-group elementor-column elementor-col-50"><select name="s"><option>a</option></select></div>
              </div>
            </main>
            <aside><h4>Sidebar widget</h4></aside>
            <footer><div class="row"><div class="col-md-6"></div><div class="col-md-6"></div><div class="col-md-6"></div><div class="col-md-6"></div></div></footer>
            </body></html>
            HTML;
        $xpath = $this->xpath($html);
        $summary = [];

        $roots = content_roots($xpath, $summary);
        $heading_findings = headings($xpath, $roots, $summary);
        $empty_findings = empty_elements($xpath, $roots, $summary);

        $this->assertSame('elementor-document', $summary['scope']);
        $this->assertSame([], $empty_findings, 'footer columns and form fields are not empty page content');
        $this->assertSame(0, $summary['empty_containers']);
        $this->assertSame(4, $summary['empty_containers_outside_content']);
        // h2 -> h4 across the sidebar is not a gap in the page.
        $this->assertSame([], $heading_findings);
        $this->assertSame(
            ['theme', 'content', 'content', 'theme'],
            array_column($summary['outline'], 'source'),
        );
    }

    public function test_an_empty_column_inside_the_page_still_fails_and_says_so(): void
    {
        $html = '<html><body class="postid-7"><div class="entry-content">'
            . str_repeat('<div class="col"></div>', 4)
            . '</div><footer><div class="col-md-6"></div></footer></body></html>';
        $xpath = $this->xpath($html);
        $summary = [];

        $roots = content_roots($xpath, $summary);
        $findings = empty_elements($xpath, $roots, $summary);

        $this->assertSame('entry-content', $summary['scope']);
        $this->assertCount(1, $findings);
        $this->assertSame('fail', $findings[0]['severity']);
        $this->assertSame('content', $findings[0]['source']);
        $this->assertSame(4, $summary['empty_containers']);
    }

    public function test_without_a_content_container_the_whole_document_is_the_scope(): void
    {
        $xpath = $this->xpath('<html><body><div class="col"></div></body></html>');
        $summary = [];

        $this->assertSame([], content_roots($xpath, $summary));
        $this->assertSame('document', $summary['scope']);
    }

    public function test_ability_param_signature_lists_required_first_and_marks_optional(): void
    {
        $this->assertSame(
            'widget_types: array, include_styles?: boolean',
            wppilot_ability_param_signature([
                'type' => 'object',
                'properties' => [
                    'include_styles' => ['type' => 'boolean'],
                    'widget_types' => ['type' => 'array'],
                ],
                'required' => ['widget_types'],
            ]),
        );
        $this->assertSame('id: integer|string', wppilot_ability_param_signature([
            'properties' => ['id' => ['type' => ['integer', 'string']]],
            'required' => ['id'],
        ]));
        $this->assertSame('', wppilot_ability_param_signature([]));
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new DOMXPath($document);
    }
}
