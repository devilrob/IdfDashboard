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

## Operational health model

v1.1.0 centralizes severity, freshness and device classification so the same
state and exact cause appear in summaries, cards and Priority Attention.
Actionable issues include device outages, thresholded sensors, services and
alerts; maintenance, recent recovery, unknown/stale telemetry and
`No sensor installed` remain distinct. Device classification produces exactly
one category and falls back to `Other` with its reason when evidence is weak.
Desktop and TV views share these structures while TV limits content, preserves
12 px minimum operational text and reports connection, refresh and last-update
state without additional polling.

## Stable updates

The web Settings page performs a cached, read-only check of stable semantic
GitHub releases. It never modifies plugin files. Installation is intentionally
CLI-only and disabled by default:

```bash
# Run these from a shell already authenticated as the librenms OS user.
php /opt/librenms/app/Plugins/IdfDashboard/bin/update.php --check
php /opt/librenms/app/Plugins/IdfDashboard/bin/update.php --dry-run
php /opt/librenms/app/Plugins/IdfDashboard/bin/update.php --install
```

The updater accepts only stable `vMAJOR.MINOR.PATCH` releases from
`devilrob/IdfDashboard`. It requires the release ZIP and `SHA256SUMS`, validates
HTTPS URLs, owner/repository, checksum, package structure, packaged version and
PHP syntax, then swaps the plugin using validated same-filesystem renames. It locks
concurrent updates and stores backups, staging, rollback evidence, the lock and
JSON-lines audit log outside `app/Plugins`. The default persistent location is
`/opt/librenms/plugin-backups/IdfDashboard`; override it only with an absolute,
same-filesystem path using `--backup-dir=/safe/path`. LibreNMS cache clearing
runs only after a scan confirms that `IdfDashboard` is the sole related folder
inside `app/Plugins`.

Before installation, recognized legacy `IdfDashboard.backup-*`, `.old*`,
`.new*`, `.failed*`, `.rollback*` and pending directories are moved to external
storage. Unsafe, unknown, colliding or unmovable paths stop the update without
deletion. Activation uses same-filesystem renames; a failed post-install lint,
self-test or `php artisan view:clear` restores the validated external backup and
keeps the failed package externally for investigation.

Before moving the active directory, the updater verifies that external staging
has at least the package size plus 5 MiB free and prints an interruption-recovery
command, including its explicit `--librenms-root`. If a terminated process
leaves `app/Plugins/IdfDashboard` absent, run that exact backup command as the
`librenms` user; `--recover` refuses to overwrite an existing active plugin,
validates and self-tests the backup, restores it with a same-filesystem rename,
clears compiled views and writes the result to the external audit log. The
explicit root keeps recovery safe when `--backup-dir` was customized.

When upgrading from v1.0.3 or earlier, install v1.0.4 first. Its updater bridge
understands both the exact 14-file `1.0.x` package and the exact 18-file `1.1.x`
package. Do not skip this bridge before an automatic v1.1.0 upgrade. Bootstrap
only the updater from the checksum-verified v1.0.4 ZIP before activation:

```bash
TAG=v1.0.4
UPDATE_TMP="$(mktemp -d)"
cd "$UPDATE_TMP"
curl -fLO "https://github.com/devilrob/IdfDashboard/releases/download/${TAG}/IdfDashboard-${TAG}.zip"
curl -fLO "https://github.com/devilrob/IdfDashboard/releases/download/${TAG}/SHA256SUMS"
sha256sum -c SHA256SUMS
unzip -p "IdfDashboard-${TAG}.zip" bin/update.php > /opt/librenms/app/Plugins/IdfDashboard/bin/.update.php.new
php /opt/librenms/app/Plugins/IdfDashboard/bin/.update.php.new --self-test
mv /opt/librenms/app/Plugins/IdfDashboard/bin/.update.php.new /opt/librenms/app/Plugins/IdfDashboard/bin/update.php
php /opt/librenms/app/Plugins/IdfDashboard/bin/update.php --dry-run --tag="$TAG"
php /opt/librenms/app/Plugins/IdfDashboard/bin/update.php --install --tag="$TAG"
```

Remove the temporary download directory after reviewing the result. Do not run
these commands as root.

After v1.0.4 is active, use its installed updater to validate and install
v1.1.0. The bridge rejects packages that do not exactly match the closed file
profile selected by the target version. A clean v1.1.0 install is also valid;
the bridge requirement applies to automatic upgrades from earlier `1.0.x`
releases.

`--dry-run` performs every download and validation step without activation.
Backups default to the five newest matching directories and can be configured
with `--keep-backups=1..50`; the backup created by the current update is always
protected from retention. Downgrades require both an explicit tag and
`--allow-downgrade`; reinstalling the current version requires an explicit tag
and `--reinstall`. Persistent plugin settings remain in LibreNMS's database;
the release directory contains no local configuration or writable state.

No code is downloaded from `main`, and downloaded PHP is linted but never
executed before activation. Install mode must run as the plugin owner
(`librenms`), never as root or the web-server user. Diagnostic check, dry-run
and self-test modes do not require ownership of the installed directory.

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
tag such as `v1.1.0`. GitHub Actions validates the tag/version match, runs PHP
and JavaScript checks, builds the minimal plugin ZIP, generates `SHA256SUMS`,
and publishes both assets to the stable GitHub release.

## LibreNMS authorization integration test

CI checks out the supported LibreNMS 26.8 commit into a disposable workspace,
starts MariaDB, installs this plugin under `app/Plugins/IdfDashboard`, migrates
the real LibreNMS schema and runs:

```bash
vendor/bin/phpunit tests/Feature/Plugins/IdfDashboard/DeviceAccessTest.php
```

The test covers administrator, global-read, itemized user, empty device access,
a completely unprivileged user, hook registration and the administrative
protection in `PluginSettingsController`. The release job cannot run until this
integration job and the PHP 8.2/8.3/8.4 standalone matrix both pass.
