<?php
require_once('../../config.php');

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
$PAGE->requires->strings_for_js(['saving', 'savedat', 'saveerror'], 'insightjournal');
$PAGE->requires->js_call_amd('mod_insightjournal/autosave', 'init', [$cm->id, (int)$diary->autosave]);

$entry = $DB->get_record('insightjournal_entries', ['insightjournalid' => $diary->id, 'userid' => $USER->id]);
$canwrite = has_capability('mod/insightjournal:submit', $context);
$canviewall = has_capability('mod/insightjournal:viewall', $context);

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($diary->name));
if (trim((string)$diary->intro) !== '') {
    echo $OUTPUT->box(format_module_intro('insightjournal', $diary, $cm->id), 'generalbox mod_introbox');
}

$templatecontext = [
    'cmid' => $cm->id,
    'prompt' => format_text($diary->prompttext, $diary->promptformat, ['context' => $context]),
    'response' => $entry ? $entry->response : '',
    'canwrite' => $canwrite,
    'autosave' => (bool)$diary->autosave,
    'minchars' => (int)$diary->minchars,
    'lastsaved' => $entry ? get_string('lastsaved', 'insightjournal', userdate($entry->timemodified, get_string('strftimedatetimeshort', 'langconfig'))) : '',
    'sesskey' => sesskey(),
    'reporturl' => (new moodle_url('/mod/insightjournal/report.php', ['id' => $cm->id]))->out(false),
    'summaryurl' => (new moodle_url('/mod/insightjournal/summary.php', ['courseid' => $course->id]))->out(false),
    'canviewall' => $canviewall,
];
echo $OUTPUT->render_from_template('mod_insightjournal/view', $templatecontext);
echo $OUTPUT->footer();
