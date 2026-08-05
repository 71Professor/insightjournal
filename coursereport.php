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

use mod_insightjournal\local\coursereport_provider;

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

$diaryids = array_keys($activities);
$diaries = $DB->get_records_list('insightjournal', 'id', $diaryids, 'id ASC');
$provider = new coursereport_provider($course, $activities);

// Checked at course context, not per-activity like report_table.php - deliberately
// coarse. A viewer reaching this branch already holds the capability course-wide,
// so this can only ever be more permissive than a hypothetical per-activity
// override, never less.
$showemail = insightjournal_email_field_visible($coursecontext);

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
    while (true) {
        $chunk = $provider->participants($offset, $csvchunksize);
        if (empty($chunk)) {
            break;
        }
        foreach ($provider->rows_for($chunk) as $row) {
            foreach ($row['cells'] as $diaryid => $cell) {
                if (!$cell['visible']) {
                    continue;
                }
                $writer->add_data(insightjournal_coursereport_csv_row(
                    $course,
                    $activities[$diaryid]->id,
                    $diaries[$diaryid],
                    $row['user'],
                    $cell['entry'],
                    $cell['private'],
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

$totalparticipants = $provider->total_participants();
$participants = $provider->participants($page * $perpage, $perpage);

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
foreach ($provider->rows_for($participants) as $userid => $row) {
    if ($row['visiblecount'] === 0) {
        continue;
    }
    $cells = [];
    foreach ($diaries as $diary) {
        $cell = $row['cells'][$diary->id];
        if (!$cell['visible'] || $cell['private']) {
            $cells[] = ['private' => true];
            continue;
        }
        $cells[] = [
            'private' => false,
            'completed' => $cell['completed'],
            'status' => get_string($cell['completed'] ? 'submitted' : 'notsubmitted', 'insightjournal'),
            'timemodified' => $cell['completed']
                ? userdate($cell['entry']->timemodified, get_string('strftimedatetimeshort', 'langconfig'))
                : '',
        ];
    }
    $rows[] = [
        'fullname' => fullname($row['user']),
        'summaryurl' => (new moodle_url(
            '/mod/insightjournal/summary.php',
            [
                'courseid' => $course->id,
                'userid' => $userid,
                'returnurl' => (new moodle_url(
                    '/mod/insightjournal/coursereport.php',
                    ['courseid' => $course->id]
                ))->out_as_local_url(false),
            ]
        ))->out(false),
        'cells' => $cells,
        'progress' => $row['done'] . ' / ' . $row['visiblecount'],
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
