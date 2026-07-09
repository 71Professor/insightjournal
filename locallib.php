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
 * Prefix potentially executable spreadsheet values before CSV export.
 *
 * @param mixed $value Raw value.
 * @return string Sanitised value.
 */
function insightjournal_csv_value($value): string {
    $value = (string)$value;
    if ($value !== '' && preg_match('/^[=\+\-@]/', $value)) {
        return "'" . $value;
    }
    return $value;
}

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
 * @param string|null $hexcolor Hex colour code (e.g. "#ffcc00" or "abc"), or empty/null for none.
 * @return string Inline style attribute value, or '' if no valid colour is set.
 */
function insightjournal_prompt_style(?string $hexcolor): string {
    $hexcolor = trim((string)$hexcolor);
    if ($hexcolor === '' || !preg_match('/^#?[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/', $hexcolor)) {
        return '';
    }
    if ($hexcolor[0] !== '#') {
        $hexcolor = '#' . $hexcolor;
    }

    return "background-color: {$hexcolor}; padding: 0.75rem 1rem; border-radius: 0.25rem;";
}

/**
 * Send standard CSV download headers.
 *
 * @param string $filename Clean file name.
 * @return void
 */
function insightjournal_send_csv_headers(string $filename): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . clean_filename($filename) . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
}

/**
 * Whether trainers/teachers may currently see this activity's insight journal entries.
 *
 * Controlled per activity by the trainer/manager via the entriesvisibility
 * field:
 * - INSIGHTJOURNAL_VISIBILITY_PRIVATE: entries stay visible to the authoring
 *   learner only.
 * - INSIGHTJOURNAL_VISIBILITY_VISIBLE (default): trainers/teachers with the
 *   mod/insightjournal:viewall capability may see the entries.
 *
 * When entries are private, the report, course report, and summary pages
 * remain reachable to anyone with the mod/insightjournal:viewall capability,
 * but show a notice instead of entry content. Any unexpected/legacy value
 * (e.g. a pre-migration "site default" of 0) is treated as visible, matching
 * the historical default.
 *
 * @param stdClass $diary The activity instance record (needs entriesvisibility).
 * @return bool
 */
function insightjournal_entries_visible_to_teacher(stdClass $diary): bool {
    $visibility = (int) ($diary->entriesvisibility ?? INSIGHTJOURNAL_VISIBILITY_VISIBLE);

    return $visibility !== INSIGHTJOURNAL_VISIBILITY_PRIVATE;
}
