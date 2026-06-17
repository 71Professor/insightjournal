<?php
// Activity report for mod_insightjournal.

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');

$id = required_param('id', PARAM_INT);
$search = optional_param('search', '', PARAM_NOTAGS);
$download = optional_param('download', '', PARAM_ALPHA);

$cm = get_coursemodule_from_id('insightjournal', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$diary = $DB->get_record('insightjournal', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/insightjournal:viewall', $context);

$sql = "SELECT e.*, u.firstname, u.lastname, u.email
          FROM {insightjournal_entries} e
          JOIN {user} u ON u.id = e.userid
         WHERE e.insightjournalid = :diaryid
      ORDER BY u.lastname, u.firstname";
$entries = $DB->get_records_sql($sql, ['diaryid' => $diary->id]);

if ($search !== '') {
    $needle = core_text::strtolower($search);
    $entries = array_filter($entries, static function($entry) use ($needle): bool {
        $user = (object)['firstname' => $entry->firstname, 'lastname' => $entry->lastname];
        $haystack = core_text::strtolower(fullname($user) . ' ' . $entry->email);
        return core_text::strpos($haystack, $needle) !== false;
    });
}

if ($download === 'csv') {
    require_capability('mod/insightjournal:export', $context);
    insightjournal_send_csv_headers('insightjournal-' . $course->shortname . '-' . $diary->id . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['courseid', 'coursename', 'cmid', 'activityname', 'userid', 'fullname', 'email', 'response', 'timemodified']);
    foreach ($entries as $entry) {
        $user = (object)['firstname' => $entry->firstname, 'lastname' => $entry->lastname];
        fputcsv($out, [
            $course->id,
            insightjournal_csv_value($course->fullname),
            $cm->id,
            insightjournal_csv_value($diary->name),
            $entry->userid,
            insightjournal_csv_value(fullname($user)),
            insightjournal_csv_value($entry->email),
            insightjournal_csv_value($entry->response),
            userdate($entry->timemodified),
        ]);
    }
    fclose($out);
    exit;
}

$PAGE->set_url('/mod/insightjournal/report.php', ['id' => $id, 'search' => $search]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('report', 'insightjournal'));
$PAGE->set_heading(format_string($course->fullname));

$rows = [];
foreach ($entries as $entry) {
    $user = (object)['firstname' => $entry->firstname, 'lastname' => $entry->lastname];
    $rows[] = [
        'fullname' => fullname($user),
        'email' => $entry->email,
        'summaryurl' => (new moodle_url('/mod/insightjournal/summary.php',
            ['courseid' => $course->id, 'userid' => $entry->userid]))->out(false),
        'response' => $entry->response,
        'timemodified' => userdate($entry->timemodified, get_string('strftimedatetimeshort', 'langconfig')),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('reportfor', 'insightjournal', format_string($diary->name)));
echo $OUTPUT->render_from_template('mod_insightjournal/report', [
    'backurl' => (new moodle_url('/mod/insightjournal/view.php', ['id' => $cm->id]))->out(false),
    'downloadurl' => (new moodle_url('/mod/insightjournal/report.php',
        ['id' => $cm->id, 'search' => $search, 'download' => 'csv']))->out(false),
    'actionurl' => (new moodle_url('/mod/insightjournal/report.php', ['id' => $cm->id]))->out(false),
    'cmid' => $cm->id,
    'search' => $search,
    'rows' => $rows,
    'hasrows' => !empty($rows),
]);
echo $OUTPUT->footer();
