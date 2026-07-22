/**
 * SitePulse Analytics — frontend tracker.
 *
 * Dependency-free visitor interaction tracker. Reads its configuration from
 * window.SitePulseConfig (printed by ScriptLoader immediately before this
 * script), batches events in memory, and delivers them to the plugin's REST
 * endpoint via fetch (or navigator.sendBeacon on page exit). Every batch is
 * PERSISTED to a bounded sessionStorage store (keyed by batch id; an in-memory
 * store stands in when sessionStorage is unavailable) before it is sent, and
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
 * A failed retryable send (network error, 5xx, or 429) backs off with
 * increasing delay rather than retrying at the same flat interval every
 * time; a 429 additionally pauses every send from this tab for a short
 * window, since it means the server is asking this visitor's traffic to
 * slow down generally, not just reject one batch.
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
 *  - hover        : pointer resting on an interactive element for the
 *                   configured dwell time (once per element per page view);
 *                   add data-spa-hover to opt any element — images included —
 *                   in explicitly
 *  - scroll_depth : 50 / 100% scroll milestones (once each per page view;
 *                   checked once on load so short pages record 100%)
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
 * the session refreshes it (last non-direct touch). A session that entered
 * with no referrer at all (Direct) sends an explicit session_direct marker
 * instead of an absent session_referrer, so the server can tell "verified
 * Direct" apart from "no signal sent" — otherwise mid-session events in a
 * Direct-entrance session would have no way to distinguish themselves from
 * an event that simply never reported its acquisition.
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

    // Retry/backoff timing — internal reliability constants, not exposed as
    // site-owner settings. RETRY_BASE_MS matches the normal flush cadence (a
    // batch's first retry isn't penalized beyond it); RETRY_BASE_MS_429 starts
    // higher because a 429 means the limiter is already saturated. Jitter
    // (see scheduleBackoff()) spreads retries across tabs/sites instead of a
    // synchronized thundering herd when a shared server-side condition (a
    // brief outage) affects many visitors at once.
    var RETRY_BASE_MS = 5000;
    var RETRY_BASE_MS_429 = 30000;
    var RETRY_MAX_MS = 5 * 60 * 1000;

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
     *  plus session_referrer or session_direct), safe to attach to an event
     *  (track() adds page context to the object it is given, so the stored
     *  record itself must never be passed in). */
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
        } else if (Object.keys(out).length === 0) {
            // Untagged session confirmed Direct (no external referrer ever
            // recorded) — an explicit marker, not merely an absent
            // session_referrer, so the server can tell "verified Direct"
            // apart from "no signal sent" (e.g. an old cached tracker).
            out.session_direct = '1';
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
            flush('normal');
        }
    }

    /* Batches persist in a sessionStorage-backed store, keyed by batch id,
     * BEFORE they are sent, so they survive the page being destroyed before
     * the server's response ever arrives (persist-first, remove-on-
     * acknowledgment). Each entry is removed only when ITS OWN send is
     * acknowledged — a single shared store cleared on any success would let
     * batch B's success discard batch A's still-undelivered events when two
     * sends overlap. Total stashed events are bounded by MAX_QUEUE (oldest
     * batches dropped first). When sessionStorage is unavailable (blocked,
     * private mode quirks), an in-memory root takes over: retries then only
     * last this page's lifetime, but failed batches are still resent by
     * later flushes instead of being lost the moment the send fails.
     *
     * The store is a single serialized root — batches (each with its own
     * events plus its own retry attempt count and not-before timestamp) and
     * the tab-wide 429 pause together — not several independently-written
     * pieces. Splitting these across separate keys would let one write
     * succeed while another fails (e.g. quota exceeded in between), leaving
     * a batch persisted with no matching backoff history or a pause that
     * only half-applied; one root means one write settles all of it. */

    /** sessionStorage when usable, else null (in-memory root instead). */
    var pendingStore = (function () {
        try {
            window.sessionStorage.setItem('spa_probe', '1');
            window.sessionStorage.removeItem('spa_probe');
            return window.sessionStorage;
        } catch (e) {
            return null;
        }
    })();

    /** In-memory root, used when sessionStorage is unusable — the same shape
     *  as the persisted root so both paths share one implementation. */
    var memoryRoot = { version: 1, globalNotBefore: 0, batches: {} };

    /**
     * Reads the single serialized root. Migrates the pre-existing bare
     * {id: events[]} shape (an already-open tab from before this format
     * existed) into the current shape on first read, rather than assuming
     * the new shape and reading undefined properties off old data.
     *
     * @returns {{version: number, globalNotBefore: number, batches: Object}}
     */
    function readStore() {
        if (!pendingStore) {
            return memoryRoot;
        }
        try {
            var raw = JSON.parse(pendingStore.getItem(PENDING_KEY));
            if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
                return { version: 1, globalNotBefore: 0, batches: {} };
            }
            if (raw.version === 1 && raw.batches && typeof raw.batches === 'object') {
                return raw;
            }

            var batches = {};
            for (var id in raw) {
                if (Object.prototype.hasOwnProperty.call(raw, id) && Array.isArray(raw[id])) {
                    batches[id] = { events: raw[id], attempts: 0, notBefore: 0 };
                }
            }
            return { version: 1, globalNotBefore: 0, batches: batches };
        } catch (e) {
            return { version: 1, globalNotBefore: 0, batches: {} };
        }
    }

    /** Persists the root, falling back to memory (and staying there for the
     *  rest of this page) if sessionStorage rejects the write. */
    function writeStore(root) {
        if (!pendingStore) {
            memoryRoot = root;
            return;
        }
        try {
            if (Object.keys(root.batches).length === 0 && !root.globalNotBefore) {
                pendingStore.removeItem(PENDING_KEY);
            } else {
                pendingStore.setItem(PENDING_KEY, JSON.stringify(root));
            }
        } catch (e) {
            // sessionStorage filled up or got blocked mid-page — fall back to
            // memory from here on so batches and their backoff metadata keep
            // being tracked together. The server's batch-id dedup absorbs
            // any overlap with entries that did make it into sessionStorage
            // earlier.
            memoryRoot   = root;
            pendingStore = null;
        }
    }

    /** Records/updates one batch's events under its id, preserving that
     *  batch's existing retry metadata if it already had any (this is called
     *  only for genuinely fresh batches — see sendBatch()), and dropping
     *  oldest batches when the total stashed events would exceed MAX_QUEUE. */
    function stashBatch(id, events) {
        var root = readStore();
        var existing = root.batches[id];
        root.batches[id] = {
            events: events,
            attempts: existing ? existing.attempts : 0,
            notBefore: existing ? existing.notBefore : 0
        };

        var ids = Object.keys(root.batches);
        var total = 0;
        for (var i = 0; i < ids.length; i++) {
            total += root.batches[ids[i]].events.length;
        }
        while (total > MAX_QUEUE && ids.length > 1) {
            var oldest = ids.shift();
            total -= root.batches[oldest].events.length;
            delete root.batches[oldest];
        }

        writeStore(root);
    }

    /** Removes one acknowledged batch — events and retry metadata together,
     *  and only that batch — from the store. */
    function unstashBatch(id) {
        var root = readStore();
        if (root.batches[id]) {
            delete root.batches[id];
            writeStore(root);
        }
    }

    /** Whether sends for this batch id are currently paused — either a
     *  tab-wide pause (set by any 429) or this specific batch's own backoff
     *  window. Pure read; never writes, so merely checking eligibility never
     *  triggers a re-serialization of the whole store. */
    function isPaused(id, root) {
        var r = root || readStore();
        var now = Date.now();
        if ((r.globalNotBefore || 0) > now) {
            return true;
        }
        var entry = r.batches[id];
        return !!(entry && entry.notBefore > now);
    }

    /** Computes and records the next backoff delay for one batch after a
     *  retryable failure, folding a 429's tab-wide pause into the same write
     *  when applicable — a 429 means the server is asking every batch from
     *  this tab to slow down, not just this one. When the server sent a
     *  Retry-After value alongside a 429, that's honored directly instead of
     *  the client-guessed exponential value — the server is the one place
     *  that actually knows how long its own limit window is. */
    function scheduleBackoff(id, is429, retryAfterSeconds) {
        var root = readStore();
        var entry = root.batches[id];
        if (!entry) {
            return; // Already removed elsewhere (e.g. a permanent 4xx raced this) — nothing to back off.
        }

        var attempts = (entry.attempts || 0) + 1;
        var notBefore;

        if (is429 && typeof retryAfterSeconds === 'number' && retryAfterSeconds > 0) {
            notBefore = Date.now() + Math.min(RETRY_MAX_MS, retryAfterSeconds * 1000);
        } else {
            var base = is429 ? RETRY_BASE_MS_429 : RETRY_BASE_MS;
            var cap = Math.min(RETRY_MAX_MS, base * Math.pow(2, attempts - 1));
            notBefore = Date.now() + Math.random() * cap;
        }

        entry.attempts = attempts;
        entry.notBefore = notBefore;

        if (is429) {
            root.globalNotBefore = Math.max(root.globalNotBefore || 0, notBefore);
        }

        writeStore(root);
    }

    /** Batch ids in flight right now, so a slow retry isn't sent twice. */
    var inFlight = {};

    /** Batch ids known to have survived a failed or unacknowledged send —
     *  either they failed retryably on THIS page (network error, 5xx, 429,
     *  503) or they were inherited from the store (their sender never saw a
     *  response). These stay persisted even through an accepted page-exit
     *  beacon hand-off: the beacon's response is never observable, and for a
     *  batch that already failed once "accepted" must not mean "delivered" —
     *  the server's batch-id dedup absorbs the resend if the beacon did
     *  land. Fresh exit batches keep best-effort hand-off semantics so
     *  ordinary navigation doesn't replay every batch. */
    var retryPending = {};

    /**
     * Sends one batch under its id.
     *
     * A genuinely fresh batch (never before in the store) is persisted
     * BEFORE the backoff gate is checked, so a visitor who navigates away
     * during a pause never loses data that was only ever in the in-memory
     * queue. A batch already sitting in the store (a retry) is durable
     * already — it is NOT re-stashed on every gate check, so a paused
     * backlog doesn't get the whole store rewritten to sessionStorage on
     * every periodic tick for nothing.
     *
     * The tab-scoped pause applies to every send except one narrow case: the
     * current page's fresh queue contents get a best-effort attempt at
     * lifecycle-exit moments (pagehide/visibilitychange-hidden) regardless
     * of an active pause, since sessionStorage — and the pause recorded in
     * it — persists across ordinary navigation but not past an actual tab
     * close, and this is the one chance to get new data out before that.
     * Already-persisted batches are NOT granted this exception even at those
     * same moments — resending the whole backed-off backlog on every
     * pagehide/visibilitychange would defeat the pause entirely, since those
     * events fire far more often than an actual tab closing.
     *
     * The persisted entry is removed only on acknowledgment: a 2xx response,
     * or a 4xx that retrying cannot fix (429 is rate limiting, not
     * rejection — the batch stays persisted and backs off). A network
     * error, 5xx, or 429 schedules an increasing backoff delay for a later
     * flush (this page or the next one in this tab) to retry.
     *
     * The batch id travels in the request body: the server keys stored rows
     * by (batch id, event ordinal), so a replay of an already-stored batch —
     * delivered, but its response lost — is deduplicated server-side rather
     * than inflating every count.
     *
     * Transport: sendBeacon is used for both lifecycle-exit signals
     * (pagehide/visibilitychange-hidden) AND navigation-interaction signals
     * (click/submit) today — a deliberate, revisitable choice. Switching
     * click/submit to a keepalive fetch would make 429/5xx failures on those
     * paths observable (currently a fresh beacon's "accepted" is trusted as
     * delivered even if the server actually rejected it), but trades that
     * for a different risk: an async fetch response racing a fast
     * navigation can mean the batch gets resent even though it actually
     * succeeded (safe, thanks to server-side dedup, but wasteful). Without a
     * way to measure the real duplicate-request/429 impact on this
     * install, sendBeacon stays in place for both; revisit with real
     * request-level measurement before changing it.
     */
    function sendBatch(id, events, mode) {
        var root = readStore();
        var alreadyPersisted = !!root.batches[id];

        if (!alreadyPersisted) {
            stashBatch(id, events);
            root = readStore();
        }

        var bypassGate = !alreadyPersisted && mode === 'lifecycle-exit';

        if (!bypassGate && isPaused(id, root)) {
            return; // Gated — already durably persisted above if this was fresh; a later flush retries it.
        }

        if (bypassGate) {
            // This fresh batch is being sent despite an active pause; the
            // beacon path below gives no visibility into whether the server
            // actually accepts it, so treat it as already-failed-once up
            // front — it stays persisted rather than being wrongly unstashed
            // on an accepted-but-unconfirmed hand-off.
            retryPending[id] = true;
        }

        inFlight[id] = true;

        var body = JSON.stringify({ batch_id: id, events: events });

        if ((mode === 'lifecycle-exit' || mode === 'navigation-interaction') && navigator.sendBeacon) {
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
                    var is429 = response.status === 429;
                    var retryAfter = null;
                    if (is429 && response.headers && response.headers.get) {
                        var parsed = parseInt(response.headers.get('Retry-After'), 10);
                        retryAfter = isNaN(parsed) ? null : parsed;
                    }
                    scheduleBackoff(id, is429, retryAfter); // 5xx/429 — retryable failure.
                    retryPending[id] = true;
                }
                delete inFlight[id];
            }).catch(function () {
                scheduleBackoff(id, false, null);
                retryPending[id] = true;
                delete inFlight[id]; // Network error — stays persisted.
            });
        } catch (e) {
            scheduleBackoff(id, false, null);
            retryPending[id] = true;
            delete inFlight[id]; // fetch unavailable/threw — stays persisted.
        }
    }

    /**
     * Sends the queued events as a new batch and resends any stashed batches
     * (each under its original id) — including batches left behind by a
     * previous page in this tab.
     */
    function flush(mode) {
        var root = readStore();
        for (var id in root.batches) {
            if (Object.prototype.hasOwnProperty.call(root.batches, id) && !inFlight[id]) {
                // Anything still in the store at resend time was never
                // acknowledged — mark it so an exit-time beacon hand-off
                // can't be its last trace (see sendBatch()), regardless of
                // which page's in-memory state (retryPending resets on
                // navigation) is now processing it.
                retryPending[id] = true;
                sendBatch(id, root.batches[id].events, mode);
            }
        }

        if (queue.length === 0) {
            return;
        }

        // 'b' prefix: purely-numeric keys would be reordered by the JS
        // engine, breaking oldest-first eviction in stashBatch().
        sendBatch('b' + randomHex(12), queue.splice(0, queue.length), mode);
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
            flush('navigation-interaction');
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

        // A submit is also the moment jQuery (if used at all) has almost
        // certainly finished loading, even past the fixed attempts below —
        // catches a delayed-jQuery scenario (a defer/async optimizer
        // plugin). Idempotent (see jQueryBound below), costs nothing once
        // already bound.
        bindJQueryFormEvents();

        // The submit may navigate away — get the batch out now.
        flush('navigation-interaction');
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
        flush('normal');
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
    // window load, a couple of timed fallbacks, AND on every native form
    // submit (above), rather than being checked only within a fixed window.
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
            ? e.target.closest(INTERACTIVE + ', [data-spa-hover]')
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
     *  Scroll depth — 50/100% milestones, once each per page view
     * ------------------------------------------------------------------ */

    var milestones = [50, 100];
    var reached = {};
    var scrollScheduled = false;

    function checkScrollDepth() {
        scrollScheduled = false;

        var doc = document.documentElement;
        var scrollable = doc.scrollHeight - window.innerHeight;

        if (scrollable <= 0) {
            // Nothing to scroll — 50% never genuinely happened here, so
            // record only the one milestone that's actually true instead of
            // firing every threshold at once.
            if (!reached[100]) {
                reached[50] = true;
                reached[100] = true;
                track('scroll_depth', { event_value: '100' });
            }
            return;
        }

        var percent = Math.round((window.scrollY || doc.scrollTop || 0) / scrollable * 100);

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
        flush('normal');
    }, FLUSH_INTERVAL);

    window.addEventListener('pagehide', function () {
        flush('lifecycle-exit');
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            flush('lifecycle-exit');
        }
    });
})();
