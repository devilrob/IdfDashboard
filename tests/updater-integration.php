<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bin/update.php';

$sourceRoot = dirname(__DIR__);
$networkInstall = in_array('--network-install-v1.0.1', $argv, true);
$assertions = 0;

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

$packageFiles = [
    'bin/update.php',
    'CHANGELOG.md',
    'Menu.php',
    'Page.php',
    'README.md',
    'resources/views/menu.blade.php',
    'resources/views/page.blade.php',
    'resources/views/settings.blade.php',
    'Settings.php',
    'Support/Config.php',
    'Support/DeviceAccess.php',
    'Support/ProblemPolicy.php',
    'Support/UpdateStatus.php',
    'Support/Version.php',
];

$copyPackage = static function (string $destination, string $marker) use ($sourceRoot, $packageFiles): void {
    foreach ($packageFiles as $relative) {
        $target = $destination . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $parent = dirname($target);

        if (! is_dir($parent) && ! mkdir($parent, 0750, true) && ! is_dir($parent)) {
            throw new RuntimeException('Unable to create test package directory.');
        }

        if (! copy($sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative), $target)) {
            throw new RuntimeException('Unable to copy test package file: ' . $relative);
        }
    }

    file_put_contents($destination . DIRECTORY_SEPARATOR . 'README.md', PHP_EOL . $marker . PHP_EOL, FILE_APPEND);
};

$createEnvironment = static function (string $base, string $marker) use ($copyPackage): array {
    $libreNms = $base . DIRECTORY_SEPARATOR . 'librenms';
    $plugins = $libreNms . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Plugins';
    $active = $plugins . DIRECTORY_SEPARATOR . 'IdfDashboard';
    $storage = $libreNms . DIRECTORY_SEPARATOR . 'plugin-backups' . DIRECTORY_SEPARATOR . 'IdfDashboard';
    mkdir($plugins, 0750, true);
    $copyPackage($active, $marker);

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
    $copyPackage($extracted, 'UPDATED');
    chmod($successActive, 0750);
    chmod($successActive . DIRECTORY_SEPARATOR . 'Menu.php', 0644);
    $originalRootMode = fileperms($successActive) & 0777;
    $originalFileMode = fileperms($successActive . DIRECTORY_SEPARATOR . 'Menu.php') & 0777;
    $originalOwner = fileowner($successActive);
    $originalGroup = filegroup($successActive);
    $successUpdater = new IdfDashboardUpdater($successActive, $successStorage);
    $invoke($successUpdater, 'initializeStorage');
    $backup = $invoke($successUpdater, 'activateAtomically', [$extracted, '1.0.2']);

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
    $copyPackage($rollbackPackage, 'BROKEN-CANDIDATE');
    file_put_contents($rollbackRoot . DIRECTORY_SEPARATOR . 'fail-next-view-clear', '1');
    $rollbackUpdater = new IdfDashboardUpdater($rollbackActive, $rollbackStorage);
    $invoke($rollbackUpdater, 'initializeStorage');
    $rolledBack = false;

    try {
        $invoke($rollbackUpdater, 'activateAtomically', [$rollbackPackage, '1.0.2']);
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

    if ($networkInstall) {
        [$networkRoot, $networkPlugins, $networkActive, $networkStorage] = $createEnvironment(
            $testRoot . DIRECTORY_SEPARATOR . 'network-install',
            'CURRENT-CANDIDATE'
        );
        $networkUpdater = new IdfDashboardUpdater($networkActive, $networkStorage);
        $status = $networkUpdater->run([
            'update.php',
            '--install',
            '--tag=v1.0.1',
            '--allow-downgrade',
            '--backup-dir=' . $networkStorage,
        ]);
        $installedVersion = (string) file_get_contents(
            $networkActive . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'Version.php'
        );
        $networkRelated = array_values(array_filter(
            scandir($networkPlugins) ?: [],
            static fn (string $name): bool => preg_match('/^\.?IdfDashboard/', $name) === 1
        ));

        $assert($status === 0 && str_contains($installedVersion, "VERSION = '1.0.1'"), 'network --install activates verified release');
        $assert($networkRelated === ['IdfDashboard'], 'network --install leaves clean plugin scan');
        $assert(count(glob($networkStorage . DIRECTORY_SEPARATOR . 'IdfDashboard.backup-*') ?: []) === 1, 'network --install retains backup externally');
    }

    fwrite(STDOUT, 'Updater integration: PASS (' . $assertions . ' assertions)' . PHP_EOL);
} finally {
    $removeTree($testRoot);
}
