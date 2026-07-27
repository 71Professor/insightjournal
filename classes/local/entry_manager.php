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
 * Shared entry-saving service for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_insightjournal\local;

use core\lock\lock_config;

/**
 * Saves or updates a learner's insight journal entry.
 *
 * The only entry point into insightjournal_entries writes: both the AJAX
 * external function (classes/external/save_entry.php) and the no-JS form
 * submit path (view.php, via classes/form/entry_form.php) call save() here,
 * so the optimistic-concurrency check, the per-entry lock, and the
 * completion update only exist in one place.
 */
class entry_manager {
    /** @var string Lock type/namespace for save(), serialising the read-compare-write per entry. */
    private const LOCK_TYPE = 'mod_insightjournal_save_entry';

    /** @var int Seconds to wait for the per-entry lock before giving up. */
    private const LOCK_TIMEOUT_SECONDS = 3;

    /** @var int Seconds after which a factory lacking auto-release may reclaim a stale lock. */
    private const LOCK_MAXLIFETIME_SECONDS = 60;

    /**
     * Saves or updates the entry for the given user and updates completion.
     *
     * Uses expectedrevision as an optimistic-concurrency check: the write is only
     * applied if it matches the entry's current revision (0 meaning "no entry
     * yet"). This stops a delayed/reordered request, or a second tab that has not
     * seen a save made elsewhere, from silently overwriting newer stored text.
     * A mismatch is reported back as a conflict rather than thrown, since it is
     * an expected, recoverable outcome rather than a failure.
     *
     * The read-compare-write itself is serialised per insightjournalid+userid via
     * the Moodle Lock API, so two genuinely concurrent requests can no longer
     * both read the same revision and both write (a lost update): whichever
     * acquires the lock first wins, and the other sees the now-current revision
     * as a conflict.
     *
     * @param \stdClass $diary The insightjournal instance.
     * @param \stdClass $course The course the instance belongs to.
     * @param \stdClass $cm The course module.
     * @param int $userid The author's user id.
     * @param string $response Learner response HTML, not yet cleaned.
     * @param int $expectedrevision Revision the caller last saw.
     * @param bool $private Whether to keep the entry private. Chosen solely by the
     *     author; trainers have no way to override it.
     * @return array Result with success/conflict flags, entry id, revision, timestamps, and rendered HTML.
     */
    public static function save(
        \stdClass $diary,
        \stdClass $course,
        \stdClass $cm,
        int $userid,
        string $response,
        int $expectedrevision,
        bool $private
    ): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');

        $context = \context_module::instance($cm->id);
        $now = time();
        $response = clean_param($response, PARAM_CLEANHTML);
        $visiblelength = \core_text::strlen(insightjournal_html_to_text($response));
        if (!empty($diary->maxchars) && $visiblelength > (int) $diary->maxchars) {
            throw new \moodle_exception('maxcharserror', 'mod_insightjournal', '', (int) $diary->maxchars);
        }
        $visibility = $private ? INSIGHTJOURNAL_VISIBILITY_PRIVATE : INSIGHTJOURNAL_VISIBILITY_VISIBLE;

        $resource = 'insightjournalid:' . $diary->id . ':userid:' . $userid;
        $lockfactory = lock_config::get_lock_factory(self::LOCK_TYPE);
        $lock = $lockfactory->get_lock($resource, self::LOCK_TIMEOUT_SECONDS, self::LOCK_MAXLIFETIME_SECONDS);
        if (!$lock) {
            throw new \moodle_exception('savelockerror', 'mod_insightjournal');
        }
        try {
            $entry = $DB->get_record(
                'insightjournal_entries',
                ['insightjournalid' => $diary->id, 'userid' => $userid]
            );
            $currentrevision = $entry ? (int) $entry->revision : 0;

            if ($expectedrevision !== $currentrevision) {
                return self::build_conflict_response($entry, $context);
            }

            $newrevision = $currentrevision + 1;
            try {
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
                        'userid' => $userid,
                        'response' => $response,
                        'responseformat' => FORMAT_HTML,
                        'revision' => $newrevision,
                        'visibility' => $visibility,
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ]);
                }
            } catch (\dml_write_exception $e) {
                // A dml_write_exception here covers any failed write, not just
                // the unique-index violation this backstop targets, so log it:
                // this stays reachable only via a write to this table from outside
                // save() (see restore_insightjournal_stepslib.php, which inserts
                // entries during a course restore) landing between our locked
                // read and write, but an unrelated DB failure (deadlock,
                // connection loss) would hit the same catch and must not be
                // silently relabelled as an ordinary conflict without a trace.
                debugging(
                    'entry_manager::save: write failed inside the lock, reporting as a conflict: ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
                $entry = $DB->get_record(
                    'insightjournal_entries',
                    ['insightjournalid' => $diary->id, 'userid' => $userid]
                );
                return self::build_conflict_response($entry, $context);
            }
        } finally {
            $lock->release();
        }

        // Let core recalculate the state via custom_completion::get_state() so the
        // minchars rule is honoured and completion reverts when the response no
        // longer qualifies. Forcing COMPLETION_COMPLETE here would bypass minchars.
        // This does not touch insightjournal_entries, so it stays outside the lock.
        $completion = new \completion_info($course);
        if ($completion->is_enabled($cm)) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
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
            'private' => $private,
        ];
    }

    /**
     * Builds the conflict response shape, reporting the entry's actual stored
     * state so the caller can reconcile rather than blindly retry.
     *
     * @param \stdClass|false $entry The current stored entry, or false if none exists.
     * @param \context $context Module context, for rendering responsehtml.
     * @return array
     */
    private static function build_conflict_response($entry, \context $context): array {
        return [
            'success' => false,
            'conflict' => true,
            'id' => $entry->id ?? 0,
            'revision' => $entry ? (int) $entry->revision : 0,
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
}
