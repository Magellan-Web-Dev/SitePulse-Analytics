/**
 * SitePulse Analytics — frontend tracker.
 *
 * Dependency-free visitor interaction tracker. Reads its configuration from
 * window.SitePulseConfig (printed by ScriptLoader immediately before this
 * script), batches events in memory, and delivers them to the plugin's REST
 * endpoint via fetch (or navigator.sendBeacon on page exit). Batches that
 * fail with a network error or a 5xx response are re-queued (bounded) and
 * retried on the next flush.
 *
 * Tracked interactions (each individually toggleable in plugin settings):
 *  - pageview     : one event per page load
 *  - click        : links, buttons, and elements with role="button"
 *  - form_submit  : native form submit events, captured before any handler
 *                   can preventDefault — note these are submission *attempts*
 *  - hover        : pointer resting on an interactive element or image for the
 *                   configured dwell time (once per element per page view)
 *  - scroll_depth : 25 / 50 / 75 / 100% scroll milestones (once each per page
 *                   view; checked once on load so short pages record 100%)
 *
 * Privacy: no cookies are set. The session identifier lives in localStorage
 * and rotates after 30 minutes of inactivity, so it groups one visit without
 * becoming a persistent user ID. Tracked URLs are canonicalized to
 * origin + path (utm_source/medium/campaign travel as separate fields);
 * referrers and click/form destinations are stripped of query strings and
 * fragments. Requests are sent without credentials, and visitors sending
 * Do Not Track / Global Privacy Control signals are skipped entirely when
 * the site has enabled that option.
 */
(function () {
    'use strict';

    var config = window.SitePulseConfig;
    if (!config || !config.endpoint || !config.events) {
        return;
    }

    // Honor browser privacy signals when the site owner opted in.
    if (config.respectDnt && (
        navigator.doNotTrack === '1' ||
        window.doNotTrack === '1' ||
        navigator.globalPrivacyControl === true
    )) {
        return;
    }

    var MAX_BATCH = config.maxBatch || 20;
    var MAX_QUEUE = 100;
    var FLUSH_INTERVAL = config.flushIntervalMs || 5000;
    var HOVER_DWELL = config.hoverDwellMs || 800;
    var SESSION_IDLE_MS = 30 * 60 * 1000;
    var INTERACTIVE = 'a, button, input[type="button"], input[type="submit"], [role="button"]';

    var queue = [];
    var PAGE_URL = location.origin + location.pathname;
    var REFERRER = normalizedReferrer();
    var CAMPAIGN = readCampaign();

    /* ------------------------------------------------------------------ *
     *  Session identity — 30-minute inactivity window, cookie-free
     * ------------------------------------------------------------------ */

    var sessionStore = pickStore();
    var memorySession = null;

    /** Returns the first usable storage, preferring localStorage so the
     *  session spans tabs; sessionStorage keeps it per-tab; null falls back
     *  to an in-memory id for this page view only. */
    function pickStore() {
        var candidates = ['localStorage', 'sessionStorage'];
        for (var i = 0; i < candidates.length; i++) {
            try {
                var store = window[candidates[i]];
                store.setItem('spa_probe', '1');
                store.removeItem('spa_probe');
                return store;
            } catch (e) {
                // Blocked or full — try the next one.
            }
        }
        return null;
    }

    /**
     * Returns the current session id, rotating it when the visitor has been
     * inactive for 30+ minutes, and refreshes the activity timestamp — so the
     * session extends as long as events keep occurring.
     */
    function sessionId() {
        var now = Date.now();
        var id = null;

        if (sessionStore) {
            try {
                var raw = sessionStore.getItem('spa_session');
                if (raw) {
                    var parts = raw.split('.');
                    if (parts.length === 2 && now - parseInt(parts[1], 10) < SESSION_IDLE_MS) {
                        id = parts[0];
                    }
                }
            } catch (e) {
                id = null;
            }
        } else {
            id = memorySession;
        }

        if (!id) {
            id = randomHex(32);
        }

        if (sessionStore) {
            try {
                sessionStore.setItem('spa_session', id + '.' + now);
            } catch (e) {
                // Storage full or blocked mid-session; keep going in memory.
            }
        }
        memorySession = id;

        return id;
    }

    /** Generates a random lowercase hex string of the given length. */
    function randomHex(length) {
        var out = '';
        if (window.crypto && window.crypto.getRandomValues) {
            var bytes = new Uint8Array(length / 2);
            window.crypto.getRandomValues(bytes);
            for (var i = 0; i < bytes.length; i++) {
                out += ('0' + bytes[i].toString(16)).slice(-2);
            }
        } else {
            while (out.length < length) {
                out += Math.floor(Math.random() * 16).toString(16);
            }
        }
        return out;
    }

    /* ------------------------------------------------------------------ *
     *  URL hygiene — canonical URLs, separate campaign fields
     * ------------------------------------------------------------------ */

    /** Campaign parameters from the landing URL, as separate fields attached
     *  to the pageview event (the tracked URL itself carries no query data). */
    function readCampaign() {
        var out = {};
        try {
            var params = new URLSearchParams(location.search);
            var keys = ['utm_source', 'utm_medium', 'utm_campaign'];
            for (var i = 0; i < keys.length; i++) {
                var value = params.get(keys[i]);
                if (value) {
                    out[keys[i]] = value.slice(0, 190);
                }
            }
        } catch (e) {
            // URLSearchParams unavailable — no campaign attribution.
        }
        return out;
    }

    /** Referrer reduced to origin + path; '' when absent or unparsable. */
    function normalizedReferrer() {
        if (!document.referrer) {
            return '';
        }
        try {
            var ref = new URL(document.referrer);
            return ref.origin + ref.pathname;
        } catch (e) {
            return '';
        }
    }

    /** Destination URL with query string and fragment removed (they can carry
     *  tokens or emails); mailto:/tel: destinations are kept whole. */
    function cleanTarget(url) {
        if (!url) {
            return '';
        }
        if (/^(mailto:|tel:)/i.test(url)) {
            return String(url).slice(0, 255);
        }
        return String(url).split('#')[0].split('?')[0];
    }

    /* ------------------------------------------------------------------ *
     *  Event queue and delivery
     * ------------------------------------------------------------------ */

    /** Collapses whitespace and truncates a label to a storable length. */
    function cleanLabel(text) {
        return (text || '').replace(/\s+/g, ' ').trim().slice(0, 120);
    }

    /** Best human-readable label for an element: aria-label > text/value > id. */
    function labelFor(el) {
        return cleanLabel(el.getAttribute('aria-label'))
            || cleanLabel(el.innerText || el.value)
            || cleanLabel(el.id);
    }

    /** True when the element lives inside the WP admin bar (never tracked). */
    function inAdminBar(el) {
        return !!(el.closest && el.closest('#wpadminbar'));
    }

    /**
     * Queues one event if its type is enabled, flushing when the batch is
     * full. Page context and the session id are attached here so callers only
     * supply the event-specific fields.
     */
    function track(type, data) {
        if (!config.events[type]) {
            return;
        }

        var event = data || {};
        event.type = type;
        event.page_url = PAGE_URL;
        event.page_title = document.title || '';
        event.referrer = REFERRER;
        event.session_id = sessionId();

        queue.push(event);

        if (queue.length >= MAX_BATCH) {
            flush(false);
        }
    }

    /** Puts a failed batch back at the front of the queue, bounded so a dead
     *  endpoint can never grow memory without limit (oldest events win). */
    function requeue(batch) {
        queue = batch.concat(queue).slice(0, MAX_QUEUE);
    }

    /**
     * Sends every queued event to the REST endpoint as one JSON batch.
     *
     * On page exit sendBeacon is preferred because it survives unload; when
     * it reports failure (or is unavailable) a keepalive fetch is attempted.
     * Batches that fail with a network error or 5xx are re-queued for the
     * next flush; 4xx responses are dropped (retrying cannot fix them).
     */
    function flush(exiting) {
        if (queue.length === 0) {
            return;
        }

        var batch = queue.splice(0, queue.length);
        var body = JSON.stringify({ events: batch });

        if (exiting && navigator.sendBeacon) {
            var accepted = false;
            try {
                accepted = navigator.sendBeacon(
                    config.endpoint,
                    new Blob([body], { type: 'application/json' })
                );
            } catch (e) {
                accepted = false;
            }
            if (accepted) {
                return;
            }
            // Beacon refused the payload — fall through to a keepalive fetch.
        }

        try {
            fetch(config.endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: body,
                credentials: 'omit',
                keepalive: true
            }).then(function (response) {
                if (!response.ok && response.status >= 500) {
                    requeue(batch);
                }
            }).catch(function () {
                requeue(batch);
            });
        } catch (e) {
            requeue(batch);
        }
    }

    /* ------------------------------------------------------------------ *
     *  Page views — carry the landing URL's campaign attribution
     * ------------------------------------------------------------------ */

    track('pageview', CAMPAIGN);

    /* ------------------------------------------------------------------ *
     *  Clicks — links, buttons, and role="button" elements
     * ------------------------------------------------------------------ */

    document.addEventListener('click', function (e) {
        var el = e.target && e.target.closest ? e.target.closest(INTERACTIVE) : null;
        if (!el || inAdminBar(el)) {
            return;
        }

        track('click', {
            element_tag: el.tagName.toLowerCase(),
            element_label: labelFor(el),
            target_url: cleanTarget(el.href)
        });

        // A link click may navigate away immediately — get the batch out now.
        if (el.href) {
            flush(true);
        }
    }, true);

    /* ------------------------------------------------------------------ *
     *  Form submissions — captured before any handler can preventDefault.
     *  Recorded at submit time, so these are attempts, not confirmed
     *  successes (client validation or the server may still reject them).
     * ------------------------------------------------------------------ */

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM' || inAdminBar(form)) {
            return;
        }

        track('form_submit', {
            element_tag: 'form',
            element_label: cleanLabel(form.getAttribute('name') || form.id || form.getAttribute('aria-label')) || 'form',
            target_url: cleanTarget(form.getAttribute('action'))
        });

        // The submit may navigate away — get the batch out now.
        flush(true);
    }, true);

    /* ------------------------------------------------------------------ *
     *  Hovers — pointer resting on an element for the dwell threshold
     * ------------------------------------------------------------------ */

    var hoverTracked = (typeof WeakSet === 'function') ? new WeakSet() : null;
    var hoverTimer = null;
    var hoverEl = null;

    document.addEventListener('mouseover', function (e) {
        var el = e.target && e.target.closest
            ? e.target.closest(INTERACTIVE + ', img, [data-spa-hover]')
            : null;

        if (!el || el === hoverEl || inAdminBar(el)) {
            return;
        }
        if (hoverTracked && hoverTracked.has(el)) {
            return;
        }

        clearTimeout(hoverTimer);
        hoverEl = el;

        hoverTimer = setTimeout(function () {
            if (hoverTracked) {
                hoverTracked.add(el);
            }

            track('hover', {
                element_tag: el.tagName.toLowerCase(),
                element_label: labelFor(el) || cleanLabel(el.src || ''),
                target_url: cleanTarget(el.href),
                event_value: String(HOVER_DWELL)
            });
        }, HOVER_DWELL);
    }, true);

    document.addEventListener('mouseout', function (e) {
        if (!hoverEl) {
            return;
        }

        // Only cancel when the pointer truly left the tracked element (not
        // when it moved between the element's own children).
        var stillInside = e.relatedTarget && hoverEl.contains(e.relatedTarget);
        if (!stillInside && (e.target === hoverEl || hoverEl.contains(e.target))) {
            clearTimeout(hoverTimer);
            hoverEl = null;
        }
    }, true);

    /* ------------------------------------------------------------------ *
     *  Scroll depth — 25/50/75/100% milestones, once each per page view
     * ------------------------------------------------------------------ */

    var milestones = [25, 50, 75, 100];
    var reached = {};
    var scrollScheduled = false;

    function checkScrollDepth() {
        scrollScheduled = false;

        var doc = document.documentElement;
        var scrollable = doc.scrollHeight - window.innerHeight;
        var percent = scrollable <= 0
            ? 100
            : Math.round((window.scrollY || doc.scrollTop || 0) / scrollable * 100);

        for (var i = 0; i < milestones.length; i++) {
            var mark = milestones[i];
            if (percent >= mark && !reached[mark]) {
                reached[mark] = true;
                track('scroll_depth', { event_value: String(mark) });
            }
        }
    }

    window.addEventListener('scroll', function () {
        if (!scrollScheduled) {
            scrollScheduled = true;
            setTimeout(checkScrollDepth, 400);
        }
    }, { passive: true });

    // Check once after layout settles: short pages with nothing to scroll
    // record 100% immediately, and a browser-restored scroll position is
    // captured even if the visitor never scrolls again.
    setTimeout(checkScrollDepth, 800);

    /* ------------------------------------------------------------------ *
     *  Delivery — periodic flush plus a final beacon on page exit
     * ------------------------------------------------------------------ */

    setInterval(function () {
        flush(false);
    }, FLUSH_INTERVAL);

    window.addEventListener('pagehide', function () {
        flush(true);
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            flush(true);
        }
    });
})();
