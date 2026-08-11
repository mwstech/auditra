# Phase 2: the 1.1 backlog

Work deliberately deferred past the 1.0.0 submission. Nothing here blocks a
release; each entry records what was measured, why it was left alone, and what
would fix it.

## Cache warming on endpoint enable

**The cost.** The first `list_plugins` call on a 45-plugin site takes **~8
seconds and ~20 MB peak memory** (measured 2026-07-28 on the seeded local
clone, cold enrichment store). It resolves every installed plugin against
wordpress.org and the WPVulnerability API and fetches the WordPress
release-cycle list, in one request. Subsequent calls answer from the persistent
store in **~0.2 seconds and ~7 MB**. `check_vulnerabilities` has the same shape
but a smaller cold cost: 1.7 s and 13 MB.

**Why it is not a bug.** The work is real and happens inside the client's own
request; it costs site visitors nothing, and the plugin does nothing at all on
normal page loads. Documented in the readme FAQ as expected behaviour.

**Why it is worth fixing.** Eight seconds is the first impression the product
makes, it lands on a client that may have a short tool-call timeout, and the
user is sitting there watching it.

**What would fix it.** Warm the cache in the background when the endpoint is
enabled on the settings page, so the store is populated before the first
question arrives. Sketch:

- On the enable action, schedule a one-off `wp_schedule_single_event` a few
  seconds out. WP-Cron fires on the next front-end request, which on a live
  site is essentially immediate.
- The handler resolves the inventory and calls the wordpress.org and
  vulnerability clients exactly as `list_plugins` does, then discards the
  result. The store is the point.
- Reuse the existing batching (8 concurrent) and the existing backoff, so a
  down upstream degrades the warm-up rather than hammering it.
- Skip entirely when the store already holds fresh entries for every installed
  slug, so re-enabling is free.

**Constraints that must hold.** No outbound request unless the endpoint is
enabled — a disabled install stays inert, which is the whole posture of the
plugin. No new option, no new schedule that survives deactivation, and
uninstall must clear it. Sites with `WP_HTTP_BLOCK_EXTERNAL` must no-op rather
than log failures.

**Open question.** Whether to re-warm periodically (a daily event) or only on
enable. Periodic warming makes every question fast but turns a passive plugin
into one making daily outbound requests on sites nobody is querying, which is
exactly the traffic pattern that gets a plugin blocked at a volunteer-run API.
Lean toward warm-on-enable only.

## Refreshing the lifecycle table

`includes/data/lifecycle.json` carries published end-of-life dates for PHP,
MySQL and MariaDB (decision 67). It needs attention when a **new version cycle
appears upstream**, not when Auditra ships a release: three Auditra releases in
a quarter with no new PHP or MariaDB cycle need no table change at all.

**What ages.** Only missing rows. Dates already in the table are vendor
commitments and do not move — PHP 8.1's end-of-life date was fixed years before
it arrived. What accumulates is new cycles the table has never heard of.

**What happens if it is not refreshed.** Nothing breaks and nothing lies. A
version whose cycle is absent is reported with no support claim attached,
exactly as an unavailable source behaves. The failure mode is silence.

**Sources, in this order — vendors only, never an aggregator:**

- PHP: https://www.php.net/supported-versions.php and https://www.php.net/eol.php
- MySQL: Oracle's Lifetime Support Policy for MySQL
- MariaDB: https://mariadb.org/about/maintenance-policy/

WordPress is deliberately absent from the file: its status comes live from
wordpress.org's stable-check endpoint and never needs refreshing.

**Cadence.** Quarterly is comfortably enough — PHP ships one minor a year, and
MySQL and MariaDB move slower. Benny's intent is a scheduled internal check
(calendar entry, or a cron that emails a reminder) once the plugin is live;
that tool is out of scope for the plugin itself and belongs in the internal
toolchain, not in shipped code. Worth pairing the reminder with a diff of the
three vendor pages so the check is "has anything changed?" rather than a
re-read.
