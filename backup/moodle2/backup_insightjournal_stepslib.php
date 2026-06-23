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
 * Backup structure step for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines the complete backup structure for the insightjournal activity.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_insightjournal_activity_structure_step extends backup_activity_structure_step {
    /**
     * Defines the XML structure of the activity to be backed up.
     *
     * @return backup_nested_element The prepared activity structure.
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $diary = new backup_nested_element('insightjournal', ['id'], [
            'course', 'name', 'intro', 'introformat', 'prompttext', 'promptformat',
            'autosave', 'minchars', 'maxchars', 'completionentries', 'timecreated', 'timemodified',
        ]);
        $entries = new backup_nested_element('entries');
        $entry = new backup_nested_element('entry', ['id'], [
            'userid', 'response', 'responseformat', 'timecreated', 'timemodified',
        ]);

        $diary->add_child($entries);
        $entries->add_child($entry);

        $diary->set_source_table('insightjournal', ['id' => backup::VAR_ACTIVITYID]);
        if ($userinfo) {
            $entry->set_source_table('insightjournal_entries', ['insightjournalid' => backup::VAR_PARENTID]);
        }

        $entry->annotate_ids('user', 'userid');
        $diary->annotate_files('mod_insightjournal', 'intro', null);
        return $this->prepare_activity_structure($diary);
    }
}
