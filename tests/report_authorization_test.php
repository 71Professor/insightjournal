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
 * $restrictuserids - unlike report_table_test.php's restrict-to-userids
 * tests, which pass a hand-supplied array, and locallib_groups_test.php,
 * which tests the helper functions in isolation - proving the two are
 * correctly wired together end-to-end at the PHPUnit level. Behat already
 * covers the equivalent for coursereport.php.
 *
 * Tests for {@see \insightjournal_activity_group_restricted()} and
 * {@see \insightjournal_current_user_group_userids()}, as wired into
 * {@see report_table}.
 */
#[CoversFunction('insightjournal_activity_group_restricted')]
#[CoversFunction('insightjournal_current_user_group_userids')]
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
     * computing $restrictuserids via the real production helpers, not a
     * hand-supplied array - and renders it to a string.
     *
     * @return string The rendered HTML.
     */
    protected function render_table(): string {
        $restrictuserids = insightjournal_activity_group_restricted($this->context, $this->course, $this->cm)
            ? insightjournal_current_user_group_userids($this->course)
            : null;

        $table = new report_table(
            'report_authorization_test',
            $this->course,
            $this->cm,
            $this->diary,
            $this->context,
            '',
            $restrictuserids
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
}
