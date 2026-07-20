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
 * Tests for {@see \insightjournal_upgrade_resolve_legacy_visibility()} and
 * {@see \insightjournal_upgrade_migrate_legacy_visibility()}.
 */
#[CoversFunction('insightjournal_upgrade_resolve_legacy_visibility')]
#[CoversFunction('insightjournal_upgrade_migrate_legacy_visibility')]
final class upgradelib_test extends advanced_testcase {
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

        $course = $this->getDataGenerator()->create_course();
        $journal = $this->getDataGenerator()->create_module('insightjournal', ['course' => $course->id]);
        $DB->set_field('insightjournal', 'entriesvisibility', 1, ['id' => $journal->id]);
        set_config('entriesvisibletoteacher', '0', 'insightjournal');

        \insightjournal_upgrade_migrate_legacy_visibility($DB);

        $this->assertEquals(1, $DB->get_field('insightjournal', 'entriesvisibility', ['id' => $journal->id]));
    }
}
