/**
 * SitePulse Analytics — settings page script.
 *
 * Powers the webhook block repeater (layout ported from the Forms Webhook
 * Integrator plugin): "+ Add Additional URL" appends a new URL/label block,
 * each added block's Remove button deletes that block and re-indexes the
 * rest, and the "Webhook Status" toggle card is shown only while at least
 * one URL field currently has a value.
 */
(function () {
    'use strict';

    /**
     * Watches all .spa-webhook-url-input fields. Shows the "Webhook Status"
     * toggle card when any field has a value, hides it otherwise — the
     * toggle is meaningless with nothing configured to activate.
     */
    function initWebhookUrlWatcher() {
        var container  = document.getElementById('spa-webhooks-container');
        var toggleCard = document.getElementById('spa-webhook-toggle-card');

        if (!container) {
            return;
        }

        function updateToggleCard() {
            var inputs = container.querySelectorAll('.spa-webhook-url-input');
            var hasAny = false;

            inputs.forEach(function (inp) {
                if (inp.value.trim() !== '') {
                    hasAny = true;
                }
            });

            if (toggleCard) {
                toggleCard.style.display = hasAny ? '' : 'none';
                var checkbox = toggleCard.querySelector('input[type="checkbox"]');
                if (checkbox) {
                    checkbox.disabled = !hasAny;
                }
            }
        }

        // Wire live input events for server-rendered blocks.
        container.querySelectorAll('.spa-webhook-url-input').forEach(function (inp) {
            inp.addEventListener('input', updateToggleCard);
        });

        // Wire remove buttons for server-rendered additional blocks.
        container.querySelectorAll('.spa-remove-webhook-btn').forEach(function (btn) {
            wireRemoveButton(btn, container, updateToggleCard);
        });

        var addButton = document.getElementById('spa-add-webhook');
        if (addButton) {
            addButton.addEventListener('click', function () {
                var index    = container.querySelectorAll('.spa-webhook-block').length;
                var newBlock = buildWebhookBlock(index);
                container.appendChild(newBlock);
                newBlock.querySelector('.spa-webhook-url-input').focus();
                updateToggleCard();
            });
        }

        updateToggleCard();
    }

    /**
     * Builds a new webhook block DOM element for the given index.
     *
     * @param {number} index
     * @returns {HTMLElement}
     */
    function buildWebhookBlock(index) {
        var block = document.createElement('div');
        block.className = 'spa-webhook-block';
        block.dataset.webhookIndex = index;

        block.innerHTML =
            '<div class="spa-webhook-block-header">' +
                '<strong class="spa-webhook-block-title">Webhook ' + (index + 1) + '</strong>' +
                '<button type="button" class="button spa-remove-webhook-btn" aria-label="Remove webhook ' + (index + 1) + '">Remove</button>' +
            '</div>' +
            '<div class="spa-webhook-url-row">' +
                '<input type="url" class="spa-webhook-url-input regular-text code"' +
                    ' name="spa_settings[webhooks][' + index + '][url]"' +
                    ' placeholder="https://example.com/analytics-hook"' +
                    ' aria-label="Webhook ' + (index + 1) + ' URL">' +
            '</div>' +
            '<div style="margin-top:8px;">' +
                '<input type="text" class="regular-text spa-webhook-label-input"' +
                    ' name="spa_settings[webhooks][' + index + '][label]"' +
                    ' placeholder="Label (optional — shown in the Delivery Log)"' +
                    ' aria-label="Webhook ' + (index + 1) + ' label">' +
            '</div>';

        var container = document.getElementById('spa-webhooks-container');

        wireRemoveButton(block.querySelector('.spa-remove-webhook-btn'), container, function () {
            var toggleCard = document.getElementById('spa-webhook-toggle-card');
            if (!toggleCard) {
                return;
            }
            var hasAny = false;
            container.querySelectorAll('.spa-webhook-url-input').forEach(function (inp) {
                if (inp.value.trim() !== '') {
                    hasAny = true;
                }
            });
            toggleCard.style.display = hasAny ? '' : 'none';
            var checkbox = toggleCard.querySelector('input[type="checkbox"]');
            if (checkbox) {
                checkbox.disabled = !hasAny;
            }
        });

        block.querySelector('.spa-webhook-url-input').addEventListener('input', function () {
            var toggleCard = document.getElementById('spa-webhook-toggle-card');
            if (!toggleCard) {
                return;
            }
            toggleCard.style.display = '';
            var checkbox = toggleCard.querySelector('input[type="checkbox"]');
            if (checkbox) {
                checkbox.disabled = false;
            }
        });

        return block;
    }

    /**
     * Wires a block's Remove button: removes the block, re-indexes the
     * remaining blocks' name attributes and titles, and re-runs the given
     * toggle-card visibility callback.
     *
     * @param {HTMLButtonElement|null} btn
     * @param {HTMLElement}            container
     * @param {function}               onRemoved
     */
    function wireRemoveButton(btn, container, onRemoved) {
        if (!btn) {
            return;
        }

        btn.addEventListener('click', function () {
            var block = btn.closest('.spa-webhook-block');
            if (block) {
                block.remove();
            }
            reindexWebhookBlocks(container);
            onRemoved();
        });
    }

    /**
     * Re-indexes name attributes and titles after a block is added or removed.
     * The first block never shows a Remove button.
     *
     * @param {HTMLElement} container
     */
    function reindexWebhookBlocks(container) {
        container.querySelectorAll('.spa-webhook-block').forEach(function (block, idx) {
            block.dataset.webhookIndex = idx;

            var title = block.querySelector('.spa-webhook-block-title');
            if (title) {
                title.textContent = 'Webhook ' + (idx + 1);
            }

            var urlInput = block.querySelector('.spa-webhook-url-input');
            if (urlInput) {
                urlInput.name = 'spa_settings[webhooks][' + idx + '][url]';
            }

            var labelInput = block.querySelector('.spa-webhook-label-input');
            if (labelInput) {
                labelInput.name = 'spa_settings[webhooks][' + idx + '][label]';
            }

            var removeBtn = block.querySelector('.spa-remove-webhook-btn');
            if (removeBtn) {
                removeBtn.style.display = idx === 0 ? 'none' : '';
            }
        });
    }

    /** Reflects the "Webhook Status" checkbox state in its adjacent label. */
    function initToggleLabel() {
        var toggle = document.getElementById('spa_webhook_active');
        var label  = document.getElementById('spa-webhook-toggle-label');

        if (!toggle || !label) {
            return;
        }

        toggle.addEventListener('change', function () {
            label.textContent = this.checked ? 'Active' : 'Inactive';
        });
    }

    function init() {
        initWebhookUrlWatcher();
        initToggleLabel();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
