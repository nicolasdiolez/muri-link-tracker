=== Muri Link Tracker ===
Contributors: muri3
Tags: link checker, broken links, affiliate links, redirect checker, seo
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A high-performance link checker for WordPress with affiliate detection, redirect chain analysis, and background processing.

== Description ==

**Muri Link Tracker** is a powerful WordPress plugin designed to help site owners maintain their SEO health and user experience by identifying broken links, analyzing redirect chains, and detecting affiliate patterns.

Muri Link Tracker scans published content in bounded background jobs and gives you a dashboard to act on what matters: broken URLs, redirect loops, and missing rel attributes.

= Key Features =

* **Background Processing** — Scans run silently via Action Scheduler, so your admin stays responsive and your front-end stays fast.
* **Three Scan Modes** — Launch a **Full Scan** of all content, a **Delta Scan** (only posts modified since the last scan) to save resources, or a **Reset Data** to wipe and start fresh.
* **Live Progress Tracking** — Watch the scan progress in real time with a progress bar, counters, and the ability to cancel mid-scan.
* **Affiliate Link Detection** — Automatically identifies affiliate links across 14+ networks (Amazon, Awin, CJ, ShareASale, Impact, Rakuten, ClickBank, PartnerStack, and more), including **cloaked internal redirects** (internal URLs that redirect to external affiliates).
* **Redirect Chain Analysis** — Classifies every redirect as a **single hop**, a **chain of 2+ hops**, or a **loop**, so you know exactly what to fix first.
* **Affiliate Networks Breakdown** — See at a glance how many links belong to each affiliate program (Amazon, CJ, Awin…).
* **Modern React Dashboard** — Real-time statistics, quick filter tabs (All / Broken / Redirects / OK / Pending), inline editing, and bulk actions in a polished admin interface built with the WordPress design system.
* **Smart Filtering & Search** — Filter by status, link type, or affiliate network, and search across URLs and anchor text instantly.
* **CSV Export** — Export your full link inventory with active filters applied, perfect for reporting and auditing.
* **Silent Link Editing** — Fix or remove a link directly from the dashboard without changing the post's modified date, with a native WordPress revision for recovery.
* **Configurable to Any Host** — Adjustable batch sizes, timeouts, request delays, and recheck intervals to fit shared hosting or dedicated servers.
* **Scan Resume** — Interrupted scans pick up exactly where they left off.
* **Gutenberg-Aware** — Extracts links from Gutenberg blocks with block-level metadata, including button blocks and navigation links.

= What It Detects =

* Broken links (4xx and 5xx HTTP status codes)
* Redirect chains and loops (single hop, multi-hop, infinite loops)
* Affiliate links via domain patterns, URL paths, query parameters, and `rel="sponsored"`
* Cloaked affiliate links (internal URLs that redirect to external affiliates)
* Missing rel attributes (nofollow, sponsored, ugc)
* Timeout and SSL/connection errors
* Both internal and external links, categorized automatically

= Privacy =

Muri Link Tracker makes outbound HTTP requests to verify the status of links found in your content. These requests use a clearly identified User-Agent string (`MuriLinkTracker/1.0.0`). No personal user data is collected, stored, or transmitted to third parties.

= Development =

The full source code, including React/JavaScript source files and build configuration, is available on GitHub:
[https://github.com/nicolasdiolez/muri-link-tracker](https://github.com/nicolasdiolez/muri-link-tracker)

== Installation ==

1. Upload the `muri-link-tracker` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Access the dashboard via **Muri Link Tracker** in the admin sidebar.
4. Open the **Settings** tab to choose which post types to scan and tune timeouts to your hosting.
5. Click **Full Scan** from the Dashboard to analyze all your published content.

= Requirements =

* WordPress 6.9 or higher
* PHP 8.2 or higher

== Frequently Asked Questions ==

= How does the scanning work? =

Muri Link Tracker uses WordPress Action Scheduler to process scans in the background. Posts are scanned in configurable batches, and each link is verified via HTTP HEAD request with GET fallback. Progress is stored in dedicated database tables and advances on the server, even after you close the dashboard. Action Scheduler needs working WP-Cron or a server cron runner; low-traffic sites should configure a server cron.

= What's the difference between Full Scan and Delta Scan? =

**Full Scan** re-indexes every link on your site and re-checks each URL's HTTP status. **Delta Scan** only processes posts modified since the last successful scan — ideal for recurring maintenance on large sites. Use Full Scan after major content migrations, Delta Scan for weekly check-ups.

= Does it slow down my site? =

Scans consume server resources. Work is split into configurable extraction batches and one HTTP URL per checking job, with a request budget and response-size limit. Adjust batch size, timeout and request delay for your hosting. Dashboard requests only read progress; they do not execute background jobs.

= What affiliate networks does it detect? =

Amazon, ShareASale, Commission Junction (CJ), Impact, Rakuten, ClickBank, Awin, Tradedoubler, Webgains, Commission Factory, FlexOffers, Skimlinks, Sovrn/VigLink, and PartnerStack. You can extend detection patterns via the `mltr/classifier/affiliate_patterns` filter.

= Can I detect cloaked affiliate links? =

Yes. When an internal URL (e.g., `/go/product-name`) redirects to an external affiliate, Muri Link Tracker flags it as **Cloaked** so you can audit your entire affiliate footprint, even when it's hidden behind pretty URLs.

= Can I scan only specific post types? =

Yes. In Settings, enter a comma-separated list of post types (e.g., `post, page, product`) to include in scans. Any custom post type is supported.

= What happens when I delete or edit a link? =

Editing updates the matching anchor attributes in content and excerpts while preserving unrelated HTML, images and formatting. Removing a link unwraps the anchor and preserves its children. The post's modification date stays unchanged. A native WordPress revision stores the previous content and excerpt, and the content and link inventory are committed together. Global editing requires InnoDB tables, permission to edit every source, and revisions enabled for those posts. Links that also occur in custom fields or block attributes are read only: edit them in WordPress, then rescan. Restore an earlier version through the article's WordPress Revisions screen and run a Delta Scan to refresh the inventory. Purge your page cache after edits if you use a caching plugin.

= Can I cancel a scan that's already running? =

Yes. A **Cancel Scan** button appears next to the progress bar during any active scan. Partial results are preserved.

= Can I resume an interrupted scan? =

Yes. If a scan is cancelled, crashes, or is interrupted by a server restart, you can resume it from the dashboard. The plugin tracks scan progress and picks up right where it left off.

= How often are links re-checked? =

You can configure a **Recheck Interval** (in days) from the Settings panel. Default is 7 days. A daily background action checks links older than that threshold. Delta scans check new or stale URLs; Full Scan checks every URL. Cancelled or failed scans wait for your explicit resume or a new scan.

= Is the plugin translation-ready? =

Yes. All user-facing strings are internationalized and the text domain is `muri-link-tracker`. A POT file ships in `/languages/` for community translations.

== Screenshots ==

1. **Complete SEO Overview** — After a scan, the Dashboard shows the full picture at a glance: total links, OK/redirects/broken/errors counts, internal vs external vs affiliate vs cloaked breakdown, and a per-network affiliate tally (Amazon, CJ, Awin…).
2. **Start in Seconds** — A clean Dashboard greets you with three clear actions: Full Scan, Delta Scan, or Reset Data. Delta Scan saves time on large sites by only checking posts modified since your last scan.
3. **Focus on What's Broken** — The Broken filter surfaces every dead link (404, 410, DNS errors) in one view, ready for bulk fix or deletion. Silent editing keeps your post modified dates untouched.
4. **Track Every Redirect** — The Redirects filter lists every link that forwards through one or more hops, so you can update them to their final destinations and preserve link equity.
5. **Every Link at a Glance** — The link inventory mixes OK, broken, redirected, and errored URLs in a single sortable table, color-coded by status for instant triage.
6. **Real-Time Scan Progress** — Watch scans unfold live: progress bar, item counters, and summary stats update in real time. Cancel at any moment — partial results are preserved and the scan can be resumed later.
7. **Fine-Tune to Your Hosting** — The Settings panel lets you define which post types to scan, adjust batch size, set HTTP request timeouts and delays, and configure the automatic recheck interval to match any hosting environment.

= URL exclusions and custom fields =

Exclusions match an exact URL or a pattern with `*` for any sequence of characters (for example `https://example.com/private/*`). They apply to extraction and HTTP verification. Custom field scanning reads public, non-protected text values and does not permit global edits of those fields.

= Build and verify from source =

Run `composer install`, `npm ci` and `npm run build` before installing a checkout. Run `vendor/bin/phpunit`, `vendor/bin/phpstan analyse --memory-limit=1G`, `npm run test:js -- --runInBand`, `npm run lint:js` and `npm run lint:css` to check changes. CI also exercises WordPress 6.9 with MariaDB 11.4 using `tests/integration/queue.php` and `tests/integration/content-editing.php`. These WP-CLI fixtures require a disposable local installation and must never run on a live site.

= Upgrading from the original queue =

The plugin adds scan and checkpoint tables automatically (database schema version 2). Existing link records are retained. Old transient-based scans cannot be resumed into the new queue: start a Full Scan once after updating. Keep normal database backups before updates. Daily maintenance removes occurrences from deleted or unpublished posts. After changing exclusions, selected post types, or restoring an article revision, run a Full Scan to rebuild the relevant inventory.

= HTTP verification limits =

Requests use WordPress's safe HTTP API, verify TLS and revalidate every redirect. Private, loopback, reserved and unresolved destinations are skipped. Unknown public internal URLs are verified over HTTP instead of being assumed valid. DNS checks are defence in depth; DNS rebinding protection also depends on outbound network controls at the hosting provider. A successful status check does not establish that a destination is trustworthy.

== Changelog ==

= Unreleased =
* Harden HTTP destination validation, redirects, timeouts and bounded response reads.
* Persist resumable scans with server-driven transitions, retries and stale-worker protection.
* Preserve source HTML and per-occurrence rel values; add transactional edits, native recovery revisions and URL merging.
* Stabilize dashboard requests, filters, confirmations, settings and error recovery; include DataViews styles.
* Bound CSV memory and paginate exports by ID while retaining active filters.
* Update compatible dependencies and add unit, integration and CI checks.

= 1.0.0 =
* Initial release.
* Background link scanning via Action Scheduler with resume capability.
* Three scan modes: Full Scan, Delta Scan (modified posts only), and Reset Data.
* Parallel HTTP checking with HEAD/GET fallback.
* Affiliate link detection for 14+ networks, including cloaked internal redirects.
* Redirect chain and loop detection (single, chains of 2+, loops).
* Per-affiliate-network breakdown in the Dashboard.
* Modern React admin dashboard with real-time scan progress and cancel control.
* Link table with quick filter tabs (All / Broken / Redirects / OK / Pending), search, advanced filters, pagination, and bulk actions.
* Inline link editing with URL and rel attribute updates — silent (no post_modified bump, no revision).
* CSV export with active filters.
* Scan resume after interruption.
* Configurable post types, batch sizes, timeouts, request delays, and recheck intervals.
* Full internationalization support (i18n ready).

== Upgrade Notice ==

= 1.0.0 =
Initial release of Muri Link Tracker.
