# Changelog

All notable changes follow semantic versioning.

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
