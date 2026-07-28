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
require_once($CFG->dirroot . '/mod/insightjournal/lib.php');
require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');

use mod_insightjournal\table\report_table;

$id = required_param('id', PARAM_INT);
$search = optional_param('search', '', PARAM_NOTAGS);
$wantscsv = optional_param('download', '', PARAM_ALPHA) === 'csv';
$perpage = max(1, min(200, optional_param('perpage', 20, PARAM_INT)));

$cm = get_coursemodule_from_id('insightjournal', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$diary = $DB->get_record('insightjournal', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/insightjournal:viewall', $context);

if ($wantscsv) {
    require_capability('mod/insightjournal:export', $context);
    confirm_sesskey();
}

$table = new report_table('mod_insightjournal_report_' . $cm->id, $course, $cm, $diary, $context, $search);
$table->is_downloading(
    $wantscsv ? 'csv' : null,
    'insightjournal-' . $course->shortname . '-' . $diary->id
);
$table->setup_columns();
$table->define_baseurl(new moodle_url('/mod/insightjournal/report.php', ['id' => $cm->id, 'search' => $search]));

if ($table->is_downloading()) {
    $table->out($perpage, false);
    // out() exits internally once the CSV has been streamed
    // (finish_document() calls exit()) - nothing below this runs for a download.
}

$PAGE->set_url('/mod/insightjournal/report.php', ['id' => $id, 'search' => $search]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('report', 'insightjournal'));
$PAGE->set_heading(format_string($course->fullname));

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
]);
$table->out($perpage, false);
echo $OUTPUT->footer();
