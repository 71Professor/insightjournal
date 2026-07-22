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
 * Backup/restore tests for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_insightjournal;

use advanced_testcase;
use backup;
use backup_controller;
use backup_setting;
use restore_controller;
use restore_dbops;
use stdClass;

/**
 * Tests that a course backup/restore round-trip preserves instance settings.
 *
 * @covers \backup_insightjournal_activity_structure_step
 * @covers \restore_insightjournal_activity_structure_step
 */
final class backup_test extends advanced_testcase {
    /**
     * Fixtures needed by the backup/restore subsystem.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        parent::setUpBeforeClass();
    }

    /**
     * An entry's visibility, decided by its author, round-trips through a
     * course backup/restore, the same as the other per-entry fields.
     *
     * Regression coverage: backup_insightjournal_stepslib.php enumerates its
     * backed-up fields explicitly, so a new DB column silently vanishes on
     * restore unless it is added to that list.
     */
    public function test_entry_visibility_survives_backup_and_restore(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $journal = $this->getDataGenerator()->create_module('insightjournal', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');

        /** @var \mod_insightjournal_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_insightjournal');
        $plugingenerator->create_entry($journal, (int) $user->id, 'Private reflection.', INSIGHTJOURNAL_VISIBILITY_PRIVATE);

        $newcourseid = $this->backup_and_restore($course);

        $restoredjournal = $DB->get_record('insightjournal', ['course' => $newcourseid], '*', MUST_EXIST);
        $restoredentry = $DB->get_record(
            'insightjournal_entries',
            ['insightjournalid' => $restoredjournal->id],
            '*',
            MUST_EXIST
        );
        $this->assertEquals(INSIGHTJOURNAL_VISIBILITY_PRIVATE, (int) $restoredentry->visibility);
        $this->assertEquals($journal->name, $restoredjournal->name);
    }

    /**
     * Backs a course up and restores it into a new course, with user data included.
     *
     * @param stdClass $srccourse Course object to back up.
     * @return int ID of the newly restored course.
     */
    private function backup_and_restore(stdClass $srccourse): int {
        global $USER, $CFG;

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

        // Turn off file logging, otherwise it can't delete the file (Windows).
        $CFG->backup_file_logger_level = backup::LOG_NONE;

        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $srccourse->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_status(backup_setting::NOT_LOCKED);
        $bc->get_plan()->get_setting('users')->set_value(true);

        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $newcourseid = restore_dbops::create_new_course(
            $srccourse->fullname,
            $srccourse->shortname . '_2',
            $srccourse->category
        );
        $rc = new restore_controller(
            $backupid,
            $newcourseid,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id,
            backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_status(backup_setting::NOT_LOCKED);
        $rc->get_plan()->get_setting('users')->set_value(true);

        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        return $newcourseid;
    }
}
