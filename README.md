# SitePulse Analytics

A self-hosted visitor analytics plugin for WordPress. SitePulse tracks page views, link and button clicks, form submissions, **confirmed form conversions with campaign attribution**, mouse hover activity, and scroll depth, then surfaces everything inside the WordPress dashboard — making it easy to identify popular pages, which campaigns and channels actually produce leads, and the areas of your content visitors engage with. On a configurable schedule, aggregated analytics (including individual attributed conversions) can also be delivered as JSON `POST` requests to one or more webhook endpoints.

- **Version:** 1.7.0
- **Requires WordPress:** 6.3+
- **Requires PHP:** 8.1+
- **License:** GPL-2.0-or-later
- **Repository:** https://github.com/Magellan-Web-Dev/SitePulse-Analytics

## Features

- **Page view tracking** — every frontend page load is recorded with URL, title, and referrer.
- **Click tracking** — links, buttons, submit inputs, and `role="button"` elements, with the element's label and destination URL.
- **Form submission tracking** — native `submit` events are captured before any AJAX handler can swallow them, so Elementor and similar AJAX forms are counted too. Counted at submit time, so these are submission *attempts*, not confirmed successes.
- **Confirmed conversions** — a separate `form_success` event fires only when the form plugin reports that the server accepted the submission: Elementor Pro's `submit_success`, Contact Form 7's `wpcf7mailsent`, WPForms' `wpformsAjaxSubmitSuccess`, and Gravity Forms' `gform_confirmation_loaded`, plus a `spa:conversion` DOM event for custom goals. **These integrations detect AJAX submissions** — the events above fire on the AJAX success response. A traditional (non-AJAX) submission that navigates to a new page has no success event the tracker can observe, so forms configured that way are counted as attempts but not as confirmed conversions; use the `spa:conversion` event (e.g. on a thank-you page) for those. Every conversion carries a unique conversion id (duplicates from retried deliveries are never double-counted — the REST endpoint rejects a `form_success` without a valid, bounded id) and a **snapshot of the session's campaign attribution at conversion time**, so each conversion record is self-contained — even when the tagged landing happened earlier in the session or the campaign data has since aged out.
- **Campaign attribution** — all six utm parameters (`utm_source`, `utm_medium`, `utm_campaign`, `utm_id`, `utm_term`, `utm_content`) are captured from tagged landing URLs, and ad-click identifiers (`gclid`, `gbraid`, `wbraid`, `fbclid`, `msclkid`, `ttclid`, `twclid`, `li_fat_id`) are recognized: only the parameter *name* is stored (never the value), and when no utm tags are present the implied source/medium is filled in (e.g. `gclid` → `google` / `cpc`). Attribution is last-touch within the session, and the snapshot rides on **every** event in the session — pageviews and conversions, but also clicks, form attempts, hovers, and scroll milestones — so intermediate funnel steps can be segmented by campaign. Untagged acquisition persists too: the referrer a session *entered* through is stored client-side and sent as `session_referrer`, so an organic or referral visit keeps its channel across internal navigation instead of degrading to Direct at conversion time.
- **Channel grouping** — every attributed event is classified into a marketing channel at ingestion (Paid Search, Paid Social, Organic Search, Organic Social, Email, Display, Affiliate, SMS, Referral, Direct, Other), with source aliases normalized (`fb` / `facebook.com` → `facebook`) so reports don't fragment. When an event's own referrer is internal or missing, classification falls back to the session's persisted entrance referrer (`session_referrer`). Referrer hosts match a search engine or social network only in the registrable-domain position (`www.google.co.uk` matches; a lookalike like `google.example.test` does not). Both the alias map and the channel rules are filterable.
- **Hover tracking** — records when a visitor's pointer rests on an interactive element or image for a configurable dwell time (default 800 ms), once per element per page view. Add `data-spa-hover` to any element to opt it into hover tracking.
- **Scroll depth** — 25/50/75/100% milestones, once each per page view.
- **Custom server-side events** — record anything else with `spa_track_event()`.
- **Dashboard analytics** — summary cards, an accessible daily page-view chart (single-Tab-stop keyboard navigation, touch/mouse tooltips, visible axes, and a data-table fallback), a print-optimized **Print / Save as PDF** report view, top pages, top landing pages, top clicked elements, top forms, most-hovered elements, top referrers, campaign performance (sessions, conversions, session conversion rate), a keyword/creative drilldown (`utm_term` / `utm_content`), a channel breakdown, a device breakdown, and a recent-activity feed. Reports are grouped into an always-visible Overview plus collapsible Content, Engagement, Acquisition, Devices, and Recent Activity sections, filterable by 7/30/90-day periods (calendar days, UTC, with the exact date range shown).
- **Webhook delivery** — aggregated analytics sent as JSON to **any number of endpoints** (HTTPS required; a filter allows HTTP for development) on an hourly, twice-daily, daily, or weekly schedule, with per-endpoint delivery windows, optional **HMAC-signed requests** (shared or **per-endpoint** signing secrets), optional **history backfill** for newly added endpoints, automatic retries on failure, a manual test-send button, and a delivery log. Conversion delivery is **lossless**: windows holding more than 100 individual conversions are split into consecutive deliveries instead of dropping the overflow.
- **Bounded storage** — a daily cleanup cron deletes events older than the configured retention window (default 90 days, adjustable 7–365).
- **GitHub-powered updates** — new releases published to the GitHub repository appear as standard update notifications on the Plugins screen.

## Installation

1. Copy the `sitepulse-analytics` folder into `wp-content/plugins/` (the folder name must remain `sitepulse-analytics`).
2. Activate **SitePulse Analytics** on the Plugins screen. Activation creates the events table and schedules the cleanup and webhook cron events.
3. Visit **SitePulse** in the admin menu to view analytics, and **SitePulse → Settings** to configure tracking and webhooks. **SitePulse → About** carries this documentation inside wp-admin.

## How tracking works

A single dependency-free script (`assets/js/tracker.js`) is enqueued deferred in the footer of frontend pages. It batches events in memory and delivers them to a public REST endpoint:

```
POST /wp-json/sitepulse/v1/track
Content-Type: application/json

{"batch_id": "b3f2a9c1b8e0d", "events": [{"type": "pageview", "page_url": "...", "page_title": "...", ...}]}
```

Batches flush every 5 seconds, when 20 events accumulate, and on page exit via `navigator.sendBeacon`, so events are not lost when a visitor navigates away. Every batch is **persisted before it is sent** — to a bounded `sessionStorage` map keyed by batch id, with an in-memory map standing in when `sessionStorage` is unavailable — and removed only when the server acknowledges it (a 2xx, or a 4xx other than 429 that retrying cannot fix). A batch whose page is destroyed before the response arrives, or that fails with a network error, a 5xx, a 429 (rate limited), or a 503 (events could not be stored — the endpoint refuses to acknowledge what never reached the database), is therefore resent by a later flush — by the same page or by the next page in that tab. Each batch is removed only by its own acknowledgment, so one batch's success can never discard another's undelivered events. Fetch-delivered batches are **at-least-once**, and replays are **idempotent**: the `batch_id` travels in the request body and rows are stored under a unique `(batch_id, event ordinal)` database key, so a replayed batch whose response was lost is deduplicated server-side instead of inflating page-view, click, and conversion counts. Fresh page-exit batches handed to `navigator.sendBeacon` are the exception: an accepted hand-off only means the browser queued the request, so those are treated as delivered — **best-effort** — because keeping every one persisted would resend every exit batch on the next page. A batch that already survived a failed or unacknowledged send, however, stays persisted even through an accepted beacon hand-off — its loss is exactly what retrying exists to prevent, and the server-side batch dedup absorbs the resend if the beacon did land.

The endpoint defends itself in layers: it accepts only whitelisted, currently-enabled event types; requires tracked page URLs to be `http(s)` URLs on this site's host (foreign schemes, and ports the site doesn't actually run on, are rejected or normalized away); rejects requests whose `Origin`/`Referer` header names a foreign host; ignores known bots and empty user agents; drops batches carrying `DNT: 1` / `Sec-GPC: 1` headers when the privacy-signal option is on (server-side backstop for the tracker's own check); caps request body and batch sizes; requires scalar field values and sanitizes/truncates every one; and rate-limits **by event count** — 300 events per IP per minute plus a site-wide cap of 3,000/minute, both tunable via the `spa_rate_limits` filter — using atomic object-cache counters when a persistent object cache is available, and an atomic single-statement database counter otherwise (also the fail-closed fallback whenever a cache increment fails — a flaky cache never disables the limit), so the caps are a hard bound on stored volume even under heavy concurrency. The per-IP check runs first and a rejected sender never consumes the site-wide budget, so one flooding IP can't starve legitimate visitors out of the global allowance. Accepted events are written with a single multi-row `INSERT` per request. The IP is used only as a hashed, short-lived rate-limit key and is never stored with analytics data (behind a reverse proxy, map the real client IP with the `spa_client_ip` filter). If the site-wide cap is reached, a warning appears on the dashboard for 24 hours so dropped events don't go unnoticed. For very-high-traffic sites, raise the limits via the filter and consider edge/WAF protection in front of the endpoint.

### Privacy posture

- No cookies are set. The session identifier lives in `localStorage` and rotates after 30 minutes of inactivity, so it groups one visit (across tabs) without becoming a persistent visitor ID.
- Tracked URLs are canonicalized to scheme + host + path — no query strings are ever stored, so search terms, tokens, and emails in a page URL's or referrer's query string never reach the database. The session's entrance referrer (`session_referrer`) is normalized the same way and is used only to classify the marketing channel at ingestion — it is never stored as its own field.
- What **is** retained, stated precisely: campaign parameter *values* (`utm_source`, `utm_medium`, `utm_campaign`, `utm_id`, `utm_term`, `utm_content`) are stored after text sanitization (source/medium are lowercased and alias-normalized) — except values containing an `@`, which are dropped as likely email addresses — so **never put personal information in UTM parameters**. Ad-click identifiers (`gclid`, `fbclid`, …) are handled more strictly: only the parameter *name* is stored (as `click_id_type`); the identifier value is a cross-site advertising ID that may qualify as personal data, so the tracker never even sends it. Clicked `mailto:` and `tel:` destinations are stored whole (the address/number *is* the destination); if that is unacceptable for your site, disable click tracking or strip `target_url` via the `spa_tracked_event` filter.
- Optional: honor Do Not Track / Global Privacy Control browser signals (off by default; toggle under Settings → Tracking). Enforced both in the tracker (it never starts) and at the REST endpoint (batches carrying `DNT: 1` / `Sec-GPC: 1` are discarded). Note DNT/GPC is an opt-out signal, not a consent mechanism — if your jurisdiction requires consent, gate the tracker with your consent tool.
- What page-level data can still contain, stated plainly: URL **paths** are stored, and a path itself can embed personal data if your site puts it there (e.g. `/reset/<token>/`, `/account/jane@example.com/`, `/order/1234/`) — the same applies to **page titles**, clicked **element labels**, and **form names**, which are stored as visitors see them. If your URLs or titles carry personal data, exclude or rewrite those fields with the `spa_tracked_event` filter before storage.
- The webhook **signing secret** is stored in the plugin's settings option (like other WordPress secrets such as SMTP credentials, it is readable by anyone with database access or `manage_options`) and is rendered back into the settings field for editing. Rotate it if you believe an admin session or database copy was exposed.
- The **Delivery Log** retains each delivery's full request payload and the endpoint's response. Structured JSON responses have sensitive-looking keys redacted automatically; **non-JSON response bodies are stored as received** (truncated to 64 KB). Use the `spa_delivery_log_row` filter for site-specific redaction, or skip logging entirely.
- No IP addresses or user agents are stored — only a coarse `mobile`/`desktop` device bucket.
- Logged-in users are excluded from tracking by default (toggleable in settings).
- Data is automatically deleted after the retention window.

## Dashboard

**SitePulse** (top-level admin menu) shows, for the selected 7/30/90-day period (UTC calendar days — the exact date range is displayed next to the period selector, and the current day is marked as still collecting). Reports are grouped into an always-visible **Overview** plus collapsible **Content**, **Engagement**, **Acquisition**, **Devices**, and **Recent Activity** sections — native, keyboard-accessible panels that keep the page scannable without hiding any report. Expand all / Collapse all controls sit above the panels, each panel remembers its open/closed state for the browsing session (including across period changes), and a **Print / Save as PDF** button produces a print-optimized report of the selected period through the browser's native print dialog, expanding every section and chart value automatically:

| Section | What it tells you |
| --- | --- |
| Summary cards | Totals for page views, clicks, form submit attempts, confirmed conversions, hovers, and scroll milestones — with short explanations under the less obvious metrics |
| Daily Page Views | An accessible bar chart of traffic over the period: every day is reachable by mouse, keyboard (one Tab stop, then Arrow/Home/End/Page keys), or touch with an exact-count tooltip, framed by a pinned Y-axis scale and date labels, with a total / average-per-completed-day / busiest-day summary, a patterned marker for the still-collecting current day (visible even at zero views), and a "View data table" fallback that works without JavaScript. Dense 30/90-day views scroll horizontally behind a pinned Y-axis so every day keeps at least a 14px interaction width |
| Top Pages | Most-viewed pages with view and session counts (sessions use a 30-minute inactivity window) |
| Top Landing Pages | The first page of each session that started in the period — where visitors actually arrive |
| Top Clicked Elements | Which links and buttons visitors click most — your conversion actions |
| Top Form Submit Attempts | Which forms are submitted, and on which pages (counted at submit time; success not confirmed) |
| Most Hovered Elements | Where visitor attention lingers |
| Top Referrers | Which external sites and pages send you traffic |
| Campaigns | Sessions, views, confirmed conversions, and session conversion rate (sessions with ≥ 1 conversion ÷ sessions) per `utm_source` / `utm_medium` / `utm_campaign` / `utm_id`. Attribution is last-touch within the session: the most recent tagged landing attributes the visit from that point on, and untagged pages inherit it |
| Campaign Terms & Content | Keyword (`utm_term`) and creative (`utm_content`) performance with campaign context — always listed, with an explanatory empty state when the period has no traffic carrying those tags |
| Channels | Sessions, views, confirmed conversions, and session conversion rate per marketing channel (Paid Search, Organic Social, Email, Referral, Direct, …), classified at ingestion |
| Devices | Mobile vs desktop share of page views |
| Recent Activity | The latest 15 raw events — independent of the selected period, shown in the site timezone using the site's date/time display settings — for verifying tracking is working |

## Webhooks

The Settings page opens with **Webhook Status** and **Webhook Settings** at the top (Tracking and Data settings follow below, and Test Delivery below that). Configure endpoints in **Webhook Settings**: each endpoint gets its own block with a URL field (HTTPS required — payloads carry visitor analytics; development setups can allow HTTP via the `spa_allow_insecure_webhooks` filter), an optional **label** (shown as a badge on Delivery Log entries so a specific endpoint is easy to spot), and an optional **per-endpoint signing secret**, and the **+ Add Additional URL** button adds as many as you need; every block but the first has a **Remove** button. A **Webhook Status** toggle above it pauses all new scheduled deliveries without discarding your configured endpoints — it appears once at least one endpoint has a URL, mirroring the layout of the Forms Webhook Integrator plugin used elsewhere on this site. The same card also has optional **Client First Name**, **Client Last Name**, **Client ID**, and **Website ID** fields, sent as `website_info.client.first_name`/`last_name`/`id` and `website_info.id` in every payload — handy for identifying which client or site a payload belongs to when one endpoint receives deliveries from several installs. On each scheduled run every endpoint receives a `POST` with `Content-Type: application/json`. Delivery windows are tracked **per endpoint**: each payload covers the time since that endpoint's last successful delivery, so a temporarily failing endpoint receives the full missed window on the next run instead of losing data. A gap longer than one send interval — downtime, a paused Webhook Status toggle, or a backfilled first send — is delivered as **consecutive interval-sized windows** (up to 10 per run; the remainder resumes on the next run), so history arrives at the same granularity as live deliveries.

The same card also offers:

- **Signing secret** *(optional)* — when set, every webhook request carries an `X-SPA-Signature` header: `sha256=<hex>`, the HMAC-SHA256 of the raw JSON body keyed with the secret. The receiver recomputes the HMAC over the exact bytes it received and compares (use a constant-time comparison such as PHP's `hash_equals()`), verifying both authenticity and integrity. Signatures are computed at send time, so retried deliveries re-sign the identical frozen bytes. An endpoint block's own signing secret overrides the shared one for that endpoint, so one receiver never learns the key that signs payloads for the others.
- **History backfill** *(optional, off by default)* — when enabled, an endpoint that has never received a delivery starts from the beginning of the retention window instead of one send interval ago, so an endpoint added months after data collection began still receives everything the site retains. Enabling it after an endpoint has already received deliveries changes nothing for that endpoint.

Each `top_*` list in the payload holds up to **200 rows** (tunable via the `spa_webhook_report_limit` filter) — much deeper than the dashboard's top 10 — so a downstream system aggregating deliveries long-term sees (near-)complete page, click, form, referrer, and campaign rankings per window.

### Delivery scatter

To avoid a thundering herd when many sites running this plugin share one endpoint and the same interval, each site's schedule is anchored at a random offset within its send interval (capped at 24 hours) instead of at the moment of activation. The offset is stable — a given site always delivers at roughly the same time — and is re-randomized only when the interval setting changes or the plugin is reactivated. The settings page shows the next scheduled send time.

### Dispatch concurrency

Only one dispatch run (and one retry) executes at a time: every run takes a site-wide mutex before reading any delivery marker — a MySQL named lock (released automatically if PHP dies mid-run, and named per install by hashing the database name, table prefix, and site URL, so unrelated WordPress installs sharing one database server never contend), with an atomic option-row lock as fallback. The fallback lock carries an **ownership token and a lease**: the holder renews the lease between delivery windows (so a legitimately long multi-endpoint run is never mistaken for a dead one), only a lock whose holder stopped renewing can be stolen, and both release and steal are compare-and-delete operations on the token — a run whose lock was stolen can never free the new holder's lock. A long run that overlaps the next cron trigger therefore can't produce overlapping windows under different `delivery_id` values; the overlapping run simply yields. Each run also captures a single delivery horizon before iterating endpoints, so caught-up endpoints share identical windows — and one computed summary — instead of re-running the ~17 aggregate queries per endpoint, keeping multi-endpoint runs short. If the payload ever fails to JSON-encode (e.g. a `spa_webhook_payload` filter introduced an unencodable value), the delivery is treated as a **failed attempt** and enters the normal retry chain — an empty body is never sent (so a lenient endpoint's 2xx can't advance the marker past a window that was never delivered), and the payload is never quietly rebuilt without the filter, since the filter may exist to redact data.

### Retry handling

Failed webhook deliveries (a transport error or any non-2xx status) are retried automatically up to 5 more times over about 24 hours — after 5 minutes, 30 minutes, 2 hours, 6 hours, and 16 hours. The JSON body that failed is **frozen** — serialized and stored with the retry state — so every attempt in the chain re-sends the literally byte-identical payload under the same `delivery_id` / `Idempotency-Key` (retention cleanup, settings changes, or a plugin update between attempts cannot alter it), and scheduled runs skip an endpoint while its chain is actively pending.

If the whole chain is exhausted (or a retry cron cannot be scheduled), the frozen delivery is **kept**: the next scheduled run re-sends that exact body under the same `delivery_id` first, restarting the retry chain if it fails again. Deactivating the plugin suspends pending retries the same way — their frozen deliveries are kept and resumed by the first scheduled dispatch after reactivation, still under their original `delivery_id`. Only after the frozen delivery is acknowledged does the endpoint's marker advance — exactly to the frozen window's end — and newer events go out (in the same run, as a separate delivery covering the window from the frozen end onward). Consecutive deliveries therefore never cover overlapping windows. Delivery is **at-least-once**, and any duplicate a receiver can ever see carries a `delivery_id` it has already processed — deduplicating by `delivery_id` is sufficient to never double-count. Pending retries and every attempt's outcome are shown on the settings page.

Every mutation of the retry bookkeeping happens while holding the dispatch mutex. A retry that finds the mutex taken only re-schedules its own cron event — it never writes shared state that the lock holder may be clearing or replacing — and if even that re-schedule fails, the chain is detected as **orphaned** by the next dispatch run (pending state with no cron event behind it, well past due) and resumed under its original frozen bytes and `delivery_id`. A retry that fires after its delivery was already acknowledged finds no stored state and stops without sending. Frozen deliveries also don't outlive the data itself: a chain frozen longer ago than the retention window is discarded automatically, and each pending retry has an explicit **Discard** action on the settings page (discarding never loses site data — the endpoint's next delivery covers that window again, under a new `delivery_id`).

Example payload:

```json
{
    "source": "sitepulse-analytics",
    "plugin_version": "1.7.0",
    "website_info": {
        "name": "Example Site",
        "url": "https://example.com",
        "id": "site-123",
        "client": { "first_name": "Jane", "last_name": "Smith", "id": "client-456" }
    },
    "generated_at": "2026-07-10T12:00:00+00:00",
    "delivery_id": "0f52c1d6a4b98e73d21f06c58a9b3e47",
    "period": {
        "start": "2026-07-09T12:00:00+00:00",
        "end": "2026-07-10T12:00:00+00:00"
    },
    "analytics": {
        "totals": { "pageview": 1240, "click": 512, "form_submit": 38, "form_success": 24, "hover": 940, "scroll_depth": 2210 },
        "daily_pageviews": [ { "date": "2026-07-09", "count": 610 }, { "date": "2026-07-10", "count": 630 } ],
        "top_pages": [ { "page_url": "https://example.com/", "page_title": "Home", "views": 400, "sessions": 310 } ],
        "top_landing_pages": [ { "page_url": "https://example.com/spring-sale/", "page_title": "Spring Sale", "sessions": 180 } ],
        "top_clicks": [ { "element_label": "Get a Quote", "element_tag": "a", "target_url": "https://example.com/quote", "clicks": 88 } ],
        "top_forms": [ { "element_label": "contact-form", "page_url": "https://example.com/contact", "submissions": 21 } ],
        "top_hovers": [ { "element_label": "Pricing", "element_tag": "a", "hovers": 130 } ],
        "top_referrers": [ { "referrer": "https://www.google.com/", "visits": 210 } ],
        "top_campaigns": [
            {
                "utm_source": "newsletter", "utm_medium": "email", "utm_campaign": "spring-sale",
                "utm_id": "cmp-2210", "channel": "Email",
                "views": 96, "sessions": 74,
                "conversions": 7, "converting_sessions": 6, "conversion_rate": 8.11
            }
        ],
        "top_campaign_content": [
            {
                "utm_source": "google", "utm_campaign": "summer-sale",
                "utm_term": "emergency plumber", "utm_content": "ad-variant-b",
                "views": 42, "sessions": 31, "conversions": 3
            }
        ],
        "channels": [
            { "channel": "Paid Search", "views": 320, "sessions": 240, "conversions": 12, "converting_sessions": 11, "conversion_rate": 4.58 },
            { "channel": "Email", "views": 96, "sessions": 74, "conversions": 7, "converting_sessions": 6, "conversion_rate": 8.11 }
        ],
        "conversions": {
            "total": 24,
            "recent": [
                {
                    "conversion_id": "c1f52c1d6a4b98e73",
                    "form": "contact-form",
                    "page_url": "https://example.com/contact",
                    "referrer": "https://example.com/services",
                    "device": "desktop",
                    "occurred_at": "2026-07-10 09:14:02",
                    "attribution": {
                        "channel": "Paid Search",
                        "utm_source": "google", "utm_medium": "cpc", "utm_campaign": "summer-sale",
                        "utm_id": "", "utm_term": "", "utm_content": "",
                        "click_id_type": "gclid"
                    }
                }
            ]
        },
        "devices": { "desktop": 820, "mobile": 420 }
    }
}
```

`website_info.id` and `website_info.client` (`first_name`, `last_name`, `id`) are optional identifiers configured under **Settings → Webhook Settings**; each is always present in the payload as an empty string when not set, so consumers never have to check for its existence.

`analytics.conversions.recent` lists **every** individual confirmed conversion in the delivery window (deduplicated by `conversion_id`), each carrying the attribution snapshot taken **when the conversion occurred** — so a downstream CRM or automation platform never has to search older payloads to attribute a lead. Conversion delivery is **lossless**: a window holding more than 100 conversions is automatically split — the delivery covers a shorter window containing the first 100, and the remainder goes out as the next delivery (consecutive, non-overlapping windows, worked off within the same dispatch run) — so no conversion record is ever skipped by a listing cap. `analytics.channels` and the enriched `analytics.top_campaigns` provide the aggregate view; conversion counts in both are deduplicated by conversion id, and `conversion_rate` is a **session conversion rate** (sessions with at least one conversion ÷ sessions, capped at 100) with the raw `conversions` and `converting_sessions` counts alongside.

Requests are sent with `wp_safe_remote_post()` (endpoints are re-validated at request time) and redirects disabled, and every request carries an `Idempotency-Key` header equal to the payload's `delivery_id` — plus an `X-SPA-Signature` HMAC header when a signing secret is configured (see above).

A **Send test payload now** button on the settings page delivers the last 7 days to every endpoint immediately (flagged with `"test": true`).

### Delivery Log

**SitePulse → Delivery Log** records every delivery attempt — scheduled, retry, or test — in its own table, including the exact JSON payload that was sent and the response the endpoint returned. Response downloads are capped at 64 KB at the transport layer (a misbehaving endpoint can't balloon memory), stored bodies are capped at 64 KB (bytes), and values of sensitive-looking keys in JSON responses (`password`, `token`, `secret`, `authorization`, `api_key`, …) are replaced with `[REDACTED]` before storage — the log must not become a credential store. The `spa_delivery_log_row` filter runs before each row is written for site-specific redaction, or to skip logging an attempt entirely (return `false`).

The page shows two paginated lists, **Successful Deliveries** and **Failed Deliveries**, each with year/month/endpoint filters, debounced payload search, a per-page selector, and per-entry delete; entries for a labeled endpoint show its label as a badge. A toolbar offers **Clear All Logs** and **CSV/JSON export** (both include the webhook label column and stream row-by-row, so even very large logs export in bounded memory). Log entries share the analytics retention window and are pruned in bounded chunks by the same daily cleanup.

### Deliveries API

The Delivery Log page can also enable a **read-only REST endpoint** that returns the log as JSON (off by default). Only a SHA-256 hash of the API key is stored — the raw key is shown **once**, right after it is generated; copy it then, or regenerate a new one (which invalidates the old key immediately):

```
GET /wp-json/sitepulse/v1/deliveries?page=1&per_page=25&status=error
Authorization: <api-key>
```

`status` accepts `success` or `error` (omit for all). Pagination metadata is returned in the `X-WP-Total`, `X-WP-TotalPages`, and `X-SPA-Page` response headers. Requests without the exact key get a 401 (repeated failures from one IP are throttled with a 429); when the API is toggled off the endpoint answers 403. In responses, `endpoint_url` is **redacted to scheme + host** — webhook URLs frequently embed bearer tokens in their path or query string, and this API's read-only key must never hand out write credentials for downstream endpoints; identify endpoints by `webhook_label` (the full URL stays visible on the admin Delivery Log page). This API is intended for **server-to-server** use — CORS headers permit browser requests from any origin for flexibility, but never embed the key in public frontend JavaScript, where any visitor could read it.

## Developer API

### Server-side custom events

```php
spa_track_event('purchase', [
    'page_url'      => home_url('/checkout/'),
    'element_label' => 'Order #1234',
    'event_value'   => '99.00',
]);
```

Custom event types appear in the dashboard's "Other Events" card, in totals, and in webhook payloads under their own type key.

### Custom frontend conversions

Any JavaScript on the page can record a confirmed conversion (a `form_success` event with the session's attribution snapshot and a unique conversion id) by dispatching a DOM event:

```js
document.dispatchEvent(new CustomEvent('spa:conversion', {
    detail: { name: 'appointment_booked' }
}));
```

Use this for goals the built-in form integrations can't see — a booking widget's success callback, a phone-click you consider a conversion, a multi-step funnel's final step.

### Filters

| Filter | Purpose |
| --- | --- |
| `spa_tracked_event` | Inspect/modify an event row before it is stored; return `false` to drop it. Receives `(array $row, string $type)`. |
| `spa_webhook_payload` | Modify the webhook payload before it is sent. Receives `(array $payload, int $startTs, int $endTs)`. |
| `spa_webhook_report_limit` | Maximum rows per `top_*` list in the webhook payload (default 200). Receives `(int $limit)`. |
| `spa_allowed_hosts` | Hostnames accepted in tracked page URLs and `Origin`/`Referer` checks, and treated as internal in referrer reports (default: this site's own hosts). |
| `spa_client_ip` | Override the client IP used for rate limiting, e.g. to map a trusted reverse-proxy header. |
| `spa_rate_limits` | Tune ingestion rate limits: `['per_ip' => 300, 'site_wide' => 3000]` events/minute. |
| `spa_source_aliases` | Extend/override the map that normalizes `utm_source` values at ingestion (e.g. `'fb' => 'facebook'`). Receives and returns `array<string, string>` keyed by raw lowercase value. |
| `spa_channel` | Override the marketing channel assigned to an event at ingestion. Receives `(string $channel, array $row, string $type)` — return any label (≤ 24 chars). |
| `spa_delivery_log_row` | Inspect/modify a delivery-log row before it is stored (e.g. site-specific redaction); return `false` to skip logging that attempt. |
| `spa_allow_insecure_webhooks` | Return `true` to allow plain-HTTP webhook endpoint URLs to be saved (development setups only; HTTPS is required by default). |

## Data storage

Events live in a custom table, `{$wpdb->prefix}spa_events`, indexed by event type and date; webhook delivery attempts live in `{$wpdb->prefix}spa_webhook_deliveries`. Deactivating the plugin preserves all data (and suspends — rather than discards — pending webhook retries); deleting the plugin from the Plugins screen removes both tables, all options, and cron events completely (see `uninstall.php`). On **multisite**, uninstall iterates every site in the network and performs the same per-site cleanup, so a network-activated install leaves nothing behind on any site.

## Folder structure

```
sitepulse-analytics/
├── sitepulse-analytics.php      # Plugin header, PHP 8.1 guard, bootstrap, activation hooks
├── uninstall.php                # Complete cleanup on plugin deletion
├── README.md
├── assets/
│   ├── css/admin.css            # Shared settings, about & delivery log styles
│   ├── css/dashboard.css        # Dashboard-only styles, incl. the print layout
│   ├── js/admin.js              # Settings page webhook endpoint repeater
│   ├── js/dashboard.js          # Dashboard page (chart navigation & tooltips, panel state, print prep)
│   ├── js/delivery-log.js       # Delivery Log page (accordions, pagination, API card)
│   └── js/tracker.js            # Frontend interaction tracker
└── src/
    ├── Autoloader.php           # Minimal PSR-4 autoloader (no Composer)
    ├── Plugin.php               # Composition root — wires all subsystems
    ├── Admin/
    │   ├── AboutPage.php        # In-admin plugin documentation page
    │   ├── DashboardPage.php    # Analytics dashboard UI
    │   ├── DeliveryLogPage.php  # Webhook delivery log UI (lists, exports, API card)
    │   └── SettingsPage.php     # Settings UI, test send
    ├── Api/
    │   └── DeliveryLogApi.php   # Read-only deliveries REST endpoint + API key
    ├── Database/
    │   ├── DatabaseManager.php  # Events table schema, inserts, retention cleanup
    │   └── Reports.php          # Aggregate queries (dashboard + webhooks)
    ├── Settings/
    │   └── Options.php          # Typed settings access with defaults
    ├── Tracking/
    │   ├── Channels.php         # Source normalization + marketing-channel classification
    │   ├── RestController.php   # Public REST endpoint for event collection
    │   └── ScriptLoader.php     # Enqueues tracker.js with its config
    ├── Updates/
    │   └── GitHubUpdater.php    # Update checks against GitHub releases
    └── Webhook/
        ├── DeliveryLog.php       # Delivery log table, queries, retention
        └── WebhookDispatcher.php # Scheduled JSON delivery to endpoints
```

## Updates

The plugin checks the [GitHub repository's](https://github.com/Magellan-Web-Dev/SitePulse-Analytics) latest release every 12 hours through WordPress's normal update pipeline. Publishing a release with a tag like `v1.1.0` makes the update banner appear on the Plugins screen; a **Check for updates** row action forces an immediate check. After an update installs, the plugin folder is automatically normalized back to `sitepulse-analytics/` before WordPress reactivates it.

## Changelog

### 1.7.0

Data-integrity and security hardening following an external code review. The webhook JSON payload format/schema is unchanged.

- **Idempotent event ingestion:** the tracker now sends its batch id in the request body, and event rows are stored under a new unique `(batch_id, event ordinal)` database key with `INSERT IGNORE` (schema version 1.4.0, applied automatically). At-least-once delivery previously meant a batch whose response was lost would be replayed and stored twice, inflating page views, clicks, submissions, and conversions; replays are now deduplicated server-side. Server-side events (`spa_track_event()`) are unaffected (`batch_id` stays `NULL`, which the unique index never collides on).
- **Storage failures are no longer acknowledged:** when the multi-row `INSERT` itself fails (e.g. a transient database outage), the tracking endpoint now answers **503** instead of 202, so the tracker keeps the batch persisted and retries it later — previously the browser permanently discarded the batch and the events were silently lost. The tracker also treats **429** as retryable (it used to discard rate-limited batches like any other 4xx), falls back to an in-memory pending queue when `sessionStorage` is unavailable, and no longer lets a page-exit `sendBeacon` hand-off be the last trace of a batch that already failed once — such batches stay persisted through the hand-off, with the server-side batch dedup absorbing the resend when the beacon did land.
- **Confirmed Conversions card consistency:** the dashboard summary card now uses the same deduplicated-by-conversion-id count as the Campaigns and Channels reports, so the two displayed conversion totals can no longer disagree.
- **Webhook retry-state race fixed:** every mutation of the shared retry state now happens while holding the dispatch mutex. A retry that finds the mutex taken only re-schedules its own cron event (previously it rewrote the shared state option unlocked, which could erase a new retry chain or resurrect an acknowledged one); a retry whose state is gone (already acknowledged or discarded) stops without sending instead of building a fresh delivery that would break the same-bytes/same-`delivery_id` guarantee; and a chain orphaned by a failed re-schedule is detected by the next dispatch run and resumed under its original frozen bytes and id.
- **Frozen retries expire and can be discarded:** a frozen delivery whose chain froze longer ago than the retention window is dropped automatically (the site no longer holds those events either — previously the frozen body sat in `wp_options` forever for an endpoint that never recovered), and each pending retry now has an explicit **Discard** action on the settings page.
- **Migrations are verified before being recorded:** both schema managers now confirm the table exists with every expected column after `dbDelta()` before writing their version option — and the events table additionally confirms the `batch_event` unique index built, since the replay dedup lives in the index and a killed index build on a large table would otherwise be marked complete. A failed or partial migration is retried on the next load instead of being permanently recorded.
- **Deliveries API no longer exposes full webhook URLs:** `endpoint_url` in API responses is redacted to scheme + host. Webhook URLs frequently carry bearer tokens in their path or query string, and the API's read-only key must not hand out downstream write credentials; identify endpoints by `webhook_label`. The full URL remains visible to admins on the Delivery Log page.
- **Stricter webhook transport:** endpoint URLs must use HTTPS (existing saved HTTP endpoints keep working until settings are next saved, at which point a settings-page warning names the dropped URL; the new `spa_allow_insecure_webhooks` filter allows HTTP for development), and each endpoint block can set its own **signing secret** that overrides the shared one, so one receiver never learns the key that signs payloads for the others. The settings page now renders settings errors (and core's "Settings saved." confirmation), which WordPress does not do automatically on custom-menu pages.
- **Rate limiting fails closed:** when a persistent object cache's increment fails, the limiter now falls back to the atomic database counter instead of allowing the request — a flaky cache can no longer disable the rate limit.
- **Delivery-log redaction hardening:** JSON response bodies prefixed with whitespace or a UTF-8 BOM are now recognized and pass through secret redaction; previously a leading newline bypassed the redactor entirely.
- **Misc:** hover events no longer use a raw image `src` (which can carry signed-URL tokens) as their fallback label — query strings and fragments are stripped first; and the plugin header now declares an `Update URI`, preventing wordpress.org from ever serving an update for a colliding slug.

### 1.6.0

Reliability and correctness hardening across webhook dispatch, tracker delivery, the updater, and reporting. The webhook JSON payload format/schema is unchanged.

- **Webhook dispatch mutex:** every dispatch run and retry now takes a site-wide lock before reading delivery markers, so overlapping cron executions can no longer build overlapping windows under different `delivery_id` values. The primary mechanism is a MySQL named lock, named per install (a hash of database name, table prefix, and site URL) so unrelated WordPress installs sharing one database server never contend. Where named locks are unavailable, the fallback is an atomic option-row lock with an **ownership token and a renewed lease**: holders extend their lease between delivery windows, only a lock whose holder stopped renewing (a dead process) can be stolen, and release/steal are compare-and-delete operations on the token — so a run that outlived its lease can never free the *new* holder's lock and let a third run in. A retry that finds the lock held re-schedules itself for a minute later without consuming an attempt.
- **Shared window summaries:** each dispatch run captures a single delivery horizon before iterating endpoints, so caught-up endpoints get byte-identical windows and share one computed analytics summary per run instead of re-running ~17 aggregate queries each — materially shortening multi-endpoint and backfill catch-up runs.
- **JSON-encoding safety:** a payload that fails to JSON-encode (e.g. an unencodable value introduced by the `spa_webhook_payload` filter) is treated as a *failed* delivery attempt entering the normal retry chain — an empty body is never sent, so a 2xx from a lenient endpoint can no longer advance the marker past a window that was never delivered. The payload is deliberately **not** rebuilt without the filter: a filter may exist to redact sensitive data, and silently sending the unfiltered payload would bypass it. Test sends get the same guard.
- **Tracker persist-before-send:** every event batch is now persisted to the bounded `sessionStorage` map *before* it is sent and removed only on server acknowledgment (2xx, or an unfixable 4xx) — previously a batch was persisted only after a failure was observed, so a page destroyed before the response arrived could lose it. Fetch-delivered batches are now genuinely at-least-once; page-exit `sendBeacon` hand-offs are documented as best-effort (an accepted hand-off counts as delivered, since keeping it persisted would systematically double-count exit batches).
- **Cross-tab attribution freshness:** after a page's first event, the tracker re-reads the session's stored attribution per event instead of trusting a page-lifetime memo, so a tab no longer keeps crediting a stale campaign after another tab (sharing the `localStorage` session) re-attributed it with a newer tagged landing.
- **Transactional updater folder normalization:** if the existing plugin folder cannot be moved to a backup during an update, the update is now aborted with an error and the current working copy left untouched — previously the fallback deleted the working folder, which could leave the site with no plugin at all if the subsequent rename also failed. Failed renames now also clean up the extracted folder and surface a `WP_Error` instead of silently passing, and the backup **restore is verified**: if restoring the previous version also fails, a distinct error names the exact backup folder path and how to restore it manually, instead of falsely reporting the previous version as restored.
- **Channels on every attributed event:** clicks, form attempts, hovers, and scroll milestones are now channel-classified at ingestion exactly like pageviews and conversions (the documented behavior), so intermediate funnel events no longer carry a blank `channel` column.
- **Conversion ids enforced at every write path:** a `form_success` event without a valid, bounded conversion id (8–100 characters of `A-Za-z0-9_.:-`; the tracker always generates one) is now rejected — enforced in the database layer that all writers share, so the REST endpoint, the `spa_track_event()` helper, and any future importer uphold the same invariant. This closes the gap where empty-id conversions were counted inconsistently — ignored by `COUNT(DISTINCT event_value)` but collapsed into a single record in conversion listings.
- **Top Landing Pages correctness:** the report now determines each session's *true* first pageview and only counts sessions whose first pageview falls inside the period — a session that began before the period is no longer assigned a fresh "landing page" at its first in-window pageview. A new `type_session_date (event_type, session_id, created_at)` index (applied automatically by the schema upgrader) serves this query as a covering, pre-grouped index read, so the corrected report stays fast on high-volume tables.
- **Hard rate-limit bound without an object cache:** the transient read-modify-write fallback is replaced by an atomic single-statement counter in the options table (`INSERT … ON DUPLICATE KEY UPDATE` with an in-row window reset), so concurrent requests can no longer all read one stale count and be accepted together. The per-IP bucket is also charged **before** the site-wide bucket, and a per-IP rejection no longer consumes site-wide budget — a single flooding IP previously could burn the entire global allowance with rejected requests and block legitimate visitors for the rest of the window. Counter rows are purged by the daily cleanup and on uninstall.
- **Multisite uninstall:** deleting the plugin on a multisite network now iterates every site and removes that site's tables, options, transients, and cron events, instead of cleaning only the current site.
- **Documentation:** the README and About page now state the AJAX-only nature of the built-in form-plugin conversion integrations (use `spa:conversion` for non-AJAX forms), the revised tracker delivery guarantee, and an expanded privacy section covering URL paths, page titles, element/form labels, `mailto:`/`tel:` targets, delivery-log response bodies, and signing-secret storage.

### 1.5.0

Dashboard UI refactor — no tracking, database, webhook, or reporting-semantics changes; all reports, calculations, period choices, permissions, and empty states are preserved.

- **Sectioned layout:** the dashboard is now organized into an always-visible **Overview** (summary cards + chart) followed by collapsible **Content**, **Engagement**, **Acquisition**, **Devices**, and **Recent Activity** panels. Panels are native `<details>` elements — keyboard accessible with no JavaScript — and each section and ambiguous table carries a short plain-language description. With JavaScript, **Expand all / Collapse all** controls appear above the panels, and each panel's open/closed state is remembered for the browsing session (`sessionStorage`, panel ids and booleans only — no analytics or user data), so the layout survives switching between the 7/30/90-day periods. The controls disable themselves when every panel is already open (or closed).
- **Accessible daily page-view chart:** the CSS-only bar chart (values hidden in `title` attributes) is replaced by a dependency-free chart where every day is a real button reachable by mouse hover, keyboard focus, and touch, with a custom tooltip showing the formatted date and exact count, a visible Y-axis scale, spaced X-axis date labels (every day at 7 days, spaced markers at 30/90, edge labels never clipped), a total / average-per-completed-day / busiest-day summary, a patterned bar plus a baseline tick for the still-collecting current day (visible even on a zero-view day), true zero-height bars for zero-view days, an accessible "View data table" fallback that works without JavaScript, and `prefers-reduced-motion` support. With JavaScript, the chart is a **single Tab stop** with roving focus — Left/Right Arrow move between days, Home/End jump to the first/latest day, Page Up/Down jump a week, moving focus scrolls the day into view and updates the tooltip, Escape dismisses the tooltip without losing focus, and concise screen-reader instructions describe the keys. Pointer handling is flicker-free, taps select a day (a second tap on it dismisses, as does tapping outside the chart), and only one tooltip can ever be active — clamped to the visible area so it is never clipped at a scrolled edge or hidden under the pinned Y-axis. The 30/90-day views keep a minimum interaction width of 14px per day by letting the plot grow and scroll horizontally behind the pinned Y-axis instead of squeezing 90 targets into the viewport (still narrower than a fingertip — the data table remains the precise alternative).
- **Chart average:** the summary shows **Avg per completed day** — today is excluded from the average while it is still collecting (with a clear fallback when no completed days exist yet). The total still includes today, and the underlying daily report and webhook data are unchanged.
- **Period selector:** the pipe-separated 7/30/90-day links are now a WordPress-style segmented button group with `aria-current="page"` on the active choice, the exact UTC start–end dates displayed, and a note that the current day is still collecting data. At narrow widths the group becomes equal-width segments that fill the row instead of overflowing. Query-string behavior is unchanged.
- **Summary cards:** stronger metric/label hierarchy, equal-height responsive grid, and concise explanations under Form Submit Attempts, Confirmed Conversions, and Scroll Milestones.
- **Report tables:** horizontal-scroll containers for wide tables (Campaigns and Campaign Terms & Content keep readable column widths), right-aligned counts with tabular numerals, readable hostname/path display for long URLs with the full URL kept as the link, a screen-reader caption naming every table, and a single-column layout at narrower widths. Page/referrer/destination values become links only after display-safe validation — an explicit `http(s)` scheme and a well-formed host, with this site's own hosts always accepted so local/staging/intranet installs keep their links (`mailto:`/`tel:` stay plain text); every link opens in a new tab with one consistent visible indicator and a single concise screen-reader announcement that also flags off-site destinations. Ranking tables state that they show up to 10 rows (Channels and Devices are complete breakdowns, not top-10 lists), and **Campaign Terms & Content** now always renders — with an explanatory empty state instead of silently disappearing when no `utm_term`/`utm_content` traffic exists. All empty-state messages are retained.
- **Recent Activity:** now labeled as the latest 15 events independent of the selected period, with human-readable event names, times converted to the site timezone and formatted with the site's own date/time display settings (timezone clearly labeled, including plain UTC-offset configurations), and clickable page references. The underlying query is unchanged.
- **Localized dates:** human-readable chart and period dates are rendered through WordPress's localized date functions while staying pinned to UTC, so they translate with the site language without shifting the reporting boundaries.
- **Print / Save as PDF:** a dependency-free, print-optimized report view. The **Print / Save as PDF** button opens the browser's native print dialog (where "Save as PDF" produces the file); before printing, every panel and the chart's data table are expanded automatically and restored afterwards — this also works for the browser's own File → Print. The printed report covers the currently selected 7/30/90-day period with a header naming the site, date range, timezone, and generation time; hides admin chrome, controls, notices, and tooltips — except the rate-limit data-quality warning, which stays in the printout because it flags that the numbers may undercount; removes scroll containers; uses landscape orientation for the wide campaign tables; keeps headings with their content, avoids splitting rows and cards across pages, repeats table headers on new pages; preserves chart colors (`print-color-adjust: exact`); includes exact daily values via the expanded data table; and prints each link's destination URL, since a PDF can't follow "opens in a new tab."
- **Implementation:** new `assets/js/dashboard.js` (chart navigation and tooltips, panel state, print preparation — enqueued solely on the dashboard screen) and new `assets/css/dashboard.css` carrying all dashboard and print styles, also loaded only on the dashboard so the other plugin screens no longer download them; styles scoped under `.spa-dash`, repeated table markup extracted into small rendering helpers, and all dynamic output escaped per WordPress standards.

### 1.4.0

- **HMAC-signed webhooks:** a new optional **Signing secret** (Settings → Webhook Settings). When set, every webhook request — scheduled, retry, or test — carries an `X-SPA-Signature: sha256=<hex>` header, the HMAC-SHA256 of the raw JSON body keyed with the secret, so receivers can verify payloads genuinely came from an authorized installation and were not altered in transit. Signatures are computed at send time, so retries re-sign the identical frozen bytes.
- **Deep webhook rankings:** every `top_*` list in the webhook payload now holds up to 200 rows instead of 10 (tunable via the new `spa_webhook_report_limit` filter), so downstream systems aggregating deliveries long-term can build (near-)complete page, click, form, hover, referrer, and campaign rankings — an item outside a window's top 10 is no longer invisible to the receiver. The dashboard keeps its top-10 views.
- **History backfill:** a new optional **History backfill** setting. When enabled, an endpoint that has never received a delivery starts from the beginning of the retention window instead of one send interval ago, so an endpoint configured after weeks of local collection still receives the full retained history.
- **Interval-sized catch-up windows:** an endpoint catching up on a gap longer than one send interval (downtime, a paused Webhook Status toggle, or a backfilled first send) now receives consecutive interval-sized windows — at most 10 per dispatch run, resuming next run — instead of one coarse window covering the whole gap, so per-window granularity (daily rankings, session counts) is preserved through outages and backfills.

### 1.3.0

- **Lossless webhook conversion delivery:** a reporting window holding more than 100 individual conversions is now split into consecutive, non-overlapping deliveries (each ≤ 100 conversions, worked off within the same dispatch run, bounded at 10 windows per run) instead of listing only the newest 100 and advancing past the rest — no conversion record can be skipped anymore. `analytics.conversions.recent` therefore always contains every conversion in its window.
- **Persistent untagged acquisition:** the tracker now stores the referrer each session *entered* through (refreshed on external re-entries, alongside the existing last-touch campaign persistence) and sends it as `session_referrer`. Channel classification falls back to it when an event's own referrer is internal or missing — so a conversion three pages into an organic, social, or referral visit is attributed to that channel instead of Direct. Applies to new events onward.
- **Campaign report correctness:** campaign rows are now grouped by `utm_source` / `utm_medium` / `utm_campaign` / `utm_id` (distinct campaign IDs no longer merge, and the displayed ID is exact rather than `MAX()`), and a visit tagged with *any* of the six utm fields — including only `utm_id`, `utm_term`, or `utm_content` — now counts as campaign traffic.
- **Session conversion rate:** `conversion_rate` in the Campaigns and Channels reports is now sessions-with-at-least-one-conversion ÷ sessions (capped at 100%), so multi-conversion sessions can no longer push it past 100%; the raw `conversions` count and the new `converting_sessions` ride alongside in the payload.
- **Keyword/creative drilldown:** new Campaign Terms & Content dashboard table and `analytics.top_campaign_content` payload section — performance per `utm_term` / `utm_content` with campaign context.
- **Intermediate-event attribution:** the session's attribution snapshot (utm fields, click-id type, `session_referrer`) is now attached to *every* tracked event — clicks, form attempts, hovers, scroll milestones — not just pageviews and conversions, enabling campaign-level funnel analysis (e.g. which campaigns attempt but never complete a form).
- **Top Landing Pages:** new dashboard table and `analytics.top_landing_pages` payload section — the first page of each session that started in the period.
- **Channel classification hardening:** a referrer host now matches a search engine or social network only in the registrable-domain position (`www.google.co.uk` still matches; a lookalike like `google.example.test` no longer classifies as Organic Search).
- **Form-plugin event binding:** the jQuery-based success listeners (Elementor Pro, WPForms, Gravity Forms) are now bound with retries at DOM-ready, window load, and timed fallbacks, so script optimizers that load jQuery after the tracker no longer silently disable conversion tracking.

### 1.2.0

- **Confirmed conversions:** new `form_success` event type, recorded only when the form plugin reports the server accepted the submission — Elementor Pro (`submit_success`), Contact Form 7 (`wpcf7mailsent`), WPForms (`wpformsAjaxSubmitSuccess`), and Gravity Forms (`gform_confirmation_loaded`) — plus a `spa:conversion` DOM event for custom goals. Toggleable under Settings → Tracking (on by default). Every conversion carries a unique conversion id; all conversion counts deduplicate by that id, so at-least-once redelivery can never double-count.
- **Attribution snapshots:** each conversion stores the session's campaign attribution (all utm fields, click-id type, channel) as it stood at the moment of conversion, making every conversion record — in the dashboard, webhook payloads, and the database — self-contained.
- **Full UTM capture:** `utm_id`, `utm_term`, and `utm_content` are now captured alongside source/medium/campaign, on pageviews and conversions.
- **Ad-click identifiers:** `gclid`, `gbraid`, `wbraid`, `fbclid`, `msclkid`, `ttclid`, `twclid`, and `li_fat_id` are recognized on landing URLs. Only the parameter *name* is stored (`click_id_type`) — the value never leaves the browser — and when no utm tags are present the implied source/medium is filled in (e.g. `gclid` → `google`/`cpc`).
- **Channel grouping:** every pageview and conversion is classified into a marketing channel at ingestion (Paid Search, Paid Social, Organic Search, Organic Social, Email, Display, Affiliate, SMS, Referral, Direct, Other), derived from click-id type, utm conventions, and referrer. `utm_source`/`utm_medium` are lowercased and alias-normalized (`fb`/`facebook.com` → `facebook`) so reports don't fragment. Customizable via the new `spa_source_aliases` and `spa_channel` filters. Classification applies to new events from this version onward; historical rows are not reinterpreted.
- **Dashboard:** new Confirmed Conversions summary card and Channels table; the Campaigns table now shows sessions, views, conversions, and conversion rate per campaign.
- **Webhook payload:** `analytics.top_campaigns` rows now include `utm_id`, `channel`, `sessions`, `conversions`, and `conversion_rate`; new `analytics.channels` aggregate; new `analytics.conversions` section with the deduplicated total and up to 100 individual conversions, each with its attribution snapshot.
- `spa_track_event()` accepts the new `utm_id`, `utm_term`, `utm_content`, `click_id_type`, and `channel` keys.

### 1.1.0
- **Webhook payload:** added a `website_info` block (`name`, `url`, optional `id`) with a nested `client` object (optional `first_name`, `last_name`, `id`), configurable under Settings → Webhook Settings — replaces the old top-level `site_url`/`site_name` fields.
- **Webhook Settings:** endpoints now get their own labeled block (URL + optional label) with a per-block Remove button, and a **Webhook Status** toggle pauses all new scheduled deliveries without discarding your configuration — layout ported from the Forms Webhook Integrator plugin. Labels appear as badges on Delivery Log entries and in the CSV/JSON export and Deliveries API response.
- **Settings page order:** Webhook Status / Webhook Settings now open the page, followed by Tracking, then Data, then Test Delivery.
- **About page:** restyled as one card per section (matching the Forms Webhook Integrator plugin's About page), with sample JSON payloads for the tracker's event batch, the outgoing webhook payload, and the Deliveries API response.
- **Delivery Log page:** every webhook delivery attempt is now stored in its own table with the exact payload sent and the response received, browsable on a dedicated **SitePulse → Delivery Log** page — paginated successful/failed lists with year/month/endpoint filters, payload search, per-entry delete, clear-all, and CSV/JSON export (replaces the 20-entry rolling log on the settings page).
- **Deliveries API:** optional read-only `GET /sitepulse/v1/deliveries` REST endpoint (paginated, `status` filter) managed from the Delivery Log page. Only a SHA-256 hash of the API key is stored; the raw key is shown once at generation, repeated authentication failures are throttled per IP, and the endpoint is documented as server-to-server.
- **Delivery log hardening:** endpoint responses are size-capped at download time (64 KB via `limit_response_size`), stored bodies are byte-capped, sensitive-looking keys in JSON responses are redacted before storage, a `spa_delivery_log_row` filter allows custom redaction or skipping, CSV/JSON exports stream row-by-row in bounded memory, and log pruning deletes in capped chunks.
- **Webhooks:** failed deliveries now freeze the serialized payload itself, so every retry is byte-identical under the same `delivery_id`; exhausted retry chains (and failed cron scheduling) keep the frozen delivery and resume it at the next scheduled run instead of building a new overlapping window — deduplicating by `delivery_id` is now sufficient for receivers to never double-count. Deactivation suspends pending retries (frozen deliveries resume after reactivation under their original IDs) instead of discarding them. Retries no longer re-run the aggregate queries.
- **Tracking:** page URLs and referrers must be `http(s)` (foreign schemes rejected; unknown ports normalized away), click/form target URLs with non-`http(s)` schemes other than `mailto:`/`tel:` are dropped, UTM values containing `@` are dropped as likely emails, and DNT/GPC is now also enforced server-side at the REST endpoint.
- **Campaigns:** a tagged landing now attributes the whole session (last-touch within the session — the most recent tagged landing wins), and the campaign report includes rows tagged with only `utm_medium`/`utm_campaign`.
- **Tracker:** batches that fail are kept in a bounded `sessionStorage` map keyed by batch id and resent by later flushes (on the same page or the next one in that tab); each batch is cleared only by its own acknowledgment, so overlapping sends can't discard each other's events.
- **Dashboard:** a warning is shown when the site-wide ingestion rate limit was hit in the last 24 hours.
- **Delivery Log UI:** payload search is debounced and out-of-order AJAX responses can no longer overwrite newer results.
- **Performance:** event batches are written with one multi-row `INSERT`, retention cleanup deletes in bounded, per-run-capped chunks, and allowed-host lookups are memoized per request.
- Documentation now states precisely what URL/UTM data is retained. Added `.gitignore` (excludes `.DS_Store` from releases).

### 1.0.0
- Initial release: page view, click, form submission, hover, and scroll-depth tracking; dashboard analytics; multi-endpoint webhook delivery; retention cleanup; GitHub release updates.
