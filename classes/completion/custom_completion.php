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
 * Custom completion rules for mod_insightjournal (Moodle 4.3+ API).
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_insightjournal\completion;

use core_completion\activity_custom_completion;

/**
 * Defines the custom completion rules for the insight journal activity.
 */
class custom_completion extends activity_custom_completion {
    /**
     * Returns the completion state for the given rule.
     *
     * @param string $rule The completion rule name.
     * @return int COMPLETION_COMPLETE or COMPLETION_INCOMPLETE.
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);

        $diary = $DB->get_record(
            'insightjournal',
            ['id' => $this->cm->instance],
            'id,minchars,completionentries',
            MUST_EXIST
        );

        if (empty($diary->completionentries)) {
            return COMPLETION_INCOMPLETE;
        }

        $entry = $DB->get_record(
            'insightjournal_entries',
            ['insightjournalid' => $diary->id, 'userid' => $this->userid],
            'response'
        );

        if (!$entry || trim((string)$entry->response) === '') {
            return COMPLETION_INCOMPLETE;
        }

        $meetsminchars = \core_text::strlen(trim($entry->response)) >= (int)$diary->minchars;
        return $meetsminchars ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Returns the names of all custom completion rules this activity defines.
     *
     * @return string[]
     */
    public static function get_defined_custom_rules(): array {
        return ['completionentries'];
    }

    /**
     * Returns human-readable descriptions of the custom completion rules.
     *
     * @return array Associative array of rule name => description string.
     */
    public function get_custom_rule_descriptions(): array {
        return [
            'completionentries' => get_string('completionentries', 'insightjournal'),
        ];
    }

    /**
     * Returns the sort order for the custom completion rules.
     *
     * @return string[]
     */
    public function get_sort_order(): array {
        return ['completionentries'];
    }
}
