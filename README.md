# Entity Metrics

Records node page views and media downloads, with local geolocation enrichment.
Development uses the `1.x` branch; releases use semantic versions such as `1.1.0`.

## Local geolocation setup

Requires Drupal 10 or 11, `maxmind-db/reader`, and GeoIP Auto-Update with City
support. Composer installs the dependencies. GeoIP Auto-Update 1.0.0 supports
only Country; apply `patches/geoip-autoupdate-city.patch` to that dependency until
the companion changes are released. The patch adds the City edition selector,
daily checks, validated atomic replacements, and Drupal 11 settings-form support.
Composer patches must be configured in the **site's root** composer.json, for
example with the site's existing `cweagans/composer-patches` plugin:

```json
{
  "extra": {
    "patches": {
      "drupal/geoip_autoupdate": {
        "Support City databases and safe daily updates": "patches/geoip-autoupdate-city.patch"
      }
    }
  }
}
```

Copy the supplied patch into the site's `patches/` directory before updating
dependencies. Do not apply it to a release that already includes these changes.

1. Configure an existing, writable local private filesystem directory in
   `settings.php` (`$settings['file_private_path']`).
2. Enable GeoIP Auto-Update and run database updates and a cache rebuild:
   `drush en geoip_autoupdate -y`, `drush updb -y`, `drush cr`.
   For a new installation, also enable `entity_metrics`.
3. At `/admin/config/system/geoip/autoupdate`, enter the MaxMind account ID and
   license key, choose **City**, and use **Download now**. Credentials must allow
   GeoLite2-City downloads. For deployments that export configuration, keep
   credentials in environment-backed `settings.php` config overrides instead.
4. At `/admin/config/system/entity-metrics`, enable local geolocation on cron.
   The default database is `private://GeoLite2-City.mmdb`; the default batch is
   500 events. An absolute local City MMDB path is also supported.
5. Run the backlog from a CLI job, outside the web request timeout:

```sh
drush entity-metrics:geolocate --batch-size=1000
```

The command commits each bounded batch and resumes from per-event status if
interrupted. It processes both page views and downloads. No visitor IP is sent
to a remote lookup API. IPv4 and IPv6 are supported.

Normal Drupal cron checks the download provider at most daily and downloads
only when its Last-Modified value changes or the local file is missing/invalid.
The checks require regular Drupal cron runs. Each cron run also enriches one
configured batch. To force a database download or explicitly retry unmatched
public addresses after installing a newer database:

```sh
drush entity-metrics:geoip-update
drush entity-metrics:geolocate --retry-unknown --batch-size=1000
```

## Storage and data definitions

- The MMDB is a local lookup file; it contains no recorded visitor events.
- `entity_metrics_data` stores events and their `region_id` reference.
  `geolocation_status` is 0 (pending), 1 (located), or 2 (unresolved).
- `entity_metrics_regions` stores country (ISO two-letter code), region
  (state/province), city, latitude, and longitude. A location fingerprint reuses
  matching regions instead of creating one row per event.
- City databases do not contain every field for every IP. Missing names remain
  empty and missing coordinates remain NULL; maps omit rows without coordinates.
  Locations are approximate network locations, not precise reader positions.
- Events from the most recent minute wait so their addresses remain available
  for the recording endpoint's rolling flood check. Resolved addresses are
  cleared after enrichment; private/invalid addresses are cleared as unresolved.
  Unmatched public addresses remain stored for explicit retry, but are not
  repeatedly processed on every cron run.
- Existing valid regions are preserved. Events whose addresses were already
  erased cannot be newly geolocated. Historical backfill uses the current MMDB,
  which may differ from the network's location when the event occurred.
- Missing/corrupt databases and failed transactions leave events pending for a
  later run. Database refresh failure preserves the previous working MMDB.

External aggregate reports do not automatically change when source rows receive
regions. Consumers such as Lehigh Analytics must read retained summaries as well
as raw events before rebuilding after historical enrichment. A rebuild from only
`entity_metrics_data` will omit events already rolled up.

## Retained history and rollups

Rollups are part of the main module. Run `drush updb -y` and `drush cr` when
upgrading; update 10004 creates the summary tables without deleting any events.
Each cron run then processes one bounded batch. To drain the backlog manually:

```sh
drush entity-metrics:rollup --batch-size=500
```

- The most recent 31 days remain raw, preserving the exact rolling 30-day count
  and the optional rate limiter's recent IP lookup.
- `entity_metrics_counts` retains daily counts grouped by entity type, ID, and
  `cookie_set`. Complete months older than a year compact into monthly counts.
  UTC defines days and months; the month containing the one-year boundary remains
  daily until the following month. Older backfilled events go directly into
  monthly buckets when eligible.
- `entity_metrics_map` retains one row per entity type, ID, and `region_id`,
  including an event count and the latest timestamp. Region records retain the
  coordinates and labels. Old map entries no longer repeat once per visit day.
- Only resolved events with an existing region and a cleared IP are eligible.
  Pending, unresolved, or inconsistent geolocation rows remain raw, including
  addresses awaiting a database retry. Sites without geolocation will therefore
  retain their raw events.

Each transaction locks its source rows, writes both summaries, and reads back
their exact keys, counts, and latest map timestamps. Only after verification
does it delete those specific source IDs. Daily-to-monthly compaction likewise
verifies the destination before deleting daily rows. A mismatch or SQL failure
rolls back the entire batch, including any earlier deletions. Re-running resumes
from remaining source rows without counting events twice. Use transactional
database tables (Drupal's default InnoDB on MySQL/MariaDB).

The visit-count endpoint and map read raw events and summaries in single SQL
statements so concurrent rollups cannot create a gap or double count. Staff
exclusion on counts, collection membership, and map view-access checks remain
in effect. Individual timestamps and session IDs are intentionally discarded
after rollup; this is not an event-level archive.

Update any external reports that query only `entity_metrics_data` before cron
runs on the upgraded site. Backups must include both summary tables and
`entity_metrics_regions`, in addition to the remaining raw events.

## Tracking validation

The write endpoint accepts only canonical `node/<positive integer>` paths for
existing nodes the caller can view. Negative, zero, malformed, oversized, missing,
and inaccessible IDs fail before inserting events. Count requests similarly
validate the allowed entity type (`node` or `media`), ID, and view access.

Media downloads count only requests without a `Range` header. All range requests
are excluded, including ranges starting at zero. These counts represent requests
for whole files, not confirmation that the transfer completed.

### Clean up historical progressive downloads

The post-update hook in `entity_metrics.post_update.php` automatically cleans up
existing raw download events when you run:

```sh
drush updb -y
```

Events are grouped by media ID and region ID within 3600 seconds of the first hit
(lowest ID breaks timestamp ties). Groups with multiple hits are treated as viewer
traffic and deleted entirely, including the first hit. Single-hit groups remain.
A 12:45 hit groups through 13:45, across the 13:00 clock-hour boundary. Repeats do
not extend the window, even after the first hit is deleted. The next event beyond
that window starts a new window. Events without a region ID and node views are
left alone. The update scans 500 rows per transaction and preserves the current
window between batches. Events inserted after it starts are excluded.

This is a heuristic: historical events do not store the Range header. Downloads
by different visitors in the same region within that hour are grouped together
and will also be deleted if the group has multiple hits.
Back up before updating and pause cron and metrics maintenance jobs during the
update. Run after geolocation has assigned regions and before rollup discards
individual events. Already rolled-up counts
cannot be deduplicated from the retained summaries; restore raw events from an
archive if those counts need correction. Rebuild external aggregate reports after
cleanup. Each batch shares the geolocation and rollup lock.

## Optional rate limiting

The main module does not rate-limit page views. To limit recording to twenty
events per IP address in a rolling minute, enable the optional submodule:

```sh
drush en entity_metrics_ratelimiter -y
```

The submodule rejects further page views with HTTP 429 before inserting an event.
As before, recent page views and media downloads both count toward the limit;
downloads themselves are not rate-limited. Geolocation retains addresses from
the most recent minute so the check can count them.

Installing the submodule creates `ip_timestamp` on
`entity_metrics_data (ip_address, timestamp)`, or adopts the existing index.
Uninstalling it removes the index without deleting metrics:

```sh
drush pm:uninstall entity_metrics_ratelimiter -y
```

When upgrading an existing site, enable the submodule **before** `drush updb` to
retain rate limiting and its index. Otherwise, update 10003 removes the old index
and rate limiting remains off. Fresh main-module installs omit the index.

## Tests

Drupal kernel tests under `tests/src/Kernel` cover tracking validation, local
IPv4/IPv6 lookup, resumable batches, schema upgrades, optional rate limiting and
its index lifecycle, rollup retention and report reads, monthly compaction, and
transaction rollback after failed verification or writes.
Fixtures are synthetic MaxMind test databases, not production lookup data.
