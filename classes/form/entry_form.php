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
 * Entry response form for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_insightjournal\form;

use moodleform;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * The learner's response form: a standard Moodle editor element, a private
 * checkbox, and a save button.
 *
 * Owns its own editor bootstrap (no file attachments, content never
 * trusted) so callers no longer need to call editors_head_setup()/
 * use_editor() themselves. Works identically for a plain POST submit (no
 * JavaScript required) or the same data taken from an AJAX call: either way,
 * the submitted values are handed to entry_manager::save().
 */
class entry_form extends moodleform {
    /**
     * Defines the form: response editor, private checkbox, save button.
     */
    public function definition(): void {
        $mform = $this->_form;
        $context = $this->_customdata['context'];

        // No file/image attachments, content is never trusted.
        // enable_filemanagement must agree with maxfiles=0: Atto otherwise
        // still shows its "manage files" button even though no draft
        // area/filepicker options exist behind it (Tiny does not read this
        // option either way).
        $editoroptions = [
            'subdirs' => false,
            'maxbytes' => 0,
            'maxfiles' => 0,
            'changeformat' => 0,
            'areamaxbytes' => FILE_AREA_MAX_BYTES_UNLIMITED,
            'context' => $context,
            'noclean' => 0,
            'trusttext' => false,
            'trusted' => false,
            'enable_filemanagement' => false,
            'removeorphaneddrafts' => false,
            'autosave' => true,
        ];

        // The editor element's HTML comes from a fixed core template
        // (core_form/editor_textarea) that only ever renders id/name/rows/
        // cols/onblur/onchange onto the actual <textarea> — not arbitrary
        // attributes — so amd/src/autosave.js locates it by its standard
        // Moodle-generated id ("id_response") rather than a data attribute.
        $mform->addElement(
            'editor',
            'response',
            get_string('response', 'mod_insightjournal'),
            ['rows' => 10, 'cols' => 80],
            $editoroptions
        );
        $mform->setType('response', PARAM_RAW);

        $mform->addElement('hidden', 'expectedrevision');
        $mform->setType('expectedrevision', PARAM_INT);

        $mform->addElement(
            'advcheckbox',
            'private',
            '',
            get_string('entryprivate', 'mod_insightjournal')
        );
        $mform->setType('private', PARAM_BOOL);
        $mform->addHelpButton('private', 'entryprivate', 'mod_insightjournal');
        $mform->getElement('private')->updateAttributes(['data-insightjournal-private' => '']);

        $this->add_action_buttons(false, get_string('save', 'mod_insightjournal'));
        $mform->getElement('submitbutton')->updateAttributes(['data-insightjournal-save' => '']);
    }

    /**
     * Forces expectedrevision to a specific value regardless of what was
     * submitted - used only when re-rendering after a save conflict, where
     * the submitted value is the very (stale) revision that just caused the
     * conflict. set_data() cannot do this: HTML_QuickForm_element's
     * onQuickFormEvent('updateValue') resolves constant values first,
     * *then* submitted, then default - a set_data()-provided default is
     * always overridden by an already-submitted value for the same field.
     * setConstant() is the one mechanism that overrides a submitted value.
     *
     * @param int $revision
     */
    public function force_expected_revision(int $revision): void {
        $this->_form->setConstant('expectedrevision', $revision);
    }

    /**
     * Server-side maxchars check, mirroring entry_manager::save()'s own
     * backstop but surfaced as a normal inline form error here.
     *
     * @param array $data Submitted form data.
     * @param array $files Submitted files.
     * @return array Error messages, keyed by element name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $maxchars = (int) ($this->_customdata['maxchars'] ?? 0);
        if ($maxchars > 0) {
            global $CFG;
            require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');
            $text = $data['response']['text'] ?? '';
            $visiblelength = insightjournal_visible_char_count($text);
            if ($visiblelength > $maxchars) {
                $errors['response'] = get_string('maxcharserror', 'mod_insightjournal', $maxchars);
            }
        }

        return $errors;
    }
}
