<?php
// Upgrade steps for mod_insightjournal.

defined('MOODLE_INTERNAL') || die();

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

    return true;
}
