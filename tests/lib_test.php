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
 * Unit tests for the mod_insightjournal lib callbacks.
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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/insightjournal/lib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Tests for the mod_insightjournal lib.php callbacks.
 */
#[CoversFunction('insightjournal_supports')]
#[CoversFunction('insightjournal_delete_instance')]
#[CoversFunction('insightjournal_get_coursemodule_info')]
#[CoversFunction('insightjournal_get_completion_active_rule_descriptions')]
#[CoversFunction('insightjournal_add_instance')]
#[CoversFunction('insightjournal_update_instance')]
#[CoversFunction('insightjournal_reset_course_userdata')]
final class lib_test extends advanced_testcase {
    /**
     * insightjournal_supports() reports the expected feature support.
     */
    public function test_supports(): void {
        $this->assertTrue(insightjournal_supports(FEATURE_MOD_INTRO));
        $this->assertTrue(insightjournal_supports(FEATURE_BACKUP_MOODLE2));
        $this->assertTrue(insightjournal_supports(FEATURE_COMPLETION_HAS_RULES));
        $this->assertTrue(insightjournal_supports(FEATURE_COMPLETION_TRACKS_VIEWS));
        $this->assertTrue(insightjournal_supports(FEATURE_GROUPS));
        $this->assertFalse(insightjournal_supports(FEATURE_GRADE_HAS_GRADE));
        $this->assertEquals(MOD_PURPOSE_COLLABORATION, insightjournal_supports(FEATURE_MOD_PURPOSE));
        $this->assertNull(insightjournal_supports('a non existent feature'));
    }

    /**
     * Creating and deleting an instance round-trips correctly and cleans up entries.
     */
    public function test_create_and_delete_instance(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $journal = $this->getDataGenerator()->create_module('insightjournal', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');

        /** @var \mod_insightjournal_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_insightjournal');
        $plugingenerator->create_entry($journal, (int) $user->id, 'Some reflection.');

        $this->assertTrue($DB->record_exists('insightjournal', ['id' => $journal->id]));
        $this->assertEquals(1, $DB->count_records('insightjournal_entries', ['insightjournalid' => $journal->id]));

        $this->assertTrue(insightjournal_delete_instance($journal->id));

        $this->assertFalse($DB->record_exists('insightjournal', ['id' => $journal->id]));
        $this->assertEquals(0, $DB->count_records('insightjournal_entries', ['insightjournalid' => $journal->id]));
    }

    /**
     * get_coursemodule_info() exposes the custom completion rule when automatic
     * completion is enabled.
     *
     * Regression test: the callback previously omitted customcompletionrules, so
     * core completion never saw the completionentries rule.
     */
    public function test_get_coursemodule_info_registers_rule(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $journal = $this->getDataGenerator()->create_module('insightjournal', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionentries' => 1,
        ]);

        $coursemodule = get_coursemodule_from_instance('insightjournal', $journal->id);
        $info = insightjournal_get_coursemodule_info($coursemodule);

        $this->assertNotNull($info);
        $this->assertEquals($journal->name, $info->name);
        $this->assertArrayHasKey('customcompletionrules', $info->customdata);
        $this->assertArrayHasKey('completionentries', $info->customdata['customcompletionrules']);
        $this->assertEquals(1, $info->customdata['customcompletionrules']['completionentries']);
    }

    /**
     * Without automatic completion the custom rule is not registered.
     */
    public function test_get_coursemodule_info_without_automatic_completion(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $journal = $this->getDataGenerator()->create_module('insightjournal', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);

        $coursemodule = get_coursemodule_from_instance('insightjournal', $journal->id);
        $info = insightjournal_get_coursemodule_info($coursemodule);

        $this->assertNotNull($info);
        $this->assertTrue(empty($info->customdata['customcompletionrules']));
    }

    /**
     * A promptcolor missing its leading hash is normalised on instance creation.
     */
    public function test_add_instance_normalises_promptcolor_without_hash(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $journal = $this->getDataGenerator()->create_module('insightjournal', [
            'course' => $course->id,
            'promptcolor' => 'ffcc00',
        ]);

        $stored = $DB->get_record('insightjournal', ['id' => $journal->id]);
        $this->assertEquals('#ffcc00', $stored->promptcolor);
    }

    /**
     * A promptcolor missing its leading hash is normalised on instance update.
     */
    public function test_update_instance_normalises_promptcolor_without_hash(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $journal = $this->getDataGenerator()->create_module('insightjournal', ['course' => $course->id]);

        $update = (object) [
            'instance' => $journal->id,
            'promptcolor' => 'abc',
        ];
        insightjournal_update_instance($update);

        $stored = $DB->get_record('insightjournal', ['id' => $journal->id]);
        $this->assertEquals('#abc', $stored->promptcolor);
    }

    /**
     * An invalid promptcolor is normalised to an empty string on instance creation,
     * rather than being stored verbatim.
     */
    public function test_add_instance_rejects_invalid_promptcolor(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $journal = $this->getDataGenerator()->create_module('insightjournal', [
            'course' => $course->id,
            'promptcolor' => 'not-a-color',
        ]);

        $stored = $DB->get_record('insightjournal', ['id' => $journal->id]);
        $this->assertSame('', $stored->promptcolor);
    }

    /**
     * An invalid promptcolor is normalised to an empty string on instance update,
     * rather than being stored verbatim.
     */
    public function test_update_instance_rejects_invalid_promptcolor(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $journal = $this->getDataGenerator()->create_module('insightjournal', ['course' => $course->id]);

        $update = (object) [
            'instance' => $journal->id,
            'promptcolor' => 'not-a-color',
        ];
        insightjournal_update_instance($update);

        $stored = $DB->get_record('insightjournal', ['id' => $journal->id]);
        $this->assertSame('', $stored->promptcolor);
    }

    /**
     * The active rule description is returned only when the rule is enabled.
     */
    public function test_get_completion_active_rule_descriptions(): void {
        $this->resetAfterTest();

        $enabled = (object) ['customdata' => ['customcompletionrules' => ['completionentries' => 1]]];
        $descriptions = insightjournal_get_completion_active_rule_descriptions($enabled);
        $this->assertCount(1, $descriptions);

        $disabled = (object) ['customdata' => ['customcompletionrules' => ['completionentries' => 0]]];
        $this->assertEmpty(insightjournal_get_completion_active_rule_descriptions($disabled));

        $none = (object) ['customdata' => []];
        $this->assertEmpty(insightjournal_get_completion_active_rule_descriptions($none));
    }

    /**
     * Reads the stored completion state for a user under a given instance's cm.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $journal The insight journal instance (with ->cmid).
     * @param int $userid The user to read completion for.
     * @return int The COMPLETION_* constant.
     */
    protected function completionstate(\stdClass $course, \stdClass $journal, int $userid): int {
        $cm = get_fast_modinfo($course)->get_cm($journal->cmid);
        $completion = new \completion_info($course);
        return (int) $completion->get_data($cm, false, $userid)->completionstate;
    }

    /**
     * Deleting entries via course reset removes every entry for every
     * instance in the course, not just the first.
     */
    public function test_reset_course_userdata_deletes_entries(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $journala = $generator->create_module('insightjournal', ['course' => $course->id]);
        $journalb = $generator->create_module('insightjournal', ['course' => $course->id]);
        $student = $generator->create_and_enrol($course, 'student');

        /** @var \mod_insightjournal_generator $plugingenerator */
        $plugingenerator = $generator->get_plugin_generator('mod_insightjournal');
        $plugingenerator->create_entry($journala, (int) $student->id, 'Reflection A.');
        $plugingenerator->create_entry($journalb, (int) $student->id, 'Reflection B.');

        insightjournal_reset_course_userdata((object) [
            'courseid' => $course->id,
            'reset_insightjournal_entries' => 1,
        ]);

        $this->assertEquals(0, $DB->count_records('insightjournal_entries', ['insightjournalid' => $journala->id]));
        $this->assertEquals(0, $DB->count_records('insightjournal_entries', ['insightjournalid' => $journalb->id]));
    }

    /**
     * Without the reset flag set, entries and completion are both left alone.
     */
    public function test_reset_course_userdata_without_flag_changes_nothing(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/completionlib.php');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $journal = $generator->create_module('insightjournal', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionentries' => 1,
            'minchars' => 1,
        ]);
        $student = $generator->create_and_enrol($course, 'student');

        /** @var \mod_insightjournal_generator $plugingenerator */
        $plugingenerator = $generator->get_plugin_generator('mod_insightjournal');
        $plugingenerator->create_entry($journal, (int) $student->id, 'Reflection.');
        $cm = get_fast_modinfo($course)->get_cm($journal->cmid);
        (new \completion_info($course))->update_state($cm, COMPLETION_UNKNOWN, (int) $student->id);
        $this->assertEquals(COMPLETION_COMPLETE, $this->completionstate($course, $journal, (int) $student->id));

        insightjournal_reset_course_userdata((object) [
            'courseid' => $course->id,
            'reset_insightjournal_entries' => 0,
        ]);

        $this->assertEquals(1, $DB->count_records('insightjournal_entries', ['insightjournalid' => $journal->id]));
        $this->assertEquals(COMPLETION_COMPLETE, $this->completionstate($course, $journal, (int) $student->id));
    }

    /**
     * A learner whose entry was deleted by a course reset no longer shows a
     * completed activity - completion must be recalculated per affected
     * instance, not left pointing at data that no longer exists.
     *
     * Regression test (CR-01): insightjournal_reset_course_userdata()
     * previously deleted entries without touching completion state, so a
     * learner who had already completed the activity stayed "complete"
     * after their entry was wiped by a course reset.
     */
    public function test_reset_course_userdata_resets_completion_state(): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/completionlib.php');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $journal = $generator->create_module('insightjournal', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionentries' => 1,
            'minchars' => 1,
        ]);
        $student = $generator->create_and_enrol($course, 'student');

        /** @var \mod_insightjournal_generator $plugingenerator */
        $plugingenerator = $generator->get_plugin_generator('mod_insightjournal');
        $plugingenerator->create_entry($journal, (int) $student->id, 'Reflection.');
        $cm = get_fast_modinfo($course)->get_cm($journal->cmid);
        (new \completion_info($course))->update_state($cm, COMPLETION_UNKNOWN, (int) $student->id);
        $this->assertEquals(COMPLETION_COMPLETE, $this->completionstate($course, $journal, (int) $student->id));

        insightjournal_reset_course_userdata((object) [
            'courseid' => $course->id,
            'reset_insightjournal_entries' => 1,
        ]);

        $this->assertEquals(COMPLETION_INCOMPLETE, $this->completionstate($course, $journal, (int) $student->id));
    }

    /**
     * The completion reset applies per affected instance - a course with two
     * insight journal activities must not leave the second one's completion
     * stale after only the first is recalculated.
     */
    public function test_reset_course_userdata_resets_completion_for_every_instance(): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/completionlib.php');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $journala = $generator->create_module('insightjournal', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionentries' => 1,
            'minchars' => 1,
        ]);
        $journalb = $generator->create_module('insightjournal', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionentries' => 1,
            'minchars' => 1,
        ]);
        $student = $generator->create_and_enrol($course, 'student');

        /** @var \mod_insightjournal_generator $plugingenerator */
        $plugingenerator = $generator->get_plugin_generator('mod_insightjournal');
        $plugingenerator->create_entry($journala, (int) $student->id, 'Reflection A.');
        $plugingenerator->create_entry($journalb, (int) $student->id, 'Reflection B.');
        $completion = new \completion_info($course);
        $completion->update_state(get_fast_modinfo($course)->get_cm($journala->cmid), COMPLETION_UNKNOWN, (int) $student->id);
        $completion->update_state(get_fast_modinfo($course)->get_cm($journalb->cmid), COMPLETION_UNKNOWN, (int) $student->id);
        $this->assertEquals(COMPLETION_COMPLETE, $this->completionstate($course, $journala, (int) $student->id));
        $this->assertEquals(COMPLETION_COMPLETE, $this->completionstate($course, $journalb, (int) $student->id));

        insightjournal_reset_course_userdata((object) [
            'courseid' => $course->id,
            'reset_insightjournal_entries' => 1,
        ]);

        $this->assertEquals(COMPLETION_INCOMPLETE, $this->completionstate($course, $journala, (int) $student->id));
        $this->assertEquals(COMPLETION_INCOMPLETE, $this->completionstate($course, $journalb, (int) $student->id));
    }
}
