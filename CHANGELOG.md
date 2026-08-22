# Changelog

All notable changes follow semantic versioning.

## [1.5.0] - 2026-08-21

### Changed — Operational noise reduction (Condition Policy + curated TV Mode)

Follows an explicit audit (see PR history) that traced the root cause of
excessive Critical noise: generic Alert Rules (e.g. "Sensor over/under
limit - Check Device Health Settings") applied their own blanket severity
to every device they fired on, and this dashboard's own fallback used
only a numeric-vs-state axis — neither distinguished a humidity reading
from a failed power supply.

- **New: Support\ConditionBucket** — the single, centralized vocabulary
  (`device_down`, `hardware_state`, `voltage`, `battery`, `fan`,
  `temperature`, `humidity`, `cpu`, `memory`, `storage`,
  `service_critical`, `service_warning`, `other`) every severity and TV
  decision now reads, translated once from the existing
  `Page::metricProblemType()` classification — never duplicated in
  Blade, Page, OperationalPolicy, or TV filtering.
- **New: per-condition-bucket Operational Priority policy**
  (`Support\OperationalPolicy::resolveConditionIssues()`, replacing
  `resolveFallbackIssues()`) — Critical/Warning/Monitor/Ignore is now
  selected by condition bucket × Operational Critical tier, not by a
  numeric-vs-state axis. Runs unconditionally for every device from
  already-loaded sensor/service data (no additional query), independent
  of whether any Alert Rule exists. Default matrix matches the audited
  target: Device Down/Hardware-State/Voltage/Battery/Fan stay
  Critical/Warning (unchanged); Temperature is now Warning/Monitor;
  Humidity, CPU are Monitor on both tiers; Memory/Storage are
  Warning/Monitor — quieting exactly the noise the audit identified,
  without hiding it (Monitor issues remain visible, just non-actionable).
- **New: Alert Rule Handling** (`Support\AlertRules::HANDLING_*`,
  `idf_alert_rule_handling`) — a third column in the Alert Rules table.
  `Direct severity` (the default, fail-safe, backward-compatible
  behavior) uses the rule's own severity as-is. `Condition policy`
  treats a firing rule only as proof a real condition exists — actual
  severity comes entirely from the condition-bucket policy above,
  falling back to the rule's own Direct severity if this plugin cannot
  correlate the firing to any currently-violating sensor/service on that
  device (a real failure is never silently dropped). `Monitor` keeps a
  rule's firing visible without ever becoming Critical/Warning.
- **New: Monitor is not a new severity tier.** Implemented entirely via
  the existing `actionable` mechanism on an issue — visible in device
  telemetry/details, never in Priority Attention, device/location
  health, header counters, or TV Mode. No second severity engine.
- **New: TV Presentation Policy** (`Support\TvPresentationPolicy`,
  `tv_show_severity_critical`/`tv_show_severity_warning`,
  `tv_show_condition_*`) — TV Mode is now a curated wall/NOC projection,
  filtered per issue/cause (not per device) using the same condition
  buckets, entirely on top of already-computed severity. A device with
  several simultaneous conditions still appears on TV if any one remains
  TV-eligible, credited to that cause; its TV card shows only the
  TV-eligible causes, while the normal dashboard keeps every condition
  untouched. Pure presentation — provably cannot alter device health,
  location health, Priority Attention, header counters, Alert Rule
  inclusion, or telemetry. Defaults: Device Down/Hardware-State/
  Voltage/Battery/Fan/Service Critical on; Service Warning/Temperature/
  Humidity/CPU/Memory/Storage/Other off. The five pre-existing
  `tv_hide_*` settings (Healthy/Unknown/Stale/Maintenance/No-sensor) are
  unchanged — they govern non-problem severity tiers the condition-bucket
  system deliberately never touches.
- **Removed:** the numeric-sensor/state-sensor fallback split
  (`fallback_numeric_sensor_*`, `fallback_state_sensor_*`) and the old
  rule-name-substring Policy Health heuristics
  (`deviceDownRuleCheck`/`serviceRuleCheck`) — both superseded by the
  explicit, administrator-declared mechanisms above. Policy Health now
  reports a Handling breakdown and Condition-policy correlation status
  instead.
- **Fixed:** `Support\UpdateStatus`/Settings now distinguish all three
  installed-vs-stable states (`installed < stable` → update available,
  `== ` → current, `>` → "running ahead of the latest published stable
  release") — previously the third state silently displayed the same
  "installed version is current" message as the second.
- **Audited, not changed:** two suspicious threshold readings found
  during the audit (`Voltage 0V — below low limit 0V`,
  `Temperature 105.8°F — below warning limit 221°F`) were traced to
  `IssueBuilder::evaluateNumericSensor()`/`Page::sensorThreshold()`.
  Both comparisons execute exactly as designed; the underlying values
  point to bad/misassigned LibreNMS-side threshold data (a 0 low-voltage
  threshold with no way to distinguish "configured" from "default", and
  an implausible 221°F low-temperature limit) rather than a bug in this
  plugin. Deliberately left unchanged rather than blanket-treating every
  zero threshold as unset, which would risk silently ignoring a
  legitimately-configured 0V floor — see `tests/run.php`'s own
  regression-pinning tests for both cases.

## [1.4.0] - 2026-08-21

### Changed

- **Settings UX simplification.** The Settings page is restructured to:
  Refresh & Timing, Dashboard Display, Operational Priority, Alert Rules,
  Sensor/Data Quality, TV Mode, Updates, Advanced. "Operational Priority
  Policy" and "Operational Severity Policy" are merged into one
  "Operational Priority" section with a real Device Groups multi-select,
  the Maintenance toggle, and a read-only effective-policy summary table
  sourced live from `Support\OperationalPolicy::effectivePolicySummary()`
  (never hardcoded Blade prose). Detailed per-condition severity overrides
  moved into a collapsed "Advanced" section; no new global "Fallback
  Safety Net enabled" master switch was added.
- **Multiple Operational Critical Device Groups.** The free-text,
  single-name Operational Critical Device Group setting is replaced by a
  real multi-select of LibreNMS Device Groups
  (`operational_critical_group_ids`, `Support\DeviceGroups`). A device is
  Operational Critical if it belongs to **any** selected group. Both
  static and dynamic LibreNMS groups are supported (membership is read
  from `device_group_device`, which LibreNMS's own poller already
  materializes for both group types). Deleted/invalid/duplicate IDs are
  normalized away safely; renaming a group no longer breaks the
  selection, since group identity is now a real ID, not a name string.
- **Alert Rules simplified to one table** — Alert Rule, Severity, Include,
  Device Down. `CATEGORY_SENSOR`/`CATEGORY_SERVICE` tagging is removed
  entirely (config parsing, Settings controls, Policy Health checks,
  help text, tests) — no exact per-sensor/per-service identity exists on
  a fired LibreNMS alert in this schema, so a tag for either could never
  safely suppress a fallback and was documentation-only. Device Down
  remains the sole exact-correlation tag
  (`Support\AlertRules::DEVICE_DOWN_SETTING_KEY`, formerly
  `CONDITION_SETTING_KEY`/`resolveConditionCoverage()`, now
  `resolveDeviceDownTaggedIds()`).
- Fixed contradictory Sensor/Data Quality copy that claimed this
  dashboard never evaluates sensor thresholds itself — the Operational
  Priority fallback still does, when enabled.

### Migration

- **Legacy compatibility (one release only).** The old
  `operational_critical_group_name` setting is still read, but only when
  an administrator has never saved the new `operational_critical_group_ids`
  multi-select, and only when the legacy name resolves to **exactly one**
  real group (case-insensitive). An empty, unresolved, or ambiguous
  legacy name resolves to an empty selection — fail-safe, matching this
  plugin's standing "no exact identity = no suppression" invariant. No
  migration ever runs automatically on a page read; the moment an
  administrator saves the new multi-select (even as an empty selection),
  `operational_critical_group_ids` becomes the sole runtime authority and
  the legacy name is never consulted again. The legacy textbox is removed
  from Settings; the field itself is planned for removal from
  `Support\Config::FIELDS` in the next release after this one.

## [1.3.0] - 2026-08-07

### Fixed

- **P1 regression**: TV Mode stopped respecting the Critical/Warning-only
  severity policy configured in Settings, showing Healthy/informational
  devices it should have excluded. `default_severity_*` were previously read
  only by client-side JavaScript; every server-computed collection (location
  groups, Priority Attention, header summary counters) was built from the
  full authorized device set and filtered after the fact with CSS, not
  server-side. `Page.php` now builds real server-side pre-filtered
  collections through one centralized decision,
  `Support\ProblemPolicy::deviceVisible()`, backed by
  `Support\Config::visibilityPolicy()`.
- Desktop's Overview inline Priority Attention / Critical Locations panels,
  and the header summary counters (`$visibleSummary`), were each
  independently re-deriving visibility from the Phase 2 interactive filter
  only, never the org-wide severity policy — both now consume the same
  centralized decision as TV Mode and the persistent Priority Attention
  banner.
- TV Mode's client-side-only toggle button (the classic desktop grid's own
  "TV Mode" control, distinct from the `?tv=1` navigation link) bypassed the
  five `tv_hide_*` TV-only restrictions entirely, since its shared
  `tvDeviceMatches()` function read from the global-context policy object.
  It now reads a separate TV-context policy object
  (`data-idf-tv-only-defaults`) carrying `tv_hide_*`, while the desktop
  classic grid's own default filter deliberately continues to use the
  global-context object so a TV-only restriction can never leak into
  desktop's default browsing experience.
- Real Blade compiler swallow-content bug: `storePhpBlocks()`'s
  `(?<!@)@php(.*?)@endphp` extraction regex does not match across newlines,
  so a second `@php ... @endphp` pair later in the same document could
  silently swallow every static HTML/Blade directive between two `@php`
  blocks. Restructured `page.blade.php` so no swallowable gap exists between
  its two `@php` blocks, and added a permanent regression test that
  recompiles the real template and asserts the previously-swallowed section
  survives, in order, in both the plain and `?tv=1` renders.

### Added

- Three severity settings that had no equivalent before:
  `default_severity_stale`, `default_severity_maintenance`,
  `default_severity_no_sensor` (Maintenance previously followed the Healthy
  toggle unconditionally).
- Five `tv_hide_*` settings that can only ever further restrict TV Mode —
  structurally impossible to re-enable a globally-disabled severity for TV
  specifically.
- `tv_maximum_devices_rendered` — a defensive, worst-first-sorted TV
  rendering ceiling (default 200, clamped to 10–2000) with an explicit
  omitted-device count, never a silent drop.

### Changed

- Operational text CSS (`.priority-cause`, `.device-state`, `.metric`,
  `.service-issue`, etc.) now guarantees a 12px floor everywhere, not only
  inside narrow-viewport `@media` breakpoints; TV's `.priority-cause`
  specifically renders at 14px. Resolves the 9–11px known limitation
  disclosed in 1.2.0.
- TV Mode's HTML/DOM size is now measurably smaller than the full-fleet
  Overview view at every scale, since it is built from real server-side
  pre-filtered collections instead of the full authorized set hidden with
  CSS. Measured on this release's own CI (small/medium/large =
  20/200/1,000 devices): HTML bytes reduced 24% / 68% / 84%; DOM nodes
  reduced 50% / 79% / 86%, versus the Overview view at the same scale.
  Resolves the unbounded-TV-size known limitation disclosed in 1.2.0.

### Known limitations

- The six semantic TV slides (Environment/Power, Network, Servers, IDF,
  Other Locations, Overview as discrete rotating full-screen slides with
  independent pacing) discussed in planning are **not** part of this
  release. TV Mode continues to rotate through the same MDF Servers/Power/
  Infrastructure/IDF/Other Locations section grid as prior versions, now
  correctly severity-policy-filtered and size-bounded. Deferred to a future
  release.

## [1.2.0] - 2026-08-06

### Added

- Server-side navigation: `Overview` (unchanged, now default), paginated
  `Locations` and `Devices` lists, and deep-linkable `location`/`device`
  detail views, all built from `Support\DeviceAccess::query($user)` with no
  separate authorization path.
- Search, severity/category filters, and a whitelisted `sort`/`direction`
  contract for the `Devices` list.
- Defensive pagination (`page`/`per_page`, clamped, never errors on
  out-of-range input) so `Devices`/`Locations`/`location` HTML stays bounded
  regardless of fleet size.
- New Settings group `Navigation & Lists`: `default_view`,
  `devices_per_page`, `show_healthy_locations`, `maximum_priority_issues`,
  `default_problems_only`.
- `Support\DeviceClassifier::CATEGORIES` constant exposing the supported
  device-classification categories.
- Real LibreNMS 26.8 + MariaDB 11.7 integration test
  (`tests/Feature/Plugins/IdfDashboard/DeviceAccessTest.php`) covering
  admin/global-read/limited/no-access authorization, authorized vs.
  unauthorized deep links, search, pagination isolation, maintenance
  inheritance, and a representative small/medium/large performance fixture.
- CI now runs that integration test and a dedicated small/medium/large
  performance matrix (20/500, 200/6,000 and 1,000/30,000 devices/sensors)
  against real LibreNMS + MariaDB on every pull request and on `main`; the
  release job requires all of it to pass.

### Changed

- Deep links to an unauthorized or nonexistent device/location return no
  data or metadata (`found: false`), never a generic error that could
  imply existence.

### Known limitations

- `Overview` and `TV` intentionally render every authorized location (TV
  rotation needs the full set to cycle through two devices per card at a
  time), so their HTML/DOM size grows with total fleet size rather than
  staying bounded like the paginated views. Measured at 1,000 devices /
  30,000 sensors: `TV` reached 474,531 bytes / 6,168 DOM nodes, versus
  131,007 bytes / 563 nodes for the paginated `Devices` view at the same
  scale. Still well under the 1 MiB per-view ceiling and no query-count or
  timing regression (query count stayed fixed at 40 across all three
  scales), but a real characteristic worth tracking for very large
  deployments.
- Some operational text (`.priority-cause`, `.device-state`,
  `.service-issue`) declares a 9–11px font-size in its base CSS rule,
  outside any `@media` breakpoint, so it is not gated to narrow viewports
  only. Confirmed by static analysis of real rendered HTML from the passing
  integration test; not yet confirmed against a live rendered browser at the
  documented viewports (1366×768, 1440×900, 1920×1080, 1024×768 tablet,
  TV 1920×1080), which was not possible in the environment that prepared
  this release.

## [1.1.0] - 2026-08-05

### Added

- Centralized operational severity and freshness states reused by summaries,
  device/location cards and Priority Attention.
- Added deterministic single-category device classification with an explicit
  reason and `Other` fallback.
- Added structured, deduplicated issues with exact causes, current values,
  units, thresholds, timestamps, age, device, location and LibreNMS links.
- Added device outages and recent recovery, maintenance state, unknown/stale
  telemetry and informational `No sensor installed` coverage.

### Changed

- Prioritized actionable device, sensor, service and alert causes consistently
  in desktop and TV views, with bounded display and improved long-text layout.
- Added TV connection, refresh and last-updated state without extra polling or
  duplicate timers.
- Require v1.0.4 to be installed first when upgrading automatically from an
  earlier `1.0.x` release; clean v1.1.0 installations remain supported.

## [1.0.4] - 2026-08-05

### Security

- Added an updater bridge with closed, version-selected package profiles: the
  legacy `1.0.x` profile accepts exactly 14 files and the functional `1.1.x`
  profile accepts exactly 18 files.
- Reject packages with missing or additional files, unknown profiles, invalid
  target versions, mismatched archive checksums, symbolic links, traversal or
  excessive ZIP expansion before activation.

### Changed

- Require v1.0.4 as the updater bridge before an automatic upgrade from an
  earlier release to v1.1.0 or newer.
- Preserve the exact v1.0.3 dashboard behavior, hooks and views; this release
  contains no functional or visual dashboard changes.

## [1.0.3] - 2026-08-04

### Security

- Reject release archives with more than 64 entries or more than 25 MiB of
  uncompressed data before extraction, preventing compressed ZIP exhaustion.
- Added a validated `--recover` path for an activation interrupted after the
  active plugin was moved to its exact external backup. Recovery never
  overwrites an existing active plugin, requires the exact LibreNMS root so
  custom backup locations remain recoverable, and is recorded in the external
  audit log.
- Added an external-staging free-space preflight and made cleanup failures
  explicit instead of silently reporting successful retention.

### Fixed

- Abort background dashboard refreshes after 30 seconds so an abandoned HTTP
  request cannot leave a long-running wall display permanently stale.
- Run the authorization and isolation integration tests against the pinned
  LibreNMS 26.8 source and MariaDB before any release job can start.
- Exercise bootstrapped v1.0.0/v1.0.1-to-candidate upgrade paths on Linux and
  validate the release workflow with Node.js 24-based checkout/setup actions.

## [1.0.2] - 2026-08-04

### Security

- Corrected the critical backup location: backups, staging, rollback evidence,
  locks and audit logs now stay in external storage, defaulting to
  `/opt/librenms/plugin-backups/IdfDashboard`, never inside `app/Plugins`.
- Prevented LibreNMS outages caused by `PluginProvider` scanning backup or
  temporary IdfDashboard directories as PHP plugins.
- Added defensive migration of recognized legacy backup, old, new, failed,
  rollback and pending directories out of `app/Plugins`; ambiguous or unsafe
  paths abort without deletion.
- Improved lock diagnostics so contention, permission denial and corrupt locks
  have distinct messages.
- Added same-filesystem atomic activation and automatic rollback from a
  validated external backup while retaining failed-package evidence externally.

## [1.0.1] - 2026-08-04

### Fixed

- Kept the Settings hook registered by returning `true` from its hook-level
  authorization; LibreNMS 26.8 continues to enforce `plugin.admin` in
  `PluginSettingsController` for both reading and saving settings.
- Kept Menu and Page hooks registered instead of discarding them through a
  `viewAny` policy call that is not usable at this hook boundary in 26.8.
- Removed hook-level calls to unsupported or unsuitable User authorization
  methods while preserving granular SQL filtering through
  `Device::query()->hasAccess($user)`.
- Added PHP 8.3 to CI and expanded LibreNMS integration coverage for hook
  registration, controller-protected Settings and empty/limited dashboards.

## [1.0.0] - 2026-08-04

### Added

- Stable plugin version source in `Support/Version.php`.
- Cached stable-channel release status on the administrator Settings page.
- CLI-only release updater with HTTPS/repository/tag/asset/SHA-256 validation,
  package validation, PHP lint, locking, atomic activation, audit logging,
  backup retention and automatic rollback.
- Updater dry-run/help modes, configurable backup retention and explicit-only
  downgrade/reinstall safety overrides.
- GitHub Actions validation and tag-only release packaging.

### Fixed

- Enforced LibreNMS's `viewAny` Device policy for dashboard and menu access and
  `plugin.admin` for plugin Settings.
- Restricted the initial device query with LibreNMS's official
  `Device::hasAccess($user)` scope, so every downstream query, location,
  summary and refresh is derived only from authorized device IDs.
- Applied Settings changes, refresh interval and animation policy to already
  open dashboards during soft refresh.
- Unified `default_problem_stale` across severity, cards, counters, filters,
  detail and Priority Attention.
- Limited recent event-log results in SQL to three per device with a defensive
  global ceiling and explicit incomplete-coverage state.
