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
 * Autosave and view/edit toggle handling for the insight journal response field.
 *
 * @module     mod_insightjournal/autosave
 * @copyright  2026 Michael Kohl
 * @author     Michael Kohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification', 'core/str', 'editor_tiny/editor'], function(Ajax, Notification, Str, TinyEditor) {
    var timer = null;
    var maxChars = 0;
    var lastSeenValue = null;

    // TinyMCE only copies its content into the backing textarea on blur, not on
    // every keystroke, so we always ask the live editor instance for its
    // content directly when one exists, falling back to the textarea's own
    // value for the plain-textarea editor (or before Tiny has finished
    // attaching).
    var getCurrentValue = function(textarea) {
        var instance = TinyEditor.getInstanceForElementId(textarea.id);
        return instance ? instance.getContent() : textarea.value;
    };

    var setStatus = function(text, cssclass) {
        var status = document.querySelector('[data-insightjournal-status]');
        if (!status) {
            return;
        }
        status.textContent = text;
        status.className = cssclass || '';
    };

    var setViewStatus = function(text) {
        var status = document.querySelector('[data-insightjournal-view-status]');
        if (status) {
            status.textContent = text;
        }
    };

    var stripHtml = function(html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        return doc.body.textContent || '';
    };

    var charCount = function(str) {
        return [...str].length;
    };

    var updateCounter = function(value) {
        var counter = document.querySelector('[data-insightjournal-charcounter]');
        var button = document.querySelector('[data-insightjournal-save]');
        if (!counter) {
            return;
        }
        var current = charCount(stripHtml(value));
        var over = current > maxChars;
        counter.textContent = current + ' / ' + maxChars;
        counter.className = 'small ms-auto ' + (over ? 'text-danger fw-bold' : 'text-muted');
        if (button) {
            button.disabled = over;
        }
    };

    var showEditPanel = function() {
        var view = document.querySelector('[data-insightjournal-view]');
        var panel = document.querySelector('[data-insightjournal-edit-panel]');
        var textarea = document.querySelector('[data-insightjournal-response]');
        if (view) {
            view.classList.add('d-none');
        }
        if (panel) {
            panel.classList.remove('d-none');
        }
        if (textarea) {
            // Resync the poll's change-detection baseline to whatever the
            // editor actually holds right now. Without this, lastSeenValue
            // can be stale here (e.g. the user clicked Save less than a
            // second after typing, before the poll had a chance to catch
            // up), which would make the next poll tick misread the gap as a
            // fresh edit and fire a spurious autosave a few seconds after
            // reopening, even though nothing was typed since.
            lastSeenValue = getCurrentValue(textarea);
            var instance = TinyEditor.getInstanceForElementId(textarea.id);
            if (instance) {
                instance.focus();
            } else {
                textarea.focus();
            }
        }
    };

    var showViewPanel = function(responsehtml, timestr) {
        var view = document.querySelector('[data-insightjournal-view]');
        var panel = document.querySelector('[data-insightjournal-edit-panel]');
        var display = document.querySelector('[data-insightjournal-response-display]');
        var editbutton = document.querySelector('[data-insightjournal-edit]');
        if (display) {
            display.innerHTML = responsehtml;
        }
        setViewStatus(timestr);
        if (panel) {
            panel.classList.add('d-none');
        }
        if (view) {
            view.classList.remove('d-none');
        }
        if (editbutton) {
            editbutton.focus();
        }
    };

    var save = function(cmid, manual) {
        clearTimeout(timer);
        var textarea = document.querySelector('[data-insightjournal-response]');
        var button = document.querySelector('[data-insightjournal-save]');
        if (!textarea) {
            return;
        }
        var value = getCurrentValue(textarea);
        if (maxChars > 0 && charCount(stripHtml(value)) > maxChars) {
            return;
        }
        if (button) {
            button.disabled = true;
        }
        Str.get_string('saving', 'mod_insightjournal').then(function(text) {
            setStatus(text, 'text-info');
            return Ajax.call([{
                methodname: 'mod_insightjournal_save_entry',
                args: {cmid: cmid, response: value}
            }])[0];
        }).then(function(result) {
            var current = getCurrentValue(textarea);
            if (button) {
                button.disabled = maxChars > 0 && charCount(stripHtml(current)) > maxChars;
            }
            return Promise.all([result, Str.get_string('savedat', 'mod_insightjournal', result.timestr)]);
        }).then(function(args) {
            var saved = args[0];
            var text = args[1];
            setStatus(text, 'text-success');
            if (manual) {
                showViewPanel(saved.responsehtml, text);
            }
            return text;
        }).catch(function(error) {
            var current = getCurrentValue(textarea);
            if (button) {
                button.disabled = maxChars > 0 && charCount(stripHtml(current)) > maxChars;
            }
            Notification.exception(error);
            return Str.get_string('saveerror', 'mod_insightjournal');
        }).then(function(text) {
            setStatus(text, 'text-danger');
            return text;
        }).catch(function() {
            return null;
        });
    };

    return {
        init: function(cmid, autosave, maxchars) {
            maxChars = maxchars || 0;
            var textarea = document.querySelector('[data-insightjournal-response]');
            var button = document.querySelector('[data-insightjournal-save]');
            var editbutton = document.querySelector('[data-insightjournal-edit]');
            if (!textarea) {
                return;
            }
            lastSeenValue = getCurrentValue(textarea);
            if (maxChars > 0) {
                updateCounter(lastSeenValue);
            }
            if (button) {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    save(cmid, true);
                });
            }
            if (editbutton) {
                editbutton.addEventListener('click', function(e) {
                    e.preventDefault();
                    showEditPanel();
                });
            }
            // Poll rather than bind to a live editor event: Tiny attaches
            // asynchronously (there is no synchronous "ready" signal available
            // to this module), and once attached it only syncs its backing
            // textarea on blur, not per keystroke. One second is frequent
            // enough for a responsive character counter/autosave trigger
            // without meaningfully loading the page.
            setInterval(function() {
                var panel = document.querySelector('[data-insightjournal-edit-panel]');
                if (!panel || panel.classList.contains('d-none')) {
                    return;
                }
                var value = getCurrentValue(textarea);
                if (value === lastSeenValue) {
                    return;
                }
                lastSeenValue = value;
                if (maxChars > 0) {
                    updateCounter(value);
                }
                if (autosave) {
                    clearTimeout(timer);
                    timer = setTimeout(function() {
                        save(cmid, false);
                    }, 3000);
                }
            }, 1000);
        }
    };
});
