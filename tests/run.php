<?php

declare(strict_types=1);

use App\Plugins\IdfDashboard\Support\Config;
use App\Plugins\IdfDashboard\Support\DeviceClassifier;
use App\Plugins\IdfDashboard\Support\Freshness;
use App\Plugins\IdfDashboard\Support\IssueBuilder;
use App\Plugins\IdfDashboard\Support\ProblemPolicy;
use App\Plugins\IdfDashboard\Support\Severity;
use App\Plugins\IdfDashboard\Support\UpdateStatus;
use App\Plugins\IdfDashboard\Support\Version;

$root = dirname(__DIR__);

require $root . '/Support/Config.php';
require $root . '/Support/DeviceClassifier.php';
require $root . '/Support/Freshness.php';
require $root . '/Support/IssueBuilder.php';
require $root . '/Support/ProblemPolicy.php';
require $root . '/Support/Severity.php';
require $root . '/Support/Version.php';
require $root . '/Support/UpdateStatus.php';
require $root . '/bin/update.php';

$failures = 0;

$assert = static function (bool $condition, string $name) use (&$failures): void {
    echo $name . ': ' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;

    if (! $condition) {
        $failures++;
    }
};

$severityOrder = [
    Severity::CRITICAL,
    Severity::WARNING,
    Severity::UNKNOWN,
    Severity::STALE,
    Severity::MAINTENANCE,
    Severity::DISABLED,
    Severity::IGNORED,
    Severity::HEALTHY,
    Severity::NO_SENSOR,
];
$severityRanks = array_map(
    fn (string $state): int => Severity::metadata($state)['rank'],
    $severityOrder
);
$assert($severityRanks === array_values(array_unique($severityRanks)), 'severity ranking is explicit and unique');
$assert(Severity::worst([Severity::HEALTHY, Severity::WARNING, Severity::CRITICAL]) === Severity::CRITICAL, 'severity selects the worst state once');
$assert(Severity::worst([Severity::HEALTHY, Severity::STALE]) === Severity::HEALTHY, 'informational stale does not elevate health');
$assert(Severity::worst([Severity::HEALTHY, Severity::STALE], true) === Severity::STALE, 'actionable stale can elevate health');
$assert(Severity::metadata(Severity::NO_SENSOR)['actionable'] === false, 'no sensor is informational');
$assert(Severity::worst([Severity::MAINTENANCE]) === Severity::MAINTENANCE, 'maintenance remains distinct from down');
$assert(Severity::worst([Severity::DISABLED]) === Severity::DISABLED, 'disabled remains a distinct state');
$assert(Severity::worst([Severity::IGNORED]) === Severity::IGNORED, 'ignored remains a distinct state');
$assert(Severity::fromLibreNmsGenericState(0) === Severity::HEALTHY, 'state translation maps healthy');
$assert(Severity::fromLibreNmsGenericState(1) === Severity::WARNING, 'state translation maps warning');
$assert(Severity::fromLibreNmsGenericState(2) === Severity::CRITICAL, 'state translation maps critical');
$assert(Severity::fromLibreNmsGenericState(3) === Severity::UNKNOWN, 'state translation maps LibreNMS unknown');
$assert(Severity::fromLibreNmsGenericState(null) === Severity::UNKNOWN, 'missing state translation is unknown');

$freshnessNow = new DateTimeImmutable('2026-08-04 12:00:00 UTC');
$fresh = Freshness::evaluate('2026-08-04 11:55:00 UTC', 30, true, true, $freshnessNow);
$aging = Freshness::evaluate('2026-08-04 11:40:00 UTC', 30, true, true, $freshnessNow);
$stale = Freshness::evaluate('2026-08-04 11:00:00 UTC', 30, true, true, $freshnessNow);
$disabledStale = Freshness::evaluate('2026-08-04 11:00:00 UTC', 30, false, true, $freshnessNow);
$future = Freshness::evaluate('2026-08-04 12:05:00 UTC', 30, true, true, $freshnessNow);
$atBoundary = Freshness::evaluate('2026-08-04 11:30:00 UTC', 30, true, true, $freshnessNow);
$assert($fresh['state'] === Freshness::FRESH && $fresh['age_seconds'] === 300, 'freshness recognizes fresh timestamps');
$assert($aging['state'] === Freshness::AGING, 'freshness recognizes aging timestamps');
$assert($stale['state'] === Freshness::STALE && $stale['actionable'], 'freshness recognizes actionable stale timestamps');
$assert($disabledStale['state'] === Freshness::STALE && ! $disabledStale['actionable'], 'disabled stale never becomes actionable');
$assert($future['state'] === Freshness::FRESH && $future['age_seconds'] === 0, 'future timestamps clamp to fresh without negative age');
$assert($atBoundary['state'] === Freshness::AGING && ! $atBoundary['actionable'], 'exact stale boundary is aging, not stale');
$assert(Freshness::evaluate(null, 30)['state'] === Freshness::UNKNOWN, 'freshness treats null timestamps as unknown');
$assert(Freshness::evaluate('not-a-date', 30)['reason'] === 'Last poll unavailable', 'freshness explains invalid timestamps');

$classificationFixtures = [
    'Power' => [['type' => 'network', 'hardware' => 'APC Smart-UPS 3000'], ['voltage', 'charge']],
    'Security' => [['type' => 'firewall', 'os' => 'fortios'], []],
    'Wireless' => [['type' => 'wireless', 'hardware' => 'Wireless Controller'], []],
    'Printer' => [['type' => 'printer', 'hardware' => 'LaserJet'], []],
    'Camera' => [['type' => 'appliance', 'purpose' => 'CCTV camera'], []],
    'POS' => [['type' => 'appliance', 'purpose' => 'Oracle MICROS workstation'], []],
    'Controller' => [['type' => 'appliance', 'purpose' => 'Building Management System controller'], []],
    'Server' => [['type' => 'server', 'hostname' => 'host-01'], []],
    'Network' => [['type' => 'network', 'hardware' => 'Ethernet switch'], []],
    'Other' => [['type' => 'appliance', 'hostname' => '10.1.2.3'], []],
];

foreach ($classificationFixtures as $expected => [$deviceFixture, $sensorClasses]) {
    $classification = DeviceClassifier::classify($deviceFixture, $sensorClasses);
    $assert($classification['category'] === $expected, 'classifier identifies ' . $expected);
    $assert(in_array($classification['confidence'], ['high', 'medium', 'low'], true), 'classifier confidence is valid for ' . $expected);
}

$conflict = DeviceClassifier::classify(['type' => 'server', 'hardware' => 'APC UPS controller']);
$assert($conflict['category'] === 'Power', 'classifier resolves conflicts with documented precedence');
$genericName = DeviceClassifier::classify(['hostname' => 'hotel-prod-01', 'display' => 'Back Office']);
$assert($genericName['category'] === 'Other', 'classifier does not infer server from a generic name');

$sensorIssue = IssueBuilder::make([
    'key' => 'sensor:7:high',
    'device_id' => 7,
    'severity' => Severity::CRITICAL,
    'source' => 'sensor',
    'type' => 'temperature',
    'title' => 'Temperature',
    'value' => 87.4,
    'unit' => '°F',
    'threshold' => 82.0,
    'threshold_direction' => 'above critical high',
    'description' => 'Temperature 87.4 °F — above high limit 82 °F',
]);
$assert($sensorIssue['priority'] === IssueBuilder::PRIORITY_CRITICAL_SENSOR, 'issue priority follows operational source order');
$assert([
    IssueBuilder::PRIORITY_DEVICE_DOWN,
    IssueBuilder::PRIORITY_CRITICAL_SENSOR,
    IssueBuilder::PRIORITY_CRITICAL_SERVICE,
    IssueBuilder::PRIORITY_CRITICAL_ALERT,
    IssueBuilder::PRIORITY_WARNING_SENSOR,
    IssueBuilder::PRIORITY_WARNING_SERVICE,
    IssueBuilder::PRIORITY_STALE,
    IssueBuilder::PRIORITY_UNKNOWN,
] === [10, 20, 30, 40, 50, 60, 70, 80], 'Priority Attention order is exact');
$assert($sensorIssue['description'] !== '', 'critical issue always has a cause');
$fallbackIssue = IssueBuilder::make(['severity' => 'warning', 'source' => 'sensor', 'value' => null]);
$assert($fallbackIssue['description'] === 'Current value unavailable', 'warning issue explains an unavailable value');
$assert(array_keys($sensorIssue) === [
    'key', 'device_id', 'location_id', 'severity', 'priority', 'source', 'type', 'title', 'description', 'value', 'unit', 'threshold', 'threshold_direction', 'timestamp', 'age_seconds', 'actionable', 'device_url',
], 'issue structure is stable');
$assert(
    IssueBuilder::evaluateNumericSensor(90, 82, 78, null, null) === [
        'severity' => Severity::CRITICAL, 'threshold' => 82.0, 'direction' => 'above critical high',
    ],
    'numeric sensor identifies critical high limit'
);
$assert(
    IssueBuilder::evaluateNumericSensor(80, 90, 78, null, null)['severity'] === Severity::WARNING,
    'numeric sensor identifies warning high limit'
);
$assert(
    IssueBuilder::evaluateNumericSensor(102, null, null, 100, 110)['severity'] === Severity::WARNING,
    'numeric sensor identifies warning low limit'
);
$assert(
    IssueBuilder::evaluateNumericSensor(95, null, null, 100, 110)['direction'] === 'below critical low',
    'numeric sensor identifies critical low direction'
);
$assert(
    IssueBuilder::evaluateNumericSensor(-12, 0, null, -20, -10)['severity'] === Severity::WARNING,
    'numeric sensor preserves valid negative values'
);
$assert(IssueBuilder::evaluateNumericSensor(null, 10, 8, 1, 2)['severity'] === Severity::UNKNOWN, 'numeric sensor treats null as unknown');
$assert(IssueBuilder::evaluateNumericSensor(NAN, 10, 8, 1, 2)['severity'] === Severity::UNKNOWN, 'numeric sensor rejects NaN');
$assert(IssueBuilder::evaluateNumericSensor(INF, 10, 8, 1, 2)['severity'] === Severity::UNKNOWN, 'numeric sensor rejects infinity');
$assert(
    IssueBuilder::evaluateNumericSensor(85, 80, 90, null, null)['direction'] === 'invalid threshold order',
    'numeric sensor rejects inverted high thresholds'
);
$assert(
    IssueBuilder::evaluateNumericSensor(15, null, null, 20, 10)['direction'] === 'invalid threshold order',
    'numeric sensor rejects inverted low thresholds'
);
$assert(
    IssueBuilder::evaluateNumericSensor(0, null, null, 0, null, true)['severity'] === Severity::HEALTHY,
    'numeric sensor ignores lower zero only for explicitly resting-at-zero sensors'
);

$defaults = Config::resolve([]);
$assert($defaults['refresh_seconds'] === 30, 'config defaults');
$assert($defaults['update_check_enabled'] === true, 'periodic update check defaults on');
$assert(Config::resolve(['refresh_seconds' => 1])['refresh_seconds'] === 5, 'config lower clamp');
$assert(Config::resolve(['refresh_seconds' => 99999])['refresh_seconds'] === 3600, 'config upper clamp');
$assert(Config::resolve(['animations_enabled' => '0'])['animations_enabled'] === false, 'config boolean');
$assert($defaults['default_view'] === 'overview' && $defaults['devices_per_page'] === 25, 'Phase 2 navigation defaults');
$assert(Config::resolve(['default_view' => 'invalid'])['default_view'] === 'overview', 'config rejects invalid default view');
$assert(Config::resolve(['devices_per_page' => '100'])['devices_per_page'] === 100, 'config accepts defensive page size');
$assert(Config::resolve(['devices_per_page' => '5000'])['devices_per_page'] === 25, 'config rejects unlimited page size');
$normalizedRequest = Config::normalizeDashboardRequest([
    'view' => 'devices',
    'search' => str_repeat('x', 150),
    'severity' => 'critical',
    'category' => 'Server',
    'sort' => 'arbitrary_sql',
    'direction' => 'sideways',
    'page' => '-2',
    'per_page' => '5000',
    'problems_only' => '1',
    'unknown_parameter' => 'ignored',
], $defaults);
$assert($normalizedRequest['view'] === 'devices' && strlen($normalizedRequest['search']) === 100, 'request view and search are normalized');
$assert($normalizedRequest['sort'] === 'severity' && $normalizedRequest['direction'] === 'asc', 'request sort whitelist is strict');
$assert($normalizedRequest['page'] === 1 && $normalizedRequest['per_page'] === 25, 'request pagination is defensive');
$assert($normalizedRequest['problems_only'] === true && ! array_key_exists('unknown_parameter', $normalizedRequest), 'request booleans and unknown keys are normalized');
$pageSource = file_get_contents(__DIR__ . '/../Page.php');
$assert(
    str_contains($pageSource, "DB::table('availability')") && str_contains($pageSource, "->whereIn('device_id', \$deviceIds)"),
    'availability is restricted to the authorized device ID set'
);

$assert(
    ! ProblemPolicy::metricEnabled(['temperature' => true, 'stale' => false], 'temperature', true),
    'stale metric excluded when policy disabled'
);
$assert(
    ! ProblemPolicy::staleEscalates(['stale' => false], 'ups', true),
    'stale UPS does not escalate when policy disabled'
);
$assert(
    ProblemPolicy::staleEscalates(['stale' => true], 'pdu', true),
    'stale curated PDU escalates when policy enabled'
);

$releases = [
    [
        'draft' => false,
        'prerelease' => false,
        'tag_name' => 'v1.2.0',
        'html_url' => 'https://github.com/devilrob/IdfDashboard/releases/tag/v1.2.0',
    ],
    [
        'draft' => false,
        'prerelease' => true,
        'tag_name' => 'v9.0.0',
        'html_url' => 'https://github.com/devilrob/IdfDashboard/releases/tag/v9.0.0',
    ],
    [
        'draft' => false,
        'prerelease' => false,
        'tag_name' => 'v1.10.0',
        'html_url' => 'https://github.com/devilrob/IdfDashboard/releases/tag/v1.10.0',
    ],
    [
        'draft' => false,
        'prerelease' => false,
        'tag_name' => 'v99.0.0',
        'html_url' => 'https://example.com/devilrob/IdfDashboard/releases/tag/v99.0.0',
    ],
];
$latest = UpdateStatus::latestStableRelease($releases);
$assert($latest !== null && $latest['version'] === '1.10.0', 'latest verified stable release');

$assert(IdfDashboardUpdater::isStableTag('v1.2.3'), 'updater accepts semantic stable tag');
$assert(! IdfDashboardUpdater::isStableTag('main'), 'updater rejects branch');
$assert(! IdfDashboardUpdater::isStableTag('v1.2.3-rc1'), 'updater rejects prerelease tag');
$assert(
    IdfDashboardUpdater::checksumFor(
        str_repeat('a', 64) . '  IdfDashboard-v1.2.3.zip',
        'IdfDashboard-v1.2.3.zip'
    ) === str_repeat('a', 64),
    'updater parses exact checksum asset'
);
$assert(IdfDashboardUpdater::isSafeArchivePath('Support/Version.php'), 'updater accepts safe archive path');
$assert(! IdfDashboardUpdater::isSafeArchivePath('../Page.php'), 'updater rejects ZIP traversal');
$assert(! IdfDashboardUpdater::isSafeArchivePath('Support//Version.php'), 'updater rejects ambiguous ZIP path');

$legacyProfile = IdfDashboardUpdater::packageProfileForVersion('1.0.4');
$phase1Profile = IdfDashboardUpdater::packageProfileForVersion('1.1.0');
$assert(
    $legacyProfile['name'] === 'legacy-v1'
        && count($legacyProfile['required']) === 14
        && $legacyProfile['optional'] === [],
    'updater selects the exact closed 14-file legacy profile'
);
$assert(
    $phase1Profile['name'] === 'phase1-v1'
        && count($phase1Profile['required']) === 18
        && $phase1Profile['optional'] === [],
    'updater selects the exact closed 18-file Phase 1 profile'
);
$assert(
    preg_match('/^[a-f0-9]{64}$/', $legacyProfile['checksum']) === 1
        && preg_match('/^[a-f0-9]{64}$/', $phase1Profile['checksum']) === 1,
    'package profiles expose stable file-list checksums'
);
$unknownProfileRejected = false;

try {
    IdfDashboardUpdater::packageProfileForVersion('2.0.0');
} catch (RuntimeException $exception) {
    $unknownProfileRejected = str_contains($exception->getMessage(), 'known package profile');
}

$assert($unknownProfileRejected, 'updater rejects an unknown package profile');

$archiveEntryLimitRejected = false;

try {
    IdfDashboardUpdater::validateArchiveEntryMetadata(array_fill(
        0,
        65,
        ['name' => 'Support/Version.php', 'size' => 1]
    ));
} catch (RuntimeException $exception) {
    $archiveEntryLimitRejected = str_contains($exception->getMessage(), 'entry count');
}

$assert($archiveEntryLimitRejected, 'updater rejects excessive ZIP entry count');

$archiveExpandedSizeRejected = false;

try {
    IdfDashboardUpdater::validateArchiveEntryMetadata([
        ['name' => 'Page.php', 'size' => 26214401],
    ]);
} catch (RuntimeException $exception) {
    $archiveExpandedSizeRejected = str_contains($exception->getMessage(), 'uncompressed size');
}

$assert($archiveExpandedSizeRejected, 'updater rejects excessive ZIP expanded size');
$assert(
    IdfDashboardUpdater::hasSufficientStagingSpace(5242880, 0),
    'updater accepts staging space with required 5 MiB headroom'
);
$assert(
    ! IdfDashboardUpdater::hasSufficientStagingSpace(5242879, 0),
    'updater rejects staging space below required headroom'
);
$assert(! IdfDashboardUpdater::isSafeArchivePath('.github/workflows/release.yml'), 'updater rejects CI files');
$assert(! IdfDashboardUpdater::isSafeArchivePath('tests/run.php'), 'updater rejects test files');
$assert(! IdfDashboardUpdater::isSafeArchivePath('.gitattributes'), 'updater rejects Git metadata');
$assert(! IdfDashboardUpdater::isSafeArchivePath('AUDIT_NOTES.md'), 'updater rejects local audit notes');
$assert(
    IdfDashboardUpdater::isTrustedDownloadUrl('https://release-assets.githubusercontent.com/file'),
    'updater accepts trusted GitHub asset host'
);
$assert(
    ! IdfDashboardUpdater::isTrustedDownloadUrl('https://example.com/payload.zip'),
    'updater rejects untrusted redirect host'
);

$extraChecksumRejected = false;

try {
    IdfDashboardUpdater::checksumFor(
        str_repeat('a', 64) . "  IdfDashboard-v1.2.3.zip\n"
            . str_repeat('b', 64) . '  extra.zip',
        'IdfDashboard-v1.2.3.zip'
    );
} catch (RuntimeException) {
    $extraChecksumRejected = true;
}

$assert($extraChecksumRejected, 'updater rejects extra checksum entries');
$invalidArchiveChecksumRejected = false;

try {
    IdfDashboardUpdater::assertArchiveChecksum(str_repeat('a', 64), str_repeat('b', 64));
} catch (RuntimeException $exception) {
    $invalidArchiveChecksumRejected = str_contains($exception->getMessage(), 'SHA-256');
}

$assert($invalidArchiveChecksumRejected, 'updater rejects an invalid archive checksum');
$assert(
    IdfDashboardUpdater::backupsToPrune([
        'IdfDashboard.backup-20260803-120000-v0.9.0',
        'not-a-plugin-backup',
        'IdfDashboard.backup-20260804-120000-v1.0.0',
    ], 1) === ['IdfDashboard.backup-20260803-120000-v0.9.0'],
    'backup retention keeps newest matching backup'
);
$assert(
    IdfDashboardUpdater::backupsToPrune([
        'IdfDashboard.backup-20260803-120000-v0.9.0',
        'IdfDashboard.backup-20260804-120000-v1.0.0',
    ], 1, 'IdfDashboard.backup-20260803-120000-v0.9.0') === [],
    'backup retention never deletes protected backup'
);
$assert(IdfDashboardUpdater::isLegacyPluginDirectoryName('IdfDashboard.old-recovery'), 'legacy old directory detected');
$assert(IdfDashboardUpdater::isLegacyPluginDirectoryName('.IdfDashboard.pending-deadbeef'), 'legacy pending directory detected');
$assert(
    array_reduce([
        'IdfDashboard.backup-20260804-203726-v1.0.1',
        'IdfDashboard.old-copy',
        'IdfDashboard.new-copy',
        'IdfDashboard.failed-copy',
        'IdfDashboard.rollback-copy',
    ], fn (bool $valid, string $name): bool => $valid && IdfDashboardUpdater::isLegacyPluginDirectoryName($name), true),
    'all required legacy directory variants detected'
);
$assert(! IdfDashboardUpdater::isLegacyPluginDirectoryName('IdfDashboard'), 'active plugin directory is never migrated');
$assert(! IdfDashboardUpdater::executionUserIsAllowed(0, 0, false), 'root updater install rejected');
$assert(! IdfDashboardUpdater::executionUserIsAllowed(1001, 1000, false), 'wrong updater user rejected');
$assert(IdfDashboardUpdater::executionUserIsAllowed(1000, 1000, false), 'plugin owner updater accepted');
$assert(
    str_contains(IdfDashboardUpdater::lockOpenFailureMessage('Permission denied'), 'Permission denied'),
    'lock permission detail preserved'
);

$updaterSource = (string) file_get_contents($root . '/bin/update.php');
$assert(
    str_contains($updaterSource, "'plugin-backups'") && str_contains($updaterSource, "'IdfDashboard'"),
    'updater defaults to external LibreNMS backup storage'
);
$assert(
    str_contains($updaterSource, '$staging = $this->storageRoot . DIRECTORY_SEPARATOR'),
    'updater never stages beside active plugin'
);
$assert(
    str_contains($updaterSource, "\$this->assertPluginTreeClean();\n        \$artisan"),
    'updater checks plugin tree immediately before artisan'
);

$pageSource = (string) file_get_contents($root . '/Page.php');
$pageBladeSource = (string) file_get_contents($root . '/resources/views/page.blade.php');
$deviceAccessSource = (string) file_get_contents($root . '/Support/DeviceAccess.php');
$menuSource = (string) file_get_contents($root . '/Menu.php');
$settingsSource = (string) file_get_contents($root . '/Settings.php');
$assert(str_contains($deviceAccessSource, 'Device::query()->hasAccess($user)'), 'LibreNMS device access scope is the SQL boundary');
$assert(str_contains($pageSource, 'DeviceAccess::query($user)'), 'dashboard starts from authorized devices');
$assert(
    preg_match('/function authorize\(User \$user\): bool\s*\{\s*return true;\s*\}/s', $pageSource) === 1,
    'page hook remains registered'
);
$assert(
    preg_match('/function authorize\(\s*User \$user,\s*array \$settings = \[\]\s*\): bool \{\s*return true;\s*\}/s', $menuSource) === 1,
    'menu hook remains registered'
);
$assert(
    preg_match('/function authorize\(User \$user\): bool\s*\{.*?return true;\s*\}/s', $settingsSource) === 1,
    'settings hook delegates authorization to LibreNMS controller'
);
$hookSources = $pageSource . $menuSource . $settingsSource;
$forbiddenUserMethods = [
    'hasGlobalAdmin(',
    'isAdmin(',
    'hasGlobalRead(',
    "can('admin')",
    "can('plugin.admin')",
    "can('viewAny'",
];
$unsupportedCalls = array_filter(
    $forbiddenUserMethods,
    fn (string $method): bool => str_contains($hookSources, $method)
);
$assert($unsupportedCalls === [], 'hooks avoid unsupported or discarding User authorization calls');
$assert(! str_contains($pageSource, "DB::table('devices"), 'dashboard has no unscoped devices query');
$assert(str_contains($pageSource, 'ROW_NUMBER() OVER'), 'event query ranks rows in SQL');
$assert(str_contains($pageSource, 'RECENT_EVENTS_GLOBAL_LIMIT'), 'event query has global ceiling');
$assert(
    str_contains($pageSource, 'private function telemetryMetricEnabled')
        && str_contains($pageSource, '[Severity::CRITICAL, Severity::WARNING, Severity::UNKNOWN]'),
    'stale disabled never hides a critical, warning or unknown cause'
);
$assert(
    str_contains($pageSource, "'severityDefinitions' => Severity::definitions()")
        && str_contains($pageBladeSource, 'data-idf-severity-definitions')
        && str_contains($pageBladeSource, 'severityDefinitions[health]'),
    'Blade and TV consume the centralized severity model'
);
$assert(
    str_contains($pageBladeSource, '@media (prefers-reduced-motion: reduce)')
        && str_contains($pageBladeSource, 'animation: none !important;')
        && str_contains($pageBladeSource, 'transition: none !important;'),
    'desktop and TV honor reduced-motion without duplicating severity logic'
);
$assert(
    str_contains($pageBladeSource, 'const locationDevicesPerCard = 2;')
        && str_contains($pageBladeSource, 'deviceStart: start')
        && str_contains($pageBladeSource, "body.tv-mode-active .priority-panel")
        && str_contains($pageBladeSource, 'display: none !important;'),
    'TV paginates large locations and hides the duplicate Priority panel'
);
$assert(
    str_contains($pageBladeSource, 'data-updated-at="{{ $generatedAt }}"')
        && str_contains($pageBladeSource, 'data-updated-at-epoch="{{ strtotime($generatedAt) }}"')
        && str_contains($pageBladeSource, "'Last updated: unavailable'")
        && str_contains($pageBladeSource, "'Connection issue'")
        && str_contains($pageBladeSource, "dashboardConnectionState = 'disconnected'")
        && str_contains($pageBladeSource, 'Date.now() - dashboardRefreshStartedAt >= 30000')
        && str_contains($pageBladeSource, 'window.clearInterval(tvRotationTimer)')
        && str_contains($pageBladeSource, 'window.clearInterval(tvClockTimer)'),
    'TV exposes the last refresh and replaces existing rotation timers'
);
$refreshSource = substr($pageBladeSource, (int) strpos($pageBladeSource, 'function refreshDashboardData()'));
$assert(
    str_contains($pageBladeSource, 'dashboardUpdateClock = updateClock;')
        && str_contains($refreshSource, 'dashboardUpdateClock();')
        && ! str_contains($refreshSource, "\n    updateClock();"),
    'background refresh updates the TV clock through the current initialized callback'
);
$assert(
    str_contains($pageSource, "DB::table('device_outages')")
        && str_contains($pageSource, "->whereIn('device_id', \$deviceIds)"),
    'outage and recovery queries stay inside authorized device IDs'
);
$assert(
    ! str_contains($pageSource, 'use LibreNMS\\Cache\\DeviceMaintenanceCache')
        && ! str_contains($pageSource, 'app(DeviceMaintenanceCache'),
    'dashboard avoids the core global maintenance cache'
);
$assert(
    str_contains($pageSource, "'sensors_to_state_indexes'")
        && str_contains($pageSource, "'state_indexes'")
        && str_contains($pageSource, "'state_translations'"),
    'state sensors use all LibreNMS state translation tables'
);

$topLevelHooks = array_map('basename', glob($root . '/*.php') ?: []);
sort($topLevelHooks);
$assert(
    $topLevelHooks === ['Menu.php', 'Page.php', 'Settings.php'],
    'only valid hook classes exist at plugin root'
);
$assert(
    preg_match('/^\d+\.\d+\.\d+$/', Version::VERSION) === 1,
    'installed version is semantic'
);

$versionDeclarations = 0;

foreach (array_merge(
    glob($root . '/*.php') ?: [],
    glob($root . '/Support/*.php') ?: [],
    glob($root . '/bin/*.php') ?: []
) as $sourceFile) {
    $versionDeclarations += preg_match_all(
        "/public const VERSION\s*=\s*'\d+\.\d+\.\d+';/",
        (string) file_get_contents($sourceFile)
    );
}

$assert($versionDeclarations === 1, 'plugin version has one source declaration');

$readmeSource = (string) file_get_contents($root . '/README.md');
$changelogSource = (string) file_get_contents($root . '/CHANGELOG.md');
$assert(
    str_contains($readmeSource, 'tag such as `v' . Version::VERSION . '`')
        && str_contains($changelogSource, '## [' . Version::VERSION . ']'),
    'README release example and changelog match installed version'
);
$assert(
    str_contains($readmeSource, 'TAG=v1.0.4')
        && str_contains($readmeSource, 'Do not skip this bridge'),
    'README preserves the mandatory v1.0.4 bootstrap path'
);
$assert(
    str_contains($updaterSource, '--recover')
        && str_contains($readmeSource, '--recover'),
    'interruption recovery is implemented and documented'
);

$workflowSource = (string) file_get_contents($root . '/.github/workflows/release.yml');
$assert(
    str_contains($workflowSource, 'librenms-integration:')
        && str_contains($workflowSource, '51344f722110350bb7301dde8b13bcf23ff65156')
        && str_contains($workflowSource, 'DeviceAccessTest.php'),
    'CI runs plugin integration tests against pinned LibreNMS 26.8'
);
$assert(
    array_reduce(
        ['DeviceClassifier.php', 'Freshness.php', 'IssueBuilder.php', 'Severity.php'],
        fn (bool $present, string $file): bool => $present
            && str_contains($updaterSource, "'Support/{$file}'")
            && str_contains($workflowSource, "Support/{$file}"),
        true
    ),
    'release whitelist includes every Phase 1 Support class explicitly'
);
$assert(
    str_contains($workflowSource, 'actions/checkout@d23441a48e516b6c34aea4fa41551a30e30af803 # v6')
        && str_contains($workflowSource, 'actions/setup-node@249970729cb0ef3589644e2896645e5dc5ba9c38 # v6'),
    'CI JavaScript actions use Node.js 24 runtimes'
);

// ---------------------------------------------------------------------
// Phase 3: centralized severity/problem-type visibility policy, TV Mode
// server-side filtering, checkbox-persistence regression coverage.
// ---------------------------------------------------------------------

// --- Section 7: unchecked-checkbox persistence must never silently
// revert to a `true` default. --------------------------------------
$assert(
    Config::resolve(['default_severity_critical' => '0'])['default_severity_critical'] === false,
    'checkbox regression: string "0" (unchecked, hidden-input value) resolves to false'
);
$assert(
    Config::resolve(['default_severity_critical' => '1'])['default_severity_critical'] === true,
    'checkbox regression: string "1" (checked) resolves to true'
);
$assert(
    Config::resolve([])['default_severity_critical'] === true,
    'checkbox regression: truly missing key falls back to the schema default (true for critical)'
);
$assert(
    Config::resolve(['default_severity_healthy' => null])['default_severity_healthy'] === false,
    'checkbox regression: explicit null falls back to the schema default (false for healthy)'
);
$assert(
    Config::resolve(['default_severity_critical' => 'false'])['default_severity_critical'] === false,
    'checkbox regression: literal string "false" resolves to false, not true'
);
$assert(
    Config::resolve(['default_severity_critical' => false])['default_severity_critical'] === false,
    'checkbox regression: PHP bool false resolves to false'
);
$assert(
    Config::resolve(['default_severity_critical' => true])['default_severity_critical'] === true,
    'checkbox regression: PHP bool true resolves to true'
);

// --- Section 2/13: Config::visibilityPolicy() is the single source of
// truth; TV context can only ever be a subset of global context. -----
$globalOffConfig = Config::resolve([
    'default_severity_critical' => '1',
    'default_severity_warning' => '1',
    'default_severity_unknown' => '0',
    'default_severity_stale' => '0',
    'default_severity_maintenance' => '0',
    'default_severity_healthy' => '0',
    'default_severity_no_sensor' => '0',
]);
$globalPolicy = Config::visibilityPolicy($globalOffConfig, 'global');
$tvPolicyNoRestrict = Config::visibilityPolicy($globalOffConfig, 'tv');
$assert(
    $globalPolicy['unknown'] === false
        && $globalPolicy['stale'] === false
        && $globalPolicy['maintenance'] === false
        && $globalPolicy['healthy'] === false
        && $globalPolicy['no_sensor'] === false,
    'visibilityPolicy: globally-disabled severities resolve to false in global context'
);
$assert(
    $tvPolicyNoRestrict === $globalPolicy,
    'visibilityPolicy: TV context with no tv_hide_* set equals global context exactly (no silent extra restriction)'
);

$tvHideConfig = array_merge($globalOffConfig, [
    'default_severity_critical' => '1',
    'default_severity_healthy' => '1',
    'tv_hide_healthy' => '1',
]);
$tvHidePolicy = Config::visibilityPolicy(Config::resolve($tvHideConfig), 'tv');
$assert(
    $tvHidePolicy['healthy'] === false && $tvHidePolicy['critical'] === true,
    'visibilityPolicy: tv_hide_healthy restricts TV specifically while leaving Critical alone'
);

$attemptToReEnableConfig = Config::resolve([
    'default_severity_critical' => '0',
]);
$reEnableAttemptPolicy = Config::visibilityPolicy($attemptToReEnableConfig, 'tv');
$assert(
    $reEnableAttemptPolicy['critical'] === false,
    'visibilityPolicy: no tv_hide_* setting exists that can turn a globally-disabled Critical back on for TV'
);

// --- Sections 3/4/8/9: ProblemPolicy::deviceVisible() — the single
// centralized decision Priority Attention and TV Mode's server-filtered
// collections both consult. --------------------------------------------
$criticalWarningOnlyPolicy = Config::visibilityPolicy(Config::resolve([
    'default_severity_critical' => '1',
    'default_severity_warning' => '1',
    'default_severity_unknown' => '0',
    'default_severity_stale' => '0',
    'default_severity_maintenance' => '0',
    'default_severity_healthy' => '0',
    'default_severity_no_sensor' => '0',
]), 'global');

$assert(
    ProblemPolicy::deviceVisible(['health' => 'critical', 'problem_types' => ['device']], $criticalWarningOnlyPolicy) === true,
    'Caso 1: Critical device is visible when Critical=ON'
);
$assert(
    ProblemPolicy::deviceVisible(['health' => 'healthy', 'problem_types' => []], $criticalWarningOnlyPolicy) === false,
    'Caso 1: Healthy device is excluded when Healthy=OFF — never shown "to fill a slide"'
);
$assert(
    ProblemPolicy::deviceVisible(['health' => 'unknown', 'problem_types' => ['state']], $criticalWarningOnlyPolicy) === false,
    'Caso 1: Unknown device is excluded when Unknown=OFF'
);
$assert(
    ProblemPolicy::deviceVisible(['health' => 'maintenance', 'problem_types' => []], $criticalWarningOnlyPolicy) === false,
    'Caso 1: Maintenance device is excluded when Maintenance=OFF (previously always tied to the Healthy toggle)'
);
$assert(
    ProblemPolicy::deviceVisible(['health' => 'stale', 'problem_types' => []], $criticalWarningOnlyPolicy) === false,
    'Caso 6: a device whose *worst state* is Stale is excluded when the Stale severity toggle is OFF'
);

// Caso 6: an old-but-still-critical reading must stay visible as
// Critical — health is never coerced to 'stale' by staleness alone
// (Severity::worst() already guarantees this; asserted again here at
// the policy layer so the guarantee is visible from this test file).
$assert(
    ProblemPolicy::deviceVisible(['health' => 'critical', 'problem_types' => ['temperature', 'stale']], $criticalWarningOnlyPolicy) === true,
    'Caso 6: a Critical device remains visible via Critical=ON even though one of its problem_types is "stale"'
);

// Caso 8: No sensor installed — a device whose ONLY issue is a
// no_sensor callout is healthy (no_sensor never becomes device
// health), so it is governed by the Healthy/other toggle, never by
// default_severity_no_sensor directly — that setting instead governs
// the summary counter/callout visibility (Page.php consumes it there,
// not inside deviceVisible()). Documented explicitly since it is the
// one severity key that is not also a reachable device['health'] value.
$assert(
    array_key_exists('no_sensor', Config::FIELDS) === false
        && array_key_exists('default_severity_no_sensor', Config::FIELDS),
    'Caso 8: "No sensor installed" is a real setting key (default_severity_no_sensor), not a device health value'
);

// Problem-type gating (section 9): a device whose severity is enabled
// but whose only present problem type is disabled must not be visible
// via that type; it may still be visible via any other enabled type,
// or via the "other" fallback when it has none.
$mixedProblemPolicy = Config::visibilityPolicy(Config::resolve([
    'default_severity_critical' => '1',
    'default_problem_temperature' => '0',
    'default_problem_battery' => '1',
]), 'global');
$assert(
    ProblemPolicy::deviceVisible(['health' => 'critical', 'problem_types' => ['temperature']], $mixedProblemPolicy) === false,
    'Section 9: a Critical device is not shown via a disabled problem type alone'
);
$assert(
    ProblemPolicy::deviceVisible(['health' => 'critical', 'problem_types' => ['temperature', 'battery']], $mixedProblemPolicy) === true,
    'Section 9: the same device is shown once it also has an enabled problem type'
);

// The 'stale' collision: default_severity_stale (device-health
// visibility) and default_problem_stale (does stale telemetry count as
// a problem type at all) are two different settings sharing the word
// "stale" — deviceVisible() must not conflate them.
$staleCollisionPolicy = Config::visibilityPolicy(Config::resolve([
    'default_severity_critical' => '1',
    'default_severity_stale' => '0',
    'default_problem_stale' => '1',
]), 'global');
$assert(
    ProblemPolicy::deviceVisible(['health' => 'critical', 'problem_types' => ['stale']], $staleCollisionPolicy) === true,
    'stale collision: a Critical device with stale telemetry is visible via the problem-type toggle, independent of the severity toggle being off'
);
$assert(
    ProblemPolicy::deviceVisible(['health' => 'stale', 'problem_types' => []], $staleCollisionPolicy) === false,
    'stale collision: a device whose worst state IS Stale is still excluded when the severity toggle (not the problem-type one) is off'
);

// --- Section 2/6: single source of truth — no independent
// re-derivation of the severity policy in Page.php, page.blade.php or
// buildPriorityAttention(). ---------------------------------------------
$pageSource = (string) file_get_contents($root . '/Page.php');
$bladeSource = (string) file_get_contents($root . '/resources/views/page.blade.php');

$assert(
    str_contains($pageSource, 'ProblemPolicy::deviceVisible($device, $policy)')
        && str_contains($pageSource, 'ProblemPolicy::deviceVisible($device, $tvPolicy)'),
    'Page.php: Priority Attention and TV collections both consult the single centralized ProblemPolicy::deviceVisible() decision'
);
$assert(
    str_contains($pageSource, "Config::visibilityPolicy(\$config, 'global')")
        && str_contains($pageSource, "Config::visibilityPolicy(\$config, 'tv')"),
    'Page.php: both policy contexts are built through Config::visibilityPolicy(), not hand-assembled inline'
);
$assert(
    str_contains($bladeSource, 'Config::visibilityPolicy($config)'),
    'page.blade.php: the JS `defaults` object is built from Config::visibilityPolicy(), not an independently hand-typed array — this is the exact class of drift that let TV Mode silently stop respecting Settings'
);
$assert(
    ! preg_match('/\$tvDefaults\s*=\s*\[/', $bladeSource),
    'page.blade.php: no independently hand-assembled $tvDefaults array remains'
);
$assert(
    ! str_contains($bladeSource, 'phase2-tv-source'),
    'page.blade.php: the removed .phase2-tv-source full-fleet, unfiltered TV rotation source does not exist'
);

// --- Section 5: TV Mode's own markup renders from the server-filtered
// $tv[...] collections, not the unfiltered desktop collections. --------
$assert(
    str_contains($bladeSource, "\$filters['tv'] ? \$tv['mdfServers'] : \$mdf['servers']")
        && str_contains($bladeSource, "\$filters['tv'] ? \$tv['idfLocations'] : \$locations")
        && str_contains($bladeSource, "\$filters['tv'] ? \$tv['otherLocations'] : \$otherLocations"),
    'page.blade.php: the shared MDF/IDF/Other Locations sections source from server-filtered $tv[...] collections in TV mode'
);

// --- Section 6: TV Mode has two entry points — the real ?tv=1
// navigation (server-filtered markup, defaults re-check is a harmless
// no-op) and the classic grid's own client-side-only rotation toggle
// (`data-action="tv"`, no navigation, renders from the un-tv-filtered
// classic grid). tvDeviceMatches() is the ONLY enforcement point for
// tv_hide_* on that second path, so it must consult the TV-context
// policy ($tv['policy'], WITH tv_hide_* intersected), never the
// 'global'-only $tvDefaults the desktop `state` starts from — using
// the global object there would let a tv_hide_*-restricted severity
// rotate onto an unattended screen via that entry point. -------------
$assert(
    str_contains($bladeSource, "\$tvOnlyDefaults = \$tv['policy']"),
    "page.blade.php: \$tvOnlyDefaults is Page.php's already-computed \$tv['policy'] (TV context), not an independently hand-assembled array"
);
$assert(
    str_contains($bladeSource, 'data-idf-tv-only-defaults="{{ json_encode($tvOnlyDefaults) }}"'),
    'page.blade.php: the TV-context policy is exposed to JS via its own data attribute, separate from the global-context data-idf-defaults'
);
$assert(
    str_contains($bladeSource, 'const tvOnlyDefaults = dashboardEl && dashboardEl.dataset.idfTvOnlyDefaults'),
    'page.blade.php: the JS side reads the TV-context policy from data-idf-tv-only-defaults'
);
preg_match('/function tvDeviceMatches\(device\) \{.*?\n    \}/s', $bladeSource, $tvDeviceMatchesMatch);
$tvDeviceMatchesBody = $tvDeviceMatchesMatch[0] ?? '';
$assert(
    $tvDeviceMatchesBody !== '' && ! str_contains($tvDeviceMatchesBody, 'defaults[')
        && str_contains($tvDeviceMatchesBody, 'tvOnlyDefaults['),
    'page.blade.php: tvDeviceMatches() reads exclusively from tvOnlyDefaults (TV-context, tv_hide_* included), never the global-only defaults object — the exact regression that would let tv_hide_* be silently bypassed via the classic grid\'s client-side-only TV Mode toggle'
);
// loadPersistedState()/state (the desktop classic-grid interactive
// filter) must stay on the global-context `defaults` — tv_hide_* is a
// wall-display-specific restriction and must never leak into what a
// viewer sees by default when just browsing the classic grid.
preg_match('/function loadPersistedState\(\) \{.*?\n    \}/s', $bladeSource, $loadPersistedStateMatch);
$loadPersistedStateBody = $loadPersistedStateMatch[0] ?? '';
$assert(
    $loadPersistedStateBody !== '' && str_contains($loadPersistedStateBody, 'defaults[key]')
        && ! str_contains($loadPersistedStateBody, 'tvOnlyDefaults'),
    'page.blade.php: loadPersistedState() (the desktop classic-grid filter) stays on the global-context defaults object — tv_hide_* must not leak into the desktop\'s default interactive filter state'
);

// --- Section 10: a defensive, worst-first, never-drops-Critical DOM
// ceiling exists for TV Mode's flat device sections. --------------------
$assert(
    str_contains($pageSource, '$tvMdfServers->take($tvMaxDevices)')
        && str_contains($pageSource, "\$tvPolicy['tvMaximumDevicesRendered']")
        && array_key_exists('tv_maximum_devices_rendered', Config::FIELDS),
    'Page.php: TV Mode device sections are bounded by a configurable, worst-first-sorted ceiling'
);
// Behavioral resolution/clamping for tv_maximum_devices_rendered itself
// (default/min/max/invalid-value normalization) is pure Config::resolve()
// logic and fully testable without a real LibreNMS instance. The
// remaining checklist items this doesn't cover — omittedDeviceCount
// correctness, worst-first truncation order (Critical never dropped to
// fit Healthy), and "Healthy only included if policy allows" — require
// Page::data()'s full pipeline and live in
// tests/librenms/DeviceAccessTest.php's dedicated TV-ceiling test.
$assert(
    Config::resolve([])['tv_maximum_devices_rendered'] === 200,
    'Config::resolve: tv_maximum_devices_rendered defaults to 200 when unset'
);
$assert(
    Config::resolve(['tv_maximum_devices_rendered' => '3'])['tv_maximum_devices_rendered'] === 10,
    'Config::resolve: tv_maximum_devices_rendered below the safe minimum (10) is clamped up, never silently accepted or dropped'
);
$assert(
    Config::resolve(['tv_maximum_devices_rendered' => '999999'])['tv_maximum_devices_rendered'] === 2000,
    'Config::resolve: tv_maximum_devices_rendered above the defensive maximum (2000) is clamped down'
);
$assert(
    Config::resolve(['tv_maximum_devices_rendered' => 'not-a-number'])['tv_maximum_devices_rendered'] === 200,
    'Config::resolve: a non-numeric tv_maximum_devices_rendered falls back to the default, never to 0 or an unbounded value'
);
$assert(
    Config::resolve(['tv_maximum_devices_rendered' => ''])['tv_maximum_devices_rendered'] === 200,
    'Config::resolve: a blank tv_maximum_devices_rendered falls back to the default'
);
$assert(
    Config::visibilityPolicy(Config::resolve(['tv_maximum_devices_rendered' => '50']), 'tv')['tvMaximumDevicesRendered'] === 50,
    'Config::visibilityPolicy: tvMaximumDevicesRendered reflects the resolved (already-clamped) value, not the raw input'
);

// ---------------------------------------------------------------------
// Fase 3A step 12 security checklist. Access-boundary behavior itself
// (limited user sees only authorized devices, a user with no devices
// gets an empty set, Settings stays behind plugin.admin) is already
// exercised end to end against a real LibreNMS instance in
// tests/librenms/DeviceAccessTest.php (testLimitedUserSeesOnly
// AuthorizedDeviceAndLocation, testUserWithoutDevicePermissionGets
// AnEmptyDeviceSet, testLibreNmsControllerStillProtectsPluginSettings).
// This plugin registers no route of its own (LibreNMS's plugin hook
// system provides /plugin/{name} generically) and has no migration/
// schema of its own (every table it reads is existing LibreNMS core
// schema, read-only) — the assertions below lock in that this session's
// (and any future) change did not introduce either.
// ---------------------------------------------------------------------
$assert(
    ! preg_match('/\bRoute::/', $pageSource . $menuSource . $settingsSource . $deviceAccessSource),
    'Security: no plugin file registers a Laravel route of its own — LibreNMS\'s plugin hook system is the only entry point'
);
$assert(
    ! preg_match('/\bSchema::(create|table|drop)\b/', $pageSource . $menuSource . $settingsSource . $deviceAccessSource),
    'Security: no plugin file creates/alters/drops a database table — every table read is existing LibreNMS core schema, read-only'
);
$assert(
    ! is_dir($root . '/database/migrations'),
    'Security: this plugin has no migrations directory of its own'
);
$assert(
    ! preg_match('/@json\(\s*\$(?:devices|payload|otherLocations|locations|mdf)\b/', $bladeSource),
    'page.blade.php: no bulk unfiltered device/location collection is embedded into the page as a client-side JSON blob — TV Mode\'s filtering is server-side, not a client-side hide of fully-loaded data'
);
$assert(
    ! preg_match('/\bwindow\.\w*[Dd]evices\s*=/', $bladeSource)
        && ! preg_match('/data-(?:all-)?devices\s*=/', $bladeSource),
    'page.blade.php: no window-scoped variable or data attribute carries a bulk device dump'
);

// --- Section 14: no TV-mode-scoped rule declares operational text
// under 12px. -------------------------------------------------------
$assert(
    preg_match('/body\.tv-mode-active[^{]*\{\s*font-size:\s*(?:9|10|11)px/s', $bladeSource) === 0,
    'page.blade.php: no body.tv-mode-active rule declares operational text under 12px'
);

// ---------------------------------------------------------------------
// $visibleSummary now consults the same centralized policy — no second
// independent implementation of "is this severity shown" in the header
// counters. Page::data() cannot be instantiated/unit-tested directly in
// this environment (it requires the full Composer-autoloaded Laravel/
// LibreNMS class hierarchy — Illuminate\Support\Collection,
// App\Plugins\Hooks\PageHook, App\Models\User, etc. — which are only
// available inside a real LibreNMS install with `composer install` run;
// neither exists in this sandbox, confirmed directly rather than
// assumed). What IS verified here, honestly: (a) the exact source shape
// proving $visibleSummary is built from $policyVisibleDevices via
// ProblemPolicy::deviceVisible($device, $policy) — the identical
// function/policy pair Priority Attention and TV already use, not a
// fourth re-derivation, and (b) the underlying shared decision function
// itself against every combination this section's matrix names, which
// is the actual logic $visibleSummary's filter now runs per device. The
// real LibreNMS/MariaDB CI integration job is the only place the full
// aggregate counts can be exercised end to end; that gap is disclosed,
// not silently worked around.
// ---------------------------------------------------------------------
$assert(
    str_contains($pageSource, 'ProblemPolicy::deviceVisible($device, $policy)')
        && str_contains($pageSource, '$policyVisibleDevices = $filteredDevices')
        && str_contains($pageSource, "'critical_devices' => \$policyVisibleDevices->where('health', Severity::CRITICAL)->count()"),
    'Page.php: $visibleSummary (header counters) is built from the same ProblemPolicy::deviceVisible($device, $policy) decision as Priority Attention and TV, not a fourth independent filter'
);
$assert(
    ! preg_match("/'critical_devices'\s*=>\s*\\\$filteredDevices->where/", $pageSource),
    'Page.php: the header counters no longer count directly from the policy-unaware $filteredDevices collection'
);

$summaryPolicy = fn (array $overrides): array => Config::visibilityPolicy(Config::resolve(array_merge([
    'default_severity_critical' => '1',
    'default_severity_warning' => '1',
    'default_severity_unknown' => '1',
    'default_severity_stale' => '1',
    'default_severity_maintenance' => '1',
    'default_severity_healthy' => '0',
    'default_severity_no_sensor' => '1',
], $overrides)), 'global');

// Critical + Warning only
$p = $summaryPolicy([
    'default_severity_unknown' => '0', 'default_severity_stale' => '0',
    'default_severity_maintenance' => '0', 'default_severity_no_sensor' => '0',
]);
$assert(
    ProblemPolicy::deviceVisible(['health' => 'critical', 'problem_types' => ['device']], $p) === true
        && ProblemPolicy::deviceVisible(['health' => 'warning', 'problem_types' => ['temperature']], $p) === true
        && ProblemPolicy::deviceVisible(['health' => 'unknown', 'problem_types' => ['state']], $p) === false
        && ProblemPolicy::deviceVisible(['health' => 'healthy', 'problem_types' => []], $p) === false
        && ProblemPolicy::deviceVisible(['health' => 'stale', 'problem_types' => []], $p) === false
        && ProblemPolicy::deviceVisible(['health' => 'maintenance', 'problem_types' => []], $p) === false,
    'Summary Caso: Critical + Warning only counts exactly Critical/Warning, nothing else'
);

// Critical only
$p = $summaryPolicy(['default_severity_warning' => '0']);
$assert(
    ProblemPolicy::deviceVisible(['health' => 'critical', 'problem_types' => []], $p) === true
        && ProblemPolicy::deviceVisible(['health' => 'warning', 'problem_types' => []], $p) === false,
    'Summary Caso: Critical solamente'
);

// Warning only
$p = $summaryPolicy(['default_severity_critical' => '0']);
$assert(
    ProblemPolicy::deviceVisible(['health' => 'warning', 'problem_types' => []], $p) === true
        && ProblemPolicy::deviceVisible(['health' => 'critical', 'problem_types' => []], $p) === false,
    'Summary Caso: Warning solamente'
);

// Healthy only
$p = $summaryPolicy([
    'default_severity_critical' => '0', 'default_severity_warning' => '0',
    'default_severity_unknown' => '0', 'default_severity_stale' => '0',
    'default_severity_maintenance' => '0', 'default_severity_healthy' => '1',
]);
$assert(
    ProblemPolicy::deviceVisible(['health' => 'healthy', 'problem_types' => []], $p) === true
        && ProblemPolicy::deviceVisible(['health' => 'critical', 'problem_types' => []], $p) === false,
    'Summary Caso: Healthy solamente — Priority Attention would still be empty (it only ever holds actionable issues, which a Healthy device never has)'
);

// Unknown deshabilitado (independent of Critical/Warning staying on)
$p = $summaryPolicy(['default_severity_unknown' => '0']);
$assert(
    ProblemPolicy::deviceVisible(['health' => 'unknown', 'problem_types' => []], $p) === false
        && ProblemPolicy::deviceVisible(['health' => 'critical', 'problem_types' => []], $p) === true,
    'Summary Caso: Unknown deshabilitado no afecta Critical/Warning'
);

// Stale deshabilitado
$p = $summaryPolicy(['default_severity_stale' => '0']);
$assert(
    ProblemPolicy::deviceVisible(['health' => 'stale', 'problem_types' => []], $p) === false,
    'Summary Caso: Stale deshabilitado'
);

// Maintenance deshabilitado
$p = $summaryPolicy(['default_severity_maintenance' => '0']);
$assert(
    ProblemPolicy::deviceVisible(['health' => 'maintenance', 'problem_types' => []], $p) === false,
    'Summary Caso: Maintenance deshabilitado'
);

// No sensor deshabilitado — the summary counter itself (Page.php's
// $policy['no_sensor'] ? ... : 0 branch), not deviceVisible(), is what
// enforces this; asserted at the source-shape level since no_sensor is
// never a device['health'] value deviceVisible() could gate on.
$assert(
    str_contains($pageSource, "\$policy['no_sensor']\n                ? \$filteredDevices->sum('no_sensor_count')\n                : 0"),
    'Summary Caso: No sensor installed — the counter itself is gated by default_severity_no_sensor, independent of the Healthy toggle'
);

// Tipo de problema deshabilitado + dispositivo con múltiples causas
// donde solo una está habilitada — same deviceVisible() the summary
// now uses, exercised with the exact multi-cause shape this section
// names explicitly.
$p = $summaryPolicy([]);
$mixed = Config::visibilityPolicy(Config::resolve([
    'default_severity_critical' => '1',
    'default_problem_temperature' => '0',
    'default_problem_battery' => '0',
    'default_problem_service' => '1',
]), 'global');
$assert(
    ProblemPolicy::deviceVisible(['health' => 'critical', 'problem_types' => ['temperature', 'battery']], $mixed) === false,
    'Summary Caso: a device whose only two causes are both disabled problem types is not counted'
);
$assert(
    ProblemPolicy::deviceVisible(['health' => 'critical', 'problem_types' => ['temperature', 'battery', 'service']], $mixed) === true,
    'Summary Caso: the same device is counted once one of its three causes (service) is an enabled problem type'
);

// --- Section 4/12: every Config::FIELDS group must actually render in
// settings.blade.php — a field with a 'group' key not present in that
// template's hardcoded $groupLabels list is persisted/resolved
// correctly but invisible and unconfigurable from the admin UI. This
// exact gap existed for the new 'tv_restrict' group (five tv_hide_*
// settings) until settings.blade.php's $groupLabels was updated;
// asserted generically here so any future group gets the same check
// without needing its own one-off test. -----------------------------
$settingsBladeSource = (string) file_get_contents($root . '/resources/views/settings.blade.php');
preg_match('/\$groupLabels\s*=\s*\[(.*?)\];/s', $settingsBladeSource, $groupLabelsMatch);
$renderedGroups = [];
preg_match_all("/'([a-zA-Z0-9_]+)'\s*=>/", $groupLabelsMatch[1] ?? '', $groupLabelMatches);
$renderedGroups = $groupLabelMatches[1] ?? [];
$definedGroups = array_keys(Config::grouped());
$missingFromForm = array_values(array_diff($definedGroups, $renderedGroups));
$assert(
    $missingFromForm === [],
    'settings.blade.php: every Config::FIELDS group renders in the admin form (missing: ' . implode(', ', $missingFromForm) . ')'
);
$assert(
    in_array('tv_restrict', $renderedGroups, true),
    'settings.blade.php: the five new tv_hide_* TV-restriction settings are configurable from the Settings page, not silently persisted-but-invisible'
);

exit($failures === 0 ? 0 : 1);
