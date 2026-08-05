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
 * Integration tests wiring locallib.php's per-activity visibility
 * helpers into coursereport.php's actual query sequence.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_insightjournal;

use advanced_testcase;
use context_module;
use PHPUnit\Framework\Attributes\CoversFunction;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');
require_once($CFG->dirroot . '/mod/insightjournal/lib.php');

/**
 * Reproduces coursereport.php's two-layer authorization sequence:
 * the SQL-level participant prefilter and the per-cell visibility
 * check, proving the R3-02 cross-activity disclosure scenario is
 * closed at both layers.
 */
#[CoversFunction('insightjournal_coursereport_restrict_groupids')]
#[CoversFunction('insightjournal_activity_visible_to_viewer')]
final class coursereport_authorization_test extends advanced_testcase {
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
     * Two activities in different groupings, both Separate Groups: a
     * teacher's SQL-level participant prefilter must include a student
     * authorized under either activity's grouping (layer 1), but the
     * per-cell check must still mask the activity the student isn't
     * authorized under (layer 2) - proving the two layers compose
     * correctly, not just each in isolation.
     */
    public function test_two_layer_authorization_across_two_groupings(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $student = $generator->create_and_enrol($this->course, 'student');
        $outsider = $generator->create_and_enrol($this->course, 'student');

        $diarya = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $diaryb = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cma = get_coursemodule_from_id('insightjournal', $diarya->cmid, 0, false, MUST_EXIST);
        $cmb = get_coursemodule_from_id('insightjournal', $diaryb->cmid, 0, false, MUST_EXIST);

        $groupinga = $generator->create_grouping(['courseid' => $this->course->id]);
        $groupingb = $generator->create_grouping(['courseid' => $this->course->id]);
        $DB->set_field('course_modules', 'groupingid', $groupinga->id, ['id' => $cma->id]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cma->id]);
        $DB->set_field('course_modules', 'groupingid', $groupingb->id, ['id' => $cmb->id]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cmb->id]);
        $cma = get_coursemodule_from_id('insightjournal', $diarya->cmid, 0, false, MUST_EXIST);
        $cmb = get_coursemodule_from_id('insightjournal', $diaryb->cmid, 0, false, MUST_EXIST);

        $groupa = $generator->create_group(['courseid' => $this->course->id]);
        $groupb = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_grouping_group(['groupingid' => $groupinga->id, 'groupid' => $groupa->id]);
        $generator->create_grouping_group(['groupingid' => $groupingb->id, 'groupid' => $groupb->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $teacher->id]);
        // The student is only in group B (grouping B) - not group A.
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $student->id]);

        $this->ij_generator()->create_entry($diarya, (int) $student->id, 'Entry in activity A.', INSIGHTJOURNAL_VISIBILITY_VISIBLE);
        $this->ij_generator()->create_entry($diaryb, (int) $student->id, 'Entry in activity B.', INSIGHTJOURNAL_VISIBILITY_VISIBLE);

        $this->setUser($teacher);

        $activities = [$cma->instance => $cma, $cmb->instance => $cmb];

        // Layer 1: the SQL-level prefilter is the union of the teacher's
        // groups across both restricted activities - group A and group B.
        $restrictgroupids = insightjournal_coursereport_restrict_groupids($activities, $this->course);
        $this->assertEqualsCanonicalizing([(int) $groupa->id, (int) $groupb->id], $restrictgroupids);

        // The student (in group B only) matches the union and would pass a
        // real get_enrolled_users($restrictgroupids) call; the outsider
        // (in neither group) would not - confirmed directly, since this
        // test doesn't call get_enrolled_users() itself.
        $studentgroupids = array_keys(groups_get_all_groups($this->course->id, (int) $student->id));
        $this->assertNotEmpty(array_intersect($restrictgroupids, $studentgroupids));
        $outsidergroupids = array_keys(groups_get_all_groups($this->course->id, (int) $outsider->id));
        $this->assertEmpty(array_intersect($restrictgroupids, $outsidergroupids));

        // Layer 2: even though the student passes the SQL prefilter, the
        // per-activity cell check must still mask activity A specifically.
        $this->assertFalse(insightjournal_activity_visible_to_viewer(
            context_module::instance($cma->id),
            $this->course,
            $cma,
            (int) $student->id
        ));
        $this->assertTrue(insightjournal_activity_visible_to_viewer(
            context_module::instance($cmb->id),
            $this->course,
            $cmb,
            (int) $student->id
        ));
    }

    /**
     * Two activities sharing a grouping resolve to the same allowed group
     * ids for the same viewer - the per-groupingid cache must not corrupt
     * the result for either activity.
     */
    public function test_allowed_groupids_by_diary_shares_result_across_shared_grouping(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');

        $diarya = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $diaryb = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cma = get_coursemodule_from_id('insightjournal', $diarya->cmid, 0, false, MUST_EXIST);
        $cmb = get_coursemodule_from_id('insightjournal', $diaryb->cmid, 0, false, MUST_EXIST);

        $grouping = $generator->create_grouping(['courseid' => $this->course->id]);
        $group = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_grouping_group(['groupingid' => $grouping->id, 'groupid' => $group->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $teacher->id]);

        $DB->set_field('course_modules', 'groupingid', $grouping->id, ['id' => $cma->id]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cma->id]);
        $DB->set_field('course_modules', 'groupingid', $grouping->id, ['id' => $cmb->id]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cmb->id]);
        $cma = get_coursemodule_from_id('insightjournal', $diarya->cmid, 0, false, MUST_EXIST);
        $cmb = get_coursemodule_from_id('insightjournal', $diaryb->cmid, 0, false, MUST_EXIST);

        $this->setUser($teacher);

        $result = insightjournal_coursereport_allowed_groupids_by_diary(
            [$diarya->id => $cma, $diaryb->id => $cmb],
            $this->course
        );

        $this->assertEquals([(int) $group->id], $result[$diarya->id]);
        $this->assertEquals([(int) $group->id], $result[$diaryb->id]);
    }

    /**
     * An activity that isn't group-restricted for the current viewer maps
     * to null - the "unrestricted" marker, not an empty group id array
     * (which would incorrectly mean "restricted, but matches nobody").
     */
    public function test_allowed_groupids_by_diary_null_when_not_restricted(): void {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $diary = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);

        $this->setUser($teacher);

        $result = insightjournal_coursereport_allowed_groupids_by_diary(
            [$diary->id => $cm],
            $this->course
        );

        $this->assertNull($result[$diary->id]);
    }

    /**
     * insightjournal_coursereport_diary_allowed_users() scopes its result to
     * exactly the given userids - a course member who belongs to an allowed
     * group but isn't in the candidate list at all must not appear. Uses a
     * plain int as the "diary id" key throughout, since this function does
     * no DB lookup tied to diary identity - it only maps over its inputs.
     */
    public function test_diary_allowed_users_scoped_to_given_userids(): void {
        $generator = $this->getDataGenerator();
        $ingroupinlist = $generator->create_and_enrol($this->course, 'student');
        $ingroupnotinlist = $generator->create_and_enrol($this->course, 'student');
        $group = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $ingroupinlist->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $ingroupnotinlist->id]);

        $result = insightjournal_coursereport_diary_allowed_users(
            [1 => [(int) $group->id]],
            [(int) $ingroupinlist->id]
        );

        $this->assertEquals([(int) $ingroupinlist->id => true], $result[1]);
    }

    /**
     * A null entry (unrestricted activity) passes through as null, never as
     * an empty map.
     */
    public function test_diary_allowed_users_passes_through_null(): void {
        $result = insightjournal_coursereport_diary_allowed_users(
            [1 => null],
            [1, 2, 3]
        );

        $this->assertNull($result[1]);
    }
}
