<?php
defined('MOODLE_INTERNAL') || die();

class backup_insightjournal_activity_structure_step extends backup_activity_structure_step {
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $diary = new backup_nested_element('insightjournal', ['id'], [
            'course', 'name', 'intro', 'introformat', 'prompttext', 'promptformat',
            'autosave', 'minchars', 'completionentries', 'timecreated', 'timemodified'
        ]);
        $entries = new backup_nested_element('entries');
        $entry = new backup_nested_element('entry', ['id'], [
            'userid', 'response', 'responseformat', 'timecreated', 'timemodified'
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
