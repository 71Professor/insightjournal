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
 * View page for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');
// Needed by editors_get_preferred_editor()->use_editor() below: unlike the mform
// 'editor' element (see mod_form.php), which pulls this in itself via
// lib/form/editor.php, calling use_editor() directly does not. Without it,
// FILE_EXTERNAL is undefined and TinyMCE's media plugin fatals.
require_once($CFG->dirroot . '/repository/lib.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('insightjournal', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$diary = $DB->get_record('insightjournal', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/insightjournal:view', $context);

$PAGE->set_url('/mod/insightjournal/view.php', ['id' => $id]);
$PAGE->set_title(format_string($diary->name));
$PAGE->set_heading(format_string($course->fullname));
$entry = $DB->get_record('insightjournal_entries', ['insightjournalid' => $diary->id, 'userid' => $USER->id]);

$PAGE->requires->js_call_amd(
    'mod_insightjournal/autosave',
    'init',
    [
        $cm->id,
        (int) $diary->autosave,
        (int) ($diary->maxchars ?? 0),
        $entry ? (int) $entry->revision : 0,
    ]
);

$canwrite = has_capability('mod/insightjournal:submit', $context);
$canviewall = has_capability('mod/insightjournal:viewall', $context);

$responseraw = $entry ? $entry->response : '';
$haveentry = insightjournal_html_to_text($responseraw) !== '';
$entryprivate = $entry ? !insightjournal_entry_visible_to_teacher($entry) : false;

if ($canwrite) {
    // Same restriction options as the prompt field's editor (mod_form.php): no
    // file/image attachments, content is never trusted. enable_filemanagement
    // must agree with maxfiles=0: Atto otherwise still shows its "manage
    // files" button even though no draft area/filepicker options exist behind
    // it (Tiny does not read this option either way).
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
        'return_types' => 15,
        'enable_filemanagement' => false,
        'removeorphaneddrafts' => false,
        'autosave' => true,
    ];
    editors_head_setup();
    editors_get_preferred_editor(FORMAT_HTML)->use_editor('insightjournal-response-' . $cm->id, $editoroptions, []);
}

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($diary->name));
if (trim((string) $diary->intro) !== '') {
    echo $OUTPUT->box(format_module_intro('insightjournal', $diary, $cm->id), 'generalbox mod_introbox');
}

$modinfo = get_fast_modinfo($course);
$sectionnum = $modinfo->get_cm($cm->id)->sectionnum;

$templatecontext = [
    'cmid' => $cm->id,
    'prompt' => format_text($diary->prompttext, $diary->promptformat, ['context' => $context]),
    'promptstyle' => insightjournal_prompt_style($diary->promptcolor ?? ''),
    'canwrite' => $canwrite,
    'haveentry' => $haveentry,
    'responseraw' => $responseraw,
    'responseformatted' => $haveentry
        ? format_text($responseraw, $entry->responseformat, ['context' => $context])
        : '',
    'entryprivate' => $entryprivate,
    'autosave' => (bool) $diary->autosave,
    'minchars' => (int) $diary->minchars,
    'maxchars' => (int) ($diary->maxchars ?? 0),
    'lastsaved' => $entry
        ? get_string(
            'lastsaved',
            'insightjournal',
            userdate($entry->timemodified, get_string('strftimedatetimeshort', 'langconfig'))
        )
        : '',
    'sesskey' => sesskey(),
    'reporturl' => (new moodle_url('/mod/insightjournal/coursereport.php', ['courseid' => $course->id]))->out(false),
    'summaryurl' => (new moodle_url('/mod/insightjournal/summary.php', ['courseid' => $course->id]))->out(false),
    'sectionurl' => (new moodle_url('/course/view.php', ['id' => $course->id, 'section' => $sectionnum]))->out(false),
    'canviewall' => $canviewall,
];
echo $OUTPUT->render_from_template('mod_insightjournal/view', $templatecontext);
echo $OUTPUT->footer();
