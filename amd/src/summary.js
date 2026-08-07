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
 * Print handling for the insight journal personal summary page.
 *
 * @module     mod_insightjournal/summary
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// The Squiz.Functions.MultiLineFunctionDeclaration sniff demands a space
// after `function`, which directly contradicts ESLint's
// space-before-function-paren rule (enforced by the Grunt CI step) that
// forbids that same space - a permanent contradiction for this file's
// style, not staleness, and not specific to the AMD define() wrapper: it
// fires on every multi-line function expression in the file. Disabled for
// this file only, so the sniff still protects every other file in the
// plugin.
// phpcs:disable Squiz.Functions.MultiLineFunctionDeclaration
export const init = function() {
    var button = document.querySelector('[data-insightjournal-print]');
    if (!button) {
        return;
    }
    button.addEventListener('click', function() {
        window.print();
    });
};
// phpcs:enable Squiz.Functions.MultiLineFunctionDeclaration
