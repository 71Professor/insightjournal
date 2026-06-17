<?php
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/mod/insightjournal/backup/moodle2/backup_insightjournal_stepslib.php');

class backup_insightjournal_activity_task extends backup_activity_task {
    protected function define_my_settings() {}
    protected function define_my_steps() {
        $this->add_step(new backup_insightjournal_activity_structure_step('insightjournal_structure', 'insightjournal.xml'));
    }
    public static function encode_content_links($content) {
        return $content;
    }
}
