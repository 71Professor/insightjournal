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
 *
 * @coversNothing
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
        ], $overrides);
    }

    /**
     * A visible row renders its response and no privacy notice.
     */
    public function test_visible_row_shows_response(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/report', $this->make_context([
            'hasrows' => true,
            'rows' => [
                [
                    'summaryurl' => 'https://example.com/summary.php',
                    'fullname' => 'Jane Doe',
                    'email' => 'jane@example.com',
                    'private' => false,
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
     * A private row (the participant's own choice) still shows their name, but the
     * notice replaces their response and no response content leaks into the markup.
     */
    public function test_private_row_shows_notice_and_hides_response(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/report', $this->make_context([
            'hasrows' => true,
            'rows' => [
                [
                    'summaryurl' => 'https://example.com/summary.php',
                    'fullname' => 'Jane Doe',
                    'email' => 'jane@example.com',
                    'private' => true,
                    'response' => '',
                    'timemodified' => '',
                ],
            ],
        ]));

        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString(get_string('entriesprivatenotice', 'mod_insightjournal'), $html);
        $this->assertStringNotContainsString('Secret reflection', $html);
        $this->assertStringContainsString(get_string('downloadcsv', 'mod_insightjournal'), $html);
    }

    /**
     * A mix of a visible and a private row in the same report renders both
     * correctly: both participants' names show, but only the visible row's
     * response content appears.
     */
    public function test_mixed_rows_only_hides_the_private_response(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/report', $this->make_context([
            'hasrows' => true,
            'rows' => [
                [
                    'summaryurl' => 'https://example.com/summary.php',
                    'fullname' => 'Jane Doe',
                    'email' => 'jane@example.com',
                    'private' => false,
                    'response' => '<p>Public reflection.</p>',
                    'timemodified' => '1 January 2026, 10:00 AM',
                ],
                [
                    'summaryurl' => 'https://example.com/summary.php',
                    'fullname' => 'John Roe',
                    'email' => 'john@example.com',
                    'private' => true,
                    'response' => '',
                    'timemodified' => '',
                ],
            ],
        ]));

        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString('Public reflection.', $html);
        $this->assertStringContainsString('John Roe', $html);
        $this->assertStringContainsString(get_string('entriesprivatenotice', 'mod_insightjournal'), $html);
    }

    /**
     * The back-to-activity link and download link always remain available,
     * regardless of any row's privacy.
     */
    public function test_back_and_download_links_always_present(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/report', $this->make_context());

        $this->assertStringContainsString(get_string('backtoactivity', 'mod_insightjournal'), $html);
        $this->assertStringContainsString(get_string('downloadcsv', 'mod_insightjournal'), $html);
    }
}
