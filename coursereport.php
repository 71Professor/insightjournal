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
foreach ($modinfo->get_instances_of('insightjournal') as $cm) {
    if (!$cm->uservisible) {
        continue;
    }
    $context = context_module::instance($cm->id);
    if (has_capability('mod/insightjournal:viewall', $context)) {
        $activities[$cm->instance] = $cm;
    }
}

if (empty($activities)) {
    throw new required_capability_exception($coursecontext, 'mod/insightjournal:viewall', 'nopermissions', '');
}

// The insightjournal_coursereport_restrict_groupids() call already returns null for
// "no restriction needed" (at least one visible activity is unrestricted); the
// falsy-$USER->id guard now lives centrally in insightjournal_current_user_allowed_groupids().
// The bare "if ($groupids)" check inside get_enrolled_users()/count_enrolled_users()
// would otherwise treat an empty array the SAME as null ("no filter"), so
// $blockallparticipants still catches "every visible activity is restricted, but
// the union of the viewer's own allowed groups is empty" explicitly, before that
// ambiguity can matter.
$restrictgroupids = insightjournal_coursereport_restrict_groupids($activities, $course);
$blockallparticipants = $restrictgroupids !== null && empty($restrictgroupids);
if ($restrictgroupids === null) {
    $restrictgroupids = 0;
}

$diaryids = array_keys($activities);
$diaries = $DB->get_records_list('insightjournal', 'id', $diaryids, 'id ASC');
// Allowed group ids per diary (R4-03): resolved once per distinct grouping,
// not once per activity, and NOT the member lookup itself - that happens
// below, scoped to only the userids in the current page/CSV chunk, never
// the whole course at once.
$diaryallowedgroupids = insightjournal_coursereport_allowed_groupids_by_diary($activities, $course);
// Checked at course context, not per-activity like report_table.php - deliberately
// coarse. A viewer reaching this branch already holds the capability course-wide,
// so this can only ever be more permissive than a hypothetical per-activity
// override, never less.
$showemail = insightjournal_email_field_visible($coursecontext);
$namefields = \core_user\fields::for_name()->including('id');
if ($showemail) {
    $namefields->including('email');
}
// Only ->selects is used below: for_name()/including('id'|'email') can never add
// a custom profile field, so ->joins and ->params are always empty here - revisit
// this assumption if a with_identity()/custom-field include is ever added.
$userfields = $namefields->get_sql('u', false, '', '', false)->selects;

if ($download === 'csv') {
    foreach ($activities as $cm) {
        require_capability('mod/insightjournal:export', context_module::instance($cm->id));
    }
    confirm_sesskey();

    require_once($CFG->libdir . '/csvlib.class.php');
    $writer = new csv_export_writer('comma', '"', 'text/csv', true); // BOM: true - matches report.php's dataformat-writer BOM.
    $writer->filename = clean_filename('insightjournal-course-' . $course->shortname . '.csv');
    $writer->add_data([
        'courseid', 'coursename', 'cmid', 'activityname', 'userid',
        'fullname', 'email', 'response', 'timemodified',
    ]);

    // Fetched and written one bounded chunk of participants at a time, each
    // with only that chunk's own entries, instead of the whole course's
    // participants/entries held in memory at once - keeps memory bounded
    // regardless of course size, the same property report.php already gets
    // for free from table_sql (R2-04).
    $csvchunksize = 500;
    $offset = 0;
    while (!$blockallparticipants) {
        $chunk = get_enrolled_users(
            $coursecontext,
            'mod/insightjournal:submit',
            $restrictgroupids,
            $userfields,
            'u.lastname,u.firstname,u.id',
            $offset,
            $csvchunksize
        );
        if (empty($chunk)) {
            break;
        }
        $chunkentries = insightjournal_entries_by_diary_and_user($diaryids, array_keys($chunk));
        $diaryallowedusers = insightjournal_coursereport_diary_allowed_users(
            $diaryallowedgroupids,
            array_keys($chunk)
        );
        foreach ($chunk as $user) {
            foreach ($diaries as $diary) {
                $allowedusers = $diaryallowedusers[$diary->id];
                if ($allowedusers !== null && !isset($allowedusers[(int) $user->id])) {
                    continue;
                }
                $writer->add_data(insightjournal_coursereport_csv_row(
                    $course,
                    $activities[$diary->id]->id,
                    $diary,
                    $user,
                    $chunkentries[$user->id][$diary->id] ?? null,
                    $showemail
                ));
            }
        }
        $offset += $csvchunksize;
        if (count($chunk) < $csvchunksize) {
            break;
        }
    }
    $writer->download_file(); // Sends headers, streams the file, and exit()s - same contract as the previous fclose()+exit.
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
        'u.lastname,u.firstname,u.id',
        $page * $perpage,
        $perpage
    );

$entries = insightjournal_entries_by_diary_and_user($diaryids, array_keys($participants));
$diaryallowedusers = insightjournal_coursereport_diary_allowed_users(
    $diaryallowedgroupids,
    array_keys($participants)
);

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
    // Counts only the diaries this viewer is authorized to see for this
    // learner (i.e. not group-restricted away), used as the progress
    // denominator below - not count($diaries), which would silently
    // understate a learner's ratio by diaries the viewer cannot see any
    // data for at all, the same "authorization-invisible activities don't
    // count" principle already applied everywhere else on this page.
    $visiblecount = 0;
    $cells = [];
    foreach ($diaries as $diary) {
        $allowedusers = $diaryallowedusers[$diary->id];
        if ($allowedusers !== null && !isset($allowedusers[(int) $user->id])) {
            $cells[] = ['private' => true];
            continue;
        }
        $visiblecount++;
        $entry = $entries[$user->id][$diary->id] ?? null;
        $state = insightjournal_coursereport_cell_state($entry);
        if ($state['completed']) {
            $done++;
        }
        if ($state['private']) {
            $cells[] = ['private' => true];
            continue;
        }
        $cells[] = [
            'private' => false,
            'completed' => $state['completed'],
            'status' => get_string($state['completed'] ? 'submitted' : 'notsubmitted', 'insightjournal'),
            'timemodified' => $state['completed']
                ? userdate($entry->timemodified, get_string('strftimedatetimeshort', 'langconfig'))
                : '',
        ];
    }
    if ($visiblecount === 0) {
        continue;
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
        'progress' => $done . ' / ' . $visiblecount,
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
