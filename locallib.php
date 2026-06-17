<?php
// Local helpers for mod_insightjournal.

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
