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
 * Autosave handling for the insight journal response field.
 *
 * @module     mod_insightjournal/autosave
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification', 'core/str'], function (Ajax, Notification, Str) {
    var timer = null;

    var setStatus = function (text, cssclass) {
        var status = document.querySelector('[data-insightjournal-status]');
        if (!status) {
            return;
        }
        status.textContent = text;
        status.className = cssclass || '';
    };

    var save = function (cmid) {
        var textarea = document.querySelector('[data-insightjournal-response]');
        var button = document.querySelector('[data-insightjournal-save]');
        if (!textarea) {
            return;
        }
        if (button) {
            button.disabled = true;
        }
        Str.get_string('saving', 'mod_insightjournal').then(function (text) {
            setStatus(text, 'text-info');
            return Ajax.call([{
                methodname: 'mod_insightjournal_save_entry',
                args: {cmid: cmid, response: textarea.value}
            }])[0];
        }).then(function (result) {
            if (button) {
                button.disabled = false;
            }
            return Str.get_string('savedat', 'mod_insightjournal', result.timestr);
        }).then(function (text) {
            setStatus(text, 'text-success');
            return text;
        }).catch(function (error) {
            if (button) {
                button.disabled = false;
            }
            Str.get_string('saveerror', 'mod_insightjournal').then(function (text) {
                setStatus(text, 'text-danger');
                return text;
            }).catch(function () {
                return null;
            });
            Notification.exception(error);
        });
    };

    return {
        init: function (cmid, autosave) {
            var textarea = document.querySelector('[data-insightjournal-response]');
            var button = document.querySelector('[data-insightjournal-save]');
            if (!textarea) {
                return;
            }
            if (button) {
                button.addEventListener('click', function (e) {
                    e.preventDefault();
                    save(cmid);
                });
            }
            if (autosave) {
                textarea.addEventListener('input', function () {
                    clearTimeout(timer);
                    timer = setTimeout(function () {
                        save(cmid);
                    }, 3000);
                });
            }
        }
    };
});
