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
 * Unit tests for the mod_insightjournal/coursereport template's per-entry privacy rendering.
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
 *
 * @coversNothing
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
            'activities' => [
                [
                    'name' => 'Week 1 reflection',
                ],
            ],
            'rows' => [
                [
                    'summaryurl' => 'https://example.com/summary.php',
                    'fullname' => 'Jane Doe',
                    'cells' => [
                        [
                            'private' => false,
                            'completed' => true,
                            'status' => 'Done',
                            'timemodified' => '1 January 2026, 10:00 AM',
                        ],
                    ],
                    'progress' => '1 / 1',
                ],
            ],
        ]));

        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString('Done', $html);
        $this->assertStringContainsString(get_string('downloadcsv', 'mod_insightjournal'), $html);
        $this->assertStringNotContainsString(get_string('private', 'mod_insightjournal'), $html);
    }

    /**
     * A mix of a visible and a private entry for the same activity renders both
     * correctly: the private participant's cell gets a muted placeholder (no
     * status/timemodified leak), while the visible participant's cell and both
     * participants' names still show normally. There is no longer a
     * column-level private badge, since visibility is decided per entry.
     */
    public function test_mixed_visibility_only_hides_the_private_cell(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/coursereport', $this->make_context([
            'hasactivities' => true,
            'activities' => [
                ['name' => 'Week 1 reflection'],
            ],
            'rows' => [
                [
                    'summaryurl' => 'https://example.com/summary.php',
                    'fullname' => 'Jane Doe',
                    'cells' => [
                        [
                            'private' => false,
                            'completed' => true,
                            'status' => 'Done',
                            'timemodified' => '1 January 2026, 10:00 AM',
                        ],
                    ],
                    'progress' => '1 / 1',
                ],
                [
                    'summaryurl' => 'https://example.com/summary.php',
                    'fullname' => 'John Roe',
                    'cells' => [
                        ['private' => true],
                    ],
                    'progress' => '0 / 1',
                ],
            ],
        ]));

        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString('Week 1 reflection', $html);
        $this->assertStringContainsString('Done', $html);
        $this->assertStringContainsString('John Roe', $html);
        $this->assertStringNotContainsString(get_string('private', 'mod_insightjournal'), $html);
        $this->assertStringContainsString(get_string('entriesprivatenotice', 'mod_insightjournal'), $html);
        $this->assertStringContainsString(get_string('downloadcsv', 'mod_insightjournal'), $html);
    }

    /**
     * The download link is always shown, even when some/all entries are private
     * (private cells are substituted, not the whole export blocked).
     */
    public function test_download_link_always_present(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_insightjournal/coursereport', $this->make_context([
            'hasactivities' => true,
            'activities' => [['name' => 'Week 1 reflection']],
            'rows' => [
                [
                    'summaryurl' => 'https://example.com/summary.php',
                    'fullname' => 'Jane Doe',
                    'cells' => [['private' => true]],
                    'progress' => '0 / 1',
                ],
            ],
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
