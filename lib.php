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
 * Core callbacks for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Returns whether this module supports a given feature.
 *
 * @param string $feature FEATURE_xx constant.
 * @return bool|null True if yes, false if no, null if unknown.
 */
function insightjournal_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return false;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_COLLABORATION;
        default:
            return null;
    }
}

/**
 * Adds a new insight journal instance.
 *
 * @param stdClass $data Form data.
 * @param moodleform|null $mform The form being submitted.
 * @return int New instance id.
 */
function insightjournal_add_instance($data, $mform = null) {
    global $DB;
    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    if (isset($data->prompttext_editor)) {
        $data->prompttext = $data->prompttext_editor['text'];
        $data->promptformat = $data->prompttext_editor['format'];
    }
    return $DB->insert_record('insightjournal', $data);
}

/**
 * Updates an existing insight journal instance.
 *
 * @param stdClass $data Form data.
 * @param moodleform|null $mform The form being submitted.
 * @return bool True on success.
 */
function insightjournal_update_instance($data, $mform = null) {
    global $DB;
    $data->id = $data->instance;
    $data->timemodified = time();
    if (isset($data->prompttext_editor)) {
        $data->prompttext = $data->prompttext_editor['text'];
        $data->promptformat = $data->prompttext_editor['format'];
    }
    return $DB->update_record('insightjournal', $data);
}

/**
 * Deletes an insight journal instance and all associated data.
 *
 * @param int $id Instance id.
 * @return bool True on success.
 */
function insightjournal_delete_instance($id) {
    global $DB;
    if (!$diary = $DB->get_record('insightjournal', ['id' => $id])) {
        return false;
    }
    $DB->delete_records('insightjournal_entries', ['insightjournalid' => $diary->id]);
    $DB->delete_records('insightjournal', ['id' => $diary->id]);
    return true;
}

/**
 * Returns course module info for display in the course view.
 *
 * @param stdClass $coursemodule The course module object.
 * @return cached_cm_info|null Info object or null.
 */
function insightjournal_get_coursemodule_info($coursemodule) {
    global $DB;
    if (!$diary = $DB->get_record('insightjournal', ['id' => $coursemodule->instance], 'id,name,intro,introformat')) {
        return null;
    }
    $info = new cached_cm_info();
    $info->name = $diary->name;
    if ($coursemodule->showdescription && trim((string)$diary->intro) !== '') {
        $info->content = format_module_intro('insightjournal', $diary, $coursemodule->id, false);
    }
    return $info;
}

/**
 * Adds the insight report link to the activity settings navigation.
 *
 * @param settings_navigation $settings The settings navigation node.
 * @param navigation_node $node The activity navigation node.
 * @return void
 */
function insightjournal_extend_settings_navigation(settings_navigation $settings, navigation_node $node) {
    global $PAGE;
    if (has_capability('mod/insightjournal:viewall', $PAGE->cm->context)) {
        $url = new moodle_url('/mod/insightjournal/report.php', ['id' => $PAGE->cm->id]);
        $node->add(get_string('report', 'insightjournal'), $url, navigation_node::TYPE_SETTING);
    }
}

/**
 * Returns the active completion rule descriptions shown to learners.
 *
 * @param cm_info|stdClass $cm Course module object.
 * @return string[] Array of rule description strings.
 */
function insightjournal_get_completion_active_rule_descriptions($cm) {
    if (!empty($cm->customdata['customcompletionrules']['completionentries'])) {
        return [get_string('completionentries', 'insightjournal')];
    }
    return [];
}

/**
 * Adds insight journal options to the course reset form.
 *
 * @param MoodleQuickForm $mform The reset form.
 * @return void
 */
function insightjournal_reset_userdata_form_definition(&$mform) {
    $mform->addElement('checkbox', 'reset_insightjournal_entries',
        get_string('deleteallentries', 'insightjournal'));
}

/**
 * Performs course reset for insight journal: deletes entries if requested.
 *
 * @param stdClass $data Reset form data.
 * @return array Status array with component, item, and error keys.
 */
function insightjournal_reset_course_userdata($data) {
    global $DB;
    $status = [];
    if (!empty($data->reset_insightjournal_entries)) {
        $instances = $DB->get_records('insightjournal', ['course' => $data->courseid], '', 'id');
        foreach ($instances as $instance) {
            $DB->delete_records('insightjournal_entries', ['insightjournalid' => $instance->id]);
        }
        $status[] = [
            'component' => get_string('modulename', 'insightjournal'),
            'item'      => get_string('deleteallentries', 'insightjournal'),
            'error'     => false,
        ];
    }
    return $status;
}
