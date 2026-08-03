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
            $iscreate = !$entry;
            if ($entry) {
                // An update only ever targets a row already selected by its own
                // primary key, so it can never race on the (insightjournalid,
                // userid) unique index the way a brand-new insert can - a write
                // failure here is a genuine DB error (deadlock, connection loss)
                // and must propagate, not be relabelled as an ordinary conflict.
                $entry->response = $response;
                $entry->responseformat = FORMAT_HTML;
                $entry->revision = $newrevision;
                $entry->visibility = $visibility;
                $entry->timemodified = $now;
                $DB->update_record('insightjournal_entries', $entry);
                $id = $entry->id;
            } else {
                $newentry = (object) [
                    'insightjournalid' => $diary->id,
                    'userid' => $userid,
                    'response' => $response,
                    'responseformat' => FORMAT_HTML,
                    'revision' => $newrevision,
                    'visibility' => $visibility,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ];
                $conflict = self::insert_or_detect_race($newentry, $diary->id, $userid, $context);
                if ($conflict !== null) {
                    return $conflict;
                }
                $entry = $newentry;
                $id = $entry->id;
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

        // Fired after both the lock release and the completion update above,
        // matching Moodle core's own mod_choice submission ordering (completion
        // settled first, then the event) - trigger() runs registered observers
        // synchronously and could throw, so anything it might disrupt should
        // already be settled first. $entry now holds the complete, post-write row
        // in both branches (built once above, reused here - not rebuilt).
        if ($iscreate) {
            \mod_insightjournal\event\entry_created::create_from_entry($entry, $diary, $cm, $course)->trigger();
        } else {
            \mod_insightjournal\event\entry_updated::create_from_entry($entry, $diary, $cm, $course)->trigger();
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
     * Inserts a brand-new entry row, treating a unique-index hit as a
     * confirmed race with a writer outside save()'s lock (the only one able
     * to reach this table without it is a course restore, see
     * restore_insightjournal_stepslib.php) rather than an ordinary write
     * failure. A genuinely unrelated failure (deadlock, connection loss)
     * raises the same exception type but leaves no row behind, so the two
     * are told apart by re-querying, not assumed from the exception type
     * alone - only a confirmed race is reported as a conflict; anything else
     * is rethrown.
     *
     * @param \stdClass $entry New entry to insert; receives its id on success.
     * @param int $diaryid
     * @param int $userid
     * @param \context $context Module context, for rendering responsehtml on conflict.
     * @return array|null Conflict response if a race was confirmed, otherwise null (entry->id is set).
     */
    private static function insert_or_detect_race(\stdClass $entry, int $diaryid, int $userid, \context $context): ?array {
        global $DB;
        try {
            $entry->id = $DB->insert_record('insightjournal_entries', $entry);
        } catch (\dml_write_exception $e) {
            $existing = $DB->get_record(
                'insightjournal_entries',
                ['insightjournalid' => $diaryid, 'userid' => $userid]
            );
            if (!$existing) {
                throw $e;
            }
            debugging(
                'entry_manager::save: insert raced with an external writer, reporting as a conflict: '
                    . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            return self::build_conflict_response($existing, $context);
        }
        return null;
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
