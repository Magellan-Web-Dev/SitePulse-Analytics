# SitePulse Analytics

A self-hosted visitor analytics plugin for WordPress. SitePulse tracks page views, link and button clicks, form submissions, mouse hover activity, and scroll depth, then surfaces everything inside the WordPress dashboard — making it easy to identify popular pages, important conversion actions, and the areas of your content visitors actually engage with. On a configurable schedule, aggregated analytics can also be delivered as JSON `POST` requests to one or more webhook endpoints.

- **Version:** 1.0.0
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

Batches flush every 5 seconds, when 20 events accumulate, and on page exit via `navigator.sendBeacon`, so events are not lost when a visitor navigates away. Batches that fail with a network error or a 5xx response are re-queued (bounded) and retried on the next flush.

The endpoint defends itself in layers: it accepts only whitelisted, currently-enabled event types; requires tracked page URLs to belong to this site's host; rejects requests whose `Origin`/`Referer` header names a foreign host; ignores known bots and empty user agents; caps request body and batch sizes; requires scalar field values and sanitizes/truncates every one; and rate-limits **by event count** — 300 events per IP per minute plus a site-wide cap of 3,000/minute, both tunable via the `spa_rate_limits` filter — using atomic object-cache counters when a persistent object cache is available. The IP is used only as a hashed, short-lived rate-limit key and is never stored with analytics data (behind a reverse proxy, map the real client IP with the `spa_client_ip` filter). For very-high-traffic sites, raise the limits via the filter and consider edge/WAF protection in front of the endpoint.

### Privacy posture

- No cookies are set. The session identifier lives in `localStorage` and rotates after 30 minutes of inactivity, so it groups one visit (across tabs) without becoming a persistent visitor ID.
- Tracked URLs are canonicalized to scheme + host + path — no query strings are ever stored. Campaign parameters (`utm_source`, `utm_medium`, `utm_campaign`) are captured into dedicated fields for campaign reporting, and referrers and click/form destination URLs are stored without query strings or fragments. Search terms, tokens, and emails in URLs never reach the database.
- Optional: honor Do Not Track / Global Privacy Control browser signals (off by default; toggle under Settings → Tracking).
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
| Campaigns | Pageviews attributed to `utm_source` / `utm_medium` / `utm_campaign` |
| Devices | Mobile vs desktop share of page views |
| Recent Activity | The latest raw events, for verifying tracking is working |

## Webhooks

Configure endpoints under **SitePulse → Settings → Webhooks** — each endpoint gets its own URL field, and the **+ Add another endpoint** button adds as many as you need. On each scheduled run every endpoint receives a `POST` with `Content-Type: application/json`. Delivery windows are tracked **per endpoint**: each payload covers the time since that endpoint's last successful delivery, so a temporarily failing endpoint receives the full missed window on the next run instead of losing data.

### Delivery scatter

To avoid a thundering herd when many sites running this plugin share one endpoint and the same interval, each site's schedule is anchored at a random offset within its send interval (capped at 24 hours) instead of at the moment of activation. The offset is stable — a given site always delivers at roughly the same time — and is re-randomized only when the interval setting changes or the plugin is reactivated. The settings page shows the next scheduled send time.

### Retry handling

Failed webhook deliveries (a transport error or any non-2xx status) are retried automatically up to 5 more times over about 24 hours — after 5 minutes, 30 minutes, 2 hours, 6 hours, and 16 hours. The reporting window that failed is **frozen**: every attempt in the chain re-sends the byte-identical payload under the same `delivery_id` / `Idempotency-Key`, and scheduled runs skip an endpoint while its chain is pending — so a receiver that processed one attempt (but whose response was lost) can safely answer later attempts from cache without any events going missing. On success, the endpoint's delivery marker advances exactly to the frozen window's end; newer events arrive with the next scheduled run. Delivery is **at-least-once**: only if a processed request's response is lost *and* the entire chain also fails to reach the receiver will the next chain re-send an overlapping window (under a new `delivery_id`) — receivers that must never double-count should deduplicate by `delivery_id`. If the whole chain is exhausted, the endpoint simply waits for the next scheduled run (whose failure starts a fresh chain); the undelivered window keeps accumulating until a delivery succeeds, bounded only by the retention window. Pending retries and every attempt's outcome are shown on the settings page.

Example payload:

```json
{
    "source": "sitepulse-analytics",
    "plugin_version": "1.0.0",
    "site_url": "https://example.com",
    "site_name": "Example Site",
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

Requests are sent with `wp_safe_remote_post()` (endpoints are re-validated at request time) and redirects disabled, and every request carries an `Idempotency-Key` header equal to the payload's `delivery_id`.

A **Send test payload now** button on the settings page delivers the last 7 days to every endpoint immediately (flagged with `"test": true`), and a delivery log shows the outcome of the last 20 attempts.

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

## Data storage

Events live in a single custom table, `{$wpdb->prefix}spa_events`, indexed by event type and date. Deactivating the plugin preserves all data; deleting the plugin from the Plugins screen removes the table, options, and cron events completely (see `uninstall.php`).

## Folder structure

```
sitepulse-analytics/
├── sitepulse-analytics.php      # Plugin header, PHP 8.1 guard, bootstrap, activation hooks
├── uninstall.php                # Complete cleanup on plugin deletion
├── README.md
├── assets/
│   ├── css/admin.css            # Dashboard & settings styles
│   ├── js/admin.js              # Settings page webhook endpoint repeater
│   └── js/tracker.js            # Frontend interaction tracker
└── src/
    ├── Autoloader.php           # Minimal PSR-4 autoloader (no Composer)
    ├── Plugin.php               # Composition root — wires all subsystems
    ├── Admin/
    │   ├── AboutPage.php        # In-admin plugin documentation page
    │   ├── DashboardPage.php    # Analytics dashboard UI
    │   └── SettingsPage.php     # Settings UI, test send, delivery log
    ├── Database/
    │   ├── DatabaseManager.php  # Table schema, inserts, retention cleanup
    │   └── Reports.php          # Aggregate queries (dashboard + webhooks)
    ├── Settings/
    │   └── Options.php          # Typed settings access with defaults
    ├── Tracking/
    │   ├── RestController.php   # Public REST endpoint for event collection
    │   └── ScriptLoader.php     # Enqueues tracker.js with its config
    ├── Updates/
    │   └── GitHubUpdater.php    # Update checks against GitHub releases
    └── Webhook/
        └── WebhookDispatcher.php # Scheduled JSON delivery to endpoints
```

## Updates

The plugin checks the [GitHub repository's](https://github.com/Magellan-Web-Dev/SitePulse-Analytics) latest release every 12 hours through WordPress's normal update pipeline. Publishing a release with a tag like `v1.1.0` makes the update banner appear on the Plugins screen; a **Check for updates** row action forces an immediate check. After an update installs, the plugin folder is automatically normalized back to `sitepulse-analytics/` before WordPress reactivates it.

## Changelog

### 1.0.0
- Initial release: page view, click, form submission, hover, and scroll-depth tracking; dashboard analytics; multi-endpoint webhook delivery; retention cleanup; GitHub release updates.
