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
 * Local helpers for mod_insightjournal.
 *
 * @package    mod_insightjournal
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Convert stored response HTML to its visible plain-text form, for display
 * (CSV export, table cells) and for deciding whether a response is
 * meaningfully empty. An empty rich-text editor serialises to markup like
 * "<p></p>" or "<p><br></p>", not "", so a raw trim()/strlen() check on
 * stored HTML is unreliable.
 *
 * Not used for minchars/maxchars length checks - see
 * insightjournal_visible_char_count() for that, which deliberately counts
 * differently (no inserted paragraph/list formatting) to match the
 * client-side counter.
 *
 * Moodle's html_to_text() upper-cases the visible content of <b>, <strong>,
 * <h1>-<h6> and <th> elements to convey emphasis in its plain-text output.
 * That case transform is undesirable for an emptiness check, so those tags
 * are unwrapped (keeping their inner text as-is) before delegating to
 * html_to_text().
 *
 * @param string $html Stored response HTML (or plain text).
 * @return string Trimmed visible text, with all markup stripped.
 */
function insightjournal_html_to_text(string $html): string {
    $html = preg_replace('#</?(?:b|strong|h[1-6]|th)(?:\s[^>]*)?>#i', '', $html);

    return trim(html_to_text($html, 0, false));
}

/**
 * Decides whether extracted text is visually empty: nothing but ASCII
 * whitespace, a non-breaking space, another Unicode space/line-separator
 * character, or a zero-width character remains after stripping. Used to
 * give insightjournal_visible_char_count() an empty result (0) for input
 * that would show as a blank field to a learner, even though
 * DOMDocument's textContent is technically a non-empty string for e.g. a
 * lone NBSP or ideographic space.
 *
 * Deliberately narrow: only decides the ALL-invisible boundary case.
 * Interior whitespace/NBSP/zero-width characters next to at least one
 * other, non-invisible character still count normally in
 * insightjournal_visible_char_count() - this function is not a general
 * "strip invisible characters" transform.
 *
 * @param string $text Already-extracted plain text (e.g. DOMDocument::$textContent).
 * @return bool
 */
function insightjournal_is_visually_empty(string $text): bool {
    // Mirrors every codepoint a browser's native String.trim() strips
    // (ECMAScript WhiteSpace + LineTerminator productions: NBSP, the other
    // Unicode space separators like em space/ideographic space, U+2028/2029,
    // and the BOM/ZWNBSP) plus the zero-width joiners trim() does NOT strip
    // (ZWSP/ZWNJ/ZWJ, the word joiner). PHP's own trim() strips none of
    // this - only ASCII whitespace - so it all has to be listed explicitly
    // here; the final trim() then catches any ordinary leading/trailing
    // ASCII whitespace around what's left.
    $stripped = preg_replace(
        '/[\x{00A0}\x{1680}\x{2000}-\x{200D}\x{2028}\x{2029}\x{202F}\x{205F}\x{2060}\x{3000}\x{FEFF}]/u',
        '',
        $text
    );

    return trim($stripped) === '';
}

/**
 * Counts "visible characters" the same way the client-side counter does
 * (amd/src/autosave.js's stripHtml()/charCount(), i.e. a browser
 * DOMParser's textContent): DOM text-node concatenation, with no separators
 * inserted between block-level elements or list items. Used everywhere a
 * length is compared against minchars/maxchars, so the server enforces
 * exactly what the learner's live counter showed.
 *
 * Deliberately distinct from insightjournal_html_to_text(), which inserts
 * paragraph/list formatting (blank lines, "* " bullets) for plain-text
 * readability - that formatting made server-side length checks count
 * higher than the client's live counter for multi-paragraph or list
 * content, most learners' most natural way of writing a longer reflection.
 *
 * Returns 0 for input that is visually empty per
 * insightjournal_is_visually_empty() (e.g. only whitespace, only NBSP,
 * only zero-width characters), even though DOMDocument's textContent is
 * technically non-empty for some of those - see R4-01. Any input with at
 * least one other character keeps its raw length, including surrounding
 * whitespace/NBSP.
 *
 * @param string $html Response HTML (or plain text).
 * @return int
 */
function insightjournal_visible_char_count(string $html): int {
    if (trim($html) === '') {
        return 0;
    }
    $doc = new DOMDocument();
    $previoushandling = libxml_use_internal_errors(true);
    // A meta charset tag, not an XML declaration, is what reliably forces
    // loadHTML() to treat $html as UTF-8: the XML-declaration form stopped
    // working once libxml2 >= 2.14.0 (each UTF-8 continuation byte is then
    // read as its own character) - the same fix Moodle core applied in
    // lib/classes/formatting.php after hitting this exact regression.
    // LIBXML_NONET blocks any network access libxml2 might otherwise
    // attempt while resolving external entities/DTDs during parsing.
    $doc->loadHTML(
        '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><div>' . $html . '</div>',
        LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previoushandling);

    $text = $doc->textContent;
    if (insightjournal_is_visually_empty($text)) {
        return 0;
    }

    return core_text::strlen($text);
}

/**
 * Build an inline CSS style for the task/question box's background colour.
 *
 * Also sets a matching text colour (black or white, whichever contrasts
 * better) so the prompt stays readable regardless of which background
 * colour a trainer picks - a trainer choosing a dark colour would
 * otherwise pair it with the theme's default dark text.
 *
 * @param string|null $hexcolor Hex colour code (e.g. "#ffcc00" or "abc"), or empty/null for none.
 * @return string Inline style attribute value, or '' if no valid colour is set.
 */
function insightjournal_prompt_style(?string $hexcolor): string {
    $hexcolor = trim((string) $hexcolor);
    if ($hexcolor === '' || !preg_match('/^#?[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/', $hexcolor)) {
        return '';
    }
    if ($hexcolor[0] !== '#') {
        $hexcolor = '#' . $hexcolor;
    }
    $textcolor = insightjournal_contrasting_text_color($hexcolor);

    return "background-color: {$hexcolor}; color: {$textcolor}; padding: 0.75rem 1rem; border-radius: 0.25rem;";
}

/**
 * Picks black or white text, whichever gives the higher WCAG 2.x contrast
 * ratio against the given background colour.
 *
 * @param string $hexcolor Background colour, as "#rrggbb" or "#rgb" (leading "#" required).
 * @return string "#000000" or "#ffffff".
 */
function insightjournal_contrasting_text_color(string $hexcolor): string {
    $hex = ltrim($hexcolor, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }

    $channel = static function (int $value): float {
        $value /= 255;
        return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    };
    $luminance = 0.2126 * $channel(hexdec(substr($hex, 0, 2)))
        + 0.7152 * $channel(hexdec(substr($hex, 2, 2)))
        + 0.0722 * $channel(hexdec(substr($hex, 4, 2)));

    // WCAG contrast ratio of white/black text against this background; pick whichever wins.
    $contrastwithwhite = 1.05 / ($luminance + 0.05);
    $contrastwithblack = ($luminance + 0.05) / 0.05;

    return $contrastwithwhite >= $contrastwithblack ? '#ffffff' : '#000000';
}

/**
 * Whether trainers/teachers may currently see this specific insight journal entry.
 *
 * Controlled per entry by the learner who authored it, via the visibility
 * field:
 * - INSIGHTJOURNAL_VISIBILITY_PRIVATE: the entry stays visible to the
 *   authoring learner only.
 * - INSIGHTJOURNAL_VISIBILITY_VISIBLE (default): trainers/teachers with the
 *   mod/insightjournal:viewall capability may see the entry.
 *
 * Trainers cannot override this: unlike the retired per-activity setting,
 * there is no trainer-facing control for it at all. When an entry is
 * private, the report, course report, and summary pages remain reachable to
 * anyone with the mod/insightjournal:viewall capability, but show a notice
 * instead of that entry's content. This fails closed: any
 * unexpected/legacy/missing value (e.g. a pre-migration sentinel of 0) is
 * treated as not visible, so an ambiguous value never exposes an entry the
 * author may have intended as private.
 *
 * @param stdClass $entry The entry record (needs visibility).
 * @return bool
 */
function insightjournal_entry_visible_to_teacher(stdClass $entry): bool {
    $visibility = (int) ($entry->visibility ?? 0);

    return $visibility === INSIGHTJOURNAL_VISIBILITY_VISIBLE;
}

/**
 * Whether the current user is restricted to their own group's members for
 * this activity, per Moodle's Separate Groups mode.
 *
 * NOGROUPS and VISIBLEGROUPS never restrict - only SEPARATEGROUPS does, and
 * only for a user without moodle/site:accessallgroups in this context.
 * groups_get_activity_groupmode() already resolves any course-forced group
 * mode, so a course-wide forced Separate Groups setting is respected even
 * if this specific activity's own groupmode field was never touched.
 *
 * @param context_module $context The activity's module context.
 * @param stdClass $course The course the activity belongs to.
 * @param cm_info|stdClass $cm The activity's course-module record.
 * @return bool
 */
function insightjournal_activity_group_restricted(context_module $context, stdClass $course, cm_info|stdClass $cm): bool {
    if ((int) groups_get_activity_groupmode($cm, $course) !== SEPARATEGROUPS) {
        return false;
    }

    return !has_capability('moodle/site:accessallgroups', $context);
}

/**
 * Group ids the current user belongs to for a specific activity, per
 * Moodle's Separate Groups rules.
 *
 * Scoped to $cm: only groups belonging to $cm->groupingid (0 means every
 * grouping, i.e. no restriction to a specific grouping) and flagged as
 * participation-eligible are considered. Never materialises member lists:
 * groups_get_all_groups() is called with $withmembers = false and
 * $fields = 'g.id', so the underlying query only ever fetches group ids,
 * regardless of how large any group is.
 *
 * @param stdClass $course The course to look up group membership in.
 * @param cm_info|stdClass $cm The activity to scope the search to.
 * @return int[] Group ids.
 */
function insightjournal_current_user_allowed_groupids(stdClass $course, cm_info|stdClass $cm): array {
    global $USER;

    // Moodle's groups_get_all_groups() only applies its userid filter when
    // $userid is non-empty ("if (!empty($userid))" in core) - a falsy id
    // (e.g. 0, the logged-out/guest sentinel) would silently return every
    // group's ids course-wide instead of "this user's groups." Every real
    // caller has already gone through require_login() by the time this
    // runs, so $USER->id is never actually 0 in practice - guard anyway,
    // since this is a security-relevant primitive, not just an internal
    // convenience function.
    if (empty($USER->id)) {
        return [];
    }

    $groups = groups_get_all_groups($course->id, $USER->id, (int) $cm->groupingid, 'g.id', false, true);
    return array_map('intval', array_keys($groups));
}

/**
 * Whether $userid is a member of any group in $groupids.
 *
 * A single existence query against groups_members, bounded by
 * count($groupids) - never by any group's member count. Intended for a
 * single-target-user check (e.g. summary.php's "is this one learner
 * visible to me") instead of materialising every allowed group's full
 * membership just to run in_array() over it.
 *
 * @param int[] $groupids Candidate group ids.
 * @param int $userid The user to check.
 * @return bool
 */
function insightjournal_groupids_contain_member(array $groupids, int $userid): bool {
    global $DB;

    if (empty($groupids) || empty($userid)) {
        return false;
    }

    [$insql, $params] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'grp');
    $params['userid'] = $userid;
    return $DB->record_exists_select('groups_members', "userid = :userid AND groupid $insql", $params);
}

/**
 * The subset of $userids that are members of any group in $groupids.
 *
 * A single groups_members query bounded by count($groupids) x
 * count($userids) - never by full course or group size. Intended for a
 * caller that already has a small, page/chunk-bounded candidate userid
 * list (e.g. coursereport.php's current screen page or CSV chunk), never
 * the whole course's participants at once.
 *
 * @param int[] $groupids Candidate group ids.
 * @param int[] $userids Candidate user ids to filter down.
 * @return int[] The subset of $userids found in any of $groupids.
 */
function insightjournal_groupids_members_among(array $groupids, array $userids): array {
    global $DB;

    if (empty($groupids) || empty($userids)) {
        return [];
    }

    [$ginsql, $gparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'grp');
    [$uinsql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
    $ids = $DB->get_fieldset_select(
        'groups_members',
        'DISTINCT userid',
        "groupid $ginsql AND userid $uinsql",
        array_merge($gparams, $uparams)
    );
    return array_map('intval', $ids);
}

/**
 * Whether $targetuserid is visible to the current user under this
 * specific activity's own Separate Groups restriction.
 *
 * Always true when the activity isn't group-restricted for the current
 * user (see insightjournal_activity_group_restricted()). When it is,
 * true only if $targetuserid is a member of one of the groups this
 * activity's own grouping allows the current user to see - scoped to
 * *this* activity, never a different one in the same course (R3-02: the
 * course-wide equivalent of this check let a viewer's group membership
 * relevant to one activity's grouping leak visibility into a different
 * activity's grouping).
 *
 * @param context_module $context The activity's module context.
 * @param stdClass $course The course the activity belongs to.
 * @param cm_info|stdClass $cm The activity's course-module record.
 * @param int $targetuserid The user whose visibility is being checked.
 * @return bool
 */
function insightjournal_activity_visible_to_viewer(
    context_module $context,
    stdClass $course,
    cm_info|stdClass $cm,
    int $targetuserid
): bool {
    if (!insightjournal_activity_group_restricted($context, $course, $cm)) {
        return true;
    }

    return insightjournal_groupids_contain_member(
        insightjournal_current_user_allowed_groupids($course, $cm),
        $targetuserid
    );
}

/**
 * Filters $cms down to just the ones under which $targetuserid is
 * visible to the current viewer, per
 * insightjournal_activity_visible_to_viewer().
 *
 * @param cm_info[]|stdClass[] $cms Candidate activities, keyed by instance id.
 * @param stdClass $course The course the activities belong to.
 * @param int $targetuserid The user whose visibility is being checked.
 * @return cm_info[]|stdClass[] The visible subset, same keys/values as input.
 */
function insightjournal_visible_activities_for_user(array $cms, stdClass $course, int $targetuserid): array {
    return array_filter($cms, function ($cm) use ($course, $targetuserid) {
        return insightjournal_activity_visible_to_viewer(
            context_module::instance($cm->id),
            $course,
            $cm,
            $targetuserid
        );
    });
}

/**
 * Allowed group ids for the current viewer, keyed by insightjournal
 * instance id, for a set of activities - resolved once per distinct
 * groupingid rather than once per activity, since two activities sharing
 * a grouping always resolve to the same allowed group ids for a given
 * viewer (insightjournal_current_user_allowed_groupids() depends only on
 * $course and $cm->groupingid).
 *
 * @param cm_info[]|stdClass[] $activities Activities keyed by instance id.
 * @param stdClass $course The course the activities belong to.
 * @return array Instance id => allowed group ids (int[]), or null for an
 *     activity that is not group-restricted for the current viewer.
 */
function insightjournal_coursereport_allowed_groupids_by_diary(array $activities, stdClass $course): array {
    $bygroupingid = [];
    $result = [];
    foreach ($activities as $diaryid => $cm) {
        $context = context_module::instance($cm->id);
        if (!insightjournal_activity_group_restricted($context, $course, $cm)) {
            $result[$diaryid] = null;
            continue;
        }
        $groupingid = (int) $cm->groupingid;
        if (!array_key_exists($groupingid, $bygroupingid)) {
            $bygroupingid[$groupingid] = insightjournal_current_user_allowed_groupids($course, $cm);
        }
        $result[$diaryid] = $bygroupingid[$groupingid];
    }

    return $result;
}

/**
 * Per-diary "is this userid visible under this diary's group restriction"
 * lookup maps, scoped to exactly $userids - the current screen page or CSV
 * chunk, never the whole course.
 *
 * @param array $diaryallowedgroupids From
 *     insightjournal_coursereport_allowed_groupids_by_diary() - instance id
 *     => allowed group ids, or null for "not restricted."
 * @param int[] $userids The userids actually present in this page/chunk.
 * @return array Instance id => (userid => true) map, or null when the
 *     diary is unrestricted for the current viewer.
 */
function insightjournal_coursereport_diary_allowed_users(array $diaryallowedgroupids, array $userids): array {
    $result = [];
    foreach ($diaryallowedgroupids as $diaryid => $groupids) {
        $result[$diaryid] = $groupids === null
            ? null
            : array_fill_keys(insightjournal_groupids_members_among($groupids, $userids), true);
    }

    return $result;
}

/**
 * The group ids coursereport.php's participant query should be
 * restricted to, or null for "no restriction needed."
 *
 * Null means at least one activity in $activities is unrestricted for
 * the current viewer - since that activity alone shows every enrolled
 * participant a potentially-visible cell, no SQL-level group filter can
 * safely narrow the participant list at all. Otherwise (every activity
 * in $activities is restricted), returns the union of the current
 * viewer's own allowed groups across all of them - a participant who
 * matches any group in this union is guaranteed authorized for at least
 * the one activity that contributed that group, so this can only ever
 * be a safe (not over-permissive) SQL-level prefilter; per-cell masking
 * (insightjournal_activity_visible_to_viewer(), applied per activity in
 * the render loop) still determines exactly which of that participant's
 * cells are actually shown.
 *
 * @param cm_info[]|stdClass[] $activities Visible activities, keyed by instance id.
 * @param stdClass $course The course the activities belong to.
 * @return int[]|null
 */
function insightjournal_coursereport_restrict_groupids(array $activities, stdClass $course): ?array {
    $groupids = [];
    foreach ($activities as $cm) {
        $context = context_module::instance($cm->id);
        if (!insightjournal_activity_group_restricted($context, $course, $cm)) {
            return null;
        }
        $groupids = array_merge($groupids, insightjournal_current_user_allowed_groupids($course, $cm));
    }

    return array_values(array_unique($groupids));
}

/**
 * Whether the current user may see participants' email addresses in this
 * context, per Moodle's user-identity configuration.
 *
 * Wraps \core_user\fields::for_identity(), which already performs both
 * checks needed here: the moodle/site:viewuseridentity capability in the
 * given context, and whether 'email' is actually part of the site's
 * configured $CFG->showuseridentity list (a site admin may have removed it
 * even for an otherwise-capable viewer).
 *
 * @param context $context The context to check moodle/site:viewuseridentity
 *     in - the activity's module context for a single-activity report, or
 *     the course context for a course-wide one.
 * @return bool
 */
function insightjournal_email_field_visible(context $context): bool {
    return in_array('email', \core_user\fields::for_identity($context)->get_required_fields(), true);
}

/**
 * Builds one course-report CSV row: one participant's entry (or lack of
 * one) for one activity. Returned in the plugin's long-standing 9-column
 * legacy order: courseid, coursename, cmid, activityname, userid,
 * fullname, email, response, timemodified.
 *
 * Values are returned raw/unescaped - spreadsheet-formula-prefix escaping
 * is csv_export_writer::add_data()'s job once this row reaches it, not
 * this function's.
 *
 * @param stdClass $course The course the activity belongs to.
 * @param int $cmid The activity's course-module id.
 * @param stdClass $diary The insight journal instance.
 * @param stdClass $user The participant.
 * @param stdClass|null $entry The participant's entry for this activity, or null if they have none.
 * @param bool $showemail Whether the viewer may see participant email addresses.
 * @return array The 9-column row.
 */
function insightjournal_coursereport_csv_row(
    stdClass $course,
    int $cmid,
    stdClass $diary,
    stdClass $user,
    ?stdClass $entry,
    bool $showemail
): array {
    $private = $entry && !insightjournal_entry_visible_to_teacher($entry);

    return [
        $course->id,
        $course->fullname,
        $cmid,
        $diary->name,
        $user->id,
        fullname($user),
        $showemail ? ($user->email ?? '') : '',
        $private
            ? get_string('entriesprivatenotice', 'insightjournal')
            : insightjournal_html_to_text($entry->response ?? ''),
        (!$private && $entry) ? userdate($entry->timemodified) : '',
    ];
}

/**
 * Fetches entries for a specific set of activities and participants, keyed
 * for O(1) per-cell lookup: userid => insightjournalid => entry. Scopes the
 * entries query to only the participants actually being rendered/exported at
 * a time (one page, or one CSV chunk), rather than every entry across the
 * whole course at once.
 *
 * @param int[] $diaryids
 * @param int[] $userids
 * @return array
 */
function insightjournal_entries_by_diary_and_user(array $diaryids, array $userids): array {
    global $DB;

    $entries = [];
    if (empty($diaryids) || empty($userids)) {
        return $entries;
    }
    [$dinsql, $dparams] = $DB->get_in_or_equal($diaryids, SQL_PARAMS_NAMED, 'diary');
    [$uinsql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'user');
    $records = $DB->get_records_select(
        'insightjournal_entries',
        "insightjournalid $dinsql AND userid $uinsql",
        array_merge($dparams, $uparams)
    );
    foreach ($records as $entry) {
        $entries[$entry->userid][$entry->insightjournalid] = $entry;
    }
    return $entries;
}

/**
 * Decides, for one participant's entry in one activity of the course-wide
 * report, whether it counts toward the learner's progress total and
 * whether its per-cell display must stay private.
 *
 * completed is decided independently of privacy and counts regardless of
 * it - matching custom_completion.php's own completion state calculation,
 * which never checks visibility either: a private entry is real completed
 * work, just hidden from the trainer's view of its content/status/
 * timestamp, not from whether it happened at all.
 *
 * @param stdClass|null $entry The participant's entry for this activity, or null if they have none.
 * @return array{completed: bool, private: bool}
 */
function insightjournal_coursereport_cell_state(?stdClass $entry): array {
    return [
        'completed' => $entry !== null && insightjournal_visible_char_count($entry->response) !== 0,
        'private' => $entry !== null && !insightjournal_entry_visible_to_teacher($entry),
    ];
}
