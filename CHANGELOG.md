# Changelog

All notable changes follow semantic versioning.

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
