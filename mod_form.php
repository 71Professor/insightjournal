<?php

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

class mod_insightjournal_mod_form extends moodleform_mod {
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $this->standard_intro_elements(get_string('intro', 'insightjournal'));

        $mform->addElement('editor', 'prompttext_editor', get_string('prompttext', 'insightjournal'), null,
            ['maxfiles' => 0, 'trusttext' => false, 'subdirs' => false]);
        $mform->setType('prompttext_editor', PARAM_RAW);
        $mform->addRule('prompttext_editor', null, 'required', null, 'client');

        $mform->addElement('advcheckbox', 'autosave', get_string('autosave', 'insightjournal'));
        $mform->setDefault('autosave', 1);

        $mform->addElement('text', 'minchars', get_string('minchars', 'insightjournal'), ['size' => 6]);
        $mform->setType('minchars', PARAM_INT);
        $mform->setDefault('minchars', 0);

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    public function data_preprocessing(&$defaultvalues) {
        if (!empty($defaultvalues['prompttext'])) {
            $defaultvalues['prompttext_editor'] = [
                'text' => $defaultvalues['prompttext'],
                'format' => $defaultvalues['promptformat'] ?? FORMAT_HTML,
            ];
        }
    }

    public function add_completion_rules() {
        $mform = $this->_form;
        $mform->addElement('checkbox', 'completionentries', get_string('completionentriesgroup', 'insightjournal'),
            get_string('completionentries', 'insightjournal'));
        $mform->setDefault('completionentries', 1);

        return ['completionentries'];
    }

    public function completion_rule_enabled($data) {
        return !empty($data['completionentries']);
    }
}
