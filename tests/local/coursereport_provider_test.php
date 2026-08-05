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
 * Unit tests for the course-wide report data provider of mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_insightjournal\local;

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');

/**
 * Tests for {@see coursereport_provider}.
 */
#[CoversClass(coursereport_provider::class)]
final class coursereport_provider_test extends advanced_testcase {
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
     * With no group restriction anywhere, every enrolled participant is
     * visible and every activity's cell is visible.
     */
    public function test_unrestricted_course_shows_every_participant_and_cell(): void {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $studenta = $generator->create_and_enrol($this->course, 'student');
        $studentb = $generator->create_and_enrol($this->course, 'student');
        $diary = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        $this->setUser($teacher);

        $provider = new coursereport_provider($this->course, [$diary->id => $cm]);

        $this->assertEquals(2, $provider->total_participants());
        $participants = $provider->participants(0, 20);
        $rows = $provider->rows_for($participants);
        $this->assertTrue($rows[(int) $studenta->id]['cells'][$diary->id]['visible']);
        $this->assertTrue($rows[(int) $studentb->id]['cells'][$diary->id]['visible']);
    }

    /**
     * A cell the viewer isn't authorized for (Separate Groups, target not
     * in the viewer's group) has visible === false and carries no other
     * keys - callers must never read entry/completed/private for it.
     */
    public function test_invisible_cell_carries_only_the_visible_key(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $outsider = $generator->create_and_enrol($this->course, 'student');
        $diary = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cm->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        $group = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $teacher->id]);
        // Outsider is enrolled but in no group - not covered by the SQL
        // prefilter in a real request, but rows_for() must still be safe
        // if ever called directly against a userid outside the restriction.
        $this->setUser($teacher);

        $provider = new coursereport_provider($this->course, [$diary->id => $cm]);
        $rows = $provider->rows_for([(int) $outsider->id => $outsider]);

        $cell = $rows[(int) $outsider->id]['cells'][$diary->id];
        $this->assertSame(['visible' => false], $cell);
    }

    /**
     * Mixed group modes: one restricted activity, one unrestricted, in the
     * same course. The SQL-level prefilter must stay open (an unrestricted
     * activity alone means every enrolled participant gets a potentially
     * visible cell), but the restricted activity's own cell must still be
     * masked per-participant.
     */
    public function test_mixed_group_modes_prefilter_open_but_cell_masked(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $student = $generator->create_and_enrol($this->course, 'student');

        $open = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $opencm = get_coursemodule_from_id('insightjournal', $open->cmid, 0, false, MUST_EXIST);

        $restricted = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $restrictedcm = get_coursemodule_from_id('insightjournal', $restricted->cmid, 0, false, MUST_EXIST);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $restrictedcm->id]);
        $restrictedcm = get_coursemodule_from_id('insightjournal', $restricted->cmid, 0, false, MUST_EXIST);
        $group = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $teacher->id]);

        $this->setUser($teacher);
        $provider = new coursereport_provider($this->course, [
            $open->id => $opencm,
            $restricted->id => $restrictedcm,
        ]);

        // Student is in no group - still shows up (open activity keeps the
        // prefilter unrestricted), but the restricted activity's cell is masked.
        $this->assertEquals(1, $provider->total_participants());
        $rows = $provider->rows_for($provider->participants(0, 20));
        $this->assertTrue($rows[(int) $student->id]['cells'][$open->id]['visible']);
        $this->assertFalse($rows[(int) $student->id]['cells'][$restricted->id]['visible']);
    }

    /**
     * A private entry is visible to the viewer (the activity isn't
     * group-restricted) but its cell reports private === true and
     * completed still reflects the entry's actual content, independent of
     * privacy - matching insightjournal_coursereport_cell_state()'s own
     * documented contract.
     */
    public function test_private_entry_cell_reports_private_and_completed(): void {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $student = $generator->create_and_enrol($this->course, 'student');
        $diary = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        $this->ij_generator()->create_entry(
            $diary,
            (int) $student->id,
            'A private reflection.',
            \INSIGHTJOURNAL_VISIBILITY_PRIVATE
        );
        $this->setUser($teacher);

        $provider = new coursereport_provider($this->course, [$diary->id => $cm]);
        $rows = $provider->rows_for($provider->participants(0, 20));

        $cell = $rows[(int) $student->id]['cells'][$diary->id];
        $this->assertTrue($cell['visible']);
        $this->assertTrue($cell['private']);
        $this->assertTrue($cell['completed']);
    }

    /**
     * done/visiblecount count only diaries the viewer is authorized to see
     * for that learner - an activity masked away by group restriction
     * contributes to neither the numerator nor the denominator.
     */
    public function test_progress_counts_only_visible_diaries(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $student = $generator->create_and_enrol($this->course, 'student');

        $open = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $opencm = get_coursemodule_from_id('insightjournal', $open->cmid, 0, false, MUST_EXIST);
        $this->ij_generator()->create_entry(
            $open,
            (int) $student->id,
            'Completed in the open activity.',
            \INSIGHTJOURNAL_VISIBILITY_VISIBLE
        );

        $restricted = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $restrictedcm = get_coursemodule_from_id('insightjournal', $restricted->cmid, 0, false, MUST_EXIST);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $restrictedcm->id]);
        $restrictedcm = get_coursemodule_from_id('insightjournal', $restricted->cmid, 0, false, MUST_EXIST);
        $group = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $teacher->id]);

        $this->setUser($teacher);
        $provider = new coursereport_provider($this->course, [
            $open->id => $opencm,
            $restricted->id => $restrictedcm,
        ]);
        $rows = $provider->rows_for($provider->participants(0, 20));

        $this->assertEquals(1, $rows[(int) $student->id]['done']);
        $this->assertEquals(1, $rows[(int) $student->id]['visiblecount']);
    }

    /**
     * participants() honours offset/limit - two calls with different
     * offsets return disjoint, correctly-sized slices, and total_participants()
     * matches the real enrolled count regardless of paging.
     */
    public function test_participants_pages_correctly(): void {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        for ($i = 0; $i < 5; $i++) {
            $generator->create_and_enrol($this->course, 'student');
        }
        $diary = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        $this->setUser($teacher);

        $provider = new coursereport_provider($this->course, [$diary->id => $cm]);

        $this->assertEquals(5, $provider->total_participants());
        $first = $provider->participants(0, 4);
        $second = $provider->participants(4, 4);
        $this->assertCount(4, $first);
        $this->assertCount(1, $second);
        $this->assertEmpty(array_intersect(array_keys($first), array_keys($second)));
    }

    /**
     * A chunk request landing exactly on the participant count returns
     * every participant with nothing left over on the next call - the CSV
     * export's chunk-boundary termination relies on this.
     */
    public function test_participants_chunk_at_exact_boundary(): void {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $generator->create_and_enrol($this->course, 'student');
        $generator->create_and_enrol($this->course, 'student');
        $diary = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        $this->setUser($teacher);

        $provider = new coursereport_provider($this->course, [$diary->id => $cm]);

        $chunk = $provider->participants(0, 2);
        $this->assertCount(2, $chunk);
        $next = $provider->participants(2, 2);
        $this->assertEmpty($next);
    }

    /**
     * A viewer with zero allowed groups, where every visible activity is
     * restricted, matches nobody - total_participants() is 0 and
     * participants() is empty, not an error.
     */
    public function test_zero_allowed_groups_blocks_all_participants(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $generator->create_and_enrol($this->course, 'student');
        $diary = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cm->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        // Teacher belongs to no group at all.
        $this->setUser($teacher);

        $provider = new coursereport_provider($this->course, [$diary->id => $cm]);

        $this->assertEquals(0, $provider->total_participants());
        $this->assertSame([], $provider->participants(0, 20));
    }

    /**
     * Two activities sharing a grouping resolve identically for the same
     * viewer - the per-grouping cache must not corrupt either activity's
     * result, and the deduplicated membership lookup must still produce
     * correct per-diary visibility for both.
     */
    public function test_two_activities_sharing_a_grouping_resolve_identically(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $student = $generator->create_and_enrol($this->course, 'student');

        $diarya = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $diaryb = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cma = get_coursemodule_from_id('insightjournal', $diarya->cmid, 0, false, MUST_EXIST);
        $cmb = get_coursemodule_from_id('insightjournal', $diaryb->cmid, 0, false, MUST_EXIST);

        $grouping = $generator->create_grouping(['courseid' => $this->course->id]);
        $group = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_grouping_group(['groupingid' => $grouping->id, 'groupid' => $group->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);

        $DB->set_field('course_modules', 'groupingid', $grouping->id, ['id' => $cma->id]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cma->id]);
        $DB->set_field('course_modules', 'groupingid', $grouping->id, ['id' => $cmb->id]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cmb->id]);
        $cma = get_coursemodule_from_id('insightjournal', $diarya->cmid, 0, false, MUST_EXIST);
        $cmb = get_coursemodule_from_id('insightjournal', $diaryb->cmid, 0, false, MUST_EXIST);

        $this->setUser($teacher);
        $provider = new coursereport_provider($this->course, [
            $diarya->id => $cma,
            $diaryb->id => $cmb,
        ]);
        $rows = $provider->rows_for($provider->participants(0, 20));

        $this->assertTrue($rows[(int) $student->id]['cells'][$diarya->id]['visible']);
        $this->assertTrue($rows[(int) $student->id]['cells'][$diaryb->id]['visible']);
    }

    /**
     * rows_for([]) returns an empty array, not an error - the CSV loop's
     * final, empty chunk relies on this.
     */
    public function test_rows_for_empty_participants_returns_empty_array(): void {
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $diary = $this->getDataGenerator()->create_module('insightjournal', ['course' => $this->course->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        $this->setUser($teacher);

        $provider = new coursereport_provider($this->course, [$diary->id => $cm]);

        $this->assertSame([], $provider->rows_for([]));
    }
}
