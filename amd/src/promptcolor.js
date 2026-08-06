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
 * Syncs the promptcolor hex text field on the activity settings form with a
 * native colour-picker input rendered alongside it.
 *
 * @module     mod_insightjournal/promptcolor
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// The Squiz.Functions.MultiLineFunctionDeclaration sniff demands a space
// after `function`, which directly contradicts ESLint's
// space-before-function-paren rule (enforced by the Grunt CI step) that
// forbids that same space - a permanent contradiction for this file's
// style, not staleness. Disabled for this file only, so the sniff still
// protects every other file in the plugin.
// phpcs:disable Squiz.Functions.MultiLineFunctionDeclaration
define([], function() {
    // Same pattern mod_form's own server-side validation and
    // insightjournal_prompt_style() use: an optional leading "#", 3 or 6 hex
    // digits.
    var HEX_PATTERN = /^#?[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/;

    // A colour-input element only ever accepts/reports full 6-digit hex, so
    // a valid 3-digit shorthand (e.g. "#abc") needs expanding before it can
    // be assigned to the picker - mirrors
    // insightjournal_contrasting_text_color() in locallib.php's own
    // 3-to-6-digit expansion.
    var expandHex = function(hex) {
        hex = hex.trim();
        if (hex.charAt(0) !== '#') {
            hex = '#' + hex;
        }
        if (hex.length === 4) {
            hex = '#' + hex.charAt(1) + hex.charAt(1) + hex.charAt(2) + hex.charAt(2) + hex.charAt(3) + hex.charAt(3);
        }
        return hex.toLowerCase();
    };

    return {
        init: function() {
            var hexField = document.getElementById('id_promptcolor');
            var picker = document.getElementById('id_promptcolor_picker');
            if (!hexField || !picker) {
                return;
            }
            var syncPickerFromHex = function() {
                var value = hexField.value.trim();
                if (value !== '' && HEX_PATTERN.test(value)) {
                    picker.value = expandHex(value);
                }
            };
            // Picks up whatever value the form already populated the hex
            // field with (new activity: blank, editing: the stored colour),
            // so the picker starts in sync without this module needing to
            // know the colour itself.
            syncPickerFromHex();
            hexField.addEventListener('input', syncPickerFromHex);
            picker.addEventListener('input', function() {
                hexField.value = picker.value;
            });
        }
    };
});
// Closes the disable block opened above define().
// phpcs:enable Squiz.Functions.MultiLineFunctionDeclaration
