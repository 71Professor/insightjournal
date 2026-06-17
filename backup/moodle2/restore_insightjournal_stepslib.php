<?php
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
        $data->insightjournalid = $this->get_new_parentid('insightjournal');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if ($data->userid) {
            $DB->insert_record('insightjournal_entries', $data);
        }
    }

    protected function after_execute() {
        $this->add_related_files('mod_insightjournal', 'intro', null);
    }
}
