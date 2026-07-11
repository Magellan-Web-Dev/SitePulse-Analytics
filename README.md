# SitePulse Analytics

A self-hosted visitor analytics plugin for WordPress. SitePulse tracks page views, link and button clicks, form submissions, mouse hover activity, and scroll depth, then surfaces everything inside the WordPress dashboard — making it easy to identify popular pages, important conversion actions, and the areas of your content visitors actually engage with. On a configurable schedule, aggregated analytics can also be delivered as JSON `POST` requests to one or more webhook endpoints.

- **Version:** 1.1.0
- **Requires WordPress:** 6.3+
- **Requires PHP:** 8.1+
- **License:** GPL-2.0-or-later
- **Repository:** https://github.com/Magellan-Web-Dev/SitePulse-Analytics

## Features

- **Page view tracking** — every frontend page load is recorded with URL, title, and referrer.
- **Click tracking** — links, buttons, submit inputs, and `role="button"` elements, with the element's label and destination URL.
- **Form submission tracking** — native `submit` events are captured before any AJAX handler can swallow them, so Elementor and similar AJAX forms are counted too. Counted at submit time, so these are submission *attempts*, not confirmed successes.
- **Hover tracking** — records when a visitor's pointer rests on an interactive element or image for a configurable dwell time (default 800 ms), once per element per page view. Add `data-spa-hover` to any element to opt it into hover tracking.
- **Scroll depth** — 25/50/75/100% milestones, once each per page view.
- **Custom server-side events** — record anything else with `spa_track_event()`.
- **Dashboard analytics** — summary cards, a daily page-view chart, top pages, top clicked elements, top forms, most-hovered elements, top referrers, campaign (UTM) attribution, a device breakdown, and a recent-activity feed, filterable by 7/30/90-day periods (calendar days, UTC).
- **Webhook delivery** — aggregated analytics sent as JSON to **any number of endpoints** on an hourly, twice-daily, daily, or weekly schedule, with per-endpoint delivery windows, automatic retries on failure, a manual test-send button, and a delivery log.
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

{"events": [{"type": "pageview", "page_url": "...", "page_title": "...", ...}]}
```

Batches flush every 5 seconds, when 20 events accumulate, and on page exit via `navigator.sendBeacon`, so events are not lost when a visitor navigates away. Batches that fail with a network error or a 5xx response are kept in a bounded `sessionStorage` map keyed by batch id and resent on later flushes — by the same page or, after a navigation destroys it, by the next page in that tab. Each batch is removed only when its own resend is acknowledged, so one batch's success can never discard another's undelivered events. Delivery is at-least-once — a rare duplicate is possible when a delivered batch's response is lost.

The endpoint defends itself in layers: it accepts only whitelisted, currently-enabled event types; requires tracked page URLs to be `http(s)` URLs on this site's host (foreign schemes, and ports the site doesn't actually run on, are rejected or normalized away); rejects requests whose `Origin`/`Referer` header names a foreign host; ignores known bots and empty user agents; drops batches carrying `DNT: 1` / `Sec-GPC: 1` headers when the privacy-signal option is on (server-side backstop for the tracker's own check); caps request body and batch sizes; requires scalar field values and sanitizes/truncates every one; and rate-limits **by event count** — 300 events per IP per minute plus a site-wide cap of 3,000/minute, both tunable via the `spa_rate_limits` filter — using atomic object-cache counters when a persistent object cache is available. Accepted events are written with a single multi-row `INSERT` per request. The IP is used only as a hashed, short-lived rate-limit key and is never stored with analytics data (behind a reverse proxy, map the real client IP with the `spa_client_ip` filter). If the site-wide cap is reached, a warning appears on the dashboard for 24 hours so dropped events don't go unnoticed. For very-high-traffic sites, raise the limits via the filter and consider edge/WAF protection in front of the endpoint.

### Privacy posture

- No cookies are set. The session identifier lives in `localStorage` and rotates after 30 minutes of inactivity, so it groups one visit (across tabs) without becoming a persistent visitor ID.
- Tracked URLs are canonicalized to scheme + host + path — no query strings are ever stored, so search terms, tokens, and emails in a page URL's or referrer's query string never reach the database.
- What **is** retained, stated precisely: campaign parameter *values* (`utm_source`, `utm_medium`, `utm_campaign`) are stored verbatim after text sanitization — except values containing an `@`, which are dropped as likely email addresses — so **never put personal information in UTM parameters**. Clicked `mailto:` and `tel:` destinations are stored whole (the address/number *is* the destination); if that is unacceptable for your site, disable click tracking or strip `target_url` via the `spa_tracked_event` filter.
- Optional: honor Do Not Track / Global Privacy Control browser signals (off by default; toggle under Settings → Tracking). Enforced both in the tracker (it never starts) and at the REST endpoint (batches carrying `DNT: 1` / `Sec-GPC: 1` are discarded). Note DNT/GPC is an opt-out signal, not a consent mechanism — if your jurisdiction requires consent, gate the tracker with your consent tool.
- No IP addresses or user agents are stored — only a coarse `mobile`/`desktop` device bucket.
- Logged-in users are excluded from tracking by default (toggleable in settings).
- Data is automatically deleted after the retention window.

## Dashboard

**SitePulse** (top-level admin menu) shows, for the selected 7/30/90-day period:

| Section | What it tells you |
| --- | --- |
| Summary cards | Totals for page views, clicks, form submissions, hovers, and scroll milestones |
| Daily Page Views | A bar chart of traffic over the period |
| Top Pages | Most-viewed pages with view and session counts (sessions use a 30-minute inactivity window) |
| Top Clicked Elements | Which links and buttons visitors click most — your conversion actions |
| Top Form Submit Attempts | Which forms are submitted, and on which pages (counted at submit time; success not confirmed) |
| Most Hovered Elements | Where visitor attention lingers |
| Top Referrers | Which external sites and pages send you traffic |
| Campaigns | Pageviews attributed to `utm_source` / `utm_medium` / `utm_campaign`. Attribution is last-touch within the session: the most recent tagged landing attributes the visit from that point on, and untagged pages inherit it |
| Devices | Mobile vs desktop share of page views |
| Recent Activity | The latest raw events, for verifying tracking is working |

## Webhooks

The Settings page opens with **Webhook Status** and **Webhook Settings** at the top (Tracking and Data settings follow below, and Test Delivery below that). Configure endpoints in **Webhook Settings**: each endpoint gets its own block with a URL field and an optional **label** (shown as a badge on Delivery Log entries so a specific endpoint is easy to spot), and the **+ Add Additional URL** button adds as many as you need; every block but the first has a **Remove** button. A **Webhook Status** toggle above it pauses all new scheduled deliveries without discarding your configured endpoints — it appears once at least one endpoint has a URL, mirroring the layout of the Forms Webhook Integrator plugin used elsewhere on this site. The same card also has optional **Client First Name**, **Client Last Name**, **Client ID**, and **Website ID** fields, sent as `website_info.client.first_name`/`last_name`/`id` and `website_info.id` in every payload — handy for identifying which client or site a payload belongs to when one endpoint receives deliveries from several installs. On each scheduled run every endpoint receives a `POST` with `Content-Type: application/json`. Delivery windows are tracked **per endpoint**: each payload covers the time since that endpoint's last successful delivery, so a temporarily failing endpoint receives the full missed window on the next run instead of losing data.

### Delivery scatter

To avoid a thundering herd when many sites running this plugin share one endpoint and the same interval, each site's schedule is anchored at a random offset within its send interval (capped at 24 hours) instead of at the moment of activation. The offset is stable — a given site always delivers at roughly the same time — and is re-randomized only when the interval setting changes or the plugin is reactivated. The settings page shows the next scheduled send time.

### Retry handling

Failed webhook deliveries (a transport error or any non-2xx status) are retried automatically up to 5 more times over about 24 hours — after 5 minutes, 30 minutes, 2 hours, 6 hours, and 16 hours. The JSON body that failed is **frozen** — serialized and stored with the retry state — so every attempt in the chain re-sends the literally byte-identical payload under the same `delivery_id` / `Idempotency-Key` (retention cleanup, settings changes, or a plugin update between attempts cannot alter it), and scheduled runs skip an endpoint while its chain is actively pending.

If the whole chain is exhausted (or a retry cron cannot be scheduled), the frozen delivery is **kept**: the next scheduled run re-sends that exact body under the same `delivery_id` first, restarting the retry chain if it fails again. Deactivating the plugin suspends pending retries the same way — their frozen deliveries are kept and resumed by the first scheduled dispatch after reactivation, still under their original `delivery_id`. Only after the frozen delivery is acknowledged does the endpoint's marker advance — exactly to the frozen window's end — and newer events go out (in the same run, as a separate delivery covering the window from the frozen end onward). Consecutive deliveries therefore never cover overlapping windows. Delivery is **at-least-once**, and any duplicate a receiver can ever see carries a `delivery_id` it has already processed — deduplicating by `delivery_id` is sufficient to never double-count. Pending retries and every attempt's outcome are shown on the settings page.

Example payload:

```json
{
    "source": "sitepulse-analytics",
    "plugin_version": "1.1.0",
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
        "totals": { "pageview": 1240, "click": 512, "form_submit": 38, "hover": 940, "scroll_depth": 2210 },
        "daily_pageviews": [ { "date": "2026-07-09", "count": 610 }, { "date": "2026-07-10", "count": 630 } ],
        "top_pages": [ { "page_url": "https://example.com/", "page_title": "Home", "views": 400, "sessions": 310 } ],
        "top_clicks": [ { "element_label": "Get a Quote", "element_tag": "a", "target_url": "https://example.com/quote", "clicks": 88 } ],
        "top_forms": [ { "element_label": "contact-form", "page_url": "https://example.com/contact", "submissions": 21 } ],
        "top_hovers": [ { "element_label": "Pricing", "element_tag": "a", "hovers": 130 } ],
        "top_referrers": [ { "referrer": "https://www.google.com/", "visits": 210 } ],
        "top_campaigns": [ { "utm_source": "newsletter", "utm_medium": "email", "utm_campaign": "spring-sale", "views": 96 } ],
        "devices": { "desktop": 820, "mobile": 420 }
    }
}
```

`website_info.id` and `website_info.client` (`first_name`, `last_name`, `id`) are optional identifiers configured under **Settings → Webhook Settings**; each is always present in the payload as an empty string when not set, so consumers never have to check for its existence.

Requests are sent with `wp_safe_remote_post()` (endpoints are re-validated at request time) and redirects disabled, and every request carries an `Idempotency-Key` header equal to the payload's `delivery_id`.

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

`status` accepts `success` or `error` (omit for all). Pagination metadata is returned in the `X-WP-Total`, `X-WP-TotalPages`, and `X-SPA-Page` response headers. Requests without the exact key get a 401 (repeated failures from one IP are throttled with a 429); when the API is toggled off the endpoint answers 403. This API is intended for **server-to-server** use — CORS headers permit browser requests from any origin for flexibility, but never embed the key in public frontend JavaScript, where any visitor could read it.

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

### Filters

| Filter | Purpose |
| --- | --- |
| `spa_tracked_event` | Inspect/modify an event row before it is stored; return `false` to drop it. Receives `(array $row, string $type)`. |
| `spa_webhook_payload` | Modify the webhook payload before it is sent. Receives `(array $payload, int $startTs, int $endTs)`. |
| `spa_allowed_hosts` | Hostnames accepted in tracked page URLs and `Origin`/`Referer` checks, and treated as internal in referrer reports (default: this site's own hosts). |
| `spa_client_ip` | Override the client IP used for rate limiting, e.g. to map a trusted reverse-proxy header. |
| `spa_rate_limits` | Tune ingestion rate limits: `['per_ip' => 300, 'site_wide' => 3000]` events/minute. |
| `spa_delivery_log_row` | Inspect/modify a delivery-log row before it is stored (e.g. site-specific redaction); return `false` to skip logging that attempt. |

## Data storage

Events live in a custom table, `{$wpdb->prefix}spa_events`, indexed by event type and date; webhook delivery attempts live in `{$wpdb->prefix}spa_webhook_deliveries`. Deactivating the plugin preserves all data (and suspends — rather than discards — pending webhook retries); deleting the plugin from the Plugins screen removes both tables, all options, and cron events completely (see `uninstall.php`).

## Folder structure

```
sitepulse-analytics/
├── sitepulse-analytics.php      # Plugin header, PHP 8.1 guard, bootstrap, activation hooks
├── uninstall.php                # Complete cleanup on plugin deletion
├── README.md
├── assets/
│   ├── css/admin.css            # Dashboard, settings & delivery log styles
│   ├── js/admin.js              # Settings page webhook endpoint repeater
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
