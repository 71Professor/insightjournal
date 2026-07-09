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
 * Unit tests for the mod_insightjournal/coursereport template's per-activity privacy rendering.
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
 * Tests for the mod_insightjournal/coursereport template.
 */
final class coursereport_template_test extends advanced_testcase {
    /**
     * Builds a minimal, valid template context, with overrides.
     *
     * @param array $overrides Context keys to override.
     * @return array The template context.
     */
    protected function make_context(array $overrides = []): array {
        return array_merge([
            'backurl' => 'https://example.com/course/view.php?id=2',
            'downloadurl' => 'https://example.com/coursereport.php?courseid=2&download=csv',
            'hasactivities' => false,
            'activities' => [],
            'rows' => [],
        ], $overrides);
    }

    /**
     * With every activity visible, the participant matrix renders with no private badges.
     */
    public function test_all_visible_shows_full_table(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/coursereport', $this->make_context([
            'hasactivities' => true,
            'activities' => [['name' => 'Week 1 reflection', 'private' => false]],
            'rows' => [[
                'summaryurl' => 'https://example.com/summary.php',
                'fullname' => 'Jane Doe',
                'cells' => [
                    ['private' => false, 'completed' => true, 'status' => 'Done', 'timemodified' => '1 January 2026, 10:00 AM'],
                ],
                'progress' => '1 / 1',
            ]],
        ]));

        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString('Done', $html);
        $this->assertStringContainsString(get_string('downloadcsv', 'mod_insightjournal'), $html);
        $this->assertStringNotContainsString(get_string('private', 'mod_insightjournal'), $html);
    }

    /**
     * A mix of a visible and a private activity in the same course renders both
     * correctly: the private column gets a badge and a muted placeholder cell
     * (no status/timemodified leak), while the visible column's data and the
     * participant name still show normally.
     */
    public function test_mixed_visibility_only_hides_the_private_column(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/coursereport', $this->make_context([
            'hasactivities' => true,
            'activities' => [
                ['name' => 'Week 1 reflection', 'private' => false],
                ['name' => 'Week 2 reflection', 'private' => true],
            ],
            'rows' => [[
                'summaryurl' => 'https://example.com/summary.php',
                'fullname' => 'Jane Doe',
                'cells' => [
                    ['private' => false, 'completed' => true, 'status' => 'Done', 'timemodified' => '1 January 2026, 10:00 AM'],
                    ['private' => true],
                ],
                'progress' => '1 / 2',
            ]],
        ]));

        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString('Week 1 reflection', $html);
        $this->assertStringContainsString('Week 2 reflection', $html);
        $this->assertStringContainsString('Done', $html);
        $this->assertStringContainsString(get_string('private', 'mod_insightjournal'), $html);
        $this->assertStringContainsString(get_string('entriesprivatenotice', 'mod_insightjournal'), $html);
        $this->assertStringContainsString(get_string('downloadcsv', 'mod_insightjournal'), $html);
    }

    /**
     * The download link is always shown now, even when some/all activities are private
     * (private rows are substituted, not the whole export blocked).
     */
    public function test_download_link_always_present(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/coursereport', $this->make_context([
            'hasactivities' => true,
            'activities' => [['name' => 'Week 1 reflection', 'private' => true]],
            'rows' => [[
                'summaryurl' => 'https://example.com/summary.php',
                'fullname' => 'Jane Doe',
                'cells' => [['private' => true]],
                'progress' => '0 / 1',
            ]],
        ]));

        $this->assertStringContainsString(get_string('downloadcsv', 'mod_insightjournal'), $html);
    }

    /**
     * The back-to-course link always remains available.
     */
    public function test_back_link_always_present(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/coursereport', $this->make_context());

        $this->assertStringContainsString(get_string('backtocourse', 'mod_insightjournal'), $html);
    }
}
