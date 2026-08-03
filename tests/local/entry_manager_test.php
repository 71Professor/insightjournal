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
 * Unit tests for the shared entry-saving service of mod_insightjournal.
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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');

/**
 * Tests for {@see entry_manager}, focused on the insert-race/write-failure
 * distinction that save_entry_test.php's end-to-end calls cannot exercise
 * (a genuine race requires a writer outside save()'s own lock, which cannot
 * be scheduled deterministically through the public save() entry point in a
 * single-threaded test).
 */
#[CoversClass(entry_manager::class)]
final class entry_manager_test extends advanced_testcase {
    /** @var \stdClass The created insight journal instance (with ->cmid). */
    protected $journal;

    /** @var \stdClass The enrolled student. */
    protected $student;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $this->journal = $generator->create_module('insightjournal', ['course' => $course->id]);
        $this->student = $generator->create_and_enrol($course, 'student');
    }

    /**
     * A unique-index hit on a brand-new insert can only happen when a row
     * for the same (insightjournalid, userid) pair was written by something
     * outside save()'s lock between its read and its own insert attempt -
     * the only real writer capable of that is a course restore (see
     * restore_insightjournal_stepslib.php). Reproduced directly here by
     * pre-inserting that row, since a genuine concurrent writer can't be
     * scheduled deterministically through save() itself in a single-threaded
     * test. Confirming the race must still surface it as an ordinary
     * conflict, not an uncaught error.
     */
    public function test_insert_race_with_external_writer_is_reported_as_conflict(): void {
        global $DB;

        $racingentry = (object) [
            'insightjournalid' => $this->journal->id,
            'userid' => $this->student->id,
            'response' => 'written by something outside save(), e.g. a course restore',
            'responseformat' => FORMAT_HTML,
            'revision' => 1,
            'visibility' => INSIGHTJOURNAL_VISIBILITY_VISIBLE,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $racingentry->id = $DB->insert_record('insightjournal_entries', $racingentry);

        $newentry = (object) [
            'insightjournalid' => $this->journal->id,
            'userid' => $this->student->id,
            'response' => 'this save lost the race',
            'responseformat' => FORMAT_HTML,
            'revision' => 1,
            'visibility' => INSIGHTJOURNAL_VISIBILITY_VISIBLE,
            'timecreated' => time(),
            'timemodified' => time(),
        ];

        $method = new \ReflectionMethod(entry_manager::class, 'insert_or_detect_race');
        $method->setAccessible(true);
        $context = \context_module::instance((int) $this->journal->cmid);
        $result = $method->invoke(null, $newentry, $this->journal->id, $this->student->id, $context);

        // The confirmed-race branch deliberately logs via debugging() (DEBUG_DEVELOPER)
        // so a real occurrence leaves a trace - expected here, not a test defect.
        $this->assertDebuggingCalled();
        $this->assertIsArray($result);
        $this->assertTrue($result['conflict']);
        $this->assertFalse($result['success']);
        $this->assertEquals($racingentry->id, $result['id']);
        $this->assertEquals(1, $result['revision']);
    }

    /**
     * A write failure that leaves no row behind is a genuine DB error (a
     * deadlock, a connection loss - not a duplicate-key race) and must
     * propagate rather than being reported as an ordinary conflict, since
     * there is nothing to reconcile against. Forced here via a value the
     * revision column's integer type genuinely rejects at the DB level,
     * distinct from the unique-index violation the other test above
     * exercises (which fails to insert but leaves a real row behind).
     */
    public function test_insert_failure_without_a_confirmed_row_propagates(): void {
        $newentry = (object) [
            'insightjournalid' => $this->journal->id,
            'userid' => $this->student->id,
            'response' => 'this insert should fail outright, not report a conflict',
            'responseformat' => FORMAT_HTML,
            'revision' => 'not-a-number',
            'visibility' => INSIGHTJOURNAL_VISIBILITY_VISIBLE,
            'timecreated' => time(),
            'timemodified' => time(),
        ];

        $method = new \ReflectionMethod(entry_manager::class, 'insert_or_detect_race');
        $method->setAccessible(true);
        $context = \context_module::instance((int) $this->journal->cmid);

        $this->expectException(\dml_write_exception::class);
        $method->invoke(null, $newentry, $this->journal->id, $this->student->id, $context);
    }
}
