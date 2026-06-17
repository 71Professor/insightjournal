define(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    var timer = null;

    var setStatus = function(text, cssclass) {
        var status = document.querySelector('[data-insightjournal-status]');
        if (!status) { return; }
        status.textContent = text;
        status.className = cssclass || '';
    };

    var save = function(cmid) {
        var textarea = document.querySelector('[data-insightjournal-response]');
        var button = document.querySelector('[data-insightjournal-save]');
        if (!textarea) { return; }
        if (button) { button.disabled = true; }
        setStatus(M.util.get_string('saving', 'insightjournal'), 'text-info');
        Ajax.call([{
            methodname: 'mod_insightjournal_save_entry',
            args: {cmid: cmid, response: textarea.value}
        }])[0].then(function(result) {
            setStatus(M.util.get_string('savedat', 'insightjournal').replace('{$a}', result.timestr), 'text-success');
            if (button) { button.disabled = false; }
            return result;
        }).catch(function(error) {
            if (button) { button.disabled = false; }
            setStatus(M.util.get_string('saveerror', 'insightjournal'), 'text-danger');
            Notification.exception(error);
        });
    };

    return {
        init: function(cmid, autosave) {
            var textarea = document.querySelector('[data-insightjournal-response]');
            var button = document.querySelector('[data-insightjournal-save]');
            if (!textarea) { return; }
            if (button) {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    save(cmid);
                });
            }
            if (autosave) {
                textarea.addEventListener('input', function() {
                    clearTimeout(timer);
                    timer = setTimeout(function() { save(cmid); }, 3000);
                });
            }
        }
    };
});
