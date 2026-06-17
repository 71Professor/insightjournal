<?php
// Core callbacks for mod_insightjournal.

defined('MOODLE_INTERNAL') || die();

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
        default:
            return null;
    }
}

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

function insightjournal_delete_instance($id) {
    global $DB;
    if (!$diary = $DB->get_record('insightjournal', ['id' => $id])) {
        return false;
    }
    $DB->delete_records('insightjournal_entries', ['insightjournalid' => $diary->id]);
    $DB->delete_records('insightjournal', ['id' => $diary->id]);
    return true;
}

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

function insightjournal_extend_settings_navigation(settings_navigation $settings, navigation_node $node) {
    global $PAGE;
    if (has_capability('mod/insightjournal:viewall', $PAGE->cm->context)) {
        $url = new moodle_url('/mod/insightjournal/report.php', ['id' => $PAGE->cm->id]);
        $node->add(get_string('report', 'insightjournal'), $url, navigation_node::TYPE_SETTING);
    }
}

function insightjournal_get_completion_state($course, $cm, $userid, $type) {
    global $DB;
    if (!$diary = $DB->get_record('insightjournal', ['id' => $cm->instance], 'id,minchars,completionentries')) {
        return false;
    }
    if (empty($diary->completionentries)) {
        return $type == COMPLETION_AND;
    }
    $entry = $DB->get_record('insightjournal_entries', ['insightjournalid' => $diary->id, 'userid' => $userid], 'id,response');
    if (!$entry || trim((string)$entry->response) === '') {
        return false;
    }
    return core_text::strlen(trim($entry->response)) >= (int)$diary->minchars;
}
