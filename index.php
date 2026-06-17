<?php
require_once('../../config.php');

$id = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_login($course);
$context = context_course::instance($course->id);

$PAGE->set_url('/mod/insightjournal/index.php', ['id' => $id]);
$PAGE->set_title(get_string('modulenameplural', 'insightjournal'));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'insightjournal'));

$modinfo = get_fast_modinfo($course);
$items = [];
foreach ($modinfo->get_instances_of('insightjournal') as $cm) {
    if (!$cm->uservisible) {
        continue;
    }
    $items[] = html_writer::link(new moodle_url('/mod/insightjournal/view.php', ['id' => $cm->id]), format_string($cm->name));
}
echo html_writer::alist($items);
echo $OUTPUT->footer();
