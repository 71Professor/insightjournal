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
 * Personal insight journal summary page.
 *
 * @package    mod_insightjournal
 * @copyright  2026 insightjournal contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

require_once('../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);
$coursecontext = context_course::instance($course->id);

$modinfo = get_fast_modinfo($course);
$cms = [];
$viewallcms = [];
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
    $canviewown = $canviewown || has_capability('mod/insightjournal:viewown', $modulecontext);
    if (has_capability('mod/insightjournal:viewall', $modulecontext)) {
        $viewallcms[$cm->instance] = $cm;
    }
}
$canviewall = !empty($viewallcms);

if (empty($cms)) {
    throw new required_capability_exception($coursecontext, 'mod/insightjournal:view', 'nopermissions', '');
}

$viewuserid = $USER->id;
if ($userid && $userid != $USER->id) {
    if (!$canviewall) {
        throw new required_capability_exception($coursecontext, 'mod/insightjournal:viewall', 'nopermissions', '');
    }
    if (!is_enrolled($coursecontext, $userid)) {
        throw new moodle_exception('notenrolled', 'enrol');
    }
    $viewuserid = $userid;
} else if (!$canviewown && !$canviewall) {
    throw new required_capability_exception($coursecontext, 'mod/insightjournal:viewown', 'nopermissions', '');
}

// Only fetch fields needed for display – avoids exposing password hashes etc. if object is passed further.
$viewuser = $DB->get_record('user', ['id' => $viewuserid], 'id,firstname,lastname,email', MUST_EXIST);
// When viewing another user, restrict to journals where viewall is explicitly granted.
$querycms = ($viewuserid !== $USER->id) ? $viewallcms : $cms;
$diaryids = array_keys($querycms);
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
