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
 * Insight journal steps definitions.
 *
 * @package    mod_insightjournal
 * @category   test
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Steps definitions for mod_insightjournal.
 */
class behat_mod_insightjournal extends behat_base {
    /**
     * Directly updates a stored insight journal entry's revision and response
     * in the database, bypassing save_entry entirely, to simulate a save made
     * from another tab/session/device that the currently open browser session
     * has not seen, without touching the currently loaded page's own state.
     *
     * @Given /^insight journal entry for "(?P<user_string>(?:[^"]|\\")*)" in "(?P<activity_string>(?:[^"]|\\")*)" was saved elsewhere as "(?P<response_string>(?:[^"]|\\")*)"$/
     * @param string $username
     * @param string $activityname
     * @param string $response
     */
    public function the_insight_journal_entry_is_saved_elsewhere($username, $activityname, $response) {
        global $DB;

        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $instance = $DB->get_record('insightjournal', ['name' => $activityname], '*', MUST_EXIST);

        $entry = $DB->get_record('insightjournal_entries', [
            'insightjournalid' => $instance->id,
            'userid' => $user->id,
        ]);
        $now = time();
        if ($entry) {
            $entry->response = $response;
            $entry->responseformat = FORMAT_HTML;
            $entry->revision = (int) $entry->revision + 1;
            $entry->timemodified = $now;
            $DB->update_record('insightjournal_entries', $entry);
        } else {
            $DB->insert_record('insightjournal_entries', (object) [
                'insightjournalid' => $instance->id,
                'userid' => $user->id,
                'response' => $response,
                'responseformat' => FORMAT_HTML,
                'revision' => 1,
                'visibility' => 1,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
    }
}
