# IdfDashboard

LibreNMS infrastructure-health dashboard for NOC and wall-display use.

## Requirements

- Current LibreNMS plugin architecture.
- PHP 8.2 or newer.
- MySQL 8 or MariaDB 10.2+ for the per-device event-log window query.
- Menu and Page hooks remain registered for authenticated LibreNMS users. The
  Settings route remains protected by LibreNMS's administrative
  `PluginSettingsController`, which requires `plugin.admin` on GET and POST.
  Device visibility is constrained in SQL through LibreNMS's official
  `Device::hasAccess($user)` scope. Admin and global-read roles see all devices;
  itemized users see only their authorized devices and locations, and users
  without device permissions receive an empty dashboard.

Install the directory as `/opt/librenms/app/Plugins/IdfDashboard`, enable it in
LibreNMS, and configure it from the plugin Settings page. Plugin settings remain
in LibreNMS's database and are not stored in the release package.

## Stable updates

The web Settings page performs a cached, read-only check of stable semantic
GitHub releases. It never modifies plugin files. Installation is intentionally
CLI-only and disabled by default:

```bash
sudo -u librenms -- php /opt/librenms/app/Plugins/IdfDashboard/bin/update.php --check
sudo -u librenms -- php /opt/librenms/app/Plugins/IdfDashboard/bin/update.php --dry-run
sudo -u librenms -- php /opt/librenms/app/Plugins/IdfDashboard/bin/update.php --install
```

The updater accepts only stable `vMAJOR.MINOR.PATCH` releases from
`devilrob/IdfDashboard`. It requires the release ZIP and `SHA256SUMS`, validates
HTTPS URLs, owner/repository, checksum, package structure, packaged version and
PHP syntax, then swaps the plugin atomically on the same filesystem. It locks
concurrent updates, retains a timestamped sibling backup, clears LibreNMS's
compiled views, records JSON-lines audit events in `IdfDashboard-update.log`,
and rolls back automatically if activation fails.

`--dry-run` performs every download and validation step without activation.
Backups default to the five newest matching directories and can be configured
with `--keep-backups=1..50`. Downgrades require both an explicit tag and
`--allow-downgrade`; reinstalling the current version requires an explicit tag
and `--reinstall`. Persistent plugin settings remain in LibreNMS's database;
the release directory contains no local configuration or writable state.

No code is downloaded from `main`, and downloaded PHP is linted but never
executed before activation. Run the command as the plugin owner (`librenms`),
not as the web-server user.

## Event-log query trade-off

The dashboard uses `ROW_NUMBER() OVER (PARTITION BY device_id ...)` to fetch at
most three recent events per authorized device in one query, with a global
3,000-row ceiling. Current LibreNMS's eventlog schema has separate indexes on
`device_id` and `datetime`; a composite `(device_id, datetime)` index could
improve very large installations, but this plugin deliberately does not alter
LibreNMS core tables. When the safety ceiling is reached, the UI marks event
coverage as limited instead of reporting a misleading percentage.

## Releases

Update `Support/Version.php` and `CHANGELOG.md`, commit, then create a matching
tag such as `v1.0.1`. GitHub Actions validates the tag/version match, runs PHP
and JavaScript checks, builds the minimal plugin ZIP, generates `SHA256SUMS`,
and publishes both assets to the stable GitHub release.

## LibreNMS authorization integration test

From a disposable LibreNMS checkout with its test database configured, install
this plugin under `app/Plugins/IdfDashboard` and run:

```bash
DB_CONNECTION=testing_memory php vendor/bin/phpunit app/Plugins/IdfDashboard/tests/librenms/DeviceAccessTest.php
```

The test covers administrator, global-read, itemized user, empty device access,
a completely unprivileged user, hook registration and the administrative
protection in `PluginSettingsController`. It is intentionally not included in
the standalone plugin CI because it requires LibreNMS's application, roles,
schema, factories and database.
