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
 * Unit tests for the save_entry external function of mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_insightjournal\external;

use advanced_testcase;
use core_external\external_api;

/**
 * Tests for {@see \mod_insightjournal\external\save_entry}.
 *
 * @covers \mod_insightjournal\external\save_entry
 */
final class save_entry_test extends advanced_testcase {
    /** @var \stdClass The created course. */
    protected $course;

    /** @var \stdClass The created insight journal instance (with ->cmid). */
    protected $journal;

    /** @var \stdClass The enrolled student. */
    protected $student;

    /**
     * Creates a completion-enabled journal with a minimum length and an enrolled student.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['enablecompletion' => 1]);
        $this->journal = $generator->create_module('insightjournal', [
            'course' => $this->course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionentries' => 1,
            'minchars' => 10,
        ]);
        $this->student = $generator->create_and_enrol($this->course, 'student');
        $this->setUser($this->student);
    }

    /**
     * Calls the external function and returns the cleaned result.
     *
     * @param string $response The learner response to save.
     * @return array The cleaned external return value.
     */
    protected function save(string $response): array {
        $result = save_entry::execute((int) $this->journal->cmid, $response);
        return external_api::clean_returnvalue(save_entry::execute_returns(), $result);
    }

    /**
     * Reads the stored completion state for the student.
     *
     * @return int The COMPLETION_* constant.
     */
    protected function completionstate(): int {
        $cm = get_fast_modinfo($this->course)->get_cm($this->journal->cmid);
        $completioninfo = new \completion_info($this->course);
        return (int) $completioninfo->get_data($cm, false, (int) $this->student->id)->completionstate;
    }

    /**
     * A first save persists the entry for the current user.
     */
    public function test_save_creates_entry(): void {
        global $DB;

        $result = $this->save('short');

        $this->assertTrue($result['success']);
        $entries = $DB->get_records('insightjournal_entries', [
            'insightjournalid' => $this->journal->id,
            'userid' => $this->student->id,
        ]);
        $this->assertCount(1, $entries);
        $this->assertEquals('short', reset($entries)->response);
    }

    /**
     * Saving again updates the same row rather than inserting a duplicate.
     */
    public function test_second_save_updates_existing_entry(): void {
        global $DB;

        $first = $this->save('first response');
        $second = $this->save('second response');

        $this->assertEquals($first['id'], $second['id']);
        $this->assertEquals(1, $DB->count_records('insightjournal_entries', [
            'insightjournalid' => $this->journal->id,
            'userid' => $this->student->id,
        ]));
        $stored = $DB->get_record('insightjournal_entries', ['id' => $second['id']]);
        $this->assertEquals('second response', $stored->response);
    }

    /**
     * A response below minchars saves but must not complete the activity.
     *
     * Regression test: save_entry previously forced COMPLETION_COMPLETE on every
     * save, bypassing the minchars rule.
     */
    public function test_short_response_does_not_complete(): void {
        $this->save('short');
        $this->assertEquals(COMPLETION_INCOMPLETE, $this->completionstate());
    }

    /**
     * A response meeting minchars completes the activity.
     */
    public function test_long_response_completes(): void {
        $this->save(str_repeat('reflection ', 5));
        $this->assertEquals(COMPLETION_COMPLETE, $this->completionstate());
    }

    /**
     * Shortening a previously qualifying response reverts completion.
     *
     * Regression test: completion must be recalculated, not latched, on each save.
     */
    public function test_completion_reverts_when_response_shortened(): void {
        $this->save(str_repeat('reflection ', 5));
        $this->assertEquals(COMPLETION_COMPLETE, $this->completionstate());

        $this->save('tiny');
        $this->assertEquals(COMPLETION_INCOMPLETE, $this->completionstate());
    }
}
