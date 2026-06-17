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
 * Restore structure step for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 insightjournal contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class restore_insightjournal_activity_structure_step extends restore_activity_structure_step {
    protected function define_structure() {
        $paths = [];
        $paths[] = new restore_path_element('insightjournal', '/activity/insightjournal');
        if ($this->get_setting_value('userinfo')) {
            $paths[] = new restore_path_element('insightjournal_entry', '/activity/insightjournal/entries/entry');
        }
        return $this->prepare_activity_structure($paths);
    }

    protected function process_insightjournal($data) {
        global $DB;
        $data = (object)$data;
        $data->course = $this->get_courseid();
        $oldid = $data->id;
        $data->id = $DB->insert_record('insightjournal', $data);
        $this->apply_activity_instance($data->id);
        $this->set_mapping('insightjournal', $oldid, $data->id, true);
    }

    protected function process_insightjournal_entry($data) {
        global $DB;
        $data = (object)$data;
        unset($data->id);
        $data->insightjournalid = $this->get_new_parentid('insightjournal');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if ($data->userid && $data->insightjournalid) {
            $DB->insert_record('insightjournal_entries', $data);
        }
    }

    protected function after_execute() {
        $this->add_related_files('mod_insightjournal', 'intro', null);
    }
}
