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
 * Mod form for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Form definition for creating and editing an insightjournal activity instance.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_insightjournal_mod_form extends moodleform_mod {
    /**
     * Defines the elements of the activity settings form.
     *
     * @return void
     */
    public function definition(): void {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $this->standard_intro_elements(get_string('intro', 'insightjournal'));

        $mform->addElement(
            'editor',
            'prompttext_editor',
            get_string('prompttext', 'insightjournal'),
            null,
            ['maxfiles' => 0, 'trusttext' => false, 'subdirs' => false]
        );
        $mform->setType('prompttext_editor', PARAM_RAW);
        $mform->addRule('prompttext_editor', null, 'required', null, 'client');
        $mform->addHelpButton('prompttext_editor', 'prompttext', 'insightjournal');

        $mform->addElement('advcheckbox', 'autosave', get_string('autosave', 'insightjournal'));
        $mform->setDefault('autosave', 1);
        $mform->addHelpButton('autosave', 'autosave', 'insightjournal');

        $mform->addElement('text', 'minchars', get_string('minchars', 'insightjournal'), ['size' => 6]);
        $mform->setType('minchars', PARAM_INT);
        $mform->setDefault('minchars', 0);
        $mform->addHelpButton('minchars', 'minchars', 'insightjournal');

        $mform->addElement('text', 'maxchars', get_string('maxchars', 'insightjournal'), ['size' => 6]);
        $mform->setType('maxchars', PARAM_INT);
        $mform->setDefault('maxchars', 0);
        $mform->addHelpButton('maxchars', 'maxchars', 'insightjournal');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Prepares the editor field default values before the form is displayed.
     *
     * @param array $defaultvalues The default values passed to the form, modified by reference.
     * @return void
     */
    public function data_preprocessing(&$defaultvalues) {
        if (!empty($defaultvalues['prompttext'])) {
            $defaultvalues['prompttext_editor'] = [
                'text' => $defaultvalues['prompttext'],
                'format' => $defaultvalues['promptformat'] ?? FORMAT_HTML,
            ];
        }
    }

    /**
     * Adds the custom completion rule elements to the form.
     *
     * @return array Array of element names that were added to the form.
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $suffix = $this->get_suffix();
        $name = 'completionentries' . $suffix;
        $mform->addElement(
            'checkbox',
            $name,
            get_string('completionentriesgroup', 'insightjournal'),
            get_string('completionentries', 'insightjournal')
        );
        $mform->setDefault($name, 1);

        return [$name];
    }

    /**
     * Determines whether the custom completion rule is enabled.
     *
     * @param array $data The form data submitted by the user.
     * @return bool True if the entries completion rule is enabled.
     */
    public function completion_rule_enabled($data) {
        $suffix = $this->get_suffix();
        return !empty($data['completionentries' . $suffix]);
    }
}
