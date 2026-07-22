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
 * Upgrade steps for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Resolve the retired site-wide "entriesvisibletoteacher" checkbox into the
 * per-activity visibility value used to migrate legacy SITEDEFAULT (0) rows.
 *
 * Fails closed: only an explicitly enabled ('1') old setting resolves to
 * visible. A missing (get_config() returns false), disabled, or otherwise
 * unrecognised old value resolves to private, so an ambiguous history never
 * exposes previously-private reflections to trainers. Literal ints are used
 * because lib.php is not necessarily loaded during upgrade.
 *
 * @param mixed $oldsitedefault The old entriesvisibletoteacher config value (false if never set).
 * @return int 1 (INSIGHTJOURNAL_VISIBILITY_VISIBLE) or 2 (INSIGHTJOURNAL_VISIBILITY_PRIVATE).
 */
function insightjournal_upgrade_resolve_legacy_visibility($oldsitedefault): int {
    return ((string) $oldsitedefault === '1') ? 1 : 2;
}

/**
 * Migrate legacy SITEDEFAULT (0) entriesvisibility rows using whatever the
 * retired site-wide setting actually was, before it is discarded.
 *
 * @param \moodle_database $db
 * @return void
 */
function insightjournal_upgrade_migrate_legacy_visibility(\moodle_database $db): void {
    $oldsitedefault = get_config('insightjournal', 'entriesvisibletoteacher');
    $migratedvisibility = insightjournal_upgrade_resolve_legacy_visibility($oldsitedefault);
    $db->set_field('insightjournal', 'entriesvisibility', $migratedvisibility, ['entriesvisibility' => 0]);
}

/**
 * Migrate legacy sentinel (0) insightjournal_entries.visibility rows using
 * the trainer-visibility value their now-retired parent activity setting
 * (insightjournal.entriesvisibility) had, before that column is dropped.
 *
 * Unlike {@see insightjournal_upgrade_migrate_legacy_visibility()}, this does
 * NOT fail closed to private for an ambiguous/missing parent value: the
 * per-activity trainer-visibility control is being removed entirely in this
 * same upgrade, and the approved replacement policy is that only entries
 * belonging to an activity that was explicitly PRIVATE (2) keep that
 * guarantee; every other entry (activity was VISIBLE, or carried an
 * unrecognised/legacy value) adopts the new default of visible to trainer,
 * matching what a freshly written entry gets from this point on. Literal
 * ints are used because lib.php is not necessarily loaded during upgrade.
 *
 * @param \moodle_database $db
 * @return void
 */
function insightjournal_upgrade_migrate_entry_visibility(\moodle_database $db): void {
    $privatediaryids = $db->get_fieldset_select('insightjournal', 'id', 'entriesvisibility = ?', [2]);
    if (!empty($privatediaryids)) {
        [$insql, $params] = $db->get_in_or_equal($privatediaryids, SQL_PARAMS_QM);
        $params[] = 0;
        $db->execute(
            "UPDATE {insightjournal_entries}
                SET visibility = 2
              WHERE insightjournalid $insql AND visibility = ?",
            $params
        );
    }
    $db->set_field_select('insightjournal_entries', 'visibility', 1, 'visibility = ?', [0]);
}

/**
 * Run insight journal database upgrades.
 *
 * @param int $oldversion Previously installed plugin version.
 * @return bool
 */
function xmldb_insightjournal_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026061701) {
        $table = new xmldb_table('insightjournal');
        $field = new xmldb_field('completionentries', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'minchars');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026061701, 'insightjournal');
    }

    if ($oldversion < 2026061703) {
        $table = new xmldb_table('insightjournal');
        $field = new xmldb_field('maxchars', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'minchars');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026061703, 'insightjournal');
    }

    if ($oldversion < 2026070800) {
        $table = new xmldb_table('insightjournal');
        $field = new xmldb_field('promptcolor', XMLDB_TYPE_CHAR, '7', null, null, null, null, 'promptformat');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026070800, 'insightjournal');
    }

    if ($oldversion < 2026070900) {
        // This step used to seed a global "entriesvisibletoteacher" toggle.
        // That setting has since been removed in favour of the per-activity
        // entriesvisibility field, so there is nothing left to do here; the
        // 2026070903 step below removes any value it wrote.
        upgrade_mod_savepoint(true, 2026070900, 'insightjournal');
    }

    if ($oldversion < 2026070901) {
        $table = new xmldb_table('insightjournal');
        $field = new xmldb_field('entriesvisibility', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'completionentries');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026070901, 'insightjournal');
    }

    if ($oldversion < 2026070903) {
        // The site-wide "entriesvisibletoteacher" setting is gone; trainer
        // visibility is now decided per activity. Existing rows still carry
        // the retired "follow the site default" value of 0, which no longer
        // maps to a form option. Resolve them using whatever the site-wide
        // setting actually was before it is discarded below (see
        // insightjournal_upgrade_migrate_legacy_visibility(): fails closed to
        // private on a missing or unrecognised old value, so previously
        // private reflections are never exposed to trainers by the upgrade).
        // New activities still default to visible via mod_form.php, so the
        // column default itself stays 1 (INSIGHTJOURNAL_VISIBILITY_VISIBLE).
        $table = new xmldb_table('insightjournal');
        $field = new xmldb_field('entriesvisibility', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'completionentries');

        insightjournal_upgrade_migrate_legacy_visibility($DB);
        $dbman->change_field_default($table, $field);

        unset_config('entriesvisibletoteacher', 'insightjournal');

        upgrade_mod_savepoint(true, 2026070903, 'insightjournal');
    }

    if ($oldversion < 2026072000) {
        // Optimistic-concurrency counter for save_entry: lets the server reject a
        // save whose client-side revision is stale instead of silently
        // overwriting newer text from a racing autosave/tab. Existing rows have
        // never been through a checked save, so the NOT NULL default of 1 backfills
        // them to the same starting point a fresh entry gets on its first insert.
        $table = new xmldb_table('insightjournal_entries');
        $field = new xmldb_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1', 'responseformat');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026072000, 'insightjournal');
    }

    if ($oldversion < 2026072200) {
        // Trainer visibility moves from a per-activity setting to a
        // per-entry choice made by the entry's author, who is the only one
        // able to change it. Sentinel default of 0 marks not-yet-migrated
        // rows, same two-step pattern as the entriesvisibility field itself
        // (see 2026070901/2026070903 above).
        $table = new xmldb_table('insightjournal_entries');
        $field = new xmldb_field('visibility', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'revision');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026072200, 'insightjournal');
    }

    if ($oldversion < 2026072201) {
        // Resolve sentinel-0 rows using their parent activity's about-to-be-
        // retired entriesvisibility value (see
        // insightjournal_upgrade_migrate_entry_visibility(): only an
        // explicitly PRIVATE activity keeps its entries private; everything
        // else defaults to visible). New rows going forward get visibility
        // explicitly from classes/external/save_entry.php, but the column
        // default is still flipped to 1 (VISIBLE) for consistency with how
        // entriesvisibility itself was handled.
        $table = new xmldb_table('insightjournal_entries');
        $field = new xmldb_field('visibility', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'revision');

        insightjournal_upgrade_migrate_entry_visibility($DB);
        $dbman->change_field_default($table, $field);

        upgrade_mod_savepoint(true, 2026072201, 'insightjournal');
    }

    if ($oldversion < 2026072202) {
        // The per-activity trainer-visibility setting is retired: it is now
        // decided per entry by the entry's author only (see
        // insightjournal_entries.visibility, migrated above), with no
        // trainer or site-wide override.
        $table = new xmldb_table('insightjournal');
        $field = new xmldb_field('entriesvisibility');

        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026072202, 'insightjournal');
    }

    return true;
}
