<?php
// Personal Insight Journal summary.

require_once('../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);
$coursecontext = context_course::instance($course->id);

$modinfo = get_fast_modinfo($course);
$cms = [];
$canviewall = false;
$canviewown = false;
foreach ($modinfo->get_instances_of('insightjournal') as $cm) {
    if (!$cm->uservisible) {
        continue;
    }
    $modulecontext = context_module::instance($cm->id);
    if (!has_capability('mod/insightjournal:view', $modulecontext)) {
        continue;
    }
    $cms[$cm->instance] = $cm;
    $canviewall = $canviewall || has_capability('mod/insightjournal:viewall', $modulecontext);
    $canviewown = $canviewown || has_capability('mod/insightjournal:viewown', $modulecontext);
}

if (empty($cms)) {
    throw new required_capability_exception($coursecontext, 'mod/insightjournal:view', 'nopermissions', '');
}

$viewuserid = $USER->id;
if ($userid && $userid != $USER->id) {
    if (!$canviewall) {
        throw new required_capability_exception($coursecontext, 'mod/insightjournal:viewall', 'nopermissions', '');
    }
    $viewuserid = $userid;
} else if (!$canviewown && !$canviewall) {
    throw new required_capability_exception($coursecontext, 'mod/insightjournal:viewown', 'nopermissions', '');
}

$viewuser = $DB->get_record('user', ['id' => $viewuserid], '*', MUST_EXIST);
$diaryids = array_keys($cms);
list($insql, $params) = $DB->get_in_or_equal($diaryids, SQL_PARAMS_NAMED);
$params['userid'] = $viewuserid;
$records = $DB->get_records_sql(
    "SELECT rd.id, rd.name, rd.prompttext, rd.promptformat, e.response, e.timemodified
       FROM {insightjournal} rd
  LEFT JOIN {insightjournal_entries} e ON e.insightjournalid = rd.id AND e.userid = :userid
      WHERE rd.id $insql
   ORDER BY rd.id ASC",
    $params
);

$PAGE->set_url('/mod/insightjournal/summary.php', ['courseid' => $courseid, 'userid' => $viewuserid]);
$PAGE->set_context($coursecontext);
$PAGE->set_title(get_string('mysummary', 'insightjournal'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->js_call_amd('mod_insightjournal/summary', 'init');

$items = [];
foreach ($records as $record) {
    $modulecontext = context_module::instance($cms[$record->id]->id);
    $items[] = [
        'activityname' => format_string($record->name),
        'prompt' => format_text($record->prompttext, $record->promptformat, ['context' => $modulecontext]),
        'response' => $record->response ?? '',
        'timemodified' => !empty($record->timemodified) ?
            userdate($record->timemodified, get_string('strftimedatetimeshort', 'langconfig')) : '',
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('mysummaryfor', 'insightjournal', fullname($viewuser)));
echo $OUTPUT->render_from_template('mod_insightjournal/summary', [
    'backurl' => (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
    'items' => $items,
    'hasitems' => !empty($items),
]);
echo $OUTPUT->footer();
