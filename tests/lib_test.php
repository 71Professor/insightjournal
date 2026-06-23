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

/**
 * Tests for the mod_insightjournal lib.php callbacks.
 */
#[CoversFunction('insightjournal_supports')]
#[CoversFunction('insightjournal_delete_instance')]
#[CoversFunction('insightjournal_get_coursemodule_info')]
#[CoversFunction('insightjournal_get_completion_active_rule_descriptions')]
final class lib_test extends advanced_testcase {
    /**
     * insightjournal_supports() reports the expected feature support.
     */
    public function test_supports(): void {
        $this->assertTrue(insightjournal_supports(FEATURE_MOD_INTRO));
        $this->assertTrue(insightjournal_supports(FEATURE_BACKUP_MOODLE2));
        $this->assertTrue(insightjournal_supports(FEATURE_COMPLETION_HAS_RULES));
        $this->assertTrue(insightjournal_supports(FEATURE_COMPLETION_TRACKS_VIEWS));
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
}
