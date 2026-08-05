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

exit($failures === 0 ? 0 : 1);
