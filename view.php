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

$entryformhtml = '';
$conflict = null;
if ($canwrite) {
    $mform = new \mod_insightjournal\form\entry_form(
        new moodle_url('/mod/insightjournal/view.php', ['id' => $id]),
        ['context' => $context, 'maxchars' => (int) ($diary->maxchars ?? 0)]
    );

    // Standard POST submit (no JavaScript required): the same entry_manager
    // service the AJAX external function (classes/external/save_entry.php)
    // calls. Handled before any output, per the usual Moodle
    // process-then-redirect pattern - except on a conflict, which falls
    // through to render immediately instead of redirecting (see below).
    if ($data = $mform->get_data()) {
        $result = \mod_insightjournal\local\entry_manager::save(
            $diary,
            $course,
            $cm,
            (int) $USER->id,
            $data->response['text'],
            (int) $data->expectedrevision,
            (bool) $data->private
        );
        if ($result['conflict']) {
            // Never silently discard the learner's just-typed draft by
            // redirecting to the server's current record - re-render
            // immediately instead, the same "show both, let the learner
            // choose" principle autosave.js's showConflictBanner() already
            // follows for the AJAX/JS path. The response/private fields need
            // no explicit action: a submitted moodleform value already wins
            // over any default at render time, so the draft is redisplayed
            // as-is. expectedrevision is different - it must be forced past
            // the just-submitted (now-stale) value via setConstant(), since
            // set_data()/setDefaults() alone can never override a submitted
            // value - see entry_form::force_expected_revision(). This makes
            // clicking Save again either succeed (nothing else changed
            // meanwhile) or report a fresh conflict; the reload link below
            // is the explicit way to discard the draft and adopt the
            // server's version instead.
            $conflict = $result;
            $mform->force_expected_revision((int) $result['revision']);
        } else {
            \core\notification::success(get_string('savedat', 'insightjournal', $result['timestr']));
            redirect(new moodle_url('/mod/insightjournal/view.php', ['id' => $id]));
        }
    } else {
        $mform->set_data([
            'response' => ['text' => $responseraw, 'format' => $entry ? $entry->responseformat : FORMAT_HTML],
            'expectedrevision' => $entry ? (int) $entry->revision : 0,
            'private' => $entryprivate ? 1 : 0,
        ]);
    }
    $entryformhtml = $mform->render();
}

insightjournal_view($diary, $course, $cm, $context);

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
    'entryformhtml' => $entryformhtml,
    'responseformatted' => $haveentry
        ? format_text($responseraw, $entry->responseformat, ['context' => $context])
        : '',
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
    'conflict' => (bool) $conflict,
    'conflictmessage' => $conflict ? get_string('saveconflict', 'insightjournal') : '',
    'conflictcontent' => $conflict ? $conflict['responsehtml'] : '',
    'viewurl' => (new moodle_url('/mod/insightjournal/view.php', ['id' => $id]))->out(false),
];
echo $OUTPUT->render_from_template('mod_insightjournal/view', $templatecontext);
echo $OUTPUT->footer();
