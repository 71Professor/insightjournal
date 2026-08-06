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
     * @Given /^insight journal entry for "((?:[^"]|\\")*)" in "((?:[^"]|\\")*)" was saved elsewhere as "((?:[^"]|\\")*)"$/
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

    /**
     * Navigates directly to the activity report page for a named insight
     * journal activity, with a specific page size, so pagination is
     * reachable in tests without needing dozens of real participants.
     *
     * @Given /^I am on the report page for "((?:[^"]|\\")*)" with "(\d+)" per page$/
     * @param string $activityname
     * @param string $perpage
     */
    public function i_am_on_the_report_page_with_perpage($activityname, $perpage) {
        global $DB;

        $instance = $DB->get_record('insightjournal', ['name' => $activityname], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('insightjournal', $instance->id, 0, false, MUST_EXIST);

        $this->execute('behat_general::i_visit', [
            '/mod/insightjournal/report.php?id=' . $cm->id . '&perpage=' . $perpage,
        ]);
    }

    /**
     * Navigates directly to the course-wide insight journal report for a
     * named course, with a specific page size, so participant pagination is
     * reachable in tests without needing dozens of real enrolments.
     *
     * @Given /^I am on the course insight report for "((?:[^"]|\\")*)" with "(\d+)" per page$/
     * @param string $coursename
     * @param string $perpage
     */
    public function i_am_on_the_course_report_page_with_perpage($coursename, $perpage) {
        global $DB;

        $course = $DB->get_record('course', ['fullname' => $coursename], '*', MUST_EXIST);

        $this->execute('behat_general::i_visit', [
            '/mod/insightjournal/coursereport.php?courseid=' . $course->id . '&perpage=' . $perpage,
        ]);
    }

    /**
     * Navigates directly to a named user's insight journal summary in a
     * named course, so group-restriction denial (or success) is reachable
     * without clicking through report/course-report row links.
     *
     * @Given /^I am on the insight journal summary for "((?:[^"]|\\")*)" in "((?:[^"]|\\")*)"$/
     * @param string $username
     * @param string $coursename
     */
    public function i_am_on_the_summary_page_for($username, $coursename) {
        global $DB;

        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['fullname' => $coursename], '*', MUST_EXIST);

        $this->execute('behat_general::i_visit', [
            '/mod/insightjournal/summary.php?courseid=' . $course->id . '&userid=' . $user->id,
        ]);
    }

    /**
     * Compares the JavaScript module's visibleCharCount() against the
     * PHP/JS shared fixture table (tests/fixtures/visible_char_fixtures.json),
     * row by row, evaluated in the real browser driving this scenario -
     * proves PHP/JS parity instead of only asserting it in a comment
     * (R4-01).
     *
     * @Then /^the visible character count matches the shared fixtures$/
     */
    public function the_visible_character_count_matches_the_shared_fixtures() {
        $fixtures = json_decode(
            file_get_contents(__DIR__ . '/../fixtures/visible_char_fixtures.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $session = $this->getSession();
        foreach ($fixtures as $fixture) {
            $encodedhtml = json_encode($fixture['html'], JSON_THROW_ON_ERROR);
            $session->executeScript(<<<JS
                window.__ijFixtureResult = undefined;
                require(['mod_insightjournal/autosave'], function(autosave) {
                    window.__ijFixtureResult = autosave.visibleCharCount({$encodedhtml});
                });
                JS
            );
            $session->wait(self::get_timeout() * 1000, 'window.__ijFixtureResult !== undefined');
            $actual = $session->evaluateScript('return window.__ijFixtureResult;');

            if ((int) $actual !== (int) $fixture['expected']) {
                throw new \Behat\Mink\Exception\ExpectationException(
                    sprintf(
                        'Fixture "%s" (html: %s): expected %d, got %s',
                        $fixture['id'],
                        $fixture['html'],
                        $fixture['expected'],
                        var_export($actual, true)
                    ),
                    $session
                );
            }
        }
    }

    /**
     * Evaluates the JavaScript module's wordCount() against a raw HTML
     * string in the real browser driving this scenario - proves the
     * <br>/paragraph block-boundary handling (deliberately different from
     * visibleCharCount()'s PHP-parity trade-off, see the comment on
     * htmlToSpacedText() in amd/src/autosave.js) against the actual
     * DOM/text-extraction behavior, not just asserted in a comment.
     *
     * @Then /^the word count for "((?:[^"]|\\")*)" should be "(\d+)"$/
     * @param string $html
     * @param string $expectedcount
     */
    public function the_word_count_for_should_be($html, $expectedcount) {
        $session = $this->getSession();
        $encodedhtml = json_encode($html, JSON_THROW_ON_ERROR);
        $session->executeScript(<<<JS
            window.__ijWordCountResult = undefined;
            require(['mod_insightjournal/autosave'], function(autosave) {
                window.__ijWordCountResult = autosave.wordCount({$encodedhtml});
            });
            JS
        );
        $session->wait(self::get_timeout() * 1000, 'window.__ijWordCountResult !== undefined');
        $actual = $session->evaluateScript('return window.__ijWordCountResult;');

        if ((int) $actual !== (int) $expectedcount) {
            throw new \Behat\Mink\Exception\ExpectationException(
                sprintf(
                    'Word count for %s: expected %s, got %s',
                    $html,
                    $expectedcount,
                    var_export($actual, true)
                ),
                $session
            );
        }
    }
}
