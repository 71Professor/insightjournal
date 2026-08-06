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
 * End-to-end integration tests for the course-wide report's CSV export
 * (R4-09): coursereport_provider::csv_rows() feeding a real
 * csv_export_writer, with the resulting bytes parsed back and checked -
 * the same real production code coursereport.php itself calls, not a
 * reimplementation of its chunking/authorization logic.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_insightjournal;

use advanced_testcase;
use mod_insightjournal\local\coursereport_provider;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');
require_once($CFG->libdir . '/csvlib.class.php');

/**
 * Tests for {@see coursereport_provider::csv_rows()} through a real
 * {@see \csv_export_writer}.
 */
#[CoversClass(coursereport_provider::class)]
final class coursereport_csv_export_test extends advanced_testcase {
    /** @var stdClass The course. */
    protected stdClass $course;

    /**
     * Creates a course.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
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
     * Feeds $rows (as coursereport_provider::csv_rows() yields them) into a
     * real csv_export_writer with the exact header coursereport.php uses,
     * gets the real written bytes back via print_csv_data(), and parses
     * them back with fgetcsv() (not a naive newline split - a field can
     * legitimately contain an embedded newline once quoted). Proves the
     * bytes a learner/trainer would actually download, not just the PHP
     * array coursereport_provider hands to the writer.
     *
     * @param iterable $rows
     * @return array<int, array> Parsed data rows (header stripped), each a positional array
     *     matching coursereport.php's header order.
     */
    protected function write_and_reparse_csv(iterable $rows): array {
        $writer = new \csv_export_writer('comma', '"', 'text/csv', true);
        $writer->add_data([
            'courseid', 'coursename', 'cmid', 'activityname', 'userid',
            'fullname', 'email', 'response', 'timemodified',
        ]);
        foreach ($rows as $row) {
            $writer->add_data($row);
        }
        $csv = $writer->print_csv_data(true);

        $bom = \core_text::UTF8_BOM;
        if (str_starts_with($csv, $bom)) {
            $csv = substr($csv, strlen($bom));
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $parsed = [];
        while (($fields = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            $parsed[] = $fields;
        }
        fclose($stream);

        array_shift($parsed); // Header row.
        return $parsed;
    }

    /**
     * Finds the parsed row for a given userid (column index 4) and,
     * whenever more than one activity is in play, its cmid (column index
     * 2) - a participant legitimately gets one row per visible activity,
     * so userid alone doesn't uniquely identify a row once a viewer (e.g.
     * accessallgroups) can see more than one. Fails the test with a clear
     * message if no matching row exists - every assertion below is only
     * meaningful once the row is confirmed present.
     *
     * @param array $rows
     * @param int $userid
     * @param int|null $cmid
     * @return array
     */
    protected function find_row(array $rows, int $userid, ?int $cmid = null): array {
        foreach ($rows as $row) {
            if ((int) $row[4] === $userid && ($cmid === null || (int) $row[2] === $cmid)) {
                return $row;
            }
        }
        $this->fail("No CSV row found for userid $userid" . ($cmid !== null ? ", cmid $cmid" : '') . '.');
    }

    /**
     * Two independently-grouped activities, a viewer restricted to one
     * group, and a private entry, all through the real writer at once: the
     * restricted teacher's downloaded CSV contains only rows for their own
     * grouping's group, and a private entry inside that authorized cell is
     * replaced by the privacy notice - never the real response text.
     */
    public function test_two_groupings_restricted_viewer_and_private_entry(): void {
        $generator = $this->getDataGenerator();

        $groupinga = $generator->create_grouping(['courseid' => $this->course->id]);
        $groupa = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_grouping_group(['groupingid' => $groupinga->id, 'groupid' => $groupa->id]);

        $groupingb = $generator->create_grouping(['courseid' => $this->course->id]);
        $groupb = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_grouping_group(['groupingid' => $groupingb->id, 'groupid' => $groupb->id]);

        $diarya = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cma = get_coursemodule_from_id('insightjournal', $diarya->cmid, 0, false, MUST_EXIST);
        $diaryb = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cmb = get_coursemodule_from_id('insightjournal', $diaryb->cmid, 0, false, MUST_EXIST);

        global $DB;
        $DB->set_field('course_modules', 'groupingid', $groupinga->id, ['id' => $cma->id]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cma->id]);
        $DB->set_field('course_modules', 'groupingid', $groupingb->id, ['id' => $cmb->id]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cmb->id]);
        $cma = get_coursemodule_from_id('insightjournal', $diarya->cmid, 0, false, MUST_EXIST);
        $cmb = get_coursemodule_from_id('insightjournal', $diaryb->cmid, 0, false, MUST_EXIST);

        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);

        $studenta = $generator->create_and_enrol($this->course, 'student');
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $studenta->id]);
        $studentaprivate = $generator->create_and_enrol($this->course, 'student');
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $studentaprivate->id]);
        $studentb = $generator->create_and_enrol($this->course, 'student');
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $studentb->id]);

        $this->ij_generator()->create_entry(
            $diarya,
            (int) $studenta->id,
            'A visible entry.',
            INSIGHTJOURNAL_VISIBILITY_VISIBLE
        );
        $this->ij_generator()->create_entry(
            $diarya,
            (int) $studentaprivate->id,
            'A secret only the author should see.',
            INSIGHTJOURNAL_VISIBILITY_PRIVATE
        );
        $this->ij_generator()->create_entry(
            $diaryb,
            (int) $studentb->id,
            'A groupB entry the restricted viewer must never see.',
            INSIGHTJOURNAL_VISIBILITY_VISIBLE
        );

        $this->setUser($teacher);
        $diaries = $DB->get_records_list('insightjournal', 'id', [$diarya->id, $diaryb->id], 'id ASC');
        $provider = new coursereport_provider($this->course, [$diarya->id => $cma, $diaryb->id => $cmb]);
        $rows = $this->write_and_reparse_csv($provider->csv_rows($diaries, true));

        // Group A's own visible entry: present, real content.
        $visiblerow = $this->find_row($rows, (int) $studenta->id);
        $this->assertSame('A visible entry.', $visiblerow[7]);

        // Group A's private entry: present (the cell is authorized - the
        // teacher may see that it exists), but the response column is the
        // privacy notice, never the real text.
        $privaterow = $this->find_row($rows, (int) $studentaprivate->id);
        $this->assertSame(get_string('entriesprivatenotice', 'mod_insightjournal'), $privaterow[7]);
        $this->assertStringNotContainsString('secret', $privaterow[7]);

        // Group B's student never appears at all - a different grouping the
        // restricted viewer holds no allowed group in.
        foreach ($rows as $row) {
            $this->assertNotSame((int) $studentb->id, (int) $row[4]);
        }
    }

    /**
     * A viewer with moodle/site:accessallgroups sees every participant
     * across both groupings, despite belonging to neither group
     * themselves - accessallgroups bypasses Separate Groups entirely
     * (insightjournal_activity_group_restricted()), it isn't just a larger
     * group membership.
     */
    public function test_accessallgroups_viewer_sees_across_both_groupings(): void {
        $generator = $this->getDataGenerator();

        $groupinga = $generator->create_grouping(['courseid' => $this->course->id]);
        $groupa = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_grouping_group(['groupingid' => $groupinga->id, 'groupid' => $groupa->id]);

        $groupingb = $generator->create_grouping(['courseid' => $this->course->id]);
        $groupb = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_grouping_group(['groupingid' => $groupingb->id, 'groupid' => $groupb->id]);

        $diarya = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cma = get_coursemodule_from_id('insightjournal', $diarya->cmid, 0, false, MUST_EXIST);
        $diaryb = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cmb = get_coursemodule_from_id('insightjournal', $diaryb->cmid, 0, false, MUST_EXIST);

        global $DB;
        $DB->set_field('course_modules', 'groupingid', $groupinga->id, ['id' => $cma->id]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cma->id]);
        $DB->set_field('course_modules', 'groupingid', $groupingb->id, ['id' => $cmb->id]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cmb->id]);
        $cma = get_coursemodule_from_id('insightjournal', $diarya->cmid, 0, false, MUST_EXIST);
        $cmb = get_coursemodule_from_id('insightjournal', $diaryb->cmid, 0, false, MUST_EXIST);

        // Manager: mod/insightjournal:viewall/:export and
        // moodle/site:accessallgroups are all CAP_ALLOW by default for this
        // archetype - no group membership at all.
        $manager = $generator->create_and_enrol($this->course, 'manager');

        $studenta = $generator->create_and_enrol($this->course, 'student');
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $studenta->id]);
        $studentb = $generator->create_and_enrol($this->course, 'student');
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $studentb->id]);

        $this->ij_generator()->create_entry($diarya, (int) $studenta->id, 'groupA entry.', INSIGHTJOURNAL_VISIBILITY_VISIBLE);
        $this->ij_generator()->create_entry($diaryb, (int) $studentb->id, 'groupB entry.', INSIGHTJOURNAL_VISIBILITY_VISIBLE);

        $this->setUser($manager);
        $diaries = $DB->get_records_list('insightjournal', 'id', [$diarya->id, $diaryb->id], 'id ASC');
        $provider = new coursereport_provider($this->course, [$diarya->id => $cma, $diaryb->id => $cmb]);
        $rows = $this->write_and_reparse_csv($provider->csv_rows($diaries, true));

        // Each student appears twice - once per activity - since
        // accessallgroups makes both visible; only the row for the
        // activity they actually wrote in has real response content, the
        // other is a legitimate "not submitted" cell for the other diary.
        $this->assertCount(4, $rows);
        $this->assertSame('groupA entry.', $this->find_row($rows, (int) $studenta->id, (int) $cma->id)[7]);
        $this->assertSame('groupB entry.', $this->find_row($rows, (int) $studentb->id, (int) $cmb->id)[7]);
        $this->assertSame('', $this->find_row($rows, (int) $studenta->id, (int) $cmb->id)[7]);
        $this->assertSame('', $this->find_row($rows, (int) $studentb->id, (int) $cma->id)[7]);
    }

    /**
     * A response with a comma, an embedded double quote, and a real
     * paragraph break survives a full write-then-parse round trip through
     * the real csv_export_writer byte-for-byte - proving the export is
     * correctly escaped, not just that the plugin's own row-builder
     * returns the right pre-escaped-looking string (see
     * coursereport_csv_test.php for that narrower, isolated check).
     */
    public function test_special_characters_and_line_breaks_round_trip_through_real_writer(): void {
        $generator = $this->getDataGenerator();
        $diary = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $student = $generator->create_and_enrol($this->course, 'student');

        $rawresponse = '<p>Hello, "world" - comma and quotes.</p><p>A second paragraph.</p>';
        $this->ij_generator()->create_entry($diary, (int) $student->id, $rawresponse, INSIGHTJOURNAL_VISIBILITY_VISIBLE);
        // The exact expected plain text is whatever insightjournal_html_to_text()
        // itself produces (including its paragraph-separator convention) -
        // this test is about CSV round-tripping that text intact, not about
        // pinning html-to-text conversion rules (covered elsewhere).
        $expected = \insightjournal_html_to_text($rawresponse);
        $this->assertStringContainsString("\n", $expected, 'Fixture must contain a real line break to exercise this test.');

        $this->setUser($teacher);
        global $DB;
        $diaries = $DB->get_records_list('insightjournal', 'id', [$diary->id], 'id ASC');
        $provider = new coursereport_provider($this->course, [$diary->id => $cm]);
        $rows = $this->write_and_reparse_csv($provider->csv_rows($diaries, true));

        $this->assertCount(1, $rows);
        $this->assertSame($expected, $this->find_row($rows, (int) $student->id)[7]);
    }

    /**
     * Five participants with a chunk size of two (three chunks: 2, 2, 1)
     * all appear in the final export exactly once each - proves
     * csv_rows() neither drops nor duplicates rows at a chunk boundary,
     * the specific failure mode a non-unique ORDER BY plus chunked
     * LIMIT/OFFSET can otherwise cause (see
     * coursereport_provider::participants()'s 'u.lastname,u.firstname,u.id'
     * ordering, unique on u.id).
     */
    public function test_complete_across_multiple_chunks_no_drops_or_dupes(): void {
        $generator = $this->getDataGenerator();
        $diary = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        $teacher = $generator->create_and_enrol($this->course, 'teacher');

        $students = [];
        foreach (['Aaa', 'Bbb', 'Ccc', 'Ddd', 'Eee'] as $lastname) {
            $student = $generator->create_and_enrol(
                $this->course,
                'student',
                ['firstname' => 'Student', 'lastname' => $lastname]
            );
            $this->ij_generator()->create_entry(
                $diary,
                (int) $student->id,
                "Entry by $lastname.",
                INSIGHTJOURNAL_VISIBILITY_VISIBLE
            );
            $students[] = $student;
        }

        $this->setUser($teacher);
        global $DB;
        $diaries = $DB->get_records_list('insightjournal', 'id', [$diary->id], 'id ASC');
        $provider = new coursereport_provider($this->course, [$diary->id => $cm]);
        $rows = $this->write_and_reparse_csv($provider->csv_rows($diaries, true, 2));

        $this->assertCount(5, $rows, 'Every participant must appear exactly once across all chunks.');
        $seenuserids = array_map(fn ($row) => (int) $row[4], $rows);
        $this->assertSame($seenuserids, array_unique($seenuserids), 'No userid may be duplicated across chunk boundaries.');
        foreach ($students as $student) {
            $this->assertContains((int) $student->id, $seenuserids);
        }
    }
}
