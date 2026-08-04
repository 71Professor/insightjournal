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
 * Unit tests for mod_insightjournal's coursereport.php CSV export.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_insightjournal;

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversFunction;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');
require_once($CFG->dirroot . '/mod/insightjournal/lib.php');

/**
 * Tests for {@see \insightjournal_coursereport_csv_row()},
 * {@see \insightjournal_entries_by_diary_and_user()}, and
 * {@see \insightjournal_coursereport_cell_state()}.
 */
#[CoversFunction('insightjournal_coursereport_csv_row')]
#[CoversFunction('insightjournal_entries_by_diary_and_user')]
#[CoversFunction('insightjournal_coursereport_cell_state')]
final class coursereport_csv_test extends advanced_testcase {
    /** @var stdClass The course. */
    protected stdClass $course;

    /** @var stdClass The insight journal instance. */
    protected stdClass $diary;

    /** @var stdClass The activity's course-module record. */
    protected stdClass $cm;

    /** @var stdClass The enrolled student. */
    protected stdClass $student;

    /**
     * Creates a course, an insight journal activity, and an enrolled student.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->diary = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_id('insightjournal', $this->diary->cmid, 0, false, MUST_EXIST);
        $this->student = $generator->create_and_enrol($this->course, 'student');
    }

    /**
     * Returns the plugin's own test data generator, for create_entry().
     *
     * @return \mod_insightjournal_generator
     */
    protected function ij_generator() {
        return $this->getDataGenerator()->get_plugin_generator('mod_insightjournal');
    }

    /**
     * A normal, visible entry produces all nine values in the documented
     * legacy column order, with the response run through
     * insightjournal_html_to_text() and nothing pre-escaped.
     */
    public function test_normal_row_matches_legacy_column_order(): void {
        $entry = $this->ij_generator()->create_entry(
            $this->diary,
            (int) $this->student->id,
            '<p>CSV entry.</p>',
            INSIGHTJOURNAL_VISIBILITY_VISIBLE
        );

        $row = \insightjournal_coursereport_csv_row(
            $this->course,
            (int) $this->cm->id,
            $this->diary,
            $this->student,
            $entry,
            true
        );

        $this->assertEquals($this->course->id, $row[0]);
        $this->assertSame($this->course->fullname, $row[1]);
        $this->assertEquals($this->cm->id, $row[2]);
        $this->assertSame($this->diary->name, $row[3]);
        $this->assertEquals($this->student->id, $row[4]);
        $this->assertSame(fullname($this->student), $row[5]);
        $this->assertSame($this->student->email, $row[6]);
        $this->assertSame('CSV entry.', $row[7]);
        $this->assertSame(userdate($entry->timemodified), $row[8]);
    }

    /**
     * A private entry's row uses the privacy notice text, not the
     * response, and blanks timemodified - same rule
     * report_table.php's col_response()/col_timemodified() already follow.
     */
    public function test_private_entry_uses_notice_and_blanks_timemodified(): void {
        $entry = $this->ij_generator()->create_entry(
            $this->diary,
            (int) $this->student->id,
            'Secret reflection.',
            INSIGHTJOURNAL_VISIBILITY_PRIVATE
        );

        $row = \insightjournal_coursereport_csv_row(
            $this->course,
            (int) $this->cm->id,
            $this->diary,
            $this->student,
            $entry,
            true
        );

        $this->assertSame(get_string('entriesprivatenotice', 'insightjournal'), $row[7]);
        $this->assertSame('', $row[8]);
    }

    /**
     * The email column is blank when the viewer isn't permitted to see it,
     * regardless of the participant's actual address.
     */
    public function test_email_blanked_when_not_permitted(): void {
        $entry = $this->ij_generator()->create_entry(
            $this->diary,
            (int) $this->student->id,
            'Entry.',
            INSIGHTJOURNAL_VISIBILITY_VISIBLE
        );

        $row = \insightjournal_coursereport_csv_row(
            $this->course,
            (int) $this->cm->id,
            $this->diary,
            $this->student,
            $entry,
            false
        );

        $this->assertSame('', $row[6]);
    }

    /**
     * A participant with no submission for this activity yet gets a blank
     * response/timemodified, not an error.
     */
    public function test_missing_entry_blanks_response_and_timemodified(): void {
        $row = \insightjournal_coursereport_csv_row(
            $this->course,
            (int) $this->cm->id,
            $this->diary,
            $this->student,
            null,
            true
        );

        $this->assertSame('', $row[7]);
        $this->assertSame('', $row[8]);
    }

    /**
     * A value that would trigger spreadsheet-formula interpretation comes
     * back verbatim, unescaped - escaping is csv_export_writer::add_data()'s
     * job downstream now, not this function's.
     */
    public function test_formula_prefixed_value_is_returned_unescaped(): void {
        $diary = clone $this->diary;
        $diary->name = '=cmd|"/c calc"!A1';

        $row = \insightjournal_coursereport_csv_row(
            $this->course,
            (int) $this->cm->id,
            $diary,
            $this->student,
            null,
            true
        );

        $this->assertSame('=cmd|"/c calc"!A1', $row[3]);
    }

    /**
     * csv_export_writer, constructed exactly as coursereport.php constructs
     * it below, prefixes its output with a UTF-8 BOM - the review's
     * explicit acceptance criterion for Excel/LibreOffice compatibility.
     * This test exercises Moodle's own core class directly (bom: true is a
     * constructor argument, not plugin logic), so it passes immediately -
     * it exists to pin the exact recipe, not to drive new implementation.
     */
    public function test_csv_export_writer_recipe_produces_bom(): void {
        global $CFG;
        require_once($CFG->libdir . '/csvlib.class.php');

        $writer = new \csv_export_writer('comma', '"', 'text/csv', true);
        $writer->add_data(['header']);

        $this->assertStringStartsWith(\core_text::UTF8_BOM, $writer->print_csv_data(true));
    }

    /**
     * csv_export_writer escapes a formula-triggering character even after
     * leading whitespace - the other half of the review's acceptance
     * criterion (the first half, BOM, is pinned by the test above). This
     * is why insightjournal_coursereport_csv_row() is allowed to return
     * values unescaped: csv_export_writer::add_data() (via core's
     * escape_spreadsheet_formula()) covers this case, which the plugin's
     * own now-deleted insightjournal_csv_value() did not.
     */
    public function test_csv_export_writer_escapes_formula_after_leading_whitespace(): void {
        global $CFG;
        require_once($CFG->libdir . '/csvlib.class.php');

        $writer = new \csv_export_writer('comma', '"', 'text/csv', true);
        $writer->add_data(['  =SUM(1+1)']);

        $this->assertStringContainsString("'  =SUM(1+1)", $writer->print_csv_data(true));
    }

    /**
     * Entries come back keyed by userid then insightjournalid - the shape
     * both coursereport.php's on-screen pagination and its CSV chunk loop
     * build their per-cell lookups around - and a user/diary combination
     * with no entry is simply absent, not a null placeholder.
     */
    public function test_maps_entries_by_userid_then_diaryid(): void {
        $otherstudent = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $entry = $this->ij_generator()->create_entry(
            $this->diary,
            (int) $this->student->id,
            'Entry for student one.',
            INSIGHTJOURNAL_VISIBILITY_VISIBLE
        );

        $entries = \insightjournal_entries_by_diary_and_user(
            [(int) $this->diary->id],
            [(int) $this->student->id, (int) $otherstudent->id]
        );

        $this->assertArrayNotHasKey($otherstudent->id, $entries);
        $this->assertEquals($entry->id, $entries[$this->student->id][$this->diary->id]->id);
    }

    /**
     * A diary not included in $diaryids is excluded even when the user has
     * an entry there - callers rely on this to scope a query to one
     * page/chunk's activities only, not every activity the user has ever
     * written in.
     */
    public function test_excludes_entries_for_diaries_not_requested(): void {
        $otherdiary = $this->getDataGenerator()->create_module('insightjournal', ['course' => $this->course->id]);
        $this->ij_generator()->create_entry(
            $this->diary,
            (int) $this->student->id,
            'In requested diary.',
            INSIGHTJOURNAL_VISIBILITY_VISIBLE
        );
        $this->ij_generator()->create_entry(
            $otherdiary,
            (int) $this->student->id,
            'In other diary.',
            INSIGHTJOURNAL_VISIBILITY_VISIBLE
        );

        $entries = \insightjournal_entries_by_diary_and_user([(int) $this->diary->id], [(int) $this->student->id]);

        $this->assertArrayNotHasKey($otherdiary->id, $entries[$this->student->id]);
    }

    /**
     * An empty diary or user list short-circuits to an empty map instead of
     * issuing a get_in_or_equal() query with an empty array, which would
     * otherwise match nothing in a way that is easy to mistake for "no
     * entries exist" rather than "no query was meaningful to run".
     */
    public function test_empty_diaryids_or_userids_returns_empty_map(): void {
        $this->assertSame([], \insightjournal_entries_by_diary_and_user([], [(int) $this->student->id]));
        $this->assertSame([], \insightjournal_entries_by_diary_and_user([(int) $this->diary->id], []));
    }

    /**
     * No entry at all is neither completed nor private.
     */
    public function test_cell_state_no_entry(): void {
        $state = \insightjournal_coursereport_cell_state(null);

        $this->assertFalse($state['completed']);
        $this->assertFalse($state['private']);
    }

    /**
     * A normal, visible, non-empty entry is completed and not private.
     */
    public function test_cell_state_visible_entry_is_completed(): void {
        $entry = $this->ij_generator()->create_entry(
            $this->diary,
            (int) $this->student->id,
            'A real reflection.',
            INSIGHTJOURNAL_VISIBILITY_VISIBLE
        );

        $state = \insightjournal_coursereport_cell_state($entry);

        $this->assertTrue($state['completed']);
        $this->assertFalse($state['private']);
    }

    /**
     * A private entry still counts as completed (R3-09: matches
     * custom_completion.php's own state calculation, which never checks
     * visibility) - only the private flag differs, so the caller can still
     * hide its status/timestamp/content from the trainer's per-cell view.
     */
    public function test_cell_state_private_entry_still_counts_as_completed(): void {
        $entry = $this->ij_generator()->create_entry(
            $this->diary,
            (int) $this->student->id,
            'A private reflection.',
            INSIGHTJOURNAL_VISIBILITY_PRIVATE
        );

        $state = \insightjournal_coursereport_cell_state($entry);

        $this->assertTrue($state['completed']);
        $this->assertTrue($state['private']);
    }

    /**
     * An empty rich-text editor shell (e.g. "<p></p>") is not completed,
     * private or not - an entry row existing is not the same as having
     * written anything.
     */
    public function test_cell_state_empty_entry_is_not_completed(): void {
        $entry = $this->ij_generator()->create_entry(
            $this->diary,
            (int) $this->student->id,
            '<p></p>',
            INSIGHTJOURNAL_VISIBILITY_VISIBLE
        );

        $state = \insightjournal_coursereport_cell_state($entry);

        $this->assertFalse($state['completed']);
        $this->assertFalse($state['private']);
    }

    /**
     * A response consisting only of NBSP does not count as completed,
     * matching custom_completion.php's Unicode-aware emptiness check -
     * this function must never disagree with the activity's own
     * completion state.
     */
    public function test_cell_state_nbsp_only_entry_is_not_completed(): void {
        $entry = $this->ij_generator()->create_entry(
            $this->diary,
            (int) $this->student->id,
            '<p>&nbsp;</p>',
            INSIGHTJOURNAL_VISIBILITY_VISIBLE
        );

        $state = \insightjournal_coursereport_cell_state($entry);

        $this->assertFalse($state['completed']);
        $this->assertFalse($state['private']);
    }
}
