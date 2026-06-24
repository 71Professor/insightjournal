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
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification', 'core/str'], function (Ajax, Notification, Str) {
    var timer = null;
    var maxChars = 0;

    var setStatus = function (text, cssclass) {
        var status = document.querySelector('[data-insightjournal-status]');
        if (!status) {
            return;
        }
        status.textContent = text;
        status.className = cssclass || '';
    };

    var charCount = function (str) {
        return [...str].length;
    };

    var updateCounter = function (textarea) {
        var counter = document.querySelector('[data-insightjournal-charcounter]');
        var button = document.querySelector('[data-insightjournal-save]');
        if (!counter) {
            return;
        }
        var current = charCount(textarea.value);
        var over = current > maxChars;
        counter.textContent = current + ' / ' + maxChars;
        counter.className = 'small ms-auto ' + (over ? 'text-danger fw-bold' : 'text-muted');
        if (button) {
            button.disabled = over;
        }
    };

    var save = function (cmid) {
        var textarea = document.querySelector('[data-insightjournal-response]');
        var button = document.querySelector('[data-insightjournal-save]');
        if (!textarea) {
            return;
        }
        if (maxChars > 0 && charCount(textarea.value) > maxChars) {
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
                button.disabled = maxChars > 0 && charCount(textarea.value) > maxChars;
            }
            return Str.get_string('savedat', 'mod_insightjournal', result.timestr);
        }).then(function (text) {
            setStatus(text, 'text-success');
            return text;
        }).catch(function (error) {
            if (button) {
                button.disabled = maxChars > 0 && charCount(textarea.value) > maxChars;
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
        init: function (cmid, autosave, maxchars) {
            maxChars = maxchars || 0;
            var textarea = document.querySelector('[data-insightjournal-response]');
            var button = document.querySelector('[data-insightjournal-save]');
            if (!textarea) {
                return;
            }
            if (maxChars > 0) {
                updateCounter(textarea);
            }
            if (button) {
                button.addEventListener('click', function (e) {
                    e.preventDefault();
                    save(cmid);
                });
            }
            textarea.addEventListener('input', function () {
                if (maxChars > 0) {
                    updateCounter(textarea);
                }
                if (autosave) {
                    clearTimeout(timer);
                    timer = setTimeout(function () {
                        save(cmid);
                    }, 3000);
                }
            });
        }
    };
});
