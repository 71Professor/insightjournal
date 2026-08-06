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
 * Integration tests wiring locallib.php's group-restriction helpers into
 * report_table construction, the same way report.php does.
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
use mod_insightjournal\table\report_table;
use moodle_url;
use PHPUnit\Framework\Attributes\CoversFunction;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');
require_once($CFG->dirroot . '/mod/insightjournal/lib.php');

/**
 * Reproduces report.php's actual production call sequence for
 * $restrictgroupids - unlike report_table_test.php's restrict-to-groupids
 * tests, which pass a hand-supplied array, and locallib_groups_test.php,
 * which tests the helper functions in isolation - proving the two are
 * correctly wired together end-to-end at the PHPUnit level. Behat already
 * covers the equivalent for coursereport.php.
 *
 * Tests for {@see \insightjournal_activity_group_restricted()} and
 * {@see \insightjournal_current_user_allowed_groupids()}, as wired into
 * {@see report_table}.
 */
#[CoversFunction('insightjournal_activity_group_restricted')]
#[CoversFunction('insightjournal_current_user_allowed_groupids')]
final class report_authorization_test extends advanced_testcase {
    /** @var stdClass The course. */
    protected stdClass $course;

    /** @var stdClass The insight journal instance. */
    protected stdClass $diary;

    /** @var stdClass The activity's course-module record. */
    protected stdClass $cm;

    /** @var context_module The activity's module context. */
    protected context_module $context;

    /**
     * Creates a course with Separate Groups forced at the activity level.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        global $DB, $PAGE;
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->diary = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_id('insightjournal', $this->diary->cmid, 0, false, MUST_EXIST);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $this->cm->id]);
        $this->cm = get_coursemodule_from_id('insightjournal', $this->diary->cmid, 0, false, MUST_EXIST);
        $this->context = context_module::instance($this->cm->id);
        $PAGE->set_url('/mod/insightjournal/report.php', ['id' => $this->cm->id]);
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
     * Builds the report_table exactly the way report.php does -
     * computing $restrictgroupids via the real production helpers, not a
     * hand-supplied array - and renders it to a string.
     *
     * @return string The rendered HTML.
     */
    protected function render_table(): string {
        $restrictgroupids = insightjournal_activity_group_restricted($this->context, $this->course, $this->cm)
            ? insightjournal_current_user_allowed_groupids($this->course, $this->cm)
            : null;

        $table = new report_table(
            'report_authorization_test',
            $this->course,
            $this->cm,
            $this->diary,
            $this->context,
            '',
            $restrictgroupids
        );
        $table->setup_columns();
        $table->define_baseurl(new moodle_url('/mod/insightjournal/report.php', ['id' => $this->cm->id]));

        ob_start();
        $table->out(20, false);
        return ob_get_clean();
    }

    /**
     * A non-editing teacher without accessallgroups, in Separate Groups
     * mode, sees only their own group's entries through the real
     * production wiring.
     */
    public function test_teacher_without_accessallgroups_sees_only_own_group(): void {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $studenta = $generator->create_and_enrol($this->course, 'student');
        $studentb = $generator->create_and_enrol($this->course, 'student');

        $groupa = $generator->create_group(['courseid' => $this->course->id]);
        $groupb = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $studenta->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $studentb->id]);

        $this->ij_generator()->create_entry($this->diary, (int) $studenta->id, 'Group A entry.', INSIGHTJOURNAL_VISIBILITY_VISIBLE);
        $this->ij_generator()->create_entry($this->diary, (int) $studentb->id, 'Group B entry.', INSIGHTJOURNAL_VISIBILITY_VISIBLE);

        $this->setUser($teacher);
        $html = $this->render_table();

        $this->assertStringContainsString('Group A entry.', $html);
        $this->assertStringNotContainsString('Group B entry.', $html);
    }

    /**
     * R5-01 regression: report_table.php's own EXISTS(...groups_members...)
     * restriction must respect a group's GROUPS_VISIBILITY_OWN setting, not
     * just plain groupid membership. A teacher who is themselves a member
     * of an OWN-visibility group can see the group exists and their own
     * membership, but never another member's - including that other
     * member's entry row, which they previously saw purely because both
     * happened to share a groupid the teacher was already allowed to see.
     *
     * The visibility/participation coupling (OWN/NONE visibility forces
     * participation=false) is enforced only inside
     * groups_create_group()/groups_update_group(), not as a database
     * constraint - course restore's
     * restore_groups_structure_step::process_group() inserts group rows via
     * a raw $DB->insert_record('groups', $data), confirmed to bypass that
     * enforcement, so a course whose source database already held an
     * inconsistent group can propagate it through an otherwise completely
     * ordinary restore. The direct set_field() below simulates that
     * resulting state, since it isn't reachable via create_group() (which
     * correctly enforces the coupling, matching real Moodle behaviour).
     *
     * moodle/course:viewhiddengroups is CAP_ALLOW for 'teacher' by default
     * and, correctly, bypasses this restriction entirely (see
     * test_viewhiddengroups_teacher_sees_entries_despite_own_visibility()
     * below) - explicitly revoked here so this test actually exercises the
     * restricted path instead of passing vacuously.
     */
    public function test_teacher_without_accessallgroups_sees_no_entries_when_group_visibility_is_own(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $student = $generator->create_and_enrol($this->course, 'student');
        $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        assign_capability(
            'moodle/course:viewhiddengroups',
            CAP_PREVENT,
            $teacherroleid,
            \context_course::instance($this->course->id),
            true
        );

        $group = $generator->create_group([
            'courseid' => $this->course->id,
            'visibility' => GROUPS_VISIBILITY_OWN,
        ]);
        $DB->set_field('groups', 'participation', 1, ['id' => $group->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);

        $this->ij_generator()->create_entry(
            $this->diary,
            (int) $student->id,
            'Hidden by OWN visibility.',
            INSIGHTJOURNAL_VISIBILITY_VISIBLE
        );

        $this->setUser($teacher);
        $html = $this->render_table();

        $this->assertStringNotContainsString('Hidden by OWN visibility.', $html);
    }

    /**
     * R5-01 counterpart: a teacher holding moodle/course:viewhiddengroups
     * (the default) still sees a fellow OWN-visibility group member's
     * entry exactly as before the fix - the fix must not regress the
     * common case for the role that primarily uses this report.
     */
    public function test_viewhiddengroups_teacher_sees_entries_despite_own_visibility(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $student = $generator->create_and_enrol($this->course, 'student');

        $group = $generator->create_group([
            'courseid' => $this->course->id,
            'visibility' => GROUPS_VISIBILITY_OWN,
        ]);
        $DB->set_field('groups', 'participation', 1, ['id' => $group->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);

        $this->ij_generator()->create_entry(
            $this->diary,
            (int) $student->id,
            'Visible to viewhiddengroups holder.',
            INSIGHTJOURNAL_VISIBILITY_VISIBLE
        );

        $this->setUser($teacher);
        $html = $this->render_table();

        $this->assertStringContainsString('Visible to viewhiddengroups holder.', $html);
    }

    /**
     * R5-01, MEMBERS-visibility case through the real production call
     * chain: unlike OWN, MEMBERS visibility means members can see each
     * other, so a teacher without viewhiddengroups must still see a
     * fellow member's entry - proves the fix doesn't over-restrict a
     * group visibility level distinct from OWN.
     */
    public function test_teacher_without_accessallgroups_sees_entries_when_group_visibility_is_members(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $student = $generator->create_and_enrol($this->course, 'student');
        $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        assign_capability(
            'moodle/course:viewhiddengroups',
            CAP_PREVENT,
            $teacherroleid,
            \context_course::instance($this->course->id),
            true
        );

        $group = $generator->create_group([
            'courseid' => $this->course->id,
            'visibility' => GROUPS_VISIBILITY_MEMBERS,
        ]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);

        $this->ij_generator()->create_entry(
            $this->diary,
            (int) $student->id,
            'Visible under MEMBERS visibility.',
            INSIGHTJOURNAL_VISIBILITY_VISIBLE
        );

        $this->setUser($teacher);
        $html = $this->render_table();

        $this->assertStringContainsString('Visible under MEMBERS visibility.', $html);
    }

    /**
     * Control case: a manager (accessallgroups by default) sees every
     * group's entries through the same production wiring - proves the
     * restriction above is conditional on the real capability check, not
     * an accident of the table always filtering.
     */
    public function test_manager_with_accessallgroups_sees_every_group(): void {
        $generator = $this->getDataGenerator();
        $manager = $generator->create_and_enrol($this->course, 'manager');
        $studenta = $generator->create_and_enrol($this->course, 'student');
        $studentb = $generator->create_and_enrol($this->course, 'student');

        $groupa = $generator->create_group(['courseid' => $this->course->id]);
        $groupb = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $studenta->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $studentb->id]);

        $this->ij_generator()->create_entry($this->diary, (int) $studenta->id, 'Group A entry.', INSIGHTJOURNAL_VISIBILITY_VISIBLE);
        $this->ij_generator()->create_entry($this->diary, (int) $studentb->id, 'Group B entry.', INSIGHTJOURNAL_VISIBILITY_VISIBLE);

        $this->setUser($manager);
        $html = $this->render_table();

        $this->assertStringContainsString('Group A entry.', $html);
        $this->assertStringContainsString('Group B entry.', $html);
    }

    /**
     * The review's own literal R3-01 acceptance criterion, reproduced
     * end-to-end through the real report.php wiring: two groupings and
     * a non-participating group - only entries belonging to members of
     * the groups actually allowed for *this* activity appear.
     */
    public function test_two_groupings_and_a_non_participating_group(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $teacher = $generator->create_and_enrol($this->course, 'teacher');
        $studenta = $generator->create_and_enrol($this->course, 'student');
        $studentb = $generator->create_and_enrol($this->course, 'student');
        $studentc = $generator->create_and_enrol($this->course, 'student');

        $groupinga = $generator->create_grouping(['courseid' => $this->course->id]);
        $groupingb = $generator->create_grouping(['courseid' => $this->course->id]);

        // Allowed: same grouping as the activity, participation-eligible.
        $groupa = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_grouping_group(['groupingid' => $groupinga->id, 'groupid' => $groupa->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $studenta->id]);

        // Wrong grouping - must not appear, even though the teacher is a member.
        $groupb = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_grouping_group(['groupingid' => $groupingb->id, 'groupid' => $groupb->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $studentb->id]);

        // Right grouping, but not participation-eligible - must not appear.
        $groupc = $generator->create_group(['courseid' => $this->course->id, 'participation' => false]);
        $generator->create_grouping_group(['groupingid' => $groupinga->id, 'groupid' => $groupc->id]);
        $generator->create_group_member(['groupid' => $groupc->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $groupc->id, 'userid' => $studentc->id]);

        $DB->set_field('course_modules', 'groupingid', $groupinga->id, ['id' => $this->cm->id]);
        $this->cm = get_coursemodule_from_id('insightjournal', $this->diary->cmid, 0, false, MUST_EXIST);
        $this->context = context_module::instance($this->cm->id);

        $this->ij_generator()->create_entry($this->diary, (int) $studenta->id, 'Allowed entry.', INSIGHTJOURNAL_VISIBILITY_VISIBLE);
        $this->ij_generator()->create_entry(
            $this->diary,
            (int) $studentb->id,
            'Wrong grouping entry.',
            INSIGHTJOURNAL_VISIBILITY_VISIBLE
        );
        $this->ij_generator()->create_entry(
            $this->diary,
            (int) $studentc->id,
            'Non participating entry.',
            INSIGHTJOURNAL_VISIBILITY_VISIBLE
        );

        $this->setUser($teacher);
        $html = $this->render_table();

        $this->assertStringContainsString('Allowed entry.', $html);
        $this->assertStringNotContainsString('Wrong grouping entry.', $html);
        $this->assertStringNotContainsString('Non participating entry.', $html);
    }
}
