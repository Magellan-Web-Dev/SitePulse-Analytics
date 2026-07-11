/**
 * SitePulse Analytics — Delivery Log page.
 *
 * Mirrors the analytics page behavior of the Forms Webhook Integrator plugin:
 * two lazy-loading accordions (successful / failed deliveries) with
 * year/month/endpoint filters, payload search, per-page selection, windowed
 * pagination, per-entry delete, and the Deliveries API toggle/key card.
 *
 * All data comes from the spa_get_delivery_logs (and sibling) AJAX actions;
 * configuration and nonces arrive via the SPA_LOG object localized by
 * DeliveryLogPage.
 */
(function () {
    'use strict';

    /**
     * Safely escapes a string for insertion into HTML.
     *
     * @param {string} text
     * @returns {string}
     */
    function escapeHtml(text) {
        var node = document.createElement('span');
        node.appendChild(document.createTextNode(String(text)));
        return node.innerHTML;
    }

    function cfg(key) {
        return (typeof SPA_LOG !== 'undefined' && SPA_LOG[key]) ? SPA_LOG[key] : '';
    }

    // ── Accordions ────────────────────────────────────────────────────────────

    function initAccordions() {
        document.querySelectorAll('.spa-accordion-header').forEach(function (header) {
            header.addEventListener('click', function () {
                var isExpanded = header.getAttribute('aria-expanded') === 'true';
                var bodyId     = header.getAttribute('aria-controls');
                var body       = bodyId ? document.getElementById(bodyId) : header.nextElementSibling;

                if (!body) return;

                header.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
                body.hidden = !body.hidden;
            });
        });
    }

    // ── Log lists (AJAX pagination) ───────────────────────────────────────────

    /**
     * Wires each accordion to load its log list on demand via the
     * spa_get_delivery_logs AJAX action. On first open, the controls bar and
     * list are injected and the first page is fetched; filter and page changes
     * trigger subsequent fetches. Delete buttons are handled via event
     * delegation and trigger a re-fetch after a successful delete.
     */
    function initLogLists() {
        document.querySelectorAll('.spa-accordion').forEach(function (accordion) {
            var header = accordion.querySelector('.spa-accordion-header');
            var body   = accordion.querySelector('.spa-accordion-body');
            if (!header || !body) return;

            var status      = body.dataset.status || '';
            var initialized = false;
            var dirty       = false;
            var state       = { page: 1, perPage: 10, search: '', year: '', month: '', endpoint: '' };

            var controls = document.createElement('div');
            controls.className = 'spa-acc-controls';
            controls.innerHTML = buildControlsHtml();
            body.appendChild(controls);

            var list = document.createElement('ul');
            list.className = 'spa-log-list';
            body.appendChild(list);

            var paginationEl = document.createElement('div');
            paginationEl.className = 'spa-pagination';
            body.appendChild(paginationEl);

            // ── Controls wiring ───────────────────────────────────────────────
            controls.querySelector('.spa-filter-year').addEventListener('change', function () {
                state.year = this.value; state.page = 1; fetchLogs();
            });
            controls.querySelector('.spa-filter-month').addEventListener('change', function () {
                state.month = this.value; state.page = 1; fetchLogs();
            });
            controls.querySelector('.spa-filter-endpoint').addEventListener('change', function () {
                state.endpoint = this.value; state.page = 1; fetchLogs();
            });
            controls.querySelector('.spa-per-page').addEventListener('change', function () {
                state.perPage = parseInt(this.value, 10); state.page = 1; fetchLogs();
            });
            // Debounced: every keystroke would otherwise fire a LIKE query
            // over LONGTEXT payloads.
            var searchTimer = null;
            controls.querySelector('.spa-search-input').addEventListener('input', function () {
                var value = this.value;
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function () {
                    state.search = value; state.page = 1; fetchLogs();
                }, 300);
            });
            controls.querySelector('.spa-search-clear').addEventListener('click', function () {
                clearTimeout(searchTimer);
                controls.querySelector('.spa-search-input').value = '';
                state.search = ''; state.page = 1; fetchLogs();
            });

            // ── Fetch on accordion open ───────────────────────────────────────
            header.addEventListener('click', function () {
                var isOpen = header.getAttribute('aria-expanded') === 'true';
                if (isOpen && (!initialized || dirty)) {
                    fetchLogs();
                }
            });

            // ── Delete via event delegation ───────────────────────────────────
            list.addEventListener('click', function (e) {
                var btn = e.target.closest('.spa-log-delete-btn');
                if (!btn) return;
                var li    = btn.closest('.spa-log-item');
                var logId = li ? li.dataset.logId : null;
                if (!logId) return;

                if (!confirm('Delete this log entry? This cannot be undone.')) return;

                btn.disabled    = true;
                btn.textContent = '…';

                var fd = new FormData();
                fd.append('action', 'spa_delete_delivery_log');
                fd.append('nonce', cfg('deleteNonce'));
                fd.append('log_id', logId);

                fetch(cfg('ajaxUrl'), { method: 'POST', body: fd })
                    .then(function (res) { return res.json(); })
                    .then(function (response) {
                        if (response.success) {
                            var badge = accordion.querySelector('.spa-accordion-header .spa-badge');
                            if (badge) {
                                var count = parseInt(badge.textContent, 10);
                                if (!isNaN(count) && count > 0) badge.textContent = String(count - 1);
                            }
                            fetchLogs();
                            document.dispatchEvent(new CustomEvent('spa:log-deleted'));
                        } else {
                            btn.disabled    = false;
                            btn.textContent = 'Delete';
                        }
                    })
                    .catch(function () {
                        btn.disabled    = false;
                        btn.textContent = 'Delete';
                    });
            });

            // Mark stale if the other accordion deletes an entry while closed.
            document.addEventListener('spa:log-deleted', function () {
                dirty = true;
            });

            // ── Core fetch ────────────────────────────────────────────────────
            // Monotonic sequence: concurrent requests (rapid filter changes,
            // slow searches) can resolve out of order, and a stale response
            // must never overwrite a newer one.
            var fetchSeq = 0;

            function fetchLogs() {
                var seq = ++fetchSeq;

                list.innerHTML         = '<li class="spa-empty-msg">Loading…</li>';
                paginationEl.innerHTML = '';

                var fd = new FormData();
                fd.append('action', 'spa_get_delivery_logs');
                fd.append('nonce', cfg('logsNonce'));
                fd.append('page', state.page);
                fd.append('per_page', state.perPage);
                fd.append('status', status);
                fd.append('search', state.search);
                fd.append('filter_year', state.year);
                fd.append('filter_month', state.month);
                fd.append('endpoint', state.endpoint);

                fetch(cfg('ajaxUrl'), { method: 'POST', body: fd })
                    .then(function (res) { return res.json(); })
                    .then(function (resp) {
                        if (seq !== fetchSeq) {
                            return; // A newer request superseded this one.
                        }
                        if (!resp.success) {
                            list.innerHTML = '<li class="spa-empty-msg">Failed to load the delivery log.</li>';
                            return;
                        }
                        var data = resp.data;

                        if (!initialized) {
                            updateFilterOptions(controls, data.years || [], data.months || [], data.endpoints || []);
                            initialized = true;
                        } else {
                            updateEndpointOptions(controls, data.endpoints || []);
                        }
                        dirty = false;

                        list.innerHTML = data.html !== ''
                            ? data.html
                            : '<li class="spa-empty-msg">' +
                              (status === 'error' ? 'No failed deliveries recorded.' : 'No successful deliveries recorded yet.') +
                              '</li>';

                        renderPagination(paginationEl, data.currentPage, data.totalPages, data.total, state.perPage, function (p) {
                            state.page = p;
                            fetchLogs();
                        });
                    })
                    .catch(function () {
                        if (seq === fetchSeq) {
                            list.innerHTML = '<li class="spa-empty-msg">Failed to load the delivery log.</li>';
                        }
                    });
            }
        });
    }

    /**
     * Populates the year, month, and endpoint filter dropdowns.
     *
     * @param {HTMLElement} controls
     * @param {string[]}    years
     * @param {string[]}    months
     * @param {string[]}    endpoints
     */
    function updateFilterOptions(controls, years, months, endpoints) {
        var MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June',
                           'July', 'August', 'September', 'October', 'November', 'December'];

        var yearSelect  = controls.querySelector('.spa-filter-year');
        var monthSelect = controls.querySelector('.spa-filter-month');

        yearSelect.innerHTML = '<option value="">All Years</option>';
        years.forEach(function (y) {
            yearSelect.innerHTML += '<option value="' + escapeHtml(y) + '">' + escapeHtml(y) + '</option>';
        });

        monthSelect.innerHTML = '<option value="">All Months</option>';
        months.forEach(function (m) {
            var name = MONTH_NAMES[parseInt(m, 10) - 1] || m;
            monthSelect.innerHTML += '<option value="' + escapeHtml(m) + '">' + escapeHtml(name) + '</option>';
        });

        updateEndpointOptions(controls, endpoints);
    }

    /**
     * Refreshes just the endpoint select options, preserving the selection.
     * The filter stays hidden until more than one endpoint has log entries.
     *
     * @param {HTMLElement} controls
     * @param {string[]}    endpoints
     */
    function updateEndpointOptions(controls, endpoints) {
        var endpointSelect = controls.querySelector('.spa-filter-endpoint');
        if (!endpointSelect) return;

        var currentVal = endpointSelect.value;

        endpointSelect.innerHTML = '<option value="">All Endpoints</option>';
        endpoints.forEach(function (url) {
            var opt = document.createElement('option');
            opt.value       = url;
            opt.textContent = url;
            if (url === currentVal) opt.selected = true;
            endpointSelect.appendChild(opt);
        });

        endpointSelect.parentElement.style.display = endpoints.length > 1 ? '' : 'none';
    }

    /**
     * Returns the HTML string for the controls bar. Year/month/endpoint
     * options start empty; updateFilterOptions() fills them.
     *
     * @returns {string}
     */
    function buildControlsHtml() {
        return '<div class="spa-acc-filters">' +
                   '<select class="spa-filter-year"><option value="">All Years</option></select>' +
                   '<select class="spa-filter-month"><option value="">All Months</option></select>' +
                   '<span style="display:none"><select class="spa-filter-endpoint"><option value="">All Endpoints</option></select></span>' +
                   '<div class="spa-acc-search">' +
                       '<input type="text" class="spa-search-input" placeholder="Search payload…" />' +
                       '<button type="button" class="spa-search-clear" aria-label="Clear search">✕</button>' +
                   '</div>' +
               '</div>' +
               '<div class="spa-acc-perpage">' +
                   '<label>Per page: <select class="spa-per-page">' +
                       '<option value="5">5</option>' +
                       '<option value="10" selected>10</option>' +
                       '<option value="25">25</option>' +
                       '<option value="50">50</option>' +
                       '<option value="100">100</option>' +
                   '</select></label>' +
               '</div>';
    }

    /**
     * Renders the pagination bar into the given container element.
     *
     * @param {HTMLElement} container
     * @param {number}      currentPage
     * @param {number}      totalPages
     * @param {number}      totalItems
     * @param {number}      perPage
     * @param {function}    onPageChange Called with the new page number.
     */
    function renderPagination(container, currentPage, totalPages, totalItems, perPage, onPageChange) {
        if (totalItems === 0) {
            container.innerHTML = '<span class="spa-page-info">No results match the selected filter.</span>';
            return;
        }

        var start = (currentPage - 1) * perPage + 1;
        var end   = Math.min(currentPage * perPage, totalItems);

        var html = '<span class="spa-page-info">Showing ' + start + '–' + end + ' of ' + totalItems + '</span>';

        if (totalPages > 1) {
            html += '<div class="spa-page-buttons">';

            if (currentPage > 1) {
                html += '<button class="spa-page-btn" data-page="' + (currentPage - 1) + '" aria-label="Previous page">&#8249;</button>';
            }

            getPageNumbers(currentPage, totalPages).forEach(function (p) {
                if (p === '...') {
                    html += '<span class="spa-page-ellipsis">&#8230;</span>';
                } else {
                    var activeClass = p === currentPage ? ' spa-page-btn-active' : '';
                    html += '<button class="spa-page-btn' + activeClass + '" data-page="' + p + '" aria-label="Page ' + p + '">' + p + '</button>';
                }
            });

            if (currentPage < totalPages) {
                html += '<button class="spa-page-btn" data-page="' + (currentPage + 1) + '" aria-label="Next page">&#8250;</button>';
            }

            html += '</div>';
        }

        container.innerHTML = html;

        container.querySelectorAll('.spa-page-btn[data-page]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                onPageChange(parseInt(this.dataset.page, 10));
            });
        });
    }

    /**
     * Returns an array of page numbers (and '...' sentinels) for a windowed
     * page selector. Always shows first, last, and up to two neighbours of
     * the current page; inserts '...' for gaps larger than one.
     *
     * @param {number} currentPage
     * @param {number} totalPages
     * @returns {Array<number|string>}
     */
    function getPageNumbers(currentPage, totalPages) {
        if (totalPages <= 7) {
            return Array.from({ length: totalPages }, function (_, i) { return i + 1; });
        }

        var pages = [1];

        if (currentPage > 3) pages.push('...');

        var rangeStart = Math.max(2, currentPage - 1);
        var rangeEnd   = Math.min(totalPages - 1, currentPage + 1);

        for (var i = rangeStart; i <= rangeEnd; i++) {
            pages.push(i);
        }

        if (currentPage < totalPages - 2) pages.push('...');

        pages.push(totalPages);

        return pages;
    }

    // ── Deliveries API card ───────────────────────────────────────────────────

    /**
     * Wires up the Deliveries API toggle switch, copy-key button, and
     * regenerate-key button.
     *
     * The server stores only a hash of the key, so a raw key arrives at most
     * once — from the generate/toggle-on response. revealKey() swaps the
     * masked placeholder for that value and unhides Copy; on the next page
     * load the mask is back and the key is gone for good.
     */
    function initApiCard() {
        var toggle   = document.getElementById('spa-delivery-api-toggle');
        var label    = document.getElementById('spa-api-toggle-label');
        var section  = document.getElementById('spa-api-key-section');
        var keyValue = document.getElementById('spa-api-key-value');
        var copyBtn  = document.querySelector('.spa-copy-key-btn');

        if (!toggle) return;

        /** Displays a freshly generated raw key (the one time it exists). */
        function revealKey(key) {
            if (!keyValue || !key) return;
            keyValue.textContent = key;
            keyValue.removeAttribute('data-masked');
            if (copyBtn) copyBtn.hidden = false;
        }

        toggle.addEventListener('change', function () {
            var active = toggle.checked;
            toggle.disabled = true;

            var fd = new FormData();
            fd.append('action', 'spa_toggle_delivery_api');
            fd.append('nonce', cfg('apiToggleNonce'));
            fd.append('active', active ? '1' : '0');

            fetch(cfg('ajaxUrl'), { method: 'POST', body: fd })
                .then(function (res) { return res.json(); })
                .then(function (resp) {
                    toggle.disabled = false;
                    if (resp.success) {
                        if (label)   label.textContent = resp.data.active ? 'Active' : 'Inactive';
                        if (section) section.hidden    = !resp.data.active;
                        revealKey(resp.data.key);
                    } else {
                        toggle.checked = !active; // revert on failure
                    }
                })
                .catch(function () {
                    toggle.disabled = false;
                    toggle.checked  = !active;
                });
        });

        if (copyBtn && keyValue) {
            copyBtn.addEventListener('click', function () {
                if (keyValue.hasAttribute('data-masked')) return;
                var key = keyValue.textContent.trim();
                if (!key) return;

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(key).then(function () {
                        var orig = copyBtn.textContent;
                        copyBtn.textContent = 'Copied!';
                        setTimeout(function () { copyBtn.textContent = orig; }, 2000);
                    }).catch(function () { fallbackCopy(key, copyBtn); });
                } else {
                    fallbackCopy(key, copyBtn);
                }
            });
        }

        var regenBtn = document.querySelector('.spa-regen-key-btn');
        if (regenBtn) {
            regenBtn.addEventListener('click', function () {
                if (!confirm('Regenerate the API key? Any existing integrations using the current key will stop working until updated. The new key is shown only once — copy it right away.')) return;

                regenBtn.disabled = true;

                var fd = new FormData();
                fd.append('action', 'spa_regen_delivery_api_key');
                fd.append('nonce', cfg('apiRegenNonce'));

                fetch(cfg('ajaxUrl'), { method: 'POST', body: fd })
                    .then(function (res) { return res.json(); })
                    .then(function (resp) {
                        regenBtn.disabled = false;
                        if (resp.success) {
                            revealKey(resp.data.key);
                        }
                    })
                    .catch(function () {
                        regenBtn.disabled = false;
                    });
            });
        }
    }

    /**
     * Fallback clipboard copy using a temporary textarea.
     *
     * @param {string}      text
     * @param {HTMLElement} btn Button whose label is temporarily replaced.
     */
    function fallbackCopy(text, btn) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0;';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try {
            document.execCommand('copy');
            var orig = btn.textContent;
            btn.textContent = 'Copied!';
            setTimeout(function () { btn.textContent = orig; }, 2000);
        } catch (_) {}
        document.body.removeChild(ta);
    }

    // ── Boot ─────────────────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', function () {
        initAccordions();
        initLogLists();
        initApiCard();
    });

})();
