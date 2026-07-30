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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for {@see \mod_insightjournal\external\save_entry}.
 */
#[CoversClass(save_entry::class)]
final class save_entry_test extends advanced_testcase {
    /** @var \stdClass The created course. */
    protected $course;

    /** @var \stdClass The created insight journal instance (with ->cmid). */
    protected $journal;

    /** @var \stdClass The enrolled student. */
    protected $student;

    /** @var int The revision the next save() call will send as expectedrevision, tracked from the last result. */
    protected int $revision = 0;

    /**
     * Creates a completion-enabled journal with a minimum length and an enrolled student.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

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
     * Calls the external function with the last known revision and returns the
     * cleaned result, updating the tracked revision for the next call.
     *
     * @param string $response The learner response to save.
     * @param int|null $expectedrevision Overrides the tracked revision, e.g. to simulate a stale/racing client.
     * @param bool $private Whether to keep the entry private.
     * @return array The cleaned external return value.
     */
    protected function save(string $response, ?int $expectedrevision = null, bool $private = false): array {
        $result = save_entry::execute(
            (int) $this->journal->cmid,
            $response,
            $expectedrevision ?? $this->revision,
            $private
        );
        $result = external_api::clean_returnvalue(save_entry::execute_returns(), $result);
        $this->revision = $result['revision'];
        return $result;
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
     * A first save persists the entry for the current user, as HTML.
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
        $entry = reset($entries);
        $this->assertEquals('short', $entry->response);
        $this->assertEquals(FORMAT_HTML, (int) $entry->responseformat);
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

    /**
     * Allowed formatting tags survive HTML cleaning and are stored as-is.
     */
    public function test_html_formatting_is_preserved(): void {
        global $DB;

        $this->save('<p>Hello <strong>world</strong></p>');

        $entry = $DB->get_record('insightjournal_entries', [
            'insightjournalid' => $this->journal->id,
            'userid' => $this->student->id,
        ]);
        $this->assertStringContainsString('<strong>world</strong>', $entry->response);
    }

    /**
     * Disallowed tags (e.g. script) are stripped by the server-side HTML cleaner.
     */
    public function test_script_tags_are_stripped(): void {
        global $DB;

        $this->save('<p>Hello</p><script>alert(1)</script>');

        $entry = $DB->get_record('insightjournal_entries', [
            'insightjournalid' => $this->journal->id,
            'userid' => $this->student->id,
        ]);
        $this->assertStringNotContainsString('<script', $entry->response);
        $this->assertStringContainsString('Hello', $entry->response);
    }

    /**
     * The return value includes the cleaned response, formatted for display.
     */
    public function test_returns_formatted_response_html(): void {
        $result = $this->save('<p>Hello <strong>world</strong></p>');

        $this->assertStringContainsString('<strong>world</strong>', $result['responsehtml']);
    }

    /**
     * maxchars counts visible characters, not HTML markup.
     */
    public function test_maxchars_counts_visible_text_not_markup(): void {
        $generator = $this->getDataGenerator();
        $journal = $generator->create_module('insightjournal', [
            'course' => $this->course->id,
            'maxchars' => 10,
        ]);

        // Heavy markup, but only 5 visible characters: fits within maxchars.
        $result = save_entry::execute((int) $journal->cmid, '<p><strong><em>hello</em></strong></p>', 0, false);
        $result = external_api::clean_returnvalue(save_entry::execute_returns(), $result);
        $this->assertTrue($result['success']);

        // 12 visible characters, no markup at all: exceeds maxchars of 10.
        $this->expectException(\moodle_exception::class);
        save_entry::execute((int) $journal->cmid, 'twelve chars', $result['revision'], false);
    }

    /**
     * A first save against a brand new entry must use expectedrevision 0.
     */
    public function test_first_save_requires_expectedrevision_zero(): void {
        $result = $this->save('first response', 0);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['conflict']);
        $this->assertEquals(1, $result['revision']);
    }

    /**
     * Each successful save increments the revision by one.
     */
    public function test_successful_saves_increment_revision(): void {
        $first = $this->save('first response');
        $second = $this->save('second response');

        $this->assertEquals(1, $first['revision']);
        $this->assertEquals(2, $second['revision']);
    }

    /**
     * A save sent with a stale expectedrevision (e.g. a delayed/reordered request,
     * or a second tab that has not seen a save made elsewhere) is rejected as a
     * conflict rather than overwriting the newer stored text.
     *
     * Regression test for IJ-01: save_entry previously had no concurrency check at
     * all, so whichever request reached the server last always won.
     */
    public function test_stale_expectedrevision_is_rejected_as_conflict(): void {
        global $DB;

        $this->save('first response');
        $result = $this->save('stale racing response', 0);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['conflict']);

        $stored = $DB->get_record('insightjournal_entries', [
            'insightjournalid' => $this->journal->id,
            'userid' => $this->student->id,
        ]);
        $this->assertEquals('first response', $stored->response);
    }

    /**
     * A rejected conflicting save must not itself advance the stored revision,
     * so a client that retries with the revision it was told about lines up with
     * what is actually stored.
     */
    public function test_conflict_reports_current_revision_and_does_not_advance_it(): void {
        $this->save('first response');
        $conflict = $this->save('stale racing response', 0);

        $this->assertEquals(1, $conflict['revision']);

        $retry = $this->save('retry with correct revision', $conflict['revision']);
        $this->assertTrue($retry['success']);
        $this->assertEquals(2, $retry['revision']);
    }

    /**
     * A client that (incorrectly) believes an entry already exists, when none
     * does yet, must not have that assumption silently create one; it is a
     * conflict like any other stale revision.
     */
    public function test_create_with_nonzero_expectedrevision_is_rejected_as_conflict(): void {
        global $DB;

        $result = $this->save('should not be created', 5);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['conflict']);
        $this->assertEquals(0, $DB->count_records('insightjournal_entries', [
            'insightjournalid' => $this->journal->id,
            'userid' => $this->student->id,
        ]));
    }

    /**
     * A new entry defaults to visible to trainer when private is not requested.
     */
    public function test_new_entry_defaults_to_visible(): void {
        global $DB;

        $this->save('first response');

        $entry = $DB->get_record('insightjournal_entries', [
            'insightjournalid' => $this->journal->id,
            'userid' => $this->student->id,
        ]);
        $this->assertEquals(INSIGHTJOURNAL_VISIBILITY_VISIBLE, (int) $entry->visibility);
    }

    /**
     * A learner can mark their entry private on first save; only they authored
     * this choice, there is no activity-level override.
     */
    public function test_entry_can_be_saved_private(): void {
        global $DB;

        $result = $this->save('secret reflection', null, true);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['private']);
        $entry = $DB->get_record('insightjournal_entries', [
            'insightjournalid' => $this->journal->id,
            'userid' => $this->student->id,
        ]);
        $this->assertEquals(INSIGHTJOURNAL_VISIBILITY_PRIVATE, (int) $entry->visibility);
    }

    /**
     * The author can change visibility on a later save of the same entry, at
     * any time, in either direction.
     */
    public function test_visibility_can_be_changed_on_later_save(): void {
        global $DB;

        $this->save('first response', null, false);
        $this->save('first response, now private', null, true);

        $entry = $DB->get_record('insightjournal_entries', [
            'insightjournalid' => $this->journal->id,
            'userid' => $this->student->id,
        ]);
        $this->assertEquals(INSIGHTJOURNAL_VISIBILITY_PRIVATE, (int) $entry->visibility);

        $result = $this->save('first response, visible again', null, false);
        $this->assertFalse($result['private']);
        $entry = $DB->get_record('insightjournal_entries', ['id' => $entry->id]);
        $this->assertEquals(INSIGHTJOURNAL_VISIBILITY_VISIBLE, (int) $entry->visibility);
    }

    /**
     * A rejected conflicting save reports the entry's actual stored privacy
     * state, not the client's attempted one, so the client can reconcile its
     * checkbox after a stale write is rejected.
     */
    public function test_conflict_reports_actual_stored_privacy(): void {
        $this->save('first response', null, true);
        $conflict = $this->save('stale racing response', 0, false);

        $this->assertTrue($conflict['conflict']);
        $this->assertTrue($conflict['private']);
    }

    /**
     * Regression test for R2-02: PHPUnit cannot fork a true concurrent request,
     * so this proves serialisation indirectly. It holds the exact lock resource
     * save_entry::execute() must acquire for this insightjournalid+userid, then
     * asserts execute() cannot proceed while it is held: it must fail rather
     * than silently reading/writing unsynchronised, and it must not have
     * written anything.
     *
     * Runs for close to LOCK_TIMEOUT_SECONDS (a few real wall-clock seconds):
     * file_lock_factory polls until it gives up, so this is expected, not a
     * hang.
     */
    public function test_concurrent_save_for_same_entry_is_serialised_by_the_lock(): void {
        global $CFG, $DB;

        // Force the file-based lock factory: Moodle's default here (MariaDB) is
        // mysql_lock_factory, which uses GET_LOCK() scoped to the DB connection.
        // This whole PHPUnit process shares a single connection, so a lock
        // "held" by this test and a lock "attempted" by execute() below would
        // be the same session and never actually contend. file_lock_factory
        // uses a real flock() per opened file handle, which does contend even
        // within one process, so it is the only way to observe genuine
        // serialisation here. resetAfterTest() reverts this $CFG change.
        $CFG->lock_factory = '\core\lock\file_lock_factory';

        $this->save('first response');

        $lockfactory = \core\lock\lock_config::get_lock_factory('mod_insightjournal_save_entry');
        $resource = 'insightjournalid:' . $this->journal->id . ':userid:' . $this->student->id;
        $lock = $lockfactory->get_lock($resource, 1);
        $this->assertNotFalse($lock, 'Precondition: the test itself must be able to acquire the lock.');

        $threw = false;
        try {
            $this->save('second response, racing');
        } catch (\moodle_exception $e) {
            $threw = true;
        } finally {
            $lock->release();
        }

        $this->assertTrue(
            $threw,
            'save_entry::execute() must fail to acquire an already-held lock rather than proceed unsynchronised.'
        );

        $stored = $DB->get_record('insightjournal_entries', [
            'insightjournalid' => $this->journal->id,
            'userid' => $this->student->id,
        ]);
        $this->assertEquals('first response', $stored->response);
        $this->assertEquals(1, (int) $stored->revision);
    }

    /**
     * Different insightjournalid+userid pairs must not block each other: the
     * lock resource key is scoped per entry, not global or per-activity-only.
     */
    public function test_locks_for_different_entries_do_not_block_each_other(): void {
        $otherstudent = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $lockfactory = \core\lock\lock_config::get_lock_factory('mod_insightjournal_save_entry');
        $resource = 'insightjournalid:' . $this->journal->id . ':userid:' . $this->student->id;
        $lock = $lockfactory->get_lock($resource, 1);
        $this->assertNotFalse($lock, 'Precondition: the test itself must be able to acquire the lock.');

        try {
            $this->setUser($otherstudent);
            $result = save_entry::execute((int) $this->journal->cmid, 'other learner response', 0, false);
            $result = external_api::clean_returnvalue(save_entry::execute_returns(), $result);
            $this->assertTrue($result['success']);
        } finally {
            $lock->release();
            $this->setUser($this->student);
        }
    }

    /**
     * Schema-level regression guard: the unique index on (insightjournalid,
     * userid) remains a working backstop even outside the lock, in case it is
     * ever accidentally dropped from db/install.xml.
     */
    public function test_unique_index_still_rejects_duplicate_insightjournalid_userid_rows(): void {
        global $DB;

        $this->save('first response');

        $this->expectException(\dml_write_exception::class);
        $DB->insert_record('insightjournal_entries', (object) [
            'insightjournalid' => $this->journal->id,
            'userid' => $this->student->id,
            'response' => 'duplicate row attempt',
            'responseformat' => FORMAT_HTML,
            'revision' => 99,
            'visibility' => INSIGHTJOURNAL_VISIBILITY_VISIBLE,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * A student whose mod/insightjournal:submit capability has been
     * explicitly revoked cannot save, and no entry row is created as a
     * side effect of anything that runs before the capability check.
     */
    public function test_capability_denied_save_throws_and_writes_nothing(): void {
        global $DB;

        $context = \context_module::instance((int) $this->journal->cmid);
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability('mod/insightjournal:submit', CAP_PREVENT, $studentroleid, $context, true);

        try {
            save_entry::execute((int) $this->journal->cmid, 'should not be saved', 0, false);
            $this->fail('Expected a required_capability_exception.');
        } catch (\required_capability_exception $e) {
            // Expected.
            unset($e);
        }

        $this->assertEquals(0, $DB->count_records('insightjournal_entries', [
            'insightjournalid' => $this->journal->id,
            'userid' => $this->student->id,
        ]));
    }

    /**
     * Resubmitting the exact same stale expectedrevision a second time,
     * after it was already rejected once, is rejected again - proving
     * there is no implicit "second attempt succeeds" path server-side,
     * independently of whatever the JS client happens to do (already
     * covered by the Behat scenario "A stale save is rejected as a
     * conflict and locks further saves until reload").
     */
    public function test_repeated_stale_expectedrevision_is_rejected_again(): void {
        global $DB;

        $this->save('first response');
        $firstconflict = $this->save('stale racing response, attempt 1', 0);
        $secondconflict = $this->save('stale racing response, attempt 2', 0);

        $this->assertTrue($firstconflict['conflict']);
        $this->assertTrue($secondconflict['conflict']);
        $this->assertEquals($firstconflict['revision'], $secondconflict['revision']);

        $stored = $DB->get_record('insightjournal_entries', [
            'insightjournalid' => $this->journal->id,
            'userid' => $this->student->id,
        ]);
        $this->assertEquals('first response', $stored->response);
    }
}
