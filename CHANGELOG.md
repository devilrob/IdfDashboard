# Changelog

All notable changes follow semantic versioning.

## [1.0.0] - 2026-08-04

### Added

- Stable plugin version source in `Support/Version.php`.
- Cached stable-channel release status on the administrator Settings page.
- CLI-only release updater with HTTPS/repository/tag/asset/SHA-256 validation,
  package validation, PHP lint, locking, atomic activation, audit logging,
  backup retention and automatic rollback.
- GitHub Actions validation and tag-only release packaging.

### Fixed

- Enforced `device.viewAny` for dashboard and menu access and `plugin.admin`
  for plugin Settings.
- Applied Settings changes, refresh interval and animation policy to already
  open dashboards during soft refresh.
- Unified `default_problem_stale` across severity, cards, counters, filters,
  detail and Priority Attention.
- Limited recent event-log results in SQL to three per device with a defensive
  global ceiling and explicit incomplete-coverage state.
