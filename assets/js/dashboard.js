/**
 * SitePulse Analytics — dashboard page.
 *
 * Progressive enhancements for the Daily Page Views chart and the report
 * panels. Everything here layers on top of markup that already works without
 * JavaScript: the chart's "View data table" fallback and per-bar accessible
 * labels are rendered server-side, and the report sections are native
 * <details> elements.
 *
 * Chart: a visual tooltip (aria-hidden — screen readers get the same data
 * from each bar's label) driven by mouse, keyboard, and touch, plus roving
 * focus so the chart occupies a single Tab stop. Arrow keys move between
 * days, Home/End jump to the first/latest day, Page Up/Down jump a week, and
 * Escape dismisses the tooltip without losing focus.
 *
 * Panels: "Expand all" / "Collapse all" controls (revealed only when this
 * script runs, disabled when they would have no effect) and per-panel
 * open/closed state persisted in sessionStorage — panel ids and booleans
 * only, no analytics or user data — so the layout survives period changes
 * within the browsing session.
 *
 * Print: the "Print / Save as PDF" button calls window.print(); beforeprint
 * expands every panel (and the chart's data table) so the printed report is
 * complete, and afterprint restores the previous states. The print layout
 * itself is pure CSS (assets/css/dashboard.css).
 */
(function () {
    'use strict';

    var PANEL_STORE_KEY = 'spa-dash-panels';
    var WEEK_JUMP = 7;

    // ── Chart ─────────────────────────────────────────────────────────────────

    function initChart() {
        document.querySelectorAll('.spa-chart-plot').forEach(function (plot, plotIndex) {
            var group    = plot.querySelector('.spa-chart-cols');
            var scroller = plot.closest('.spa-chart-scroll');
            var cols     = Array.prototype.slice.call(plot.querySelectorAll('.spa-chart-col'));
            if (!group || !cols.length) {
                return;
            }

            // ── Tooltip (single instance per chart) ───────────────────────────
            var tip = document.createElement('div');
            tip.className = 'spa-chart-tip';
            tip.setAttribute('aria-hidden', 'true');
            tip.hidden = true;
            plot.appendChild(tip);

            var activeBtn = null;

            function position(btn) {
                var plotRect = plot.getBoundingClientRect();
                var btnRect  = btn.getBoundingClientRect();
                var half = tip.offsetWidth / 2;

                var minX = half;
                var maxX = plotRect.width - half;

                // Clamp to the scroller's visible window too, so a bar near a
                // scrolled edge never yields a tooltip that is clipped by the
                // viewport or hidden under the sticky Y-axis.
                if (scroller) {
                    var scRect = scroller.getBoundingClientRect();
                    var yaxis  = scroller.querySelector('.spa-chart-yaxis');
                    var left   = scRect.left + (yaxis ? yaxis.getBoundingClientRect().width : 0);
                    minX = Math.max(minX, left - plotRect.left + half);
                    maxX = Math.min(maxX, scRect.right - plotRect.left - half);
                }
                if (maxX < minX) {
                    maxX = minX;
                }

                var x = btnRect.left - plotRect.left + btnRect.width / 2;
                tip.style.left = Math.max(minX, Math.min(maxX, x)) + 'px';
            }

            function show(btn) {
                if (activeBtn === btn && !tip.hidden) {
                    position(btn); // Same day — just re-clamp, no rebuild flicker.
                    return;
                }
                activeBtn = btn;

                var count  = btn.getAttribute('data-count') || '0';
                var suffix = count === '1' ? ' page view' : ' page views';
                if (btn.classList.contains('is-today')) {
                    suffix += ' — today, still collecting';
                }

                tip.textContent = '';

                var dateEl = document.createElement('span');
                dateEl.className = 'spa-chart-tip-date';
                dateEl.textContent = btn.getAttribute('data-date') || '';

                var countEl = document.createElement('span');
                countEl.className = 'spa-chart-tip-count';
                countEl.textContent = count + suffix;

                tip.appendChild(dateEl);
                tip.appendChild(countEl);
                tip.hidden = false;
                position(btn);
            }

            function hide() {
                tip.hidden = true;
                activeBtn = null;
            }

            // ── Roving focus: one Tab stop for the whole chart ────────────────
            cols.forEach(function (col, i) {
                col.setAttribute('tabindex', i === cols.length - 1 ? '0' : '-1');
            });

            var instructions = document.createElement('p');
            instructions.className = 'screen-reader-text';
            instructions.id = 'spa-chart-keys-' + plotIndex;
            instructions.textContent = 'Chart navigation: use the Left and Right Arrow keys to move between days, '
                + 'Home and End for the first and latest day, and Page Up or Page Down to jump a week. '
                + 'Press Escape to dismiss the tooltip.';
            plot.appendChild(instructions);
            group.setAttribute('aria-describedby', instructions.id);

            function setTabStop(btn) {
                var current = group.querySelector('.spa-chart-col[tabindex="0"]');
                if (current && current !== btn) {
                    current.setAttribute('tabindex', '-1');
                }
                btn.setAttribute('tabindex', '0');
            }

            function focusCol(index) {
                index = Math.max(0, Math.min(cols.length - 1, index));
                var target = cols[index];
                setTabStop(target);
                target.focus();
                if (target.scrollIntoView) {
                    // Center the day horizontally so its tooltip has room on
                    // both sides; block "nearest" avoids vertical page jumps.
                    target.scrollIntoView({ block: 'nearest', inline: 'center' });
                }
                show(target);
            }

            plot.addEventListener('keydown', function (e) {
                var btn = e.target.closest('.spa-chart-col');
                if (!btn) {
                    return;
                }
                var i = cols.indexOf(btn);

                switch (e.key) {
                    case 'ArrowLeft':
                        e.preventDefault();
                        focusCol(i - 1);
                        break;
                    case 'ArrowRight':
                        e.preventDefault();
                        focusCol(i + 1);
                        break;
                    case 'Home':
                        e.preventDefault();
                        focusCol(0);
                        break;
                    case 'End':
                        e.preventDefault();
                        focusCol(cols.length - 1);
                        break;
                    case 'PageUp':
                        e.preventDefault();
                        focusCol(i - WEEK_JUMP);
                        break;
                    case 'PageDown':
                        e.preventDefault();
                        focusCol(i + WEEK_JUMP);
                        break;
                    case 'Escape':
                        hide(); // Dismiss the tooltip only; focus stays on the day.
                        break;
                }
            });

            // ── Pointer ───────────────────────────────────────────────────────
            // relatedTarget checks keep the tooltip steady while the pointer
            // moves between a button and its child bar span, and hand over
            // cleanly (without a hide/show flicker) when it crosses to the
            // neighbouring day.
            plot.addEventListener('mouseover', function (e) {
                var btn = e.target.closest('.spa-chart-col');
                if (btn && !(e.relatedTarget && btn.contains(e.relatedTarget))) {
                    show(btn);
                }
            });
            plot.addEventListener('mouseout', function (e) {
                var btn = e.target.closest('.spa-chart-col');
                if (!btn) {
                    return;
                }
                var to = e.relatedTarget;
                if (to && (btn.contains(to) || (to.closest && to.closest('.spa-chart-col')))) {
                    return; // Still inside this day, or handed over to another day.
                }
                if (activeBtn === btn) {
                    hide();
                }
            });

            // ── Keyboard focus ────────────────────────────────────────────────
            plot.addEventListener('focusin', function (e) {
                var btn = e.target.closest('.spa-chart-col');
                if (btn) {
                    setTabStop(btn); // Clicks/taps focus a day too — keep the rover in sync.
                    show(btn);
                }
            });
            plot.addEventListener('focusout', function (e) {
                if (!(e.relatedTarget && plot.contains(e.relatedTarget))) {
                    hide();
                }
            });

            // ── Touch / click ─────────────────────────────────────────────────
            // A tap selects and shows the day; on touch (no hover available), a
            // second tap on the already-active day dismisses the tooltip.
            plot.addEventListener('click', function (e) {
                var btn = e.target.closest('.spa-chart-col');
                if (!btn) {
                    return;
                }
                var isTouch = e.pointerType === 'touch'
                    || (window.matchMedia && window.matchMedia('(hover: none)').matches);

                if (isTouch && activeBtn === btn && !tip.hidden && btn.hasAttribute('data-spa-shown')) {
                    btn.removeAttribute('data-spa-shown');
                    hide();
                    return;
                }
                cols.forEach(function (c) { c.removeAttribute('data-spa-shown'); });
                btn.setAttribute('data-spa-shown', '1');
                setTabStop(btn);
                show(btn);
            });

            // A tap or click outside the chart dismisses the tooltip.
            document.addEventListener('pointerdown', function (e) {
                if (!plot.contains(e.target)) {
                    cols.forEach(function (c) { c.removeAttribute('data-spa-shown'); });
                    hide();
                }
            });

            // The tooltip scrolls with its bar (both live inside the plot),
            // but its clamped position depends on the visible window — keep
            // it glued to the bar while the plot scrolls, and dismiss it once
            // the bar itself leaves the visible area: a tooltip pinned to the
            // edge would appear to describe whichever bar sits there instead.
            if (scroller) {
                scroller.addEventListener('scroll', function () {
                    if (!activeBtn || tip.hidden) {
                        return;
                    }
                    var scRect = scroller.getBoundingClientRect();
                    var yaxis  = scroller.querySelector('.spa-chart-yaxis');
                    var left   = scRect.left + (yaxis ? yaxis.getBoundingClientRect().width : 0);
                    var btnRect = activeBtn.getBoundingClientRect();

                    if (btnRect.right < left || btnRect.left > scRect.right) {
                        hide();
                    } else {
                        position(activeBtn);
                    }
                }, { passive: true });
            }

            // Bar geometry changes on resize; dismiss rather than drift.
            window.addEventListener('resize', hide);
        });
    }

    // ── Report panels ─────────────────────────────────────────────────────────

    function readPanelState() {
        try {
            return JSON.parse(sessionStorage.getItem(PANEL_STORE_KEY) || '{}') || {};
        } catch (err) {
            return {};
        }
    }

    function savePanelState(id, open) {
        try {
            var state = readPanelState();
            state[id] = open;
            sessionStorage.setItem(PANEL_STORE_KEY, JSON.stringify(state));
        } catch (err) {
            // Storage unavailable (private mode, quota) — panels still work,
            // they just won't remember their state across period changes.
        }
    }

    function initPanels() {
        var panels = Array.prototype.slice.call(document.querySelectorAll('.spa-dash .spa-panel'));
        if (!panels.length) {
            return;
        }

        var toolbar  = document.querySelector('.spa-dash .spa-panel-toolbar');
        var expand   = toolbar ? toolbar.querySelector('.spa-panels-expand') : null;
        var collapse = toolbar ? toolbar.querySelector('.spa-panels-collapse') : null;

        function updateToolbarButtons() {
            var openCount = panels.filter(function (p) { return p.open; }).length;
            if (expand) {
                expand.disabled = openCount === panels.length;
            }
            if (collapse) {
                collapse.disabled = openCount === 0;
            }
        }

        var stored = readPanelState();

        panels.forEach(function (panel) {
            if (panel.id && Object.prototype.hasOwnProperty.call(stored, panel.id)) {
                panel.open = !!stored[panel.id];
            }
            panel.addEventListener('toggle', function () {
                if (panel.id) {
                    savePanelState(panel.id, panel.open);
                }
                updateToolbarButtons();
            });
        });

        if (toolbar) {
            toolbar.hidden = false; // Buttons only make sense once this script runs.

            var setAll = function (open) {
                panels.forEach(function (panel) {
                    panel.open = open; // Fires each panel's toggle handler, which persists it.
                });
                updateToolbarButtons();
            };

            if (expand) {
                expand.addEventListener('click', function () { setAll(true); });
            }
            if (collapse) {
                collapse.addEventListener('click', function () { setAll(false); });
            }
        }

        updateToolbarButtons();
    }

    // ── Print / Save as PDF ───────────────────────────────────────────────────

    function initPrint() {
        var savedStates = null;

        // beforeprint/afterprint also cover the browser's own File → Print,
        // so the printed report is always fully expanded.
        window.addEventListener('beforeprint', function () {
            if (savedStates) {
                return;
            }
            savedStates = [];
            document.querySelectorAll('.spa-dash details').forEach(function (details) {
                savedStates.push([details, details.open]);
                details.open = true;
            });
        });

        window.addEventListener('afterprint', function () {
            if (!savedStates) {
                return;
            }
            savedStates.forEach(function (pair) {
                pair[0].open = pair[1];
            });
            savedStates = null;
        });

        var printBtn = document.querySelector('.spa-dash .spa-print-btn');
        if (printBtn) {
            printBtn.addEventListener('click', function () {
                window.print();
            });
        }
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', function () {
        initChart();
        initPanels();
        initPrint();
    });
})();
