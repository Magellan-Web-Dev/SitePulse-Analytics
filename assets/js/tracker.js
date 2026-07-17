/**
 * SitePulse Analytics — frontend tracker.
 *
 * Dependency-free visitor interaction tracker. Reads its configuration from
 * window.SitePulseConfig (printed by ScriptLoader immediately before this
 * script), batches events in memory, and delivers them to the plugin's REST
 * endpoint via fetch (or navigator.sendBeacon on page exit). Every batch is
 * PERSISTED to a bounded sessionStorage map (keyed by batch id; an in-memory
 * map stands in when sessionStorage is unavailable) before it is sent, and
 * removed only when the server acknowledges it (2xx, or a 4xx — other than
 * 429 — that retrying cannot fix) — so a page destroyed before the response
 * arrives leaves the batch behind for the next page in this tab to resend.
 * One batch's success can never discard another's undelivered events.
 * Fetch-delivered batches are therefore at-least-once; the batch id is sent
 * in the request body, and the server stores rows under a unique
 * (batch id, ordinal) key, so a replayed batch whose response was lost is
 * deduplicated instead of double-counting. FRESH page-exit batches handed to
 * navigator.sendBeacon are the exception: an accepted hand-off only means
 * the browser queued the request, so those are treated as delivered
 * (best-effort) — keeping every one persisted would resend every exit batch
 * on the next page. But a batch that already survived a failed or
 * unacknowledged send stays persisted even through an accepted hand-off:
 * its loss is exactly what retrying exists to prevent, and the server-side
 * batch-id dedup absorbs the resend if the beacon did land.
 *
 * Tracked interactions (each individually toggleable in plugin settings):
 *  - pageview     : one event per page load
 *  - click        : links, buttons, and elements with role="button"
 *  - form_submit  : native form submit events, captured before any handler
 *                   can preventDefault — note these are submission *attempts*
 *  - form_success : CONFIRMED form submissions — recorded only when the form
 *                   plugin reports that the server accepted the submission
 *                   (Elementor Pro, Contact Form 7, WPForms, Gravity Forms),
 *                   or when custom code dispatches a "spa:conversion" event.
 *                   Each carries a unique conversion id and a snapshot of the
 *                   session's campaign attribution at conversion time.
 *  - hover        : pointer resting on an interactive element or image for the
 *                   configured dwell time (once per element per page view)
 *  - scroll_depth : 25 / 50 / 75 / 100% scroll milestones (once each per page
 *                   view; checked once on load so short pages record 100%)
 *
 * Campaign attribution: all six utm parameters (source/medium/campaign/id/
 * term/content) from a tagged landing URL are stored alongside the session
 * and attached to every event in that session — pageviews and conversions,
 * but also clicks, form attempts, hovers, and scroll milestones, so
 * intermediate funnel steps can be segmented by campaign. Ad-click
 * identifiers (gclid, fbclid, msclkid, …) are recognized too: only the
 * parameter NAME is kept (click_id_type) — the value is a cross-site
 * advertising ID and is never sent — and, when no utm tags are present, the
 * source/medium they imply is filled in (e.g. gclid → google / cpc). The
 * model is last-touch within the session: the most recent tagged landing
 * attributes the visit from that point on; untagged pages inherit it.
 *
 * Untagged acquisition persists too: the referrer the session ENTERED
 * through (e.g. google.com for an organic visit) is stored against the
 * session and sent as session_referrer, so a conversion three pages deep
 * into an organic visit is still classified Organic Search rather than
 * falling back to Direct. Like the campaign, an external re-entry within
 * the session refreshes it (last non-direct touch).
 *
 * Privacy: no cookies are set. The session identifier lives in localStorage
 * and rotates after 30 minutes of inactivity, so it groups one visit without
 * becoming a persistent user ID. Tracked URLs are canonicalized to
 * origin + path (utm parameters travel as separate fields; ad-click
 * identifier values are never sent, only which parameter was present);
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
    var PENDING_KEY = 'spa_pending';

    /** utm parameter names captured from a tagged landing URL. */
    var UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'utm_term', 'utm_content'];

    /** Ad-click identifier parameters → the [source, medium] they imply when
     *  the URL carries no utm tags. Only the parameter NAME is ever sent
     *  (click_id_type); the value is a cross-site advertising identifier and
     *  never leaves the browser. fbclid implies no medium — Facebook adds it
     *  to organic shares too, so paid cannot be assumed. */
    var CLICK_IDS = {
        gclid:     ['google', 'cpc'],
        gbraid:    ['google', 'cpc'],
        wbraid:    ['google', 'cpc'],
        msclkid:   ['bing', 'cpc'],
        ttclid:    ['tiktok', 'paid'],
        twclid:    ['twitter', 'paid'],
        li_fat_id: ['linkedin', 'paid'],
        fbclid:    ['facebook', '']
    };

    /** Every field a stored campaign (and a conversion's snapshot) may carry. */
    var CAMPAIGN_KEYS = UTM_KEYS.concat(['click_id_type']);

    var queue = [];
    var PAGE_URL = location.origin + location.pathname;
    var REFERRER = normalizedReferrer();
    var CAMPAIGN = readCampaign();

    /** True when this page load entered the site from outside: no referrer
     *  (direct / stripped) or a referrer on another origin. */
    var IS_ENTRANCE = REFERRER === '' ||
        (REFERRER !== location.origin && REFERRER.indexOf(location.origin + '/') !== 0);

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

    /**
     * The acquisition attributed to this session — {c: campaign fields,
     * r: entrance referrer} — last-touch within the session.
     *
     * A tagged landing URL wins and is persisted against the session id (the
     * most recent tagged landing re-attributes the session from that point
     * on). An untagged EXTERNAL entrance keeps the session's campaign but
     * refreshes the entrance referrer (last non-direct touch), so organic,
     * social, and referral acquisition survives internal navigation instead
     * of degrading to Direct at conversion time. Untagged internal pageviews
     * inherit whatever the session currently carries.
     */
    function sessionAcquisition(id) {
        var tagged = false;
        for (var i = 0; i < CAMPAIGN_KEYS.length; i++) {
            if (CAMPAIGN[CAMPAIGN_KEYS[i]]) {
                tagged = true;
                break;
            }
        }

        var stored = null;
        if (sessionStore) {
            try {
                stored = JSON.parse(sessionStore.getItem('spa_campaign'));
            } catch (e) {
                stored = null;
            }
        }
        if (!stored || stored.id !== id || typeof stored.c !== 'object' || !stored.c) {
            stored = null;
        }

        var record;
        if (tagged) {
            record = { id: id, c: CAMPAIGN, r: IS_ENTRANCE ? REFERRER : (stored ? String(stored.r || '') : '') };
        } else if (stored && (!IS_ENTRANCE || !REFERRER)) {
            return stored; // Internal navigation or a direct re-entry: inherit as-is.
        } else if (stored) {
            record = { id: id, c: stored.c, r: REFERRER }; // External re-entry refreshes the referrer.
        } else {
            record = { id: id, c: {}, r: IS_ENTRANCE ? REFERRER : '' };
        }

        if (sessionStore) {
            try {
                sessionStore.setItem('spa_campaign', JSON.stringify(record));
            } catch (e) {
                // Storage full or blocked — attribution lasts this page only.
            }
        }
        return record;
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
     *  to pageview and conversion events (the tracked URL itself carries no
     *  query data). */
    function readCampaign() {
        var out = {};
        try {
            var params = new URLSearchParams(location.search);
            for (var i = 0; i < UTM_KEYS.length; i++) {
                var value = params.get(UTM_KEYS[i]);
                if (value) {
                    out[UTM_KEYS[i]] = value.slice(0, 190);
                }
            }
            for (var key in CLICK_IDS) {
                if (CLICK_IDS.hasOwnProperty(key) && params.get(key)) {
                    out.click_id_type = key;
                    if (!out.utm_source) {
                        out.utm_source = CLICK_IDS[key][0];
                        if (!out.utm_medium && CLICK_IDS[key][1]) {
                            out.utm_medium = CLICK_IDS[key][1];
                        }
                    }
                    break;
                }
            }
        } catch (e) {
            // URLSearchParams unavailable — no campaign attribution.
        }
        return out;
    }

    /** This page load's applied acquisition — sessionAcquisition() is run
     *  once per session id so the landing URL's tags (or entrance referrer)
     *  are written into the session exactly once, then kept as a fallback
     *  for when storage becomes unreadable mid-page. */
    var appliedAcquisition = { id: null, record: { c: {}, r: '' } };

    /**
     * The session's current acquisition record. The first event for a given
     * session id applies this page load's landing attribution via
     * sessionAcquisition(); later events RE-READ the stored record instead
     * of trusting a memo — another tab sharing the localStorage session may
     * have re-attributed it (last touch) in the meantime, and a stale memo
     * would keep crediting the old campaign from this tab.
     */
    function currentAcquisition(id) {
        if (appliedAcquisition.id !== id) {
            appliedAcquisition = { id: id, record: sessionAcquisition(id) };
            return appliedAcquisition.record;
        }

        var stored = null;
        if (sessionStore) {
            try {
                stored = JSON.parse(sessionStore.getItem('spa_campaign'));
            } catch (e) {
                stored = null;
            }
        }
        if (stored && stored.id === id && typeof stored.c === 'object' && stored.c) {
            return stored;
        }
        return appliedAcquisition.record;
    }

    /** A fresh copy of the session's current attribution (campaign fields
     *  plus session_referrer), safe to attach to an event (track() adds page
     *  context to the object it is given, so the stored record itself must
     *  never be passed in). */
    function campaignSnapshot() {
        var record = currentAcquisition(sessionId());

        var campaign = record.c || {};
        var out = {};
        for (var i = 0; i < CAMPAIGN_KEYS.length; i++) {
            if (campaign[CAMPAIGN_KEYS[i]]) {
                out[CAMPAIGN_KEYS[i]] = campaign[CAMPAIGN_KEYS[i]];
            }
        }
        if (record.r) {
            out.session_referrer = String(record.r);
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
     * full. Page context, the session id, and the session's attribution
     * snapshot are attached here so callers only supply the event-specific
     * fields — every event type can be segmented by campaign and channel.
     */
    function track(type, data) {
        if (!config.events[type]) {
            return;
        }

        var event = data || {};
        var attribution = campaignSnapshot();
        for (var key in attribution) {
            if (attribution.hasOwnProperty(key) && !event[key]) {
                event[key] = attribution[key];
            }
        }
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

    /* Batches persist in a sessionStorage MAP keyed by batch id BEFORE they
     * are sent, so they survive the page being destroyed before the server's
     * response ever arrives (persist-first, remove-on-acknowledgment). Each
     * entry is removed only when ITS OWN send is acknowledged — a single
     * shared stash cleared on any success would let batch B's success
     * discard batch A's still-undelivered events when two sends overlap.
     * Total stashed events are bounded by MAX_QUEUE (oldest batches dropped
     * first). When sessionStorage is unavailable (blocked, private mode
     * quirks), an in-memory map takes over: retries then only last this
     * page's lifetime, but failed batches are still resent by later flushes
     * instead of being lost the moment the send fails. */

    /** sessionStorage when usable, else null (in-memory pending map instead). */
    var pendingStore = (function () {
        try {
            window.sessionStorage.setItem('spa_probe', '1');
            window.sessionStorage.removeItem('spa_probe');
            return window.sessionStorage;
        } catch (e) {
            return null;
        }
    })();

    /** In-memory pending-batch map, used when sessionStorage is unusable. */
    var memoryPending = {};

    /** @returns {Object<string, Array>} The pending-batch map ({} when unreadable). */
    function readPendingMap() {
        if (!pendingStore) {
            return memoryPending;
        }
        try {
            var map = JSON.parse(pendingStore.getItem(PENDING_KEY));
            return (map && typeof map === 'object' && !Array.isArray(map)) ? map : {};
        } catch (e) {
            return {};
        }
    }

    function writePendingMap(map) {
        if (!pendingStore) {
            memoryPending = map;
            return;
        }
        try {
            if (Object.keys(map).length === 0) {
                pendingStore.removeItem(PENDING_KEY);
            } else {
                pendingStore.setItem(PENDING_KEY, JSON.stringify(map));
            }
        } catch (e) {
            // sessionStorage filled up or got blocked mid-page — fall back to
            // memory from here on so pending batches keep being tracked. The
            // server's batch-id dedup absorbs any overlap with entries that
            // did make it into sessionStorage earlier.
            memoryPending = map;
            pendingStore  = null;
        }
    }

    /** Records a failed batch under its id, dropping oldest batches when the
     *  total stashed events would exceed MAX_QUEUE. */
    function stashBatch(id, events) {
        var map = readPendingMap();
        map[id] = events;

        var ids = Object.keys(map);
        var total = 0;
        for (var i = 0; i < ids.length; i++) {
            total += map[ids[i]].length;
        }
        while (total > MAX_QUEUE && ids.length > 1) {
            var oldest = ids.shift();
            total -= map[oldest].length;
            delete map[oldest];
        }

        writePendingMap(map);
    }

    /** Removes one acknowledged batch — and only that batch — from the map. */
    function unstashBatch(id) {
        var map = readPendingMap();
        if (map[id]) {
            delete map[id];
            writePendingMap(map);
        }
    }

    /** Batch ids in flight right now, so a slow retry isn't sent twice. */
    var inFlight = {};

    /** Batch ids known to have survived a failed or unacknowledged send —
     *  either they failed retryably on THIS page (network error, 5xx, 429,
     *  503) or they were inherited from the pending map (their sender never
     *  saw a response). These stay persisted even through an accepted
     *  page-exit beacon hand-off: the beacon's response is never observable,
     *  and for a batch that already failed once "accepted" must not mean
     *  "delivered" — the server's batch-id dedup absorbs the resend if the
     *  beacon did land. Fresh exit batches keep best-effort hand-off
     *  semantics so ordinary navigation doesn't replay every batch. */
    var retryPending = {};

    /**
     * Sends one batch under its id, persisting it BEFORE the attempt so a
     * page destroyed mid-flight can never lose it. The persisted entry is
     * removed only on acknowledgment: a 2xx response, or a 4xx that retrying
     * cannot fix (429 is rate limiting, not rejection — the batch stays
     * persisted and a later flush retries it). A network error, 5xx, or 429
     * leaves it persisted for a later flush (this page or the next one in
     * this tab) to resend.
     *
     * The batch id travels in the request body: the server keys stored rows
     * by (batch id, event ordinal), so a replay of an already-stored batch —
     * delivered, but its response lost — is deduplicated server-side rather
     * than inflating every count.
     *
     * On page exit sendBeacon is preferred because it survives unload;
     * otherwise a keepalive fetch runs. For a FRESH batch an accepted beacon
     * hand-off is treated as delivered even though the browser has only
     * queued it — beacon delivery is best-effort by nature, and keeping
     * every exit batch persisted would resend all of them on the next page.
     * A batch already marked retry-pending is the exception: it stays
     * persisted through the hand-off (see retryPending above).
     */
    function sendBatch(id, events, exiting) {
        var body = JSON.stringify({ batch_id: id, events: events });

        inFlight[id] = true;
        stashBatch(id, events);

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
                // A batch that already failed once must not be forgotten on
                // a mere hand-off — the beacon's outcome is unobservable, and
                // losing it here is exactly the loss the retry path exists to
                // prevent. It stays persisted; a later flush resends it and
                // the server's batch-id dedup absorbs the overlap if this
                // beacon did land.
                if (!retryPending[id]) {
                    unstashBatch(id);
                }
                delete inFlight[id];
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
                if (response.ok || (response.status >= 400 && response.status < 500 && response.status !== 429)) {
                    unstashBatch(id); // Acknowledged, or unfixable by retry.
                    delete retryPending[id];
                } else {
                    retryPending[id] = true; // 5xx/429 — retryable failure.
                }
                delete inFlight[id];
            }).catch(function () {
                retryPending[id] = true;
                delete inFlight[id]; // Network error — stays persisted.
            });
        } catch (e) {
            retryPending[id] = true;
            delete inFlight[id]; // fetch unavailable/threw — stays persisted.
        }
    }

    /**
     * Sends the queued events as a new batch and resends any stashed batches
     * (each under its original id) — including batches left behind by a
     * previous page in this tab.
     */
    function flush(exiting) {
        var map = readPendingMap();
        for (var id in map) {
            if (Object.prototype.hasOwnProperty.call(map, id) && !inFlight[id]) {
                // Anything still in the pending map at resend time was never
                // acknowledged — mark it so an exit-time beacon hand-off
                // can't be its last trace (see sendBatch()).
                retryPending[id] = true;
                sendBatch(id, map[id], exiting);
            }
        }

        if (queue.length === 0) {
            return;
        }

        // 'b' prefix: purely-numeric keys would be reordered by the JS
        // engine, breaking oldest-first eviction in stashBatch().
        sendBatch('b' + randomHex(12), queue.splice(0, queue.length), exiting);
    }

    /* ------------------------------------------------------------------ *
     *  Page views — carry the session's campaign attribution (attached by
     *  track(), like every other event)
     * ------------------------------------------------------------------ */

    track('pageview', {});

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
     *  Confirmed conversions — recorded only when the form plugin reports
     *  that the SERVER accepted the submission (its success event fires on
     *  the AJAX success response), unlike form_submit attempts above.
     *  Each conversion carries a unique id (event_value) so an at-least-once
     *  redelivery can be deduplicated, plus a snapshot of the session's
     *  campaign attribution at the moment of conversion — the conversion
     *  record is self-contained even days after the tagged landing.
     * ------------------------------------------------------------------ */

    function trackConversion(label) {
        track('form_success', {
            element_tag: 'form',
            element_label: cleanLabel(label) || 'form',
            event_value: 'c' + randomHex(16)
        });
        flush(false);
    }

    /** The form element a plugin's success event fired on, or null. */
    function eventForm(e) {
        return e.target && e.target.tagName === 'FORM' ? e.target : null;
    }

    // Contact Form 7 — native DOM event, fired after the server confirms.
    document.addEventListener('wpcf7mailsent', function (e) {
        var id = e.detail && e.detail.contactFormId;
        trackConversion(id ? 'cf7-' + id : 'cf7');
    });

    // Custom goals: dispatch from your own code when a conversion completes —
    // document.dispatchEvent(new CustomEvent('spa:conversion', {detail: {name: 'appointment_booked'}}))
    document.addEventListener('spa:conversion', function (e) {
        trackConversion((e.detail && e.detail.name) || 'custom');
    });

    // Elementor Pro, WPForms, and Gravity Forms announce success through
    // jQuery events, which plain addEventListener cannot observe. jQuery is
    // present when those plugins run their frontends, but script optimizers
    // can load it AFTER this tracker — so binding is retried at DOM-ready,
    // window load, and a couple of timed fallbacks instead of being checked
    // only once.
    var jQueryBound = false;

    function bindJQueryFormEvents() {
        if (jQueryBound || !window.jQuery) {
            return;
        }
        jQueryBound = true;

        window.jQuery(document).on('submit_success', function (e) {
            var form = eventForm(e);
            trackConversion((form && (form.getAttribute('name') || form.id)) || 'elementor-form');
        });

        window.jQuery(document).on('wpformsAjaxSubmitSuccess', function (e) {
            var form = eventForm(e);
            trackConversion((form && (form.getAttribute('name') || form.id)) || 'wpforms');
        });

        window.jQuery(document).on('gform_confirmation_loaded', function (e, formId) {
            trackConversion('gravity-form-' + formId);
        });
    }

    bindJQueryFormEvents();
    if (!jQueryBound) {
        document.addEventListener('DOMContentLoaded', bindJQueryFormEvents);
        window.addEventListener('load', bindJQueryFormEvents);
        setTimeout(bindJQueryFormEvents, 3000);
        setTimeout(bindJQueryFormEvents, 8000);
    }

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

            // An image src can carry query-string tokens (signed CDN URLs,
            // cache busters) — strip query and fragment before using it as
            // the fallback label, like every other stored URL.
            track('hover', {
                element_tag: el.tagName.toLowerCase(),
                element_label: labelFor(el) || cleanLabel(String(el.src || '').split('#')[0].split('?')[0]),
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
