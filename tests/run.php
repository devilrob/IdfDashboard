<?php

declare(strict_types=1);

use App\Plugins\IdfDashboard\Support\Config;
use App\Plugins\IdfDashboard\Support\ProblemPolicy;
use App\Plugins\IdfDashboard\Support\UpdateStatus;
use App\Plugins\IdfDashboard\Support\Version;

$root = dirname(__DIR__);

require $root . '/Support/Config.php';
require $root . '/Support/ProblemPolicy.php';
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

$defaults = Config::resolve([]);
$assert($defaults['refresh_seconds'] === 30, 'config defaults');
$assert($defaults['update_check_enabled'] === true, 'periodic update check defaults on');
$assert(Config::resolve(['refresh_seconds' => 1])['refresh_seconds'] === 5, 'config lower clamp');
$assert(Config::resolve(['refresh_seconds' => 99999])['refresh_seconds'] === 3600, 'config upper clamp');
$assert(Config::resolve(['animations_enabled' => '0'])['animations_enabled'] === false, 'config boolean');

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
$assert(
    IdfDashboardUpdater::backupsToPrune([
        'IdfDashboard.backup-20260803-120000-v0.9.0',
        'not-a-plugin-backup',
        'IdfDashboard.backup-20260804-120000-v1.0.0',
    ], 1) === ['IdfDashboard.backup-20260803-120000-v0.9.0'],
    'backup retention keeps newest matching backup'
);

$pageSource = (string) file_get_contents($root . '/Page.php');
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

exit($failures === 0 ? 0 : 1);
