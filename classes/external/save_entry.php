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
            'response' => new external_value(PARAM_RAW, 'Learner response (HTML)'),
            'expectedrevision' => new external_value(
                PARAM_INT,
                'Revision the client last saw (0 if it believes no entry exists yet)'
            ),
            'private' => new external_value(
                PARAM_BOOL,
                'Whether to keep this entry private (visible to its author only, never to trainers)'
            ),
        ]);
    }

    /**
     * Saves or updates the entry for the current user and updates completion.
     *
     * Uses expectedrevision as an optimistic-concurrency check: the write is only
     * applied if it matches the entry's current revision (0 meaning "no entry
     * yet"). This stops a delayed/reordered request, or a second tab that has not
     * seen a save made elsewhere, from silently overwriting newer stored text.
     * A mismatch is reported back as a conflict rather than thrown, since it is
     * an expected, recoverable outcome rather than a failure.
     *
     * @param int $cmid Course module id.
     * @param string $response Learner response HTML.
     * @param int $expectedrevision Revision the client last saw.
     * @param bool $private Whether to keep the entry private. Chosen solely by the
     *     author; trainers have no way to override it.
     * @return array Result with success/conflict flags, entry id, revision, timestamps, and rendered HTML.
     */
    public static function execute(int $cmid, string $response, int $expectedrevision, bool $private): array {
        global $DB, $USER, $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/mod/insightjournal/lib.php');
        require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'response' => $response,
            'expectedrevision' => $expectedrevision,
            'private' => $private,
        ]);
        $cm = get_coursemodule_from_id('insightjournal', $params['cmid'], 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $diary = $DB->get_record('insightjournal', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_login($course, false, $cm);
        require_capability('mod/insightjournal:submit', $context);
        $now = time();
        $response = clean_param($params['response'], PARAM_CLEANHTML);
        $visiblelength = \core_text::strlen(insightjournal_html_to_text($response));
        if (!empty($diary->maxchars) && $visiblelength > (int) $diary->maxchars) {
            throw new \moodle_exception('maxcharserror', 'mod_insightjournal', '', (int) $diary->maxchars);
        }
        $entry = $DB->get_record('insightjournal_entries', ['insightjournalid' => $diary->id, 'userid' => $USER->id]);
        $currentrevision = $entry ? (int) $entry->revision : 0;
        $visibility = $params['private'] ? INSIGHTJOURNAL_VISIBILITY_PRIVATE : INSIGHTJOURNAL_VISIBILITY_VISIBLE;

        if ($params['expectedrevision'] !== $currentrevision) {
            return [
                'success' => false,
                'conflict' => true,
                'id' => $entry->id ?? 0,
                'revision' => $currentrevision,
                'timemodified' => $entry->timemodified ?? 0,
                'timestr' => $entry
                    ? userdate($entry->timemodified, get_string('strftimedatetimeshort', 'langconfig'))
                    : '',
                'responsehtml' => $entry
                    ? format_text($entry->response, $entry->responseformat, ['context' => $context])
                    : '',
                'private' => $entry ? !insightjournal_entry_visible_to_teacher($entry) : false,
            ];
        }

        $newrevision = $currentrevision + 1;
        if ($entry) {
            $entry->response = $response;
            $entry->responseformat = FORMAT_HTML;
            $entry->revision = $newrevision;
            $entry->visibility = $visibility;
            $entry->timemodified = $now;
            $DB->update_record('insightjournal_entries', $entry);
            $id = $entry->id;
        } else {
            $id = $DB->insert_record('insightjournal_entries', (object) [
                'insightjournalid' => $diary->id,
                'userid' => $USER->id,
                'response' => $response,
                'responseformat' => FORMAT_HTML,
                'revision' => $newrevision,
                'visibility' => $visibility,
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
        return [
            'success' => true,
            'conflict' => false,
            'id' => $id,
            'revision' => $newrevision,
            'timemodified' => $now,
            'timestr' => $timestr,
            'responsehtml' => format_text($response, FORMAT_HTML, ['context' => $context]),
            'private' => $params['private'],
        ];
    }

    /**
     * Describes the return value for the save_entry function.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the entry was saved'),
            'conflict' => new external_value(PARAM_BOOL, 'Whether the save was rejected due to a stale expectedrevision'),
            'id' => new external_value(PARAM_INT, 'Entry id (0 if no entry exists yet)'),
            'revision' => new external_value(PARAM_INT, 'The entry\'s current revision after this call'),
            'timemodified' => new external_value(PARAM_INT, 'Unix timestamp'),
            'timestr' => new external_value(PARAM_TEXT, 'Formatted timestamp'),
            'responsehtml' => new external_value(PARAM_RAW, 'The current response, cleaned and rendered for display'),
            'private' => new external_value(PARAM_BOOL, 'Whether the entry is currently private (visible to its author only)'),
        ]);
    }
}
