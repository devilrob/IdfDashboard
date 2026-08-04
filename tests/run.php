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

$pageSource = (string) file_get_contents($root . '/Page.php');
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

exit($failures === 0 ? 0 : 1);
