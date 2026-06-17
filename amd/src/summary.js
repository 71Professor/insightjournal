define([], function() {
    return {
        init: function() {
            var button = document.querySelector('[data-insightjournal-print]');
            if (!button) {
                return;
            }
            button.addEventListener('click', function() {
                window.print();
            });
        }
    };
});
