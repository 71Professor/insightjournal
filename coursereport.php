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
 * Course-wide insight journal report.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/insightjournal/lib.php');
require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');

$courseid = required_param('courseid', PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);
$page = max(0, optional_param('page', 0, PARAM_INT));
$perpage = max(1, min(200, optional_param('perpage', 20, PARAM_INT)));

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);
$coursecontext = context_course::instance($course->id);

$modinfo = get_fast_modinfo($course);
$activities = [];
$restricted = false;
foreach ($modinfo->get_instances_of('insightjournal') as $cm) {
    if (!$cm->uservisible) {
        continue;
    }
    $context = context_module::instance($cm->id);
    if (has_capability('mod/insightjournal:viewall', $context)) {
        $activities[$cm->instance] = $cm;
        if (insightjournal_activity_group_restricted($context, $course, $cm)) {
            $restricted = true;
        }
    }
}

if (empty($activities)) {
    throw new required_capability_exception($coursecontext, 'mod/insightjournal:viewall', 'nopermissions', '');
}

// $restrictgroupids is passed straight to get_enrolled_users()/count_enrolled_users(),
// which check it with a bare "if ($groupids)" - an empty array is falsy there, meaning
// "no filter at all." $blockallparticipants explicitly catches "restricted, but the
// viewer's own group list is empty" before that ambiguity can matter, so a viewer in
// zero groups sees zero participants rather than everyone.
$restrictgroupids = $restricted ? array_keys(groups_get_all_groups($course->id, $USER->id)) : 0;
$blockallparticipants = $restricted && empty($restrictgroupids);

$diaryids = array_keys($activities);
$diaries = $DB->get_records_list('insightjournal', 'id', $diaryids, 'id ASC');
$userfields = 'u.id,u.firstname,u.lastname,u.firstnamephonetic,u.lastnamephonetic,u.middlename,u.alternatename,u.email';

if ($download === 'csv') {
    foreach ($activities as $cm) {
        require_capability('mod/insightjournal:export', context_module::instance($cm->id));
    }
    confirm_sesskey();

    $participants = $blockallparticipants
        ? []
        : get_enrolled_users($coursecontext, 'mod/insightjournal:submit', $restrictgroupids, $userfields, 'u.lastname,u.firstname');

    $entries = [];
    if (!empty($diaryids)) {
        [$insql, $params] = $DB->get_in_or_equal($diaryids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_select('insightjournal_entries', "insightjournalid $insql", $params);
        foreach ($records as $entry) {
            $entries[$entry->userid][$entry->insightjournalid] = $entry;
        }
    }

    insightjournal_send_csv_headers('insightjournal-course-' . $course->shortname . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['courseid', 'coursename', 'cmid', 'activityname', 'userid', 'fullname', 'email', 'response', 'timemodified']);
    foreach ($participants as $user) {
        foreach ($diaries as $diary) {
            $entry = $entries[$user->id][$diary->id] ?? null;
            $private = $entry && !insightjournal_entry_visible_to_teacher($entry);
            fputcsv($out, [
                $course->id,
                insightjournal_csv_value($course->fullname),
                $activities[$diary->id]->id,
                insightjournal_csv_value($diary->name),
                $user->id,
                insightjournal_csv_value(fullname($user)),
                insightjournal_csv_value($user->email),
                $private
                    ? insightjournal_csv_value(get_string('entriesprivatenotice', 'insightjournal'))
                    : insightjournal_csv_value(insightjournal_html_to_text($entry->response ?? '')),
                (!$private && $entry) ? userdate($entry->timemodified) : '',
            ]);
        }
    }
    fclose($out);
    exit;
}

$totalparticipants = $blockallparticipants
    ? 0
    : count_enrolled_users($coursecontext, 'mod/insightjournal:submit', $restrictgroupids);
$participants = $blockallparticipants
    ? []
    : get_enrolled_users(
        $coursecontext,
        'mod/insightjournal:submit',
        $restrictgroupids,
        $userfields,
        'u.lastname,u.firstname',
        $page * $perpage,
        $perpage
    );

$entries = [];
if (!empty($diaryids) && !empty($participants)) {
    $userids = array_keys($participants);
    [$dinsql, $dparams] = $DB->get_in_or_equal($diaryids, SQL_PARAMS_NAMED, 'diary');
    [$uinsql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'user');
    $records = $DB->get_records_select(
        'insightjournal_entries',
        "insightjournalid $dinsql AND userid $uinsql",
        array_merge($dparams, $uparams)
    );
    foreach ($records as $entry) {
        $entries[$entry->userid][$entry->insightjournalid] = $entry;
    }
}

$PAGE->set_url('/mod/insightjournal/coursereport.php', ['courseid' => $course->id, 'page' => $page, 'perpage' => $perpage]);
$PAGE->set_context($coursecontext);
$PAGE->set_title(get_string('coursereport', 'insightjournal'));
$PAGE->set_heading(format_string($course->fullname));

$activityheaders = [];
foreach ($diaries as $diary) {
    $activityheaders[] = [
        'name' => format_string($diary->name),
    ];
}

$rows = [];
foreach ($participants as $user) {
    $done = 0;
    $cells = [];
    foreach ($diaries as $diary) {
        $entry = $entries[$user->id][$diary->id] ?? null;
        if ($entry && !insightjournal_entry_visible_to_teacher($entry)) {
            $cells[] = ['private' => true];
            continue;
        }
        $completed = $entry && insightjournal_html_to_text($entry->response) !== '';
        if ($completed) {
            $done++;
        }
        $cells[] = [
            'private' => false,
            'completed' => $completed,
            'status' => get_string($completed ? 'submitted' : 'notsubmitted', 'insightjournal'),
            'timemodified' => $completed ? userdate($entry->timemodified, get_string('strftimedatetimeshort', 'langconfig')) : '',
        ];
    }
    $rows[] = [
        'fullname' => fullname($user),
        'summaryurl' => (new moodle_url(
            '/mod/insightjournal/summary.php',
            [
                'courseid' => $course->id,
                'userid' => $user->id,
                'returnurl' => (new moodle_url(
                    '/mod/insightjournal/coursereport.php',
                    ['courseid' => $course->id]
                ))->out_as_local_url(false),
            ]
        ))->out(false),
        'cells' => $cells,
        'progress' => $done . ' / ' . count($diaries),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coursereport', 'insightjournal'));
echo $OUTPUT->render_from_template('mod_insightjournal/coursereport', [
    'backurl' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
    'downloadurl' => (new moodle_url(
        '/mod/insightjournal/coursereport.php',
        ['courseid' => $course->id, 'download' => 'csv', 'sesskey' => sesskey()]
    ))->out(false),
    'activities' => $activityheaders,
    'rows' => $rows,
    'hasactivities' => !empty($activityheaders),
    'pagingbar' => $OUTPUT->paging_bar($totalparticipants, $page, $perpage, $PAGE->url),
]);
echo $OUTPUT->footer();
