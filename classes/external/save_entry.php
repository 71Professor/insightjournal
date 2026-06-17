<?php
namespace mod_insightjournal\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class save_entry extends \external_api {
    public static function execute_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'cmid' => new \external_value(PARAM_INT, 'Course module id'),
            'response' => new \external_value(PARAM_RAW, 'Learner response'),
        ]);
    }

    public static function execute(int $cmid, string $response): array {
        global $DB, $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'response' => $response]);
        $cm = get_coursemodule_from_id('insightjournal', $params['cmid'], 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $diary = $DB->get_record('insightjournal', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_login($course, false, $cm);
        require_capability('mod/insightjournal:submit', $context);
        if (!is_enrolled($context, $USER, 'mod/insightjournal:submit', true)) {
            throw new \required_capability_exception($context, 'mod/insightjournal:submit', 'nopermissions', '');
        }

        $now = time();
        $response = clean_param($params['response'], PARAM_TEXTAREA);
        $entry = $DB->get_record('insightjournal_entries', ['insightjournalid' => $diary->id, 'userid' => $USER->id]);
        if ($entry) {
            $entry->response = $response;
            $entry->responseformat = FORMAT_PLAIN;
            $entry->timemodified = $now;
            $DB->update_record('insightjournal_entries', $entry);
            $id = $entry->id;
        } else {
            $id = $DB->insert_record('insightjournal_entries', (object)[
                'insightjournalid' => $diary->id,
                'userid' => $USER->id,
                'response' => $response,
                'responseformat' => FORMAT_PLAIN,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        $completion = new \completion_info($course);
        if ($completion->is_enabled($cm)) {
            $completion->update_state($cm, COMPLETION_COMPLETE, $USER->id);
        }

        return ['success' => true, 'id' => $id, 'timemodified' => $now, 'timestr' => userdate($now, get_string('strftimedatetimeshort', 'langconfig'))];
    }

    public static function execute_returns(): \external_single_structure {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL, 'Whether the entry was saved'),
            'id' => new \external_value(PARAM_INT, 'Entry id'),
            'timemodified' => new \external_value(PARAM_INT, 'Unix timestamp'),
            'timestr' => new \external_value(PARAM_TEXT, 'Formatted timestamp'),
        ]);
    }
}
