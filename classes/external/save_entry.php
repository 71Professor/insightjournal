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
 * External API: save_entry for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_insightjournal\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;

/**
 * External function to save or update a learner's insight journal entry.
 */
class save_entry extends external_api {
    /**
     * Describes the parameters for the save_entry function.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'response' => new external_value(PARAM_TEXT, 'Learner response'),
        ]);
    }

    /**
     * Saves or updates the entry for the current user and updates completion.
     *
     * @param int $cmid Course module id.
     * @param string $response Learner response text.
     * @return array Result with success flag, entry id and timestamps.
     */
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
        $now = time();
        $response = clean_param($params['response'], PARAM_TEXT);
        if (!empty($diary->maxchars) && \core_text::strlen($response) > (int)$diary->maxchars) {
            throw new \moodle_exception('maxcharserror', 'mod_insightjournal', '', (int)$diary->maxchars);
        }
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

        // Let core recalculate the state via custom_completion::get_state() so the
        // minchars rule is honoured and completion reverts when the response no
        // longer qualifies. Forcing COMPLETION_COMPLETE here would bypass minchars.
        $completion = new \completion_info($course);
        if ($completion->is_enabled($cm)) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, $USER->id);
        }

        $timestr = userdate($now, get_string('strftimedatetimeshort', 'langconfig'));
        return ['success' => true, 'id' => $id, 'timemodified' => $now, 'timestr' => $timestr];
    }

    /**
     * Describes the return value for the save_entry function.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the entry was saved'),
            'id' => new external_value(PARAM_INT, 'Entry id'),
            'timemodified' => new external_value(PARAM_INT, 'Unix timestamp'),
            'timestr' => new external_value(PARAM_TEXT, 'Formatted timestamp'),
        ]);
    }
}
