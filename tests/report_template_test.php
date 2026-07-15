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
 * Unit tests for the mod_insightjournal/report template's privacy-notice rendering.
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
 * Tests for the mod_insightjournal/report template.
 */
final class report_template_test extends advanced_testcase {
    /**
     * Builds a minimal, valid template context, with overrides.
     *
     * @param array $overrides Context keys to override.
     * @return array The template context.
     */
    protected function make_context(array $overrides = []): array {
        return array_merge([
            'backurl' => 'https://example.com/view.php?id=5',
            'downloadurl' => 'https://example.com/report.php?id=5&download=csv',
            'actionurl' => 'https://example.com/report.php',
            'cmid' => 5,
            'search' => '',
            'hasrows' => false,
            'rows' => [],
            'entriesprivate' => false,
        ], $overrides);
    }

    /**
     * With entries visible and rows present, the table renders and no privacy notice appears.
     */
    public function test_visible_mode_shows_table(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/report', $this->make_context([
            'hasrows' => true,
            'rows' => [
                [
                    'summaryurl' => 'https://example.com/summary.php',
                    'fullname' => 'Jane Doe',
                    'email' => 'jane@example.com',
                    'response' => '<p>Today I learned about Mustache templates.</p>',
                    'timemodified' => '1 January 2026, 10:00 AM',
                ],
            ],
        ]));

        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString('Today I learned about Mustache templates.', $html);
        $this->assertStringContainsString(get_string('downloadcsv', 'mod_insightjournal'), $html);
        $this->assertStringNotContainsString(get_string('entriesprivatenotice', 'mod_insightjournal'), $html);
    }

    /**
     * In private mode, the notice replaces the table, search form, and download link,
     * and no entry content leaks into the markup even if rows were (mistakenly) supplied.
     */
    public function test_private_mode_shows_notice_and_hides_entries(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/report', $this->make_context([
            'entriesprivate' => true,
            'hasrows' => true,
            'rows' => [
                [
                    'summaryurl' => 'https://example.com/summary.php',
                    'fullname' => 'Jane Doe',
                    'email' => 'jane@example.com',
                    'response' => '<p>Secret reflection.</p>',
                    'timemodified' => '1 January 2026, 10:00 AM',
                ],
            ],
        ]));

        $this->assertStringContainsString(get_string('entriesprivatenotice', 'mod_insightjournal'), $html);
        $this->assertStringNotContainsString('Jane Doe', $html);
        $this->assertStringNotContainsString('Secret reflection.', $html);
        $this->assertStringNotContainsString(get_string('downloadcsv', 'mod_insightjournal'), $html);
    }

    /**
     * The back-to-activity link always remains available, in both modes.
     */
    public function test_back_link_always_present(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/report', $this->make_context(['entriesprivate' => true]));

        $this->assertStringContainsString(get_string('backtoactivity', 'mod_insightjournal'), $html);
    }
}
