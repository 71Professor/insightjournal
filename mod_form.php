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
require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');

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

        // Moodle core's own docblock for standard_intro_elements() (course/moodleform_mod.php)
        // says "@param null $customlabel", even though the method body fully supports - and
        // core itself elsewhere passes - a string label; this is the documented, intended use,
        // a core docblock bug rather than a real type error.
        // @phpstan-ignore-next-line argument.type (documented false positive).
        $this->standard_intro_elements(get_string('intro', 'mod_insightjournal'));

        $mform->addElement(
            'editor',
            'prompttext_editor',
            get_string('prompttext', 'mod_insightjournal'),
            null,
            ['maxfiles' => 0, 'trusttext' => false, 'subdirs' => false]
        );
        $mform->setType('prompttext_editor', PARAM_RAW);
        $mform->addRule('prompttext_editor', null, 'required', null, 'client');
        $mform->addHelpButton('prompttext_editor', 'prompttext', 'mod_insightjournal');

        $mform->addElement('text', 'promptcolor', get_string('promptcolor', 'mod_insightjournal'), ['size' => 10]);
        $mform->setType('promptcolor', PARAM_RAW);
        $mform->addHelpButton('promptcolor', 'promptcolor', 'mod_insightjournal');

        $mform->addElement('advcheckbox', 'autosave', get_string('autosave', 'mod_insightjournal'));
        $mform->setDefault('autosave', 1);
        $mform->addHelpButton('autosave', 'autosave', 'mod_insightjournal');

        $mform->addElement('text', 'minchars', get_string('minchars', 'mod_insightjournal'), ['size' => 6]);
        $mform->setType('minchars', PARAM_INT);
        $mform->setDefault('minchars', 0);
        $mform->addHelpButton('minchars', 'minchars', 'mod_insightjournal');

        $mform->addElement('text', 'maxchars', get_string('maxchars', 'mod_insightjournal'), ['size' => 6]);
        $mform->setType('maxchars', PARAM_INT);
        $mform->setDefault('maxchars', 0);
        $mform->addHelpButton('maxchars', 'maxchars', 'mod_insightjournal');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Validates the form data.
     *
     * @param array $data Submitted form data.
     * @param array $files Submitted files.
     * @return array Validation errors, keyed by field name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $minchars = (int) ($data['minchars'] ?? 0);
        $maxchars = (int) ($data['maxchars'] ?? 0);
        if ($minchars < 0) {
            $errors['minchars'] = get_string('err_numeric', 'form');
        }
        if ($maxchars < 0) {
            $errors['maxchars'] = get_string('err_numeric', 'form');
        }
        if ($maxchars > 0 && $minchars > $maxchars) {
            $errors['minchars'] = get_string('err_mingtmax', 'mod_insightjournal');
        }
        $promptcolor = trim((string) ($data['promptcolor'] ?? ''));
        if ($promptcolor !== '' && !preg_match('/^#?[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/', $promptcolor)) {
            $errors['promptcolor'] = get_string('err_invalidcolor', 'mod_insightjournal');
        }
        // Client-side 'required' rule on prompttext_editor is not enough on its own -
        // it can be bypassed by posting directly to this form, and an editor can also
        // serialise an empty entry as markup like "<p></p>" rather than "".
        $prompttext = $data['prompttext_editor']['text'] ?? '';
        if (insightjournal_html_to_text($prompttext) === '') {
            $errors['prompttext_editor'] = get_string('err_emptyprompt', 'mod_insightjournal');
        }
        return $errors;
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
            get_string('completionentriesgroup', 'mod_insightjournal'),
            get_string('completionentries', 'mod_insightjournal')
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
