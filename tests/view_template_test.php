<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Unit tests for the mod_insightjournal/view template's view/edit panel markup.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_insightjournal;

use advanced_testcase;

/**
 * Tests for the mod_insightjournal/view template.
 *
 * @coversNothing
 */
final class view_template_test extends advanced_testcase {
    /**
     * Builds a minimal, valid template context, with overrides.
     *
     * @param array $overrides Context keys to override.
     * @return array The template context.
     */
    protected function make_context(array $overrides = []): array {
        return array_merge([
            'cmid' => 5,
            'prompt' => '<p>What did you learn?</p>',
            'canwrite' => true,
            'haveentry' => false,
            'responseraw' => '',
            'responseformatted' => '',
            'autosave' => true,
            'minchars' => 0,
            'maxchars' => 0,
            'lastsaved' => '',
            'sesskey' => 'abc',
            'reporturl' => 'https://example.com/report.php',
            'summaryurl' => 'https://example.com/summary.php',
            'sectionurl' => 'https://example.com/course.php',
            'canviewall' => false,
            'promptstyle' => '',
        ], $overrides);
    }

    /**
     * With no saved entry, the edit panel is shown and the view panel is hidden.
     */
    public function test_no_entry_shows_edit_panel_only(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/view', $this->make_context());

        $this->assertMatchesRegularExpression('/data-insightjournal-view[^>]*class="[^"]*d-none/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-insightjournal-edit-panel[^>]*class="[^"]*d-none/', $html);
    }

    /**
     * With a saved entry, the view panel is shown and the edit panel is hidden.
     */
    public function test_existing_entry_shows_view_panel_only(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/view', $this->make_context([
            'haveentry' => true,
            'responseformatted' => '<p>My reflection</p>',
        ]));

        $this->assertDoesNotMatchRegularExpression('/data-insightjournal-view[^>]*class="[^"]*d-none/', $html);
        $this->assertMatchesRegularExpression('/data-insightjournal-edit-panel[^>]*class="[^"]*d-none/', $html);
        $this->assertStringContainsString('My reflection', $html);
    }

    /**
     * A user without submit capability sees neither panel.
     */
    public function test_readonly_user_sees_no_editor(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/view', $this->make_context(['canwrite' => false]));

        $this->assertStringNotContainsString('data-insightjournal-response', $html);
    }

    /**
     * A configured promptstyle is rendered as a style attribute on the prompt box.
     */
    public function test_prompt_style_applied_when_configured(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/view', $this->make_context([
            'promptstyle' => 'background-color: #ffcc00;',
        ]));

        $this->assertStringContainsString(
            'class="insightjournal-prompt mb-3" style="background-color: #ffcc00;"',
            $html
        );
    }

    /**
     * With no promptstyle configured, the prompt box has no style attribute.
     */
    public function test_prompt_style_absent_by_default(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/view', $this->make_context());

        $this->assertDoesNotMatchRegularExpression('/insightjournal-prompt[^>]*style=/', $html);
    }
}
