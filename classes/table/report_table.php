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
 * Paginated activity report table for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_insightjournal\table;

use context_module;
use html_writer;
use moodle_url;
use stdClass;
use table_sql;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/tablelib.php');

/**
 * Paginated, searchable table of every participant's response to one
 * activity. Renders three columns on screen (participant, response, last
 * modified) and nine columns when downloaded as CSV, matching the plugin's
 * long-standing CSV export format exactly. An entry the participant chose
 * to keep private (see insightjournal_entry_visible_to_teacher()) blanks
 * the response/timemodified cells in both modes. Sorting is intentionally
 * not offered: row order is always lastname, then firstname.
 */
class report_table extends table_sql {
    /** @var stdClass The course the activity belongs to. */
    protected stdClass $course;

    /** @var stdClass The activity's course-module record. */
    protected stdClass $cm;

    /** @var stdClass The insight journal instance. */
    protected stdClass $diary;

    /** @var context_module The activity's module context. */
    protected context_module $context;

    /** @var bool Whether the current user may see participant email addresses. */
    protected bool $showemail;

    /**
     * Builds the table for one activity's entries, optionally filtered by a search term.
     *
     * @param string $uniqueid Unique id for this table instance.
     * @param stdClass $course The course the activity belongs to.
     * @param stdClass $cm The activity's course-module record.
     * @param stdClass $diary The insight journal instance.
     * @param context_module $context The activity's module context.
     * @param string $search Optional search term across participant name, and
     *     email too when the viewer is permitted to see it.
     * @param ?array $restrictgroupids When not null, only entries from
     *     members of these group ids are included (an empty array means
     *     "match nobody," not "no restriction") - used to enforce Moodle's
     *     Separate Groups mode.
     */
    public function __construct(
        string $uniqueid,
        stdClass $course,
        stdClass $cm,
        stdClass $diary,
        context_module $context,
        string $search = '',
        ?array $restrictgroupids = null
    ) {
        parent::__construct($uniqueid);

        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/insightjournal/locallib.php');

        $this->course = $course;
        $this->cm = $cm;
        $this->diary = $diary;
        $this->context = $context;
        $this->showemail = insightjournal_email_field_visible($context);

        $userfields = \core_user\fields::for_name();
        if ($this->showemail) {
            $userfields->including('email');
        }
        // The for_name()/including('email') call can never add a custom profile
        // field, so ->joins and ->params are always empty here - revisit this
        // assumption if a with_identity()/custom-field include is ever added.
        $userfieldsql = $userfields->get_sql('u');

        $params = ['diaryid' => $diary->id];
        $where = 'e.insightjournalid = :diaryid';
        if ($search !== '') {
            $needle = '%' . $DB->sql_like_escape($search) . '%';
            $clauses = [
                $DB->sql_like('u.firstname', ':sfn', false),
                $DB->sql_like('u.lastname', ':sln', false),
            ];
            $params['sfn'] = $needle;
            $params['sln'] = $needle;
            if ($this->showemail) {
                $clauses[] = $DB->sql_like('u.email', ':sem', false);
                $params['sem'] = $needle;
            }
            $where .= ' AND (' . implode(' OR ', $clauses) . ')';
        }
        if ($restrictgroupids !== null) {
            [$ginsql, $gparams] = $DB->get_in_or_equal($restrictgroupids, SQL_PARAMS_NAMED, 'grp', true, -1);
            $where .= ' AND EXISTS (
                SELECT 1 FROM {groups_members} gm
                 WHERE gm.userid = u.id AND gm.groupid ' . $ginsql . '
            )';
            $params = array_merge($params, $gparams);
        }

        $this->set_sql(
            'e.*' . $userfieldsql->selects,
            '{insightjournal_entries} e JOIN {user} u ON u.id = e.userid',
            $where,
            $params
        );

        $this->sortable(false);
        $this->collapsible(false);
    }

    /**
     * Fixed participant order: sorting is not offered, on screen or in CSV.
     *
     * @return string SQL ORDER BY fragment.
     */
    public function get_sql_sort() {
        return 'u.lastname, u.firstname';
    }

    /**
     * Defines the on-screen (3-column) or CSV (9-column, legacy-compatible)
     * column set, depending on the current download mode. Must be called
     * after is_downloading() and before out().
     */
    public function setup_columns(): void {
        if ($this->is_downloading()) {
            $this->define_columns([
                'courseid', 'coursename', 'cmid', 'activityname', 'userid',
                'participantname', 'email', 'response', 'timemodified',
            ]);
            $this->define_headers([
                'courseid', 'coursename', 'cmid', 'activityname', 'userid',
                'fullname', 'email', 'response', 'timemodified',
            ]);
        } else {
            $this->define_columns(['participant', 'response', 'timemodified']);
            $this->define_headers([
                get_string('participant', 'mod_insightjournal'),
                get_string('response', 'mod_insightjournal'),
                get_string('timemodified', 'mod_insightjournal'),
            ]);
        }
    }

    /**
     * On-screen participant cell: name linked to their summary page, with
     * their email shown underneath when the viewer is permitted to see it.
     *
     * @param stdClass $row The current row.
     * @return string HTML for the cell.
     */
    public function col_participant(stdClass $row): string {
        $summaryurl = new moodle_url('/mod/insightjournal/summary.php', [
            'courseid' => $this->course->id,
            'userid' => $row->userid,
            'returnurl' => (new moodle_url(
                '/mod/insightjournal/report.php',
                ['id' => $this->cm->id]
            ))->out_as_local_url(false),
        ]);

        $html = html_writer::link($summaryurl, fullname($row));
        if ($this->showemail) {
            $html .= html_writer::div(s($row->email), 'small text-muted');
        }
        return $html;
    }

    /**
     * CSV-only course id column: constant for every row.
     *
     * @param stdClass $row The current row (unused; constant for every row).
     * @return int
     */
    public function col_courseid(stdClass $row): int {
        return $this->course->id;
    }

    /**
     * CSV-only course name column: constant for every row.
     *
     * @param stdClass $row The current row (unused; constant for every row).
     * @return string
     */
    public function col_coursename(stdClass $row): string {
        return $this->course->fullname;
    }

    /**
     * CSV-only course-module id column: constant for every row.
     *
     * @param stdClass $row The current row (unused; constant for every row).
     * @return int
     */
    public function col_cmid(stdClass $row): int {
        return $this->cm->id;
    }

    /**
     * CSV-only activity name column: constant for every row.
     *
     * @param stdClass $row The current row (unused; constant for every row).
     * @return string
     */
    public function col_activityname(stdClass $row): string {
        return $this->diary->name;
    }

    /**
     * CSV-only plain-text participant name column (no link, unlike the
     * on-screen 'participant' column).
     *
     * @param stdClass $row The current row.
     * @return string
     */
    public function col_participantname(stdClass $row): string {
        return fullname($row);
    }

    /**
     * CSV-only participant email column.
     *
     * @param stdClass $row The current row.
     * @return string
     */
    public function col_email(stdClass $row): string {
        return $row->email ?? '';
    }

    /**
     * Response cell, shared between screen and CSV: blanked with a privacy
     * notice when the entry's author chose to keep it private.
     *
     * @param stdClass $row The current row.
     * @return string
     */
    public function col_response(stdClass $row): string {
        if (!insightjournal_entry_visible_to_teacher($row)) {
            $notice = get_string('entriesprivatenotice', 'mod_insightjournal');
            return $this->is_downloading()
                ? $notice
                : html_writer::span($notice, 'text-muted font-italic');
        }

        if ($this->is_downloading()) {
            return insightjournal_html_to_text($row->response);
        }

        return html_writer::div(
            format_text($row->response, $row->responseformat, ['context' => $this->context]),
            'insightjournal-response-text'
        );
    }

    /**
     * Last-modified cell, shared between screen and CSV: blank when the
     * entry is private, otherwise a localised (screen) or plain (CSV) date.
     *
     * @param stdClass $row The current row.
     * @return string
     */
    public function col_timemodified(stdClass $row): string {
        if (!insightjournal_entry_visible_to_teacher($row)) {
            return '';
        }

        if ($this->is_downloading()) {
            return userdate($row->timemodified);
        }

        return userdate($row->timemodified, get_string('strftimedatetimeshort', 'langconfig'));
    }
}
