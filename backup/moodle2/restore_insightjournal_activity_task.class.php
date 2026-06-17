<?php
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/mod/insightjournal/backup/moodle2/restore_insightjournal_stepslib.php');

class restore_insightjournal_activity_task extends restore_activity_task {
    protected function define_my_settings() {}
    protected function define_my_steps() {
        $this->add_step(new restore_insightjournal_activity_structure_step('insightjournal_structure', 'insightjournal.xml'));
    }
    public static function define_decode_contents() { return []; }
    public static function define_decode_rules() { return []; }
}
