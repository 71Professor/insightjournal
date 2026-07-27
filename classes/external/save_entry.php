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
use mod_insightjournal\local\entry_manager;

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
     * Validates the request and delegates the actual save to entry_manager,
     * the same service the no-JS form submit path (view.php) uses.
     *
     * @param int $cmid Course module id.
     * @param string $response Learner response HTML.
     * @param int $expectedrevision Revision the client last saw.
     * @param bool $private Whether to keep the entry private. Chosen solely by the
     *     author; trainers have no way to override it.
     * @return array Result with success/conflict flags, entry id, revision, timestamps, and rendered HTML.
     */
    public static function execute(int $cmid, string $response, int $expectedrevision, bool $private): array {
        global $DB, $USER;
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

        return entry_manager::save(
            $diary,
            $course,
            $cm,
            (int) $USER->id,
            $params['response'],
            $params['expectedrevision'],
            $params['private']
        );
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
