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
define(['core/ajax', 'core/notification', 'core/str'], function(Ajax, Notification, Str) {
    // The PHP entry_form renders the response field via Moodle's standard
    // 'editor' mform element, whose fixed core template
    // (core_form/editor_textarea) only ever emits id/name/rows/cols/onblur/
    // onchange onto the actual <textarea> - not arbitrary attributes - so it
    // is located by its standard Moodle-generated id rather than a data
    // attribute like the other controls below.
    var RESPONSE_ID = 'id_response';
    var timer = null;
    var maxChars = 0;
    var lastSeenValue = null;
    var currentRevision = 0;
    var saving = false;
    var pendingSave = null;
    var conflicted = false;
    var tinyEditor = null;
    var tinyEditorRequested = false;

    // The editor_tiny plugin is optional, not a guaranteed dependency: a
    // site may run Atto or the plain textarea editor instead, in which case
    // this module must not fail to load along with it. Request it lazily and
    // tolerate failure; getCurrentValue() below falls back to the textarea's
    // own value when no live Tiny instance is found for it.
    var requestTinyEditor = function() {
        if (tinyEditorRequested) {
            return;
        }
        tinyEditorRequested = true;
        require(['editor_tiny/editor'], function(TinyEditor) {
            tinyEditor = TinyEditor;
        }, function() {
            // The editor_tiny plugin is not installed or enabled on this site; ignore.
        });
    };

    // TinyMCE only copies its content into the backing textarea on blur, not on
    // every keystroke, so when a live Tiny instance is attached we ask it for
    // its content directly. Every other editor (Atto, plain textarea) keeps
    // the textarea's own value continuously in sync as the user types, so
    // reading it directly is always correct there.
    var getCurrentValue = function(textarea) {
        var instance = tinyEditor ? tinyEditor.getInstanceForElementId(textarea.id) : null;
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
            button.disabled = over || conflicted;
        }
    };

    var showEditPanel = function() {
        var view = document.querySelector('[data-insightjournal-view]');
        var panel = document.querySelector('[data-insightjournal-edit-panel]');
        var textarea = document.getElementById(RESPONSE_ID);
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
            var instance = tinyEditor ? tinyEditor.getInstanceForElementId(textarea.id) : null;
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

    // Shown on a save conflict instead of silently retrying: displays the
    // server's actual current content (already returned by save_entry on a
    // conflict) so the learner can compare it against their own local draft,
    // which is deliberately left untouched in the textarea beside it.
    var showConflictBanner = function(result, message) {
        var banner = document.querySelector('[data-insightjournal-conflict-banner]');
        var messageEl = document.querySelector('[data-insightjournal-conflict-message]');
        var content = document.querySelector('[data-insightjournal-conflict-content]');
        if (messageEl) {
            messageEl.textContent = message;
        }
        if (content) {
            content.innerHTML = result.responsehtml;
        }
        if (banner) {
            banner.classList.remove('d-none');
        }
    };

    // Runs once whichever branch of save()'s promise chain settles, so a
    // queued save (see the "saving" guard below) always gets its turn. Kept
    // as its own function, and called from each branch, so the promise chain
    // can still end in a genuine .catch() as required by eslint's
    // promise/catch-or-return rule.
    var finishSave = function() {
        saving = false;
        if (pendingSave) {
            var next = pendingSave;
            pendingSave = null;
            save(next.cmid, next.manual);
        }
    };

    var save = function(cmid, manual) {
        clearTimeout(timer);
        if (conflicted) {
            return;
        }
        // Only one save may be in flight at a time: an overlapping request (e.g.
        // the autosave debounce firing again before a slow save has returned)
        // would otherwise let responses arrive out of order and let a stale one
        // overwrite newer stored text. Queue it instead; when it eventually
        // runs it reads the textarea live, so only the latest dirty state is
        // ever actually sent.
        if (saving) {
            pendingSave = {cmid: cmid, manual: manual || Boolean(pendingSave && pendingSave.manual)};
            return;
        }
        var textarea = document.getElementById(RESPONSE_ID);
        var button = document.querySelector('[data-insightjournal-save]');
        var privatecheckbox = document.querySelector('[data-insightjournal-private]');
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
        saving = true;
        Str.get_string('saving', 'mod_insightjournal').then(function(text) {
            setStatus(text, 'text-info');
            return Ajax.call([{
                methodname: 'mod_insightjournal_save_entry',
                args: {
                    cmid: cmid,
                    response: value,
                    expectedrevision: currentRevision,
                    'private': Boolean(privatecheckbox && privatecheckbox.checked)
                }
            }])[0];
        }).then(async function(result) {
            if (result.conflict) {
                conflicted = true;
                pendingSave = null;
                saving = false;
                clearTimeout(timer);
                if (button) {
                    button.disabled = true;
                }
                if (privatecheckbox) {
                    privatecheckbox.disabled = true;
                }
                var conflicttext = await Str.get_string('saveconflict', 'mod_insightjournal');
                setStatus(conflicttext, 'text-danger');
                showConflictBanner(result, conflicttext);
                return conflicttext;
            }
            var current = getCurrentValue(textarea);
            if (button) {
                button.disabled = maxChars > 0 && charCount(stripHtml(current)) > maxChars;
            }
            currentRevision = result.revision;
            if (privatecheckbox) {
                privatecheckbox.checked = result.private;
            }
            var savedtext = await Str.get_string('savedat', 'mod_insightjournal', result.timestr);
            setStatus(savedtext, 'text-success');
            if (manual) {
                showViewPanel(result.responsehtml, savedtext);
            }
            finishSave();
            return savedtext;
        }).catch(async function(error) {
            var current = getCurrentValue(textarea);
            if (button) {
                button.disabled = maxChars > 0 && charCount(stripHtml(current)) > maxChars;
            }
            Notification.exception(error);
            var errortext = await Str.get_string('saveerror', 'mod_insightjournal');
            setStatus(errortext, 'text-danger');
            finishSave();
            return errortext;
        }).catch(function() {
            finishSave();
            return null;
        });
    };

    return {
        init: function(cmid, autosave, maxchars, initialrevision) {
            maxChars = maxchars || 0;
            currentRevision = initialrevision || 0;
            var textarea = document.getElementById(RESPONSE_ID);
            var button = document.querySelector('[data-insightjournal-save]');
            var editbutton = document.querySelector('[data-insightjournal-edit]');
            var privatecheckbox = document.querySelector('[data-insightjournal-private]');
            if (!textarea) {
                return;
            }
            // The minchars hint below the editor is only linked here, not via
            // a static aria-describedby in the markup: the mform-rendered
            // textarea's id is only known once entry_form has actually
            // rendered, and its fixed core template does not accept an
            // aria-describedby option to set this itself.
            if (document.getElementById('insightjournal-minchars-note')) {
                textarea.setAttribute('aria-describedby', 'insightjournal-minchars-note');
            }
            requestTinyEditor();
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
            if (privatecheckbox) {
                // Saved immediately so a visibility choice is never lost if the
                // learner navigates away before typing anything else, but as a
                // non-manual save: manual=true would switch to the read-only
                // view panel via showViewPanel(), which would yank the learner
                // out of the editor just for toggling a checkbox.
                privatecheckbox.addEventListener('change', function() {
                    save(cmid, false);
                });
            }
            var conflictreload = document.querySelector('[data-insightjournal-conflict-reload]');
            if (conflictreload) {
                // Navigates via the link's own href rather than
                // window.location.reload(): the no-JS conflict path (see
                // view.php) renders this same control on a POST response, and
                // reload() would resubmit that POST (triggering a browser
                // "confirm form resubmission" prompt) instead of loading a
                // fresh GET. Using href re-derives every bit of client state
                // (currentRevision, lastSeenValue, the response itself) from
                // the server's actual current record via a normal page load,
                // rather than trying to reconcile it in place.
                conflictreload.addEventListener('click', function(e) {
                    e.preventDefault();
                    window.location.href = conflictreload.href;
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
                if (!panel || panel.classList.contains('d-none') || conflicted) {
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
