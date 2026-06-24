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
 * Activity report for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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

$sqlparams = ['diaryid' => $diary->id];
$where = 'e.insightjournalid = :diaryid';
if ($search !== '') {
    $needle = '%' . $DB->sql_like_escape($search) . '%';
    $where .= ' AND (' . $DB->sql_like('u.firstname', ':sfn', false) .
              ' OR ' . $DB->sql_like('u.lastname', ':sln', false) .
              ' OR ' . $DB->sql_like('u.email', ':sem', false) . ')';
    $sqlparams['sfn'] = $needle;
    $sqlparams['sln'] = $needle;
    $sqlparams['sem'] = $needle;
}
$sql = "SELECT e.*, u.firstname, u.lastname,
               u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
               u.email
          FROM {insightjournal_entries} e
          JOIN {user} u ON u.id = e.userid
         WHERE $where
      ORDER BY u.lastname, u.firstname";
$entries = $DB->get_records_sql($sql, $sqlparams);

if ($download === 'csv') {
    require_capability('mod/insightjournal:export', $context);
    confirm_sesskey();
    insightjournal_send_csv_headers('insightjournal-' . $course->shortname . '-' . $diary->id . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['courseid', 'coursename', 'cmid', 'activityname', 'userid', 'fullname', 'email', 'response', 'timemodified']);
    foreach ($entries as $entry) {
        $user = (object)['firstname' => $entry->firstname, 'lastname' => $entry->lastname,
                         'firstnamephonetic' => $entry->firstnamephonetic,
                         'lastnamephonetic' => $entry->lastnamephonetic,
                         'middlename' => $entry->middlename,
                         'alternatename' => $entry->alternatename];
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
    $user = (object)[
        'firstname' => $entry->firstname,
        'lastname' => $entry->lastname,
        'firstnamephonetic' => $entry->firstnamephonetic,
        'lastnamephonetic' => $entry->lastnamephonetic,
        'middlename' => $entry->middlename,
        'alternatename' => $entry->alternatename,
    ];
    $rows[] = [
        'fullname' => fullname($user),
        'email' => $entry->email,
        'summaryurl' => (new moodle_url(
            '/mod/insightjournal/summary.php',
            [
                'courseid' => $course->id,
                'userid' => $entry->userid,
                'returnurl' => (new moodle_url('/mod/insightjournal/report.php', ['id' => $cm->id]))->out_as_local_url(false),
            ]
        ))->out(false),
        'response' => $entry->response,
        'timemodified' => userdate($entry->timemodified, get_string('strftimedatetimeshort', 'langconfig')),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('reportfor', 'insightjournal', format_string($diary->name)));
echo $OUTPUT->render_from_template('mod_insightjournal/report', [
    'backurl' => (new moodle_url('/mod/insightjournal/view.php', ['id' => $cm->id]))->out(false),
    'downloadurl' => (new moodle_url(
        '/mod/insightjournal/report.php',
        ['id' => $cm->id, 'search' => $search, 'download' => 'csv', 'sesskey' => sesskey()]
    ))->out(false),
    'actionurl' => (new moodle_url('/mod/insightjournal/report.php', ['id' => $cm->id]))->out(false),
    'cmid' => $cm->id,
    'search' => $search,
    'rows' => $rows,
    'hasrows' => !empty($rows),
]);
echo $OUTPUT->footer();
