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
 * Course-wide report data provider for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_insightjournal\local;

/**
 * Resolves coursereport.php's authorization, paging, progress-counting, and
 * export-selection logic once, so the on-screen page and the CSV export
 * both call exactly the same core instead of duplicating the same
 * participant x activity loop with two different output shapes.
 */
final class coursereport_provider {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var array Activities (cm_info|stdClass), keyed by insightjournal instance id. */
    private array $activities;

    /** @var array Instance id => allowed group ids (int[]), or null if unrestricted for the current viewer. */
    private array $diaryallowedgroupids;

    /** @var int[]|null Group ids to restrict get_enrolled_users()/count_enrolled_users() to, or null for no restriction. */
    private ?array $restrictgroupids;

    /** @var bool Whether every visible activity is restricted but the viewer's own allowed groups are empty - no participant can ever match. */
    private bool $blockallparticipants;

    /** @var \context_course The course context. */
    private \context_course $coursecontext;

    /** @var string The core_user\fields select fragment for get_enrolled_users(). */
    private string $userfields;

    /**
     * Initialize the provider for a course and its visible insightjournal activities.
     *
     * @param \stdClass $course The course.
     * @param array $activities Visible insight journal activities (cm_info|stdClass), keyed by instance id.
     */
    public function __construct(\stdClass $course, array $activities) {
        $this->course = $course;
        $this->activities = $activities;
        $this->coursecontext = \context_course::instance($course->id);
        $this->diaryallowedgroupids = $this->resolve_allowed_groupids_by_diary();

        // Derived from $diaryallowedgroupids instead of a separate
        // resolution pass (R4-03 final-review follow-up): null as soon as
        // any activity is unrestricted (that activity alone means every
        // enrolled participant gets a potentially-visible cell, so no
        // SQL-level filter is safe), otherwise the union of every
        // restricted activity's already-resolved allowed groups.
        $groupids = [];
        $anyunrestricted = false;
        foreach ($this->diaryallowedgroupids as $allowed) {
            if ($allowed === null) {
                $anyunrestricted = true;
                break;
            }
            $groupids = array_merge($groupids, $allowed);
        }
        $this->restrictgroupids = $anyunrestricted ? null : array_values(array_unique($groupids));
        $this->blockallparticipants = $this->restrictgroupids !== null && empty($this->restrictgroupids);

        // Checked at course context, not per-activity like report_table.php -
        // deliberately coarse. A viewer reaching this provider already holds
        // the capability course-wide, so this can only ever be more
        // permissive than a hypothetical per-activity override, never less.
        $showemail = insightjournal_email_field_visible($this->coursecontext);
        $namefields = \core_user\fields::for_name()->including('id');
        if ($showemail) {
            $namefields->including('email');
        }
        // Only ->selects is used: for_name()/including('id'|'email') can
        // never add a custom profile field, so ->joins and ->params are
        // always empty here - revisit this assumption if a
        // with_identity()/custom-field include is ever added.
        $this->userfields = $namefields->get_sql('u', false, '', '', false)->selects;
    }

    /**
     * Total participants matching the SQL-level restriction, or 0 if no
     * participant can ever match.
     *
     * @return int
     */
    public function total_participants(): int {
        if ($this->blockallparticipants) {
            return 0;
        }

        return count_enrolled_users(
            $this->coursecontext,
            '',
            $this->restrictgroupids ?? 0
        );
    }

    /**
     * One bounded slice of enrolled participants (a screen page or a CSV
     * chunk), ordered by name.
     *
     * @param int $offset
     * @param int $limit
     * @return \stdClass[] Keyed by userid.
     */
    public function participants(int $offset, int $limit): array {
        if ($this->blockallparticipants) {
            return [];
        }

        return get_enrolled_users(
            $this->coursecontext,
            '',
            $this->restrictgroupids ?? 0,
            $this->userfields,
            'u.lastname,u.firstname,u.id',
            $offset,
            $limit
        );
    }

    /**
     * Fully resolved row data for exactly the given participants - never a
     * wider set. Both the CSV export and the on-screen page call this with
     * their own bounded participant slice, so authorization and membership
     * are always resolved per page/chunk, never course-wide (R4-03).
     *
     * @param \stdClass[] $participants From participants(), keyed by userid.
     * @return array<int, array{
     *     user: \stdClass,
     *     cells: array<int, array{visible: bool, entry: ?\stdClass, completed: bool, private: bool}>,
     *     done: int,
     *     visiblecount: int,
     * }> Keyed by userid. A cell with visible === false carries ONLY that key.
     */
    public function rows_for(array $participants): array {
        if (empty($participants)) {
            return [];
        }

        $userids = array_map('intval', array_keys($participants));
        $diaryids = array_keys($this->activities);
        $entries = insightjournal_entries_by_diary_and_user($diaryids, $userids);
        $diaryallowedusers = $this->resolve_diary_allowed_users($userids);

        $rows = [];
        foreach ($participants as $userid => $user) {
            $userid = (int) $userid;
            $cells = [];
            $done = 0;
            $visiblecount = 0;
            foreach ($this->activities as $diaryid => $cm) {
                $allowedusers = $diaryallowedusers[$diaryid];
                if ($allowedusers !== null && !isset($allowedusers[$userid])) {
                    $cells[$diaryid] = ['visible' => false];
                    continue;
                }
                $visiblecount++;
                $entry = $entries[$userid][$diaryid] ?? null;
                $state = insightjournal_coursereport_cell_state($entry);
                if ($state['completed']) {
                    $done++;
                }
                $cells[$diaryid] = [
                    'visible' => true,
                    'entry' => $entry,
                    'completed' => $state['completed'],
                    'private' => $state['private'],
                ];
            }
            $rows[$userid] = [
                'user' => $user,
                'cells' => $cells,
                'done' => $done,
                'visiblecount' => $visiblecount,
            ];
        }

        return $rows;
    }

    /**
     * Allowed group ids for the current viewer, keyed by insightjournal
     * instance id - resolved once per distinct groupingid, not once per
     * activity (two activities sharing a grouping always resolve to the
     * same allowed group ids for a given viewer).
     *
     * @return array Instance id => allowed group ids (int[]), or null for an
     *     activity that is not group-restricted for the current viewer.
     */
    private function resolve_allowed_groupids_by_diary(): array {
        $bygroupingid = [];
        $result = [];
        foreach ($this->activities as $diaryid => $cm) {
            $context = \context_module::instance($cm->id);
            if (!insightjournal_activity_group_restricted($context, $this->course, $cm)) {
                $result[$diaryid] = null;
                continue;
            }
            $groupingid = (int) $cm->groupingid;
            if (!array_key_exists($groupingid, $bygroupingid)) {
                $bygroupingid[$groupingid] = insightjournal_current_user_allowed_groupids($this->course, $cm);
            }
            $result[$diaryid] = $bygroupingid[$groupingid];
        }

        return $result;
    }

    /**
     * Per-diary "is this userid visible under this diary's group
     * restriction" lookup maps, scoped to exactly $userids. Deduplicates
     * the groups_members query across diaries that share the identical
     * (already-cached) allowed-group-ids array, instead of querying once
     * per restricted diary regardless of overlap (R4-03 final-review
     * follow-up) - two diaries sharing a groupingid always hold the exact
     * same cached array from resolve_allowed_groupids_by_diary(), so a
     * value-based key (not the groupingid itself, which isn't available
     * here) is sufficient and always consistent for genuine duplicates.
     *
     * @param int[] $userids The userids actually present in this page/chunk.
     * @return array Instance id => (userid => true) map, or null when the
     *     diary is unrestricted for the current viewer.
     */
    private function resolve_diary_allowed_users(array $userids): array {
        $bygroupidskey = [];
        $result = [];
        foreach ($this->diaryallowedgroupids as $diaryid => $groupids) {
            if ($groupids === null) {
                $result[$diaryid] = null;
                continue;
            }
            $key = implode(',', $groupids);
            if (!array_key_exists($key, $bygroupidskey)) {
                $bygroupidskey[$key] = array_fill_keys(
                    insightjournal_groupids_members_among($groupids, $userids),
                    true
                );
            }
            $result[$diaryid] = $bygroupidskey[$key];
        }

        return $result;
    }
}
