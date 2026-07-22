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
 * Unit tests for the legacy entriesvisibility migration in db/upgrade.php.
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
require_once($CFG->dirroot . '/mod/insightjournal/db/upgrade.php');

/**
 * Tests for {@see \insightjournal_upgrade_resolve_legacy_visibility()},
 * {@see \insightjournal_upgrade_migrate_legacy_visibility()}, and
 * {@see \insightjournal_upgrade_migrate_entry_visibility()}.
 */
#[CoversFunction('insightjournal_upgrade_resolve_legacy_visibility')]
#[CoversFunction('insightjournal_upgrade_migrate_legacy_visibility')]
#[CoversFunction('insightjournal_upgrade_migrate_entry_visibility')]
final class upgradelib_test extends advanced_testcase {
    /**
     * Recreates the now-retired insightjournal.entriesvisibility column for
     * the duration of a single test.
     *
     * The column no longer exists in the current install.xml (it is dropped
     * by upgrade step 2026072202), but both migration functions under test
     * here run earlier in the upgrade chain, while a real upgrading site
     * still has it. Simulating that intermediate schema state directly is
     * the only way to exercise these functions against a real table once the
     * column is gone from a fresh install.
     *
     * @return void
     */
    private function add_legacy_entriesvisibility_field(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('insightjournal');
        $field = new \xmldb_field('entriesvisibility', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
    }

    /**
     * Drops the simulated legacy column again so later tests see the real,
     * current schema (resetAfterTest() does not undo raw DDL changes).
     *
     * @return void
     */
    protected function tearDown(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('insightjournal');
        $field = new \xmldb_field('entriesvisibility');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }
        parent::tearDown();
    }

    /**
     * An explicitly enabled old site setting ('1') resolves to VISIBLE.
     */
    public function test_old_site_default_visible_resolves_to_visible(): void {
        $this->assertSame(1, \insightjournal_upgrade_resolve_legacy_visibility('1'));
        $this->assertSame(1, \insightjournal_upgrade_resolve_legacy_visibility(1));
    }

    /**
     * An explicitly disabled old site setting ('0') resolves to PRIVATE.
     */
    public function test_old_site_default_private_resolves_to_private(): void {
        $this->assertSame(2, \insightjournal_upgrade_resolve_legacy_visibility('0'));
        $this->assertSame(2, \insightjournal_upgrade_resolve_legacy_visibility(0));
    }

    /**
     * A never-configured old setting (get_config() returns false) fails closed to PRIVATE.
     */
    public function test_missing_old_site_default_fails_closed_to_private(): void {
        $this->assertSame(2, \insightjournal_upgrade_resolve_legacy_visibility(false));
    }

    /**
     * Any unrecognised old value fails closed to PRIVATE, not VISIBLE.
     */
    public function test_invalid_old_site_default_fails_closed_to_private(): void {
        $this->assertSame(2, \insightjournal_upgrade_resolve_legacy_visibility('garbage'));
        $this->assertSame(2, \insightjournal_upgrade_resolve_legacy_visibility(null));
    }

    /**
     * Migrating legacy SITEDEFAULT (0) rows when the old site setting was private
     * must leave the activity PRIVATE, never exposed to trainers.
     */
    public function test_migrate_from_old_private_site_default(): void {
        global $DB;
        $this->resetAfterTest();
        $this->add_legacy_entriesvisibility_field();

        $course = $this->getDataGenerator()->create_course();
        $journal = $this->getDataGenerator()->create_module('insightjournal', ['course' => $course->id]);
        $DB->set_field('insightjournal', 'entriesvisibility', 0, ['id' => $journal->id]);
        set_config('entriesvisibletoteacher', '0', 'insightjournal');

        \insightjournal_upgrade_migrate_legacy_visibility($DB);

        $this->assertEquals(2, $DB->get_field('insightjournal', 'entriesvisibility', ['id' => $journal->id]));
    }

    /**
     * Migrating legacy SITEDEFAULT (0) rows when the old site setting was visible
     * resolves the activity to VISIBLE.
     */
    public function test_migrate_from_old_visible_site_default(): void {
        global $DB;
        $this->resetAfterTest();
        $this->add_legacy_entriesvisibility_field();

        $course = $this->getDataGenerator()->create_course();
        $journal = $this->getDataGenerator()->create_module('insightjournal', ['course' => $course->id]);
        $DB->set_field('insightjournal', 'entriesvisibility', 0, ['id' => $journal->id]);
        set_config('entriesvisibletoteacher', '1', 'insightjournal');

        \insightjournal_upgrade_migrate_legacy_visibility($DB);

        $this->assertEquals(1, $DB->get_field('insightjournal', 'entriesvisibility', ['id' => $journal->id]));
    }

    /**
     * With no old site setting on record at all, migrated rows must fail closed
     * to PRIVATE rather than default to VISIBLE.
     */
    public function test_migrate_with_no_old_site_default_fails_closed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->add_legacy_entriesvisibility_field();

        $course = $this->getDataGenerator()->create_course();
        $journal = $this->getDataGenerator()->create_module('insightjournal', ['course' => $course->id]);
        $DB->set_field('insightjournal', 'entriesvisibility', 0, ['id' => $journal->id]);
        unset_config('entriesvisibletoteacher', 'insightjournal');

        \insightjournal_upgrade_migrate_legacy_visibility($DB);

        $this->assertEquals(2, $DB->get_field('insightjournal', 'entriesvisibility', ['id' => $journal->id]));
    }

    /**
     * Rows that already carry an explicit, non-legacy value are left untouched
     * by the migration, even if the old site default was private.
     */
    public function test_migrate_does_not_touch_already_explicit_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $this->add_legacy_entriesvisibility_field();

        $course = $this->getDataGenerator()->create_course();
        $journal = $this->getDataGenerator()->create_module('insightjournal', ['course' => $course->id]);
        $DB->set_field('insightjournal', 'entriesvisibility', 1, ['id' => $journal->id]);
        set_config('entriesvisibletoteacher', '0', 'insightjournal');

        \insightjournal_upgrade_migrate_legacy_visibility($DB);

        $this->assertEquals(1, $DB->get_field('insightjournal', 'entriesvisibility', ['id' => $journal->id]));
    }

    /**
     * Creates an insight journal entry row directly, bypassing save_entry, so
     * the sentinel/explicit visibility value under test can be controlled.
     *
     * @param int $insightjournalid Parent activity id.
     * @param int $userid Author id.
     * @param int $visibility Value to store, including the pre-migration sentinel (0).
     * @return int The new entry id.
     */
    private function insert_entry(int $insightjournalid, int $userid, int $visibility): int {
        global $DB;
        $now = time();
        return $DB->insert_record('insightjournal_entries', (object) [
            'insightjournalid' => $insightjournalid,
            'userid' => $userid,
            'response' => 'response text',
            'responseformat' => FORMAT_HTML,
            'revision' => 1,
            'visibility' => $visibility,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * An entry belonging to an activity explicitly marked PRIVATE (2) keeps
     * that guarantee after migration.
     */
    public function test_entry_in_private_activity_migrates_to_private(): void {
        global $DB;
        $this->resetAfterTest();
        $this->add_legacy_entriesvisibility_field();

        $course = $this->getDataGenerator()->create_course();
        $journal = $this->getDataGenerator()->create_module('insightjournal', ['course' => $course->id]);
        $DB->set_field('insightjournal', 'entriesvisibility', 2, ['id' => $journal->id]);
        $user = $this->getDataGenerator()->create_user();
        $entryid = $this->insert_entry((int) $journal->id, (int) $user->id, 0);

        \insightjournal_upgrade_migrate_entry_visibility($DB);

        $this->assertEquals(2, $DB->get_field('insightjournal_entries', 'visibility', ['id' => $entryid]));
    }

    /**
     * An entry belonging to an activity explicitly marked VISIBLE (1)
     * migrates to visible.
     */
    public function test_entry_in_visible_activity_migrates_to_visible(): void {
        global $DB;
        $this->resetAfterTest();
        $this->add_legacy_entriesvisibility_field();

        $course = $this->getDataGenerator()->create_course();
        $journal = $this->getDataGenerator()->create_module('insightjournal', ['course' => $course->id]);
        $DB->set_field('insightjournal', 'entriesvisibility', 1, ['id' => $journal->id]);
        $user = $this->getDataGenerator()->create_user();
        $entryid = $this->insert_entry((int) $journal->id, (int) $user->id, 0);

        \insightjournal_upgrade_migrate_entry_visibility($DB);

        $this->assertEquals(1, $DB->get_field('insightjournal_entries', 'visibility', ['id' => $entryid]));
    }

    /**
     * Unlike the retired site-default migration, an ambiguous/legacy parent
     * value (the pre-2026070903 sentinel of 0) does NOT fail closed to
     * private here: the per-activity control is being removed entirely, and
     * the approved policy is that only an explicitly PRIVATE activity keeps
     * its entries private. Everything else adopts the new visible-by-default
     * behaviour.
     */
    public function test_entry_migrates_to_visible_when_activity_value_is_legacy(): void {
        global $DB;
        $this->resetAfterTest();
        $this->add_legacy_entriesvisibility_field();

        $course = $this->getDataGenerator()->create_course();
        $journal = $this->getDataGenerator()->create_module('insightjournal', ['course' => $course->id]);
        $DB->set_field('insightjournal', 'entriesvisibility', 0, ['id' => $journal->id]);
        $user = $this->getDataGenerator()->create_user();
        $entryid = $this->insert_entry((int) $journal->id, (int) $user->id, 0);

        \insightjournal_upgrade_migrate_entry_visibility($DB);

        $this->assertEquals(1, $DB->get_field('insightjournal_entries', 'visibility', ['id' => $entryid]));
    }

    /**
     * Entries that already carry an explicit, non-sentinel value are left
     * untouched by the migration, even if the parent activity was private.
     */
    public function test_entry_migration_does_not_touch_already_explicit_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $this->add_legacy_entriesvisibility_field();

        $course = $this->getDataGenerator()->create_course();
        $journal = $this->getDataGenerator()->create_module('insightjournal', ['course' => $course->id]);
        $DB->set_field('insightjournal', 'entriesvisibility', 2, ['id' => $journal->id]);
        $user = $this->getDataGenerator()->create_user();
        $entryid = $this->insert_entry((int) $journal->id, (int) $user->id, 1);

        \insightjournal_upgrade_migrate_entry_visibility($DB);

        $this->assertEquals(1, $DB->get_field('insightjournal_entries', 'visibility', ['id' => $entryid]));
    }
}
