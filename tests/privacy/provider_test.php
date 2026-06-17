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
 * Privacy provider tests for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_insightjournal\privacy;

use context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_insightjournal\privacy\provider;

/**
 * Tests for {@see \mod_insightjournal\privacy\provider}.
 *
 * @covers \mod_insightjournal\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass The course. */
    protected $course;

    /** @var \stdClass The insight journal instance. */
    protected $journal;

    /** @var \context_module The module context. */
    protected $context;

    /** @var \stdClass First student (has an entry). */
    protected $user1;

    /** @var \stdClass Second student (has an entry). */
    protected $user2;

    /**
     * Sets up a journal with two students who each have an entry.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->journal = $generator->create_module('insightjournal', ['course' => $this->course->id]);
        $this->context = context_module::instance($this->journal->cmid);

        $this->user1 = $generator->create_and_enrol($this->course, 'student');
        $this->user2 = $generator->create_and_enrol($this->course, 'student');

        /** @var \mod_insightjournal_generator $plugingenerator */
        $plugingenerator = $generator->get_plugin_generator('mod_insightjournal');
        $plugingenerator->create_entry($this->journal, (int) $this->user1->id, 'Entry by user one.');
        $plugingenerator->create_entry($this->journal, (int) $this->user2->id, 'Entry by user two.');
    }

    /**
     * The metadata describes the entries table.
     */
    public function test_get_metadata(): void {
        $collection = new collection('mod_insightjournal');
        $collection = provider::get_metadata($collection);
        $this->assertNotEmpty($collection->get_collection());
    }

    /**
     * A user with an entry is reported against the module context.
     */
    public function test_get_contexts_for_userid(): void {
        $contextlist = provider::get_contexts_for_userid((int) $this->user1->id);
        $this->assertCount(1, $contextlist);
        $this->assertEquals($this->context->id, $contextlist->get_contextids()[0]);
    }

    /**
     * Exporting writes the user's own entry into the export.
     */
    public function test_export_user_data(): void {
        $this->export_context_data_for_user((int) $this->user1->id, $this->context, 'mod_insightjournal');
        $writer = writer::with_context($this->context);
        $this->assertTrue($writer->has_any_data());
    }

    /**
     * Deleting for a context removes every user's entry there.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        provider::delete_data_for_all_users_in_context($this->context);
        $this->assertEquals(0, $DB->count_records('insightjournal_entries', ['insightjournalid' => $this->journal->id]));
    }

    /**
     * Deleting for a single user leaves other users' entries intact.
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $approved = new approved_contextlist($this->user1, 'mod_insightjournal', [$this->context->id]);
        provider::delete_data_for_user($approved);

        $this->assertFalse($DB->record_exists('insightjournal_entries', [
            'insightjournalid' => $this->journal->id,
            'userid' => $this->user1->id,
        ]));
        $this->assertTrue($DB->record_exists('insightjournal_entries', [
            'insightjournalid' => $this->journal->id,
            'userid' => $this->user2->id,
        ]));
    }

    /**
     * All users with entries are reported for the context.
     */
    public function test_get_users_in_context(): void {
        $userlist = new userlist($this->context, 'mod_insightjournal');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing(
            [(int) $this->user1->id, (int) $this->user2->id],
            $userlist->get_userids()
        );
    }

    /**
     * Deleting an approved user list removes only the approved users' entries.
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        $approved = new approved_userlist($this->context, 'mod_insightjournal', [(int) $this->user1->id]);
        provider::delete_data_for_users($approved);

        $this->assertFalse($DB->record_exists('insightjournal_entries', [
            'insightjournalid' => $this->journal->id,
            'userid' => $this->user1->id,
        ]));
        $this->assertTrue($DB->record_exists('insightjournal_entries', [
            'insightjournalid' => $this->journal->id,
            'userid' => $this->user2->id,
        ]));
    }
}
