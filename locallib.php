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
 * @copyright  2026 insightjournal contributors
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

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
