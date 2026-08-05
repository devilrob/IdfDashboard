<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bin/update.php';

$sourceRoot = dirname(__DIR__);
$installedVersion = '1.0.3';
$bridgeVersion = '1.0.4';
$candidateVersion = \App\Plugins\IdfDashboard\Support\Version::VERSION;
$publishedBridgeZip = getenv('IDF_PUBLISHED_BRIDGE_ZIP');
$publishedBridgeSha256 = getenv('IDF_PUBLISHED_BRIDGE_SHA256');
$linuxUpgrades = in_array('--linux-upgrades', $argv, true);
$assertions = 0;

if ($linuxUpgrades && DIRECTORY_SEPARATOR !== '/') {
    fwrite(STDOUT, 'Bootstrapped candidate upgrades: SKIP (activation renames an executing directory and requires Linux).' . PHP_EOL);
    $linuxUpgrades = false;
}

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;

    if (! $condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }

    fwrite(STDOUT, $message . ': PASS' . PHP_EOL);
};

$invoke = static function (IdfDashboardUpdater $updater, string $method, array $arguments = []): mixed {
    $reflection = new ReflectionMethod($updater, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($updater, $arguments);
};

$removeTree = static function (string $path) use (&$removeTree): void {
    if (! file_exists($path) && ! is_link($path)) {
        return;
    }

    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }

    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        $removeTree($entry->getPathname());
    }

    rmdir($path);
};

$copyPackage = static function (
    string $destination,
    string $marker,
    string $version
) use ($sourceRoot): void {
    $profile = IdfDashboardUpdater::packageProfileForVersion($version);

    foreach ($profile['required'] as $relative) {
        $target = $destination . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $parent = dirname($target);

        if (! is_dir($parent) && ! mkdir($parent, 0750, true) && ! is_dir($parent)) {
            throw new RuntimeException('Unable to create test package directory.');
        }

        $source = $sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (is_file($source)) {
            if (! copy($source, $target)) {
                throw new RuntimeException('Unable to copy test package file: ' . $relative);
            }
        } elseif (str_starts_with($relative, 'Support/')) {
            $class = pathinfo($relative, PATHINFO_FILENAME);
            file_put_contents(
                $target,
                "<?php\n\nnamespace App\\Plugins\\IdfDashboard\\Support;\n\nfinal class {$class}\n{\n}\n"
            );
        } else {
            throw new RuntimeException('Unable to source test package file: ' . $relative);
        }
    }

    $versionPath = $destination . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'Version.php';
    $versionSource = (string) file_get_contents($versionPath);
    $versionSource = preg_replace(
        "/public const VERSION\s*=\s*'[^']+';/",
        "public const VERSION = '$version';",
        $versionSource,
        1
    );
    file_put_contents($versionPath, $versionSource);
    file_put_contents($destination . DIRECTORY_SEPARATOR . 'README.md', PHP_EOL . $marker . PHP_EOL, FILE_APPEND);
};

$gitFile = static function (string $tag, string $relative) use ($sourceRoot): string {
    $output = [];
    $status = 1;
    exec(
        'git -c ' . escapeshellarg('safe.directory=' . $sourceRoot)
            . ' -C ' . escapeshellarg($sourceRoot)
            . ' show ' . escapeshellarg($tag . ':' . $relative) . ' 2>&1',
        $output,
        $status
    );

    if ($status !== 0) {
        throw new RuntimeException('Unable to read published file ' . $tag . ':' . $relative);
    }

    return implode(PHP_EOL, $output) . PHP_EOL;
};

$hydrateLegacyApplication = static function (string $destination, string $tag) use ($gitFile): void {
    $applicationFiles = array_values(array_diff(
        IdfDashboardUpdater::packageProfileForVersion('1.0.3')['required'],
        ['bin/update.php', 'Support/Version.php', 'README.md', 'CHANGELOG.md']
    ));

    foreach ($applicationFiles as $relative) {
        $target = $destination . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        file_put_contents($target, $gitFile($tag, $relative));
    }
};

$copyBridgePackage = static function (string $destination, string $marker) use (
    $copyPackage,
    $hydrateLegacyApplication,
    $bridgeVersion,
    $publishedBridgeZip
): void {
    if (is_string($publishedBridgeZip) && $publishedBridgeZip !== '') {
        if (! is_file($publishedBridgeZip) || ! mkdir($destination, 0750, true)) {
            throw new RuntimeException('Unable to prepare the published bridge package.');
        }

        $zip = new ZipArchive();

        if ($zip->open($publishedBridgeZip) !== true || ! $zip->extractTo($destination)) {
            throw new RuntimeException('Unable to extract the published bridge package.');
        }

        $zip->close();

        return;
    }

    $copyPackage($destination, $marker, $bridgeVersion);
    $hydrateLegacyApplication($destination, 'v1.0.3');
};

$createEnvironment = static function (
    string $base,
    string $marker,
    ?string $version = null
) use ($copyPackage): array {
    $version ??= \App\Plugins\IdfDashboard\Support\Version::VERSION;
    $libreNms = $base . DIRECTORY_SEPARATOR . 'librenms';
    $plugins = $libreNms . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Plugins';
    $active = $plugins . DIRECTORY_SEPARATOR . 'IdfDashboard';
    $storage = $libreNms . DIRECTORY_SEPARATOR . 'plugin-backups' . DIRECTORY_SEPARATOR . 'IdfDashboard';
    mkdir($plugins, 0750, true);
    $copyPackage($active, $marker, $version);

    $artisan = <<<'PHP'
<?php
$plugins = __DIR__ . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Plugins';
$related = array_values(array_filter(
    scandir($plugins) ?: [],
    static fn (string $name): bool => preg_match('/^\.?IdfDashboard/', $name) === 1
));
sort($related);
$hooks = ['Menu.php', 'Page.php', 'Settings.php'];
$valid = $related === ['IdfDashboard'];
foreach ($hooks as $hook) {
    $valid = $valid && is_file($plugins . DIRECTORY_SEPARATOR . 'IdfDashboard' . DIRECTORY_SEPARATOR . $hook);
}
file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'view-clear.log', json_encode($related) . PHP_EOL, FILE_APPEND);
if (! $valid) {
    fwrite(STDERR, 'unsafe plugin scan');
    exit(41);
}
if (is_file(__DIR__ . DIRECTORY_SEPARATOR . 'fail-next-view-clear')) {
    unlink(__DIR__ . DIRECTORY_SEPARATOR . 'fail-next-view-clear');
    fwrite(STDERR, 'induced view clear failure');
    exit(42);
}
exit(0);
PHP;
    file_put_contents($libreNms . DIRECTORY_SEPARATOR . 'artisan', $artisan);

    return [$libreNms, $plugins, $active, $storage];
};

$testRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . 'idf-updater-integration-' . bin2hex(random_bytes(8));
mkdir($testRoot, 0700, true);

try {
    if (is_string($publishedBridgeZip) && $publishedBridgeZip !== '') {
        $assert(is_file($publishedBridgeZip), 'published v1.0.4 bridge ZIP is available');
        $assert(
            is_string($publishedBridgeSha256)
                && preg_match('/^[a-f0-9]{64}$/', $publishedBridgeSha256) === 1
                && hash_equals($publishedBridgeSha256, (string) hash_file('sha256', $publishedBridgeZip)),
            'published v1.0.4 bridge ZIP checksum matches the verified release'
        );
    }

    [$libreNms, $plugins, $active, $storage] = $createEnvironment($testRoot . DIRECTORY_SEPARATOR . 'migration', 'ORIGINAL');
    $insidePluginsRejected = false;

    try {
        new IdfDashboardUpdater($active, $plugins . DIRECTORY_SEPARATOR . 'unsafe-backups');
    } catch (RuntimeException $exception) {
        $insidePluginsRejected = str_contains($exception->getMessage(), 'outside app/Plugins');
    }

    $assert($insidePluginsRejected, 'backup storage inside app/Plugins is rejected');
    $updater = new IdfDashboardUpdater($active, $storage);
    $invoke($updater, 'initializeStorage');

    $legacyProfile = IdfDashboardUpdater::packageProfileForVersion($bridgeVersion);
    $phase1Profile = IdfDashboardUpdater::packageProfileForVersion($candidateVersion);
    $assert($legacyProfile['name'] === 'legacy-v1', 'bridge selects the exact legacy-v1 profile');
    $assert(count($legacyProfile['required']) === 14, 'legacy-v1 profile contains exactly 14 files');
    $assert($phase1Profile['name'] === 'phase1-v1', 'functional release selects the exact phase1-v1 profile');
    $assert(count($phase1Profile['required']) === 18, 'phase1-v1 profile contains exactly 18 files');
    $assert(
        preg_match('/^[a-f0-9]{64}$/', $legacyProfile['checksum']) === 1
            && preg_match('/^[a-f0-9]{64}$/', $phase1Profile['checksum']) === 1,
        'each package profile exposes a stable file-list checksum'
    );

    $bridgePackage = $testRoot . DIRECTORY_SEPARATOR . 'bridge-package';
    $phase1Package = $testRoot . DIRECTORY_SEPARATOR . 'phase1-package';
    $copyBridgePackage($bridgePackage, 'BRIDGE');
    $copyPackage($phase1Package, 'PHASE1', $candidateVersion);
    $updater->validateReleasePackage($bridgePackage, $bridgeVersion);
    $assert(true, 'bridge updater accepts an exact 14-file package');
    $assert(
        ! str_contains((string) file_get_contents($bridgePackage . DIRECTORY_SEPARATOR . 'Page.php'), 'Support\\Severity'),
        'bridge package retains the published v1.0.3 application without Phase 1 dependencies'
    );
    $updater->validateReleasePackage($phase1Package, $candidateVersion);
    $assert(true, 'bridge updater accepts an exact 18-file package');

    foreach ([
        'bridge' => [$bridgePackage, $bridgeVersion, $legacyProfile],
        'phase1' => [$phase1Package, $candidateVersion, $phase1Profile],
    ] as $archiveName => [$packagePath, $packageVersion, $profile]) {
        $archivePath = $testRoot . DIRECTORY_SEPARATOR . $archiveName . '.zip';
        $extractPath = $testRoot . DIRECTORY_SEPARATOR . $archiveName . '-extract';
        $zip = new ZipArchive();

        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create valid ' . $archiveName . ' ZIP fixture.');
        }

        foreach ($profile['required'] as $relative) {
            $zip->addFile(
                $packagePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative),
                'IdfDashboard/' . $relative
            );
        }

        $zip->close();
        $invoke($updater, 'extractArchive', [$archivePath, $extractPath]);
        $extractedPackage = $extractPath . DIRECTORY_SEPARATOR . 'IdfDashboard';
        $updater->validateReleasePackage($extractedPackage, $packageVersion);
        $extractedFiles = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extractedPackage, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $extractedFiles[] = str_replace('\\', '/', substr(
                    $file->getPathname(),
                    strlen($extractedPackage) + 1
                ));
            }
        }

        sort($extractedFiles);
        $expectedFiles = $profile['required'];
        sort($expectedFiles);
        $assert($extractedFiles === $expectedFiles, $archiveName . ' ZIP extracts to its exact closed profile');
    }

    $missingPackage = $testRoot . DIRECTORY_SEPARATOR . 'phase1-missing-support';
    $copyPackage($missingPackage, 'MISSING', $candidateVersion);
    unlink($missingPackage . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'Severity.php');
    $missingRejected = false;

    try {
        $updater->validateReleasePackage($missingPackage, $candidateVersion);
    } catch (RuntimeException $exception) {
        $missingRejected = str_contains($exception->getMessage(), 'missing required file');
    }

    $assert($missingRejected, 'bridge updater rejects a 17-file Phase 1 package');

    $extraPackage = $testRoot . DIRECTORY_SEPARATOR . 'phase1-extra-file';
    $copyPackage($extraPackage, 'EXTRA', $candidateVersion);
    file_put_contents($extraPackage . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'Unexpected.php', '<?php');
    $extraRejected = false;

    try {
        $updater->validateReleasePackage($extraPackage, $candidateVersion);
    } catch (RuntimeException $exception) {
        $extraRejected = str_contains($exception->getMessage(), 'Unexpected release file');
    }

    $assert($extraRejected, 'bridge updater rejects a 19-file Phase 1 package');

    if (function_exists('symlink')) {
        $symlinkPackage = $testRoot . DIRECTORY_SEPARATOR . 'phase1-symlink';
        $copyPackage($symlinkPackage, 'SYMLINK', $candidateVersion);
        $readme = $symlinkPackage . DIRECTORY_SEPARATOR . 'README.md';
        unlink($readme);

        if (@symlink($sourceRoot . DIRECTORY_SEPARATOR . 'README.md', $readme)) {
            $symlinkRejected = false;

            try {
                $updater->validateReleasePackage($symlinkPackage, $candidateVersion);
            } catch (RuntimeException $exception) {
                $symlinkRejected = str_contains($exception->getMessage(), 'Symbolic links');
            }

            $assert($symlinkRejected, 'bridge updater rejects a package symlink');
        }
    }

    $falseVersionRejected = false;

    try {
        $updater->validateReleasePackage($phase1Package, '1.1.1');
    } catch (RuntimeException $exception) {
        $falseVersionRejected = str_contains($exception->getMessage(), 'does not match');
    }

    $assert($falseVersionRejected, 'bridge updater rejects a false target version');
    $unknownProfileRejected = false;

    try {
        IdfDashboardUpdater::packageProfileForVersion('2.0.0');
    } catch (RuntimeException $exception) {
        $unknownProfileRejected = str_contains($exception->getMessage(), 'known package profile');
    }

    $assert($unknownProfileRejected, 'bridge updater rejects an unknown package profile');
    $downgradeRejected = false;

    try {
        IdfDashboardUpdater::assertVersionTransition($bridgeVersion, $installedVersion, false, false);
    } catch (RuntimeException $exception) {
        $downgradeRejected = str_contains($exception->getMessage(), 'Downgrade refused');
    }

    $assert($downgradeRejected, 'bridge updater rejects an implicit downgrade');
    IdfDashboardUpdater::assertVersionTransition($bridgeVersion, $installedVersion, true, false);
    $assert(true, 'bridge updater permits only an explicit downgrade transition');
    $invalidDowngradeFlagRejected = false;

    try {
        IdfDashboardUpdater::assertVersionTransition($installedVersion, $bridgeVersion, true, false);
    } catch (RuntimeException $exception) {
        $invalidDowngradeFlagRejected = str_contains($exception->getMessage(), 'only valid for an older version');
    }

    $assert($invalidDowngradeFlagRejected, 'downgrade flag is rejected for an upgrade');

    $legacyUpdaterLines = [];
    $legacyUpdaterStatus = 1;
    exec(
        'git -c ' . escapeshellarg('safe.directory=' . $sourceRoot)
            . ' -C ' . escapeshellarg($sourceRoot) . ' show v1.0.3:bin/update.php 2>&1',
        $legacyUpdaterLines,
        $legacyUpdaterStatus
    );
    $assert($legacyUpdaterStatus === 0, 'published v1.0.3 updater source is available for compatibility proof');
    $legacyUpdaterRoot = $testRoot . DIRECTORY_SEPARATOR . 'published-v1.0.3';
    $copyPackage($legacyUpdaterRoot, 'PUBLISHED-V1.0.3', $installedVersion);
    $legacyUpdaterSource = implode(PHP_EOL, $legacyUpdaterLines) . PHP_EOL;
    file_put_contents($legacyUpdaterRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'update.php', $legacyUpdaterSource);
    putenv('IDF_LEGACY_UPDATER=' . $legacyUpdaterRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'update.php');
    putenv('IDF_LEGACY_ROOT=' . $legacyUpdaterRoot);
    putenv('IDF_PHASE1_PACKAGE=' . $phase1Package);
    putenv('IDF_PHASE1_VERSION=' . $candidateVersion);
    $legacyCheckCode = <<<'PHP'
<?php
require getenv('IDF_LEGACY_UPDATER');
(new IdfDashboardUpdater(getenv('IDF_LEGACY_ROOT')))
    ->validateReleasePackage(getenv('IDF_PHASE1_PACKAGE'), getenv('IDF_PHASE1_VERSION'));
PHP;
    $legacyCheckRunner = $testRoot . DIRECTORY_SEPARATOR . 'legacy-updater-check.php';
    file_put_contents($legacyCheckRunner, $legacyCheckCode);
    $legacyOutput = [];
    $legacyExit = 0;
    exec(
        implode(' ', array_map('escapeshellarg', [PHP_BINARY, $legacyCheckRunner])) . ' 2>&1',
        $legacyOutput,
        $legacyExit
    );
    $assert(
        $legacyExit !== 0 && str_contains(implode(' ', $legacyOutput), 'Unexpected release file'),
        'published v1.0.3 updater rejects the 18-file Phase 1 package'
    );

    $recoverConflictRejected = false;

    try {
        $invoke($updater, 'parseOptions', [['update.php', '--recover', '--install']]);
    } catch (RuntimeException $exception) {
        $recoverConflictRejected = str_contains($exception->getMessage(), 'cannot be combined');
    }

    $assert($recoverConflictRejected, 'recovery mode rejects update option combinations');

    $recoverRetentionRejected = false;

    try {
        $invoke($updater, 'parseOptions', [['update.php', '--recover', '--keep-backups=5']]);
    } catch (RuntimeException $exception) {
        $recoverRetentionRejected = str_contains($exception->getMessage(), 'cannot be combined');
    }

    $assert($recoverRetentionRejected, 'recovery mode rejects ignored retention options');

    $recoverRootRequired = false;

    try {
        $invoke($updater, 'parseOptions', [['update.php', '--recover']]);
    } catch (RuntimeException $exception) {
        $recoverRootRequired = str_contains($exception->getMessage(), '--librenms-root');
    }

    $assert($recoverRootRequired, 'recovery mode requires an explicit LibreNMS root');
    $recoveryOptions = $invoke($updater, 'parseOptions', [[
        'update.php',
        '--recover',
        '--librenms-root=' . $libreNms,
    ]]);
    $assert(
        $recoveryOptions['librenms_root'] === $libreNms,
        'recovery mode accepts the exact LibreNMS root'
    );

    $oversizedManifestZip = $testRoot . DIRECTORY_SEPARATOR . 'too-many-entries.zip';
    $oversizedManifestExtract = $testRoot . DIRECTORY_SEPARATOR . 'too-many-entries';
    $zip = new ZipArchive();

    if ($zip->open($oversizedManifestZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create updater ZIP limit fixture.');
    }

    for ($index = 0; $index < 65; $index++) {
        $zip->addFromString('overflow/file-' . $index . '.txt', 'x');
    }

    $zip->close();
    $entryLimitRejected = false;

    try {
        $invoke($updater, 'extractArchive', [$oversizedManifestZip, $oversizedManifestExtract]);
    } catch (RuntimeException $exception) {
        $entryLimitRejected = str_contains($exception->getMessage(), 'entry count');
    }

    $assert($entryLimitRejected, 'ZIP entry limit is enforced before extraction');
    $assert(
        is_dir($oversizedManifestExtract)
            && iterator_count(new FilesystemIterator($oversizedManifestExtract, FilesystemIterator::SKIP_DOTS)) === 0,
        'rejected ZIP writes no archive entries'
    );
    $removeTree($oversizedManifestExtract);
    unlink($oversizedManifestZip);

    $traversalZip = $testRoot . DIRECTORY_SEPARATOR . 'traversal.zip';
    $traversalExtract = $testRoot . DIRECTORY_SEPARATOR . 'traversal-extract';
    $traversalEscape = $testRoot . DIRECTORY_SEPARATOR . 'escaped.php';
    $zip = new ZipArchive();
    $zip->open($traversalZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('../escaped.php', '<?php');
    $zip->close();
    $traversalRejected = false;

    try {
        $invoke($updater, 'extractArchive', [$traversalZip, $traversalExtract]);
    } catch (RuntimeException $exception) {
        $traversalRejected = str_contains($exception->getMessage(), 'invalid entry metadata');
    }

    $assert($traversalRejected, 'ZIP traversal is rejected during isolated extraction');
    $assert(! file_exists($traversalEscape), 'ZIP traversal writes nothing outside extraction');

    $legacyName = 'IdfDashboard.backup-20260804-203726-v1.0.1';
    mkdir($plugins . DIRECTORY_SEPARATOR . $legacyName, 0750);
    file_put_contents($plugins . DIRECTORY_SEPARATOR . $legacyName . DIRECTORY_SEPARATOR . 'Menu.php', '<?php');
    $invoke($updater, 'migrateLegacyPluginDirectories');
    $assert(! file_exists($plugins . DIRECTORY_SEPARATOR . $legacyName), 'legacy backup removed from app/Plugins');
    $assert(is_dir($storage . DIRECTORY_SEPARATOR . $legacyName), 'legacy backup migrated to external storage');
    $assert(is_file($storage . DIRECTORY_SEPARATOR . 'IdfDashboard-update.log'), 'migration is recorded in external audit log');
    $assert(! file_exists($plugins . DIRECTORY_SEPARATOR . 'IdfDashboard-update.log'), 'audit log never enters app/Plugins');

    $blockedName = 'IdfDashboard.old-recovery';
    mkdir($plugins . DIRECTORY_SEPARATOR . $blockedName, 0750);
    mkdir($storage . DIRECTORY_SEPARATOR . $blockedName, 0750);
    $migrationBlocked = false;

    try {
        $invoke($updater, 'migrateLegacyPluginDirectories');
    } catch (RuntimeException $exception) {
        $migrationBlocked = str_contains($exception->getMessage(), 'nothing was moved');
    }

    $assert($migrationBlocked, 'unsafe legacy migration aborts clearly');
    $assert(is_dir($plugins . DIRECTORY_SEPARATOR . $blockedName), 'failed migration leaves legacy source untouched');
    $removeTree($plugins . DIRECTORY_SEPARATOR . $blockedName);
    $removeTree($storage . DIRECTORY_SEPARATOR . $blockedName);

    $unknownName = 'IdfDashboard.unrecognized-copy';
    mkdir($plugins . DIRECTORY_SEPARATOR . $unknownName, 0750);
    $invoke($updater, 'migrateLegacyPluginDirectories');
    $unknownBlocked = false;

    try {
        $invoke($updater, 'assertPluginTreeClean');
    } catch (RuntimeException $exception) {
        $unknownBlocked = str_contains($exception->getMessage(), 'Unsafe alternate');
    }

    $assert(is_dir($plugins . DIRECTORY_SEPARATOR . $unknownName), 'unknown plugin directory is never deleted or moved');
    $assert($unknownBlocked, 'unknown plugin directory blocks activation');
    $removeTree($plugins . DIRECTORY_SEPARATOR . $unknownName);

    $firstLock = $invoke($updater, 'acquireLock');
    $secondUpdater = new IdfDashboardUpdater($active, $storage);
    $invoke($secondUpdater, 'initializeStorage');
    $occupied = false;

    try {
        $invoke($secondUpdater, 'acquireLock');
    } catch (RuntimeException $exception) {
        $occupied = $exception->getMessage() === 'Another IdfDashboard update is already running.';
    }

    $assert($occupied, 'occupied lock is distinguished');
    flock($firstLock, LOCK_UN);
    fclose($firstLock);

    $lockPath = $storage . DIRECTORY_SEPARATOR . '.IdfDashboard-update.lock';
    file_put_contents($lockPath, 'corrupt');
    $corrupt = false;

    try {
        $invoke($updater, 'acquireLock');
    } catch (RuntimeException $exception) {
        $corrupt = str_contains($exception->getMessage(), 'lock is corrupt');
    }

    $assert($corrupt, 'corrupt lock is distinguished');
    unlink($lockPath);

    if (DIRECTORY_SEPARATOR === '/' && function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        file_put_contents($lockPath, '');
        chmod($lockPath, 0400);
        $permissionDenied = false;

        try {
            $invoke($updater, 'acquireLock');
        } catch (RuntimeException $exception) {
            $permissionDenied = str_contains($exception->getMessage(), 'Permission denied')
                && ! str_contains($exception->getMessage(), 'already running');
        }

        chmod($lockPath, 0600);
        unlink($lockPath);
        $assert($permissionDenied, 'real lock permission denial is distinguished');
    }

    $permissionMessage = IdfDashboardUpdater::lockOpenFailureMessage('fopen(): Permission denied');
    $assert(str_contains($permissionMessage, 'Permission denied'), 'lock permission error is preserved');
    $assert(! str_contains($permissionMessage, 'already running'), 'lock permission error is not reported as contention');

    $assert(! IdfDashboardUpdater::executionUserIsAllowed(1001, 1000, false), 'wrong operating-system user is rejected');
    $assert(! IdfDashboardUpdater::executionUserIsAllowed(0, 0, false), 'root install is rejected');
    $assert(IdfDashboardUpdater::executionUserIsAllowed(1000, 1000, false), 'plugin owner install is accepted');
    $assert(IdfDashboardUpdater::executionUserIsAllowed(1001, 1000, true), 'diagnostic mode permits a different user');

    [$successRoot, $successPlugins, $successActive, $successStorage] = $createEnvironment(
        $testRoot . DIRECTORY_SEPARATOR . 'success',
        'ORIGINAL'
    );
    $extracted = $testRoot . DIRECTORY_SEPARATOR . 'success-package';
    $copyPackage($extracted, 'UPDATED', $candidateVersion);
    chmod($successActive, 0750);
    chmod($successActive . DIRECTORY_SEPARATOR . 'Menu.php', 0644);
    $originalRootMode = fileperms($successActive) & 0777;
    $originalFileMode = fileperms($successActive . DIRECTORY_SEPARATOR . 'Menu.php') & 0777;
    $originalOwner = fileowner($successActive);
    $originalGroup = filegroup($successActive);
    $successUpdater = new IdfDashboardUpdater($successActive, $successStorage);
    $invoke($successUpdater, 'initializeStorage');
    $backup = $invoke($successUpdater, 'activateAtomically', [$extracted, $candidateVersion]);

    $assert(realpath(dirname($backup)) === realpath($successStorage), 'backup is outside app/Plugins');
    $assert(str_contains((string) file_get_contents($successActive . DIRECTORY_SEPARATOR . 'README.md'), 'UPDATED'), 'activation installs prepared package');
    $assert((fileperms($successActive) & 0777) === $originalRootMode, 'activation preserves root directory permissions');
    $assert((fileperms($successActive . DIRECTORY_SEPARATOR . 'Menu.php') & 0777) === $originalFileMode, 'activation preserves existing file permissions');
    $assert(fileowner($successActive) === $originalOwner && filegroup($successActive) === $originalGroup, 'activation preserves owner and group');
    $related = array_values(array_filter(
        scandir($successPlugins) ?: [],
        static fn (string $name): bool => preg_match('/^\.?IdfDashboard/', $name) === 1
    ));
    $assert($related === ['IdfDashboard'], 'LibreNMS scan sees only active IdfDashboard');
    $scanLog = trim((string) file_get_contents($successRoot . DIRECTORY_SEPARATOR . 'view-clear.log'));
    $assert($scanLog === '["IdfDashboard"]', 'view:clear runs only after plugin tree is clean');
    $assert(glob($successStorage . DIRECTORY_SEPARATOR . '.IdfDashboard.staging-*') === [], 'external staging is consumed after activation');

    $oldest = $successStorage . DIRECTORY_SEPARATOR . 'IdfDashboard.backup-20260101-000000-v0.9.0';
    $older = $successStorage . DIRECTORY_SEPARATOR . 'IdfDashboard.backup-20260102-000000-v0.9.1';
    mkdir($oldest, 0750);
    mkdir($older, 0750);
    $invoke($successUpdater, 'pruneBackups', [2, $backup]);
    $assert(! is_dir($oldest), 'retention removes only expired external backup');
    $assert(is_dir($older) && is_dir($backup), 'retention preserves newest and protected backup');

    if (function_exists('symlink')) {
        $unsafeBackup = $successStorage . DIRECTORY_SEPARATOR . 'IdfDashboard.backup-20250101-000000-v0.8.0';
        mkdir($unsafeBackup, 0750);
        $linkCreated = @symlink($successActive . DIRECTORY_SEPARATOR . 'Menu.php', $unsafeBackup . DIRECTORY_SEPARATOR . 'Menu.php');

        if ($linkCreated) {
            $unsafeRetention = false;

            try {
                $invoke($successUpdater, 'pruneBackups', [2, $backup]);
            } catch (RuntimeException $exception) {
                $unsafeRetention = str_contains($exception->getMessage(), 'symbolic link');
            }

            $assert($unsafeRetention && is_dir($unsafeBackup), 'retention rejects symlink backup without deleting it');
        }
    }

    [$rollbackRoot, $rollbackPlugins, $rollbackActive, $rollbackStorage] = $createEnvironment(
        $testRoot . DIRECTORY_SEPARATOR . 'rollback',
        'ORIGINAL'
    );
    $rollbackPackage = $testRoot . DIRECTORY_SEPARATOR . 'rollback-package';
    $copyPackage($rollbackPackage, 'BROKEN-CANDIDATE', $candidateVersion);
    file_put_contents($rollbackRoot . DIRECTORY_SEPARATOR . 'fail-next-view-clear', '1');
    $rollbackUpdater = new IdfDashboardUpdater($rollbackActive, $rollbackStorage);
    $invoke($rollbackUpdater, 'initializeStorage');
    $rolledBack = false;

    try {
        $invoke($rollbackUpdater, 'activateAtomically', [$rollbackPackage, $candidateVersion]);
    } catch (RuntimeException $exception) {
        $rolledBack = str_contains($exception->getMessage(), 'automatic rollback succeeded');
    }

    $assert($rolledBack, 'post-activation failure triggers automatic rollback');
    $assert(str_contains((string) file_get_contents($rollbackActive . DIRECTORY_SEPARATOR . 'README.md'), 'ORIGINAL'), 'rollback restores original package');
    $rollbackRelated = array_values(array_filter(
        scandir($rollbackPlugins) ?: [],
        static fn (string $name): bool => preg_match('/^\.?IdfDashboard/', $name) === 1
    ));
    $assert($rollbackRelated === ['IdfDashboard'], 'rollback leaves no alternate directory in app/Plugins');
    $assert(count(glob($rollbackStorage . DIRECTORY_SEPARATOR . 'IdfDashboard.failed-*') ?: []) === 1, 'failed package evidence remains external');
    $rollbackScans = file($rollbackRoot . DIRECTORY_SEPARATOR . 'view-clear.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $assert($rollbackScans === ['["IdfDashboard"]', '["IdfDashboard"]'], 'rollback cache clears only with a clean plugin tree');

    [$recoveryRoot, $recoveryPlugins, $recoveryActive, $recoveryStorage] = $createEnvironment(
        $testRoot . DIRECTORY_SEPARATOR . 'interrupted-recovery',
        'INTERRUPTED-ORIGINAL'
    );
    $recoveryStorage = $testRoot . DIRECTORY_SEPARATOR . 'custom-recovery-storage';
    $recoveryUpdater = new IdfDashboardUpdater($recoveryActive, $recoveryStorage);
    $invoke($recoveryUpdater, 'initializeStorage');
    $interruptedBackup = $recoveryStorage . DIRECTORY_SEPARATOR
        . 'IdfDashboard.backup-20260804-230000-v' . $candidateVersion;

    if (! rename($recoveryActive, $interruptedBackup)) {
        throw new RuntimeException('Unable to simulate interruption after active-to-backup rename.');
    }

    $assert(! file_exists($recoveryActive) && is_dir($interruptedBackup), 'interruption fixture leaves active plugin absent and backup external');
    $backupUpdater = new IdfDashboardUpdater($interruptedBackup);
    $recoveryStatus = $backupUpdater->run([
        'update.php',
        '--recover',
        '--librenms-root=' . $recoveryRoot,
    ]);
    $recoveryRelated = array_values(array_filter(
        scandir($recoveryPlugins) ?: [],
        static fn (string $name): bool => preg_match('/^\.?IdfDashboard/', $name) === 1
    ));

    $assert(
        $recoveryStatus === 0 && is_dir($recoveryActive),
        'interrupted activation recovers from a custom external backup directory'
    );
    $assert(! file_exists($interruptedBackup), 'successful interruption recovery consumes restored backup path');
    $assert($recoveryRelated === ['IdfDashboard'], 'interruption recovery leaves a clean LibreNMS plugin scan');
    $recoveryAudit = (string) file_get_contents($recoveryStorage . DIRECTORY_SEPARATOR . 'IdfDashboard-update.log');
    $assert(str_contains($recoveryAudit, 'recovery_succeeded'), 'interruption recovery is recorded in external audit log');

    [$refusalRoot, $refusalPlugins, $refusalActive, $refusalStorage] = $createEnvironment(
        $testRoot . DIRECTORY_SEPARATOR . 'recovery-refusal',
        'ACTIVE-MUST-WIN'
    );
    $refusalBackup = $refusalStorage . DIRECTORY_SEPARATOR
        . 'IdfDashboard.backup-20260804-230100-v' . $candidateVersion;
    $copyPackage($refusalBackup, 'BACKUP-MUST-STAY', $installedVersion);
    $refusalUpdater = new IdfDashboardUpdater($refusalBackup, $refusalStorage);
    $activeRecoveryRefused = false;

    try {
        $invoke($refusalUpdater, 'recoverActiveInstallation');
    } catch (RuntimeException $exception) {
        $activeRecoveryRefused = str_contains($exception->getMessage(), 'already exists');
    }

    $assert($activeRecoveryRefused, 'recovery never overwrites an existing active plugin');
    $assert(
        is_dir($refusalActive) && is_dir($refusalBackup),
        'refused recovery preserves both active plugin and external backup'
    );

    if ($linuxUpgrades) {
        $activationRunner = $testRoot . DIRECTORY_SEPARATOR . 'activate-package.php';
        file_put_contents($activationRunner, <<<'PHP'
<?php
require getenv('IDF_BOOTSTRAP_UPDATER');
$updater = new IdfDashboardUpdater(
    getenv('IDF_BOOTSTRAP_ACTIVE'),
    getenv('IDF_BOOTSTRAP_STORAGE')
);
$initialize = new ReflectionMethod($updater, 'initializeStorage');
$initialize->invoke($updater);
$activate = new ReflectionMethod($updater, 'activateAtomically');
$activate->invoke(
    $updater,
    getenv('IDF_BOOTSTRAP_CANDIDATE'),
    getenv('IDF_BOOTSTRAP_VERSION')
);
PHP);
        $runActivation = static function (
            string $active,
            string $storage,
            string $candidate,
            string $version
        ) use ($activationRunner): array {
            putenv('IDF_BOOTSTRAP_UPDATER=' . $active . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'update.php');
            putenv('IDF_BOOTSTRAP_ACTIVE=' . $active);
            putenv('IDF_BOOTSTRAP_STORAGE=' . $storage);
            putenv('IDF_BOOTSTRAP_CANDIDATE=' . $candidate);
            putenv('IDF_BOOTSTRAP_VERSION=' . $version);
            $output = [];
            $status = 1;
            exec(
                implode(' ', array_map('escapeshellarg', [PHP_BINARY, $activationRunner])) . ' 2>&1',
                $output,
                $status
            );

            return [$status, $output];
        };

        foreach (['1.0.2', '1.0.3'] as $fromVersion) {
            [$upgradeRoot, $upgradePlugins, $upgradeActive, $upgradeStorage] = $createEnvironment(
                $testRoot . DIRECTORY_SEPARATOR . 'bridge-upgrade-' . str_replace('.', '-', $fromVersion),
                'BOOTSTRAPPED-' . $fromVersion,
                $fromVersion
            );
            $hydrateLegacyApplication($upgradeActive, 'v' . $fromVersion);
            file_put_contents(
                $upgradeActive . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'update.php',
                $gitFile('v' . $fromVersion, 'bin/update.php')
            );
            $assert(true, 'published v' . $fromVersion . ' application and updater are available');

            $bridgeUpgradePackage = $testRoot . DIRECTORY_SEPARATOR
                . 'bridge-package-' . str_replace('.', '-', $fromVersion);
            $functionalPackage = $testRoot . DIRECTORY_SEPARATOR
                . 'functional-package-' . str_replace('.', '-', $fromVersion);
            $copyBridgePackage($bridgeUpgradePackage, 'BRIDGE-' . $bridgeVersion);
            $copyPackage($functionalPackage, 'FUNCTIONAL-' . $candidateVersion, $candidateVersion);

            $versionPath = $upgradeActive . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'Version.php';
            [$bridgeStatus, $bridgeOutput] = $runActivation(
                $upgradeActive,
                $upgradeStorage,
                $bridgeUpgradePackage,
                $bridgeVersion
            );
            $activeVersionSource = (string) file_get_contents($versionPath);
            $assert(
                $bridgeStatus === 0 && str_contains($activeVersionSource, "VERSION = '$bridgeVersion'"),
                'published v' . $fromVersion . ' updater installs bridge v' . $bridgeVersion
                    . ($bridgeStatus === 0 ? '' : ' (exit ' . $bridgeStatus . ': ' . implode(' | ', $bridgeOutput) . ')')
            );

            file_put_contents($upgradeRoot . DIRECTORY_SEPARATOR . 'fail-next-view-clear', '1');
            [$rollbackStatus, $rollbackOutput] = $runActivation(
                $upgradeActive,
                $upgradeStorage,
                $functionalPackage,
                $candidateVersion
            );
            $rolledBackVersion = (string) file_get_contents($versionPath);
            $assert(
                $rollbackStatus !== 0
                    && str_contains(implode(' ', $rollbackOutput), 'automatic rollback succeeded')
                    && str_contains($rolledBackVersion, "VERSION = '$bridgeVersion'"),
                'failed Phase 1 activation rolls back to bridge v' . $bridgeVersion
            );

            [$functionalStatus, $functionalOutput] = $runActivation(
                $upgradeActive,
                $upgradeStorage,
                $functionalPackage,
                $candidateVersion
            );
            $activeVersionSource = (string) file_get_contents($versionPath);
            $upgradeRelated = array_values(array_filter(
                scandir($upgradePlugins) ?: [],
                static fn (string $name): bool => preg_match('/^\.?IdfDashboard/', $name) === 1
            ));
            $backups = glob($upgradeStorage . DIRECTORY_SEPARATOR . 'IdfDashboard.backup-*') ?: [];
            $backupVersions = array_map(
                static fn (string $backup): string => (string) file_get_contents(
                    $backup . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'Version.php'
                ),
                $backups
            );

            $assert(
                $functionalStatus === 0 && str_contains($activeVersionSource, "VERSION = '$candidateVersion'"),
                'bridge upgrades v' . $fromVersion . ' through v' . $bridgeVersion . ' to Phase 1 v' . $candidateVersion
                    . ($functionalStatus === 0 ? '' : ' (exit ' . $functionalStatus . ': ' . implode(' | ', $functionalOutput) . ')')
            );
            $assert($upgradeRelated === ['IdfDashboard'], 'bridge flow leaves a clean plugin scan');
            $assert(
                array_filter($backupVersions, fn (string $source): bool => str_contains($source, "VERSION = '$fromVersion'")) !== []
                    && array_filter($backupVersions, fn (string $source): bool => str_contains($source, "VERSION = '$bridgeVersion'")) !== [],
                'bridge flow retains source and bridge backups externally'
            );
        }
    }

    fwrite(STDOUT, 'Updater integration: PASS (' . $assertions . ' assertions)' . PHP_EOL);
} finally {
    $removeTree($testRoot);
}
