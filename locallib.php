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
 * Convert stored response HTML to its visible plain-text form.
 *
 * Used to measure "visible characters" for minchars/maxchars and to decide
 * whether a response is meaningfully empty. An empty rich-text editor
 * serialises to markup like "<p></p>" or "<p><br></p>", not "", so a raw
 * trim()/strlen() check on stored HTML is unreliable.
 *
 * Moodle's html_to_text() upper-cases the visible content of <b>, <strong>,
 * <h1>-<h6> and <th> elements to convey emphasis in its plain-text output.
 * That case transform is undesirable for a character-count/emptiness check,
 * so those tags are unwrapped (keeping their inner text as-is) before
 * delegating to html_to_text().
 *
 * @param string $html Stored response HTML (or plain text).
 * @return string Trimmed visible text, with all markup stripped.
 */
function insightjournal_html_to_text(string $html): string {
    $html = preg_replace('#</?(?:b|strong|h[1-6]|th)(?:\s[^>]*)?>#i', '', $html);

    return trim(html_to_text($html, 0, false));
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
 * Userids of every member of every group the current user belongs to.
 *
 * Returns an empty array if the current user belongs to no matching
 * groups - callers must treat that as "matches nobody," not "no
 * restriction": an empty result here still means the restriction is
 * active, just that it currently excludes everyone.
 *
 * When $cm is given, the search is scoped to that activity: only groups
 * belonging to $cm->groupingid (0 means every grouping, i.e. no
 * restriction to a specific grouping) and flagged as
 * participation-eligible are considered - matching what Moodle core's
 * own groups_get_activity_allowed_groups() resolves to for a user
 * without moodle/site:accessallgroups in Separate Groups mode. When $cm
 * is omitted, every group in the course counts regardless of grouping
 * or participation flag - this is the pre-R3-01 behaviour, kept for
 * callers (summary.php) that don't yet have a single natural activity
 * to scope to.
 *
 * @param stdClass $course The course to look up group membership in.
 * @param cm_info|stdClass|null $cm The activity to scope the search to,
 *     or null for the course-wide (unscoped) legacy behaviour.
 * @return int[] Deduplicated user ids.
 */
function insightjournal_current_user_group_userids(stdClass $course, cm_info|stdClass|null $cm = null): array {
    global $USER;

    // Moodle's groups_get_all_groups() only applies its userid filter when $userid is
    // non-empty ("if (!empty($userid))" in core) - a falsy id (e.g. 0, the
    // logged-out/guest sentinel) would silently return every group's
    // members course-wide instead of "this user's groups," inverting the
    // "empty means matches nobody" contract this function promises. Every
    // real caller has already gone through require_login() by the time this
    // runs, so $USER->id is never actually 0 in practice - guard anyway,
    // since this is a security-relevant primitive, not just an internal
    // convenience function.
    if (empty($USER->id)) {
        return [];
    }

    $groupingid = $cm !== null ? (int) $cm->groupingid : 0;
    $participationonly = $cm !== null;

    $groups = groups_get_all_groups($course->id, $USER->id, $groupingid, 'g.*', true, $participationonly);
    $userids = [];
    foreach ($groups as $group) {
        $userids = array_merge($userids, array_map('intval', $group->members));
    }

    return array_values(array_unique($userids));
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
