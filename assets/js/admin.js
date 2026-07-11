/**
 * SitePulse Analytics — settings page script.
 *
 * Powers the webhook endpoint repeater: "+ Add another endpoint" appends a
 * fresh URL field (cloned from a <template> printed by SettingsPage), and
 * each added row's Remove button deletes that row. The first field is
 * permanent — it renders without a Remove button, so the form always shows
 * at least one field. Empty fields are ignored on save.
 */
(function () {
    'use strict';

    function init() {
        var repeater = document.getElementById('spa-webhook-repeater');
        var addButton = document.getElementById('spa-add-webhook');
        var template = document.getElementById('spa-webhook-row-template');

        if (!repeater || !addButton || !template) {
            return;
        }

        addButton.addEventListener('click', function () {
            var row = template.content.firstElementChild.cloneNode(true);
            repeater.appendChild(row);

            var input = row.querySelector('input');
            if (input) {
                input.focus();
            }
        });

        repeater.addEventListener('click', function (e) {
            var button = e.target && e.target.closest ? e.target.closest('.spa-remove-webhook') : null;
            if (!button) {
                return;
            }

            var row = button.closest('.spa-webhook-row');
            if (row) {
                row.remove();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
