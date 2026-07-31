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
 * Unit tests for the group-restriction helpers in locallib.php.
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
 * Tests for {@see \insightjournal_activity_group_restricted()} and
 * {@see \insightjournal_current_user_group_userids()}.
 */
#[CoversFunction('insightjournal_activity_group_restricted')]
#[CoversFunction('insightjournal_current_user_group_userids')]
final class locallib_groups_test extends advanced_testcase {
    /** @var stdClass The course. */
    protected stdClass $course;

    /** @var stdClass The insight journal instance. */
    protected stdClass $diary;

    /** @var stdClass The activity's course-module record. */
    protected stdClass $cm;

    /** @var context_module The activity's module context. */
    protected context_module $context;

    /**
     * Creates a course and an insight journal activity.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->diary = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_id('insightjournal', $this->diary->cmid, 0, false, MUST_EXIST);
        $this->context = context_module::instance($this->cm->id);
    }

    /**
     * Sets this activity's own group mode, bypassing any course-level force.
     *
     * @param int $groupmode NOGROUPS, SEPARATEGROUPS, or VISIBLEGROUPS.
     */
    protected function set_activity_groupmode(int $groupmode): void {
        global $DB;

        $DB->set_field('course_modules', 'groupmode', $groupmode, ['id' => $this->cm->id]);
        $this->cm = get_coursemodule_from_id('insightjournal', $this->diary->cmid, 0, false, MUST_EXIST);
    }

    /**
     * Assigns this activity to a specific grouping, bypassing the form.
     *
     * @param int $groupingid The grouping id (0 clears any grouping).
     */
    protected function set_activity_grouping(int $groupingid): void {
        global $DB;

        $DB->set_field('course_modules', 'groupingid', $groupingid, ['id' => $this->cm->id]);
        $this->cm = get_coursemodule_from_id('insightjournal', $this->diary->cmid, 0, false, MUST_EXIST);
    }

    /**
     * Creates a second insight journal activity in the same course,
     * assigned to a given grouping with Separate Groups mode - for
     * tests proving a viewer's authorization under one activity's
     * grouping does not leak into a different activity's grouping.
     *
     * @param int $groupingid The grouping id to assign the new activity to.
     * @return stdClass The new activity's course-module record.
     */
    protected function create_second_restricted_activity(int $groupingid): stdClass {
        global $DB;

        $diary = $this->getDataGenerator()->create_module('insightjournal', ['course' => $this->course->id]);
        $cm = get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
        $DB->set_field('course_modules', 'groupingid', $groupingid, ['id' => $cm->id]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cm->id]);

        return get_coursemodule_from_id('insightjournal', $diary->cmid, 0, false, MUST_EXIST);
    }

    /**
     * With group mode off, nobody is restricted.
     */
    public function test_not_restricted_when_group_mode_is_off(): void {
        $this->set_activity_groupmode(NOGROUPS);

        $this->assertFalse(
            insightjournal_activity_group_restricted($this->context, $this->course, $this->cm)
        );
    }

    /**
     * Visible Groups mode never restricts, even without accessallgroups.
     */
    public function test_not_restricted_in_visible_groups_mode(): void {
        $this->set_activity_groupmode(VISIBLEGROUPS);

        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $this->setUser($teacher);

        $this->assertFalse(
            insightjournal_activity_group_restricted($this->context, $this->course, $this->cm)
        );
    }

    /**
     * Separate Groups mode restricts a user without accessallgroups (the
     * 'teacher' archetype does not have it by default, unlike
     * 'editingteacher'/'manager').
     */
    public function test_restricted_in_separate_groups_without_accessallgroups(): void {
        $this->set_activity_groupmode(SEPARATEGROUPS);

        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $this->setUser($teacher);

        $this->assertTrue(
            insightjournal_activity_group_restricted($this->context, $this->course, $this->cm)
        );
    }

    /**
     * Separate Groups mode does not restrict a user with accessallgroups
     * ('manager' has it by default).
     */
    public function test_not_restricted_with_accessallgroups(): void {
        $this->set_activity_groupmode(SEPARATEGROUPS);

        $manager = $this->getDataGenerator()->create_and_enrol($this->course, 'manager');
        $this->setUser($manager);

        $this->assertFalse(
            insightjournal_activity_group_restricted($this->context, $this->course, $this->cm)
        );
    }

    /**
     * A course-forced group mode applies even if this activity's own
     * groupmode field was never touched.
     */
    public function test_restricted_when_course_forces_separate_groups(): void {
        global $DB;

        $DB->set_field('course', 'groupmode', SEPARATEGROUPS, ['id' => $this->course->id]);
        $DB->set_field('course', 'groupmodeforce', 1, ['id' => $this->course->id]);
        $this->course = $DB->get_record('course', ['id' => $this->course->id], '*', MUST_EXIST);

        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $this->setUser($teacher);

        $this->assertTrue(
            insightjournal_activity_group_restricted($this->context, $this->course, $this->cm)
        );
    }

    /**
     * The same course-forced restriction applies when $cm is a cm_info
     * instance (as coursereport.php/summary.php pass) rather than a plain
     * stdClass (as report.php passes). groups_get_activity_groupmode()
     * takes a genuinely different code path for cm_info - it reads
     * $cm->effectivegroupmode, which does consult FEATURE_GROUPS - versus a
     * plain stdClass, which reads $course->groupmodeforce directly and
     * never consults FEATURE_GROUPS at all. Without this test, deleting
     * FEATURE_GROUPS from lib.php would go undetected by every other test
     * in this file, since they all build $cm via get_coursemodule_from_id()
     * (a plain stdClass).
     */
    public function test_restricted_when_course_forces_separate_groups_with_cm_info(): void {
        global $DB;

        $DB->set_field('course', 'groupmode', SEPARATEGROUPS, ['id' => $this->course->id]);
        $DB->set_field('course', 'groupmodeforce', 1, ['id' => $this->course->id]);
        $this->course = $DB->get_record('course', ['id' => $this->course->id], '*', MUST_EXIST);
        rebuild_course_cache((int) $this->course->id, true);

        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $this->setUser($teacher);

        $cminfo = get_fast_modinfo($this->course)->get_cm($this->cm->id);

        $this->assertTrue(
            insightjournal_activity_group_restricted($this->context, $this->course, $cminfo)
        );
    }

    /**
     * The current user's own group's members are returned, and another
     * group's members are excluded.
     */
    public function test_group_userids_returns_own_group_members(): void {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $studenta = $generator->create_and_enrol($this->course, 'student');
        $studentb = $generator->create_and_enrol($this->course, 'student');

        $groupa = $generator->create_group(['courseid' => $this->course->id]);
        $groupb = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $studenta->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $studentb->id]);

        $this->setUser($teacher);

        $userids = insightjournal_current_user_group_userids($this->course);

        $this->assertContains((int) $teacher->id, $userids);
        $this->assertContains((int) $studenta->id, $userids);
        $this->assertNotContains((int) $studentb->id, $userids);
    }

    /**
     * A user belonging to no groups gets an empty result, not an error -
     * callers must treat this as "matches nobody," never as "unrestricted."
     */
    public function test_group_userids_empty_when_user_has_no_groups(): void {
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $this->setUser($teacher);

        $this->assertSame([], insightjournal_current_user_group_userids($this->course));
    }

    /**
     * A user in multiple groups gets the union of every group's members.
     */
    public function test_group_userids_unions_multiple_groups(): void {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $studenta = $generator->create_and_enrol($this->course, 'student');
        $studentb = $generator->create_and_enrol($this->course, 'student');

        $groupa = $generator->create_group(['courseid' => $this->course->id]);
        $groupb = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $studenta->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $studentb->id]);

        $this->setUser($teacher);

        $userids = insightjournal_current_user_group_userids($this->course);

        $this->assertContains((int) $studenta->id, $userids);
        $this->assertContains((int) $studentb->id, $userids);
    }

    /**
     * A falsy $USER->id (e.g. 0, the logged-out/guest sentinel) must still
     * produce "matches nobody," never silently fall through to
     * groups_get_all_groups() ignoring its userid filter and returning
     * every group's members course-wide.
     */
    public function test_group_userids_empty_when_user_id_is_falsy(): void {
        $generator = $this->getDataGenerator();
        $student = $generator->create_and_enrol($this->course, 'student');
        $group = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);

        $this->setUser(0);

        $this->assertSame([], insightjournal_current_user_group_userids($this->course));
    }

    /**
     * A grouping-scoped $cm restricts the returned userids to only the
     * groups belonging to that grouping - a group in a different
     * grouping must never leak in, even if the current user also
     * belongs to it.
     */
    public function test_group_userids_scoped_to_activity_groupingid_when_cm_given(): void {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $studenta = $generator->create_and_enrol($this->course, 'student');
        $studentb = $generator->create_and_enrol($this->course, 'student');

        $groupinga = $generator->create_grouping(['courseid' => $this->course->id]);
        $groupingb = $generator->create_grouping(['courseid' => $this->course->id]);

        $groupa = $generator->create_group(['courseid' => $this->course->id]);
        $groupb = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_grouping_group(['groupingid' => $groupinga->id, 'groupid' => $groupa->id]);
        $generator->create_grouping_group(['groupingid' => $groupingb->id, 'groupid' => $groupb->id]);

        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $studenta->id]);
        // The teacher also belongs to groupb, in the OTHER grouping - must not leak in.
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $studentb->id]);

        $this->set_activity_grouping((int) $groupinga->id);
        $this->setUser($teacher);

        $userids = insightjournal_current_user_group_userids($this->course, $this->cm);

        $this->assertContains((int) $studenta->id, $userids);
        $this->assertNotContains((int) $studentb->id, $userids);
    }

    /**
     * A non-participating group's members are excluded even when the
     * group belongs to the activity's own grouping - "participation"
     * governs whether a group counts for activity-level restriction at
     * all, separately from grouping membership.
     */
    public function test_group_userids_excludes_non_participating_group_when_cm_given(): void {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $studenta = $generator->create_and_enrol($this->course, 'student');

        $grouping = $generator->create_grouping(['courseid' => $this->course->id]);
        $group = $generator->create_group([
            'courseid' => $this->course->id,
            'participation' => false,
        ]);
        $generator->create_grouping_group(['groupingid' => $grouping->id, 'groupid' => $group->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $studenta->id]);

        $this->set_activity_grouping((int) $grouping->id);
        $this->setUser($teacher);

        $userids = insightjournal_current_user_group_userids($this->course, $this->cm);

        $this->assertSame([], $userids);
    }

    /**
     * Omitting $cm preserves today's course-wide behaviour exactly -
     * this is the exact call summary.php's untouched call site
     * continues to make. A future accidental change here would
     * silently alter summary.php's behaviour without summary.php itself
     * ever being touched.
     */
    public function test_group_userids_ignores_grouping_when_cm_omitted(): void {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $studentb = $generator->create_and_enrol($this->course, 'student');

        $groupinga = $generator->create_grouping(['courseid' => $this->course->id]);
        $groupingb = $generator->create_grouping(['courseid' => $this->course->id]);

        $groupa = $generator->create_group(['courseid' => $this->course->id]);
        $groupb = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_grouping_group(['groupingid' => $groupinga->id, 'groupid' => $groupa->id]);
        $generator->create_grouping_group(['groupingid' => $groupingb->id, 'groupid' => $groupb->id]);

        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $studentb->id]);

        $this->set_activity_grouping((int) $groupinga->id);
        $this->setUser($teacher);

        // No $cm passed - must return the union across every grouping,
        // exactly like before this fix, even though the activity itself
        // is now scoped to groupinga.
        $userids = insightjournal_current_user_group_userids($this->course);

        $this->assertContains((int) $studentb->id, $userids);
    }

    /**
     * Unrestricted activity: always visible, regardless of group
     * membership.
     */
    public function test_activity_visible_when_not_restricted(): void {
        $this->set_activity_groupmode(NOGROUPS);
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($teacher);

        $this->assertTrue(
            insightjournal_activity_visible_to_viewer($this->context, $this->course, $this->cm, (int) $student->id)
        );
    }

    /**
     * Restricted activity: target user in the viewer's own group for
     * this activity's grouping is visible.
     */
    public function test_activity_visible_when_target_in_viewer_group(): void {
        $this->set_activity_groupmode(SEPARATEGROUPS);
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $student = $generator->create_and_enrol($this->course, 'student');
        $group = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);
        $this->setUser($teacher);

        $this->assertTrue(
            insightjournal_activity_visible_to_viewer($this->context, $this->course, $this->cm, (int) $student->id)
        );
    }

    /**
     * A target user visible to the viewer only under a DIFFERENT
     * activity's grouping must not be visible under THIS one - the
     * R3-02 property.
     */
    public function test_activity_not_visible_when_target_only_in_different_grouping(): void {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $student = $generator->create_and_enrol($this->course, 'student');

        $groupinga = $generator->create_grouping(['courseid' => $this->course->id]);
        $groupingb = $generator->create_grouping(['courseid' => $this->course->id]);
        $groupa = $generator->create_group(['courseid' => $this->course->id]);
        $groupb = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_grouping_group(['groupingid' => $groupinga->id, 'groupid' => $groupa->id]);
        $generator->create_grouping_group(['groupingid' => $groupingb->id, 'groupid' => $groupb->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $student->id]);

        $this->set_activity_grouping((int) $groupinga->id);
        $this->set_activity_groupmode(SEPARATEGROUPS);
        $this->setUser($teacher);

        $this->assertFalse(
            insightjournal_activity_visible_to_viewer($this->context, $this->course, $this->cm, (int) $student->id)
        );
    }

    /**
     * Filters an activity list to just the ones visible to the viewer
     * for a given target user - two activities, different groupings,
     * target authorized under only one.
     */
    public function test_visible_activities_filters_to_authorized_only(): void {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $student = $generator->create_and_enrol($this->course, 'student');

        $groupinga = $generator->create_grouping(['courseid' => $this->course->id]);
        $groupingb = $generator->create_grouping(['courseid' => $this->course->id]);
        $this->set_activity_grouping((int) $groupinga->id);
        $this->set_activity_groupmode(SEPARATEGROUPS);
        $cmb = $this->create_second_restricted_activity((int) $groupingb->id);

        $groupa = $generator->create_group(['courseid' => $this->course->id]);
        $groupb = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_grouping_group(['groupingid' => $groupinga->id, 'groupid' => $groupa->id]);
        $generator->create_grouping_group(['groupingid' => $groupingb->id, 'groupid' => $groupb->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $student->id]);

        $this->setUser($teacher);

        $cms = [$this->cm->instance => $this->cm, $cmb->instance => $cmb];
        $visible = insightjournal_visible_activities_for_user($cms, $this->course, (int) $student->id);

        $this->assertArrayNotHasKey($this->cm->instance, $visible);
        $this->assertArrayHasKey($cmb->instance, $visible);
    }

    /**
     * At least one visible activity is unrestricted - no SQL-level
     * group filter is safe, since that activity alone could show every
     * enrolled participant a visible cell.
     */
    public function test_restrict_groupids_null_when_any_activity_unrestricted(): void {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $this->set_activity_groupmode(NOGROUPS);
        $this->setUser($teacher);

        $this->assertNull(
            insightjournal_coursereport_restrict_groupids([$this->cm->instance => $this->cm], $this->course)
        );
    }

    /**
     * Every visible activity is restricted - returns the union of the
     * viewer's own allowed groups across all of them.
     */
    public function test_restrict_groupids_unions_across_restricted_activities(): void {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $this->set_activity_groupmode(SEPARATEGROUPS);
        $cmb = $this->create_second_restricted_activity(0);

        $groupa = $generator->create_group(['courseid' => $this->course->id]);
        $groupb = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $teacher->id]);

        $this->setUser($teacher);

        $groupids = insightjournal_coursereport_restrict_groupids(
            [$this->cm->instance => $this->cm, $cmb->instance => $cmb],
            $this->course
        );

        $this->assertEqualsCanonicalizing([(int) $groupa->id, (int) $groupb->id], $groupids);
    }
}
