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
 * Test data generator for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @category   test
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Generator that creates insight journal activity instances for unit tests.
 */
class mod_insightjournal_generator extends testing_module_generator {
    /**
     * Creates an insight journal activity instance, filling required defaults.
     *
     * @param array|stdClass|null $record Instance fields to override.
     * @param array|null $options Generator options (course, etc.).
     * @return stdClass The created instance record.
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object) (array) $record;

        $defaults = [
            'prompttext' => 'Reflect on what you have learned today.',
            'promptformat' => FORMAT_HTML,
            'autosave' => 1,
            'minchars' => 0,
            'completionentries' => 1,
        ];
        foreach ($defaults as $field => $value) {
            if (!isset($record->$field)) {
                $record->$field = $value;
            }
        }

        return parent::create_instance((array) $record, $options);
    }

    /**
     * Creates an entry (learner response) for a given instance and user.
     *
     * @param stdClass $instance The insight journal instance.
     * @param int $userid The user the entry belongs to.
     * @param string $response The response text.
     * @return stdClass The created entry record.
     */
    public function create_entry(stdClass $instance, int $userid, string $response): stdClass {
        global $DB;

        $now = time();
        $entry = (object) [
            'insightjournalid' => $instance->id,
            'userid' => $userid,
            'response' => $response,
            'responseformat' => FORMAT_PLAIN,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $entry->id = $DB->insert_record('insightjournal_entries', $entry);

        return $entry;
    }
}
