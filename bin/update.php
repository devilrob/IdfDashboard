<?php

declare(strict_types=1);

use App\Plugins\IdfDashboard\Support\Version;

require_once dirname(__DIR__) . '/Support/Version.php';

final class IdfDashboardUpdater
{
    private const API_ROOT = 'https://api.github.com/repos/devilrob/IdfDashboard';

    private const MAX_API_BYTES = 2097152;

    private const MAX_CHECKSUM_BYTES = 1048576;

    private const MAX_ARCHIVE_BYTES = 52428800;

    private const MAX_ARCHIVE_ENTRIES = 64;

    private const MAX_EXTRACTED_BYTES = 26214400;

    private const MIN_STAGING_HEADROOM_BYTES = 5242880;

    private const LEGACY_V1_PATHS = [
        'Menu.php',
        'Page.php',
        'Settings.php',
        'Support/Config.php',
        'Support/DeviceAccess.php',
        'Support/ProblemPolicy.php',
        'Support/UpdateStatus.php',
        'Support/Version.php',
        'resources/views/menu.blade.php',
        'resources/views/page.blade.php',
        'resources/views/settings.blade.php',
        'bin/update.php',
        'CHANGELOG.md',
        'README.md',
    ];

    private const PHASE1_V1_PATHS = [
        'Menu.php',
        'Page.php',
        'Settings.php',
        'Support/Config.php',
        'Support/DeviceAccess.php',
        'Support/DeviceClassifier.php',
        'Support/Freshness.php',
        'Support/IssueBuilder.php',
        'Support/ProblemPolicy.php',
        'Support/Severity.php',
        'Support/UpdateStatus.php',
        'Support/Version.php',
        'resources/views/menu.blade.php',
        'resources/views/page.blade.php',
        'resources/views/settings.blade.php',
        'bin/update.php',
        'CHANGELOG.md',
        'README.md',
    ];

    private const ALLOWED_DIRECTORIES = [
        'Support',
        'resources',
        'resources/views',
        'bin',
    ];

    private string $pluginRoot;

    private string $parentDirectory;

    private string $libreNmsRoot;

    private string $storageRoot;

    private string $auditLog;

    public function __construct(?string $pluginRoot = null, ?string $storageRoot = null)
    {
        $resolved = realpath($pluginRoot ?? dirname(__DIR__));

        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException('Unable to resolve the installed plugin directory.');
        }

        $this->pluginRoot = $resolved;
        $this->parentDirectory = dirname($resolved);
        $this->libreNmsRoot = dirname($resolved, 3);
        $this->setStorageRoot($storageRoot ?? $this->libreNmsRoot
            . DIRECTORY_SEPARATOR . 'plugin-backups'
            . DIRECTORY_SEPARATOR . 'IdfDashboard');
    }

    public function run(array $arguments): int
    {
        if (PHP_VERSION_ID < 80200) {
            throw new RuntimeException('PHP 8.2 or newer is required.');
        }

        $options = $this->parseOptions($arguments);

        if ($options['backup_dir'] !== null) {
            $this->setStorageRoot($options['backup_dir']);
        }

        if ($options['help']) {
            $this->printHelp();

            return 0;
        }

        if ($options['self_test']) {
            return $this->selfTest();
        }

        if ($options['recover']) {
            $this->configureRecoveryRoot($options['librenms_root']);

            return $this->recoverActiveInstallation();
        }

        $release = $this->fetchRelease($options['tag']);
        $version = self::versionFromTag((string) $release['tag_name']);
        $available = version_compare($version, Version::VERSION, '>');

        fwrite(STDOUT, 'Installed: v' . Version::VERSION . PHP_EOL);
        fwrite(STDOUT, 'Latest stable: v' . $version . PHP_EOL);
        fwrite(STDOUT, 'Channel: ' . Version::CHANNEL . PHP_EOL);

        if (! $options['install'] && ! $options['dry_run']) {
            fwrite(STDOUT, $available ? 'Update available.' . PHP_EOL : 'Already current.' . PHP_EOL);

            return 0;
        }

        $comparison = version_compare($version, Version::VERSION);

        if ($comparison === 0 && $options['install'] && $options['tag'] === null) {
            fwrite(STDOUT, 'No newer stable release to install.' . PHP_EOL);

            return 0;
        }

        self::assertVersionTransition(
            Version::VERSION,
            $version,
            $options['allow_downgrade'],
            $options['reinstall']
        );

        return $this->install(
            $release,
            $version,
            $options['dry_run'],
            $options['keep_backups']
        );
    }

    public static function isStableTag(string $tag): bool
    {
        return preg_match('/^v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/', $tag) === 1;
    }

    public static function versionFromTag(string $tag): string
    {
        if (! self::isStableTag($tag)) {
            throw new RuntimeException('Release tag is not stable semantic versioning: ' . $tag);
        }

        return substr($tag, 1);
    }

    public static function assertVersionTransition(
        string $installedVersion,
        string $targetVersion,
        bool $allowDowngrade,
        bool $reinstall
    ): void {
        $comparison = version_compare($targetVersion, $installedVersion);

        if ($comparison < 0 && ! $allowDowngrade) {
            throw new RuntimeException('Downgrade refused. Use --allow-downgrade with an explicit --tag.');
        }

        if ($comparison === 0 && ! $reinstall) {
            throw new RuntimeException('Reinstall refused. Use --reinstall with an explicit --tag.');
        }

        if ($comparison > 0 && $reinstall) {
            throw new RuntimeException('--reinstall is only valid for the currently installed version.');
        }

        if ($comparison >= 0 && $allowDowngrade) {
            throw new RuntimeException('--allow-downgrade is only valid for an older version.');
        }
    }

    public static function archiveName(string $version): string
    {
        if (! self::isStableTag('v' . $version)) {
            throw new RuntimeException('Invalid release version: ' . $version);
        }

        return 'IdfDashboard-v' . $version . '.zip';
    }

    public static function checksumFor(string $contents, string $archiveName): string
    {
        $lines = array_values(array_filter(
            preg_split('/\R/', $contents) ?: [],
            fn (string $line): bool => trim($line) !== ''
        ));

        if (count($lines) === 1
            && preg_match('/^([a-f0-9]{64})\s+\*?(.+)$/i', trim($lines[0]), $matches)
            && hash_equals($archiveName, trim($matches[2]))
        ) {
            return strtolower($matches[1]);
        }

        throw new RuntimeException('SHA256SUMS must contain exactly the expected archive.');
    }

    public static function assertArchiveChecksum(string $expectedHash, string|false $actualHash): void
    {
        if (! is_string($actualHash)
            || preg_match('/^[a-f0-9]{64}$/i', $expectedHash) !== 1
            || ! hash_equals(strtolower($expectedHash), strtolower($actualHash))
        ) {
            throw new RuntimeException('Release archive SHA-256 verification failed.');
        }
    }

    public static function isSafeArchivePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            return false;
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            return false;
        }

        $trimmed = trim($path, '/');

        if ($trimmed === '') {
            return false;
        }

        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        $top = explode('/', $trimmed)[0];

        return ! in_array($top, [
            '.git',
            '.github',
            '.claude',
            '.env',
            '.gitattributes',
            '.gitignore',
            'AUDIT_NOTES.md',
            'backups',
            'config.local.php',
            'storage',
            'tests',
        ], true);
    }

    /**
     * Rejects ZIP bombs before extraction. The release package has a
     * deliberately small allowlist, so neither a large entry count nor
     * tens of megabytes of uncompressed data is legitimate.
     *
     * @param  array<int, array{name: string, size: int}>  $entries
     */
    public static function validateArchiveEntryMetadata(array $entries): void
    {
        if (count($entries) < 1 || count($entries) > self::MAX_ARCHIVE_ENTRIES) {
            throw new RuntimeException('Release archive entry count exceeds the safety limit.');
        }

        $totalBytes = 0;

        foreach ($entries as $entry) {
            $name = $entry['name'] ?? null;
            $size = $entry['size'] ?? null;

            if (! is_string($name) || ! is_int($size) || $size < 0 || ! self::isSafeArchivePath($name)) {
                throw new RuntimeException('Release archive contains invalid entry metadata.');
            }

            if ($size > self::MAX_EXTRACTED_BYTES - $totalBytes) {
                throw new RuntimeException('Release archive uncompressed size exceeds the safety limit.');
            }

            $totalBytes += $size;
        }
    }

    public static function hasSufficientStagingSpace(int|float $freeBytes, int $packageBytes): bool
    {
        return $freeBytes >= 0
            && $packageBytes >= 0
            && $freeBytes >= $packageBytes + self::MIN_STAGING_HEADROOM_BYTES;
    }

    public static function isTrustedDownloadUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && self::isTrustedGithubHost((string) ($parts['host'] ?? ''));
    }

    public static function backupsToPrune(array $names, int $keep, ?string $protected = null): array
    {
        if ($keep < 1 || $keep > 50) {
            throw new RuntimeException('Backup retention must be between 1 and 50.');
        }

        $backups = array_values(array_filter(
            $names,
            fn (mixed $name): bool => is_string($name)
                && preg_match('/^IdfDashboard\.backup-\d{8}-\d{6}-v\d+\.\d+\.\d+$/', $name) === 1
        ));
        rsort($backups, SORT_STRING);

        return array_values(array_filter(
            array_slice($backups, $keep),
            fn (string $name): bool => $protected === null || ! hash_equals($protected, $name)
        ));
    }

    public function validateReleasePackage(string $root, string $expectedVersion): void
    {
        $resolved = realpath($root);

        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException('Unable to resolve the release package directory.');
        }

        $this->validatePackage($resolved, $expectedVersion);
        $this->lintPhp($resolved);
    }

    /**
     * Select an exact, closed package manifest from the target version.
     * The downloaded archive's SHA-256 is verified before extraction; this
     * manifest checksum additionally makes the selected file set explicit.
     *
     * @return array{name: string, minimum: string, maximum_exclusive: string, required: array<int, string>, optional: array<int, string>, checksum: string}
     */
    public static function packageProfileForVersion(string $version): array
    {
        if (preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/', $version) !== 1) {
            throw new RuntimeException('Target version does not select a known package profile.');
        }

        if (version_compare($version, '1.0.0', '>=') && version_compare($version, '1.1.0', '<')) {
            $name = 'legacy-v1';
            $minimum = '1.0.0';
            $maximum = '1.1.0';
            $required = self::LEGACY_V1_PATHS;
        } elseif (version_compare($version, '1.1.0', '>=') && version_compare($version, '2.0.0', '<')) {
            $name = 'phase1-v1';
            $minimum = '1.1.0';
            $maximum = '2.0.0';
            $required = self::PHASE1_V1_PATHS;
        } else {
            throw new RuntimeException('Target version does not select a known package profile.');
        }

        $optional = [];

        return [
            'name' => $name,
            'minimum' => $minimum,
            'maximum_exclusive' => $maximum,
            'required' => $required,
            'optional' => $optional,
            'checksum' => hash('sha256', implode("\n", $required)),
        ];
    }

    private function parseOptions(array $arguments): array
    {
        $options = [
            'install' => false,
            'dry_run' => false,
            'tag' => null,
            'self_test' => false,
            'help' => false,
            'recover' => false,
            'allow_downgrade' => false,
            'reinstall' => false,
            'keep_backups' => 5,
            'backup_dir' => null,
            'librenms_root' => null,
        ];

        $explicitCheck = false;
        $explicitKeepBackups = false;

        foreach (array_slice($arguments, 1) as $argument) {
            if ($argument === '--check') {
                $explicitCheck = true;
                continue;
            }

            if ($argument === '--install') {
                $options['install'] = true;
                continue;
            }

            if ($argument === '--dry-run') {
                $options['dry_run'] = true;
                continue;
            }

            if ($argument === '--self-test') {
                $options['self_test'] = true;
                continue;
            }

            if ($argument === '--recover') {
                $options['recover'] = true;
                continue;
            }

            if ($argument === '--help' || $argument === '-h') {
                $options['help'] = true;
                continue;
            }

            if ($argument === '--allow-downgrade') {
                $options['allow_downgrade'] = true;
                continue;
            }

            if ($argument === '--reinstall') {
                $options['reinstall'] = true;
                continue;
            }

            if (str_starts_with($argument, '--keep-backups=')) {
                $explicitKeepBackups = true;
                $value = substr($argument, 15);

                if (! ctype_digit($value) || (int) $value < 1 || (int) $value > 50) {
                    throw new RuntimeException('--keep-backups must be between 1 and 50.');
                }

                $options['keep_backups'] = (int) $value;
                continue;
            }

            if (str_starts_with($argument, '--backup-dir=')) {
                $path = substr($argument, 13);

                if ($path === '') {
                    throw new RuntimeException('--backup-dir requires an absolute path.');
                }

                $options['backup_dir'] = $path;
                continue;
            }

            if (str_starts_with($argument, '--librenms-root=')) {
                $path = substr($argument, 16);

                if ($path === '') {
                    throw new RuntimeException('--librenms-root requires an absolute path.');
                }

                $options['librenms_root'] = $path;
                continue;
            }

            if (str_starts_with($argument, '--tag=')) {
                $tag = substr($argument, 6);

                if (! self::isStableTag($tag)) {
                    throw new RuntimeException('Invalid --tag value. Expected vMAJOR.MINOR.PATCH.');
                }

                $options['tag'] = $tag;
                continue;
            }

            throw new RuntimeException('Unknown argument: ' . $argument);
        }

        if ($options['install'] && $options['dry_run']) {
            throw new RuntimeException('Choose either --install or --dry-run.');
        }

        if ($explicitCheck && ($options['install'] || $options['dry_run'])) {
            throw new RuntimeException('--check cannot be combined with --install or --dry-run.');
        }

        if (($options['allow_downgrade'] || $options['reinstall']) && $options['tag'] === null) {
            throw new RuntimeException('--allow-downgrade and --reinstall require an explicit --tag.');
        }

        if (($options['allow_downgrade'] || $options['reinstall'])
            && ! $options['install']
            && ! $options['dry_run']
        ) {
            throw new RuntimeException('Version override flags require --install or --dry-run.');
        }

        if ($options['recover'] && (
            $options['install']
            || $options['dry_run']
            || $options['tag'] !== null
            || $options['allow_downgrade']
            || $options['reinstall']
            || $options['backup_dir'] !== null
            || $explicitKeepBackups
            || $explicitCheck
            || $options['self_test']
        )) {
            throw new RuntimeException('--recover cannot be combined with update, version, backup or test options.');
        }

        if ($options['recover'] && $options['librenms_root'] === null) {
            throw new RuntimeException('--recover requires the exact --librenms-root printed before activation.');
        }

        if (! $options['recover'] && $options['librenms_root'] !== null) {
            throw new RuntimeException('--librenms-root is only valid with --recover.');
        }

        return $options;
    }

    private function printHelp(): void
    {
        fwrite(STDOUT, <<<'HELP'
IdfDashboard stable release updater

Usage:
  php bin/update.php --check [--tag=vMAJOR.MINOR.PATCH]
  php bin/update.php --dry-run [--tag=vMAJOR.MINOR.PATCH]
  php bin/update.php --install [--tag=vMAJOR.MINOR.PATCH] [--keep-backups=5]
      [--backup-dir=/opt/librenms/plugin-backups/IdfDashboard]
  php /opt/librenms/plugin-backups/IdfDashboard/IdfDashboard.backup-*/bin/update.php \
      --recover --librenms-root=/opt/librenms
  php bin/update.php --self-test

Safety overrides (an explicit --tag is required):
  --allow-downgrade   Permit installing or validating an older version.
  --reinstall         Permit reinstalling or validating the installed version.

The command only consumes stable GitHub release assets. --dry-run downloads,
checks SHA-256, extracts, validates structure and lints PHP without activation.
Install mode must run directly as the LibreNMS operating-system user. Backups,
staging, rollback evidence, the lock and audit log stay outside app/Plugins.
If an interrupted activation leaves app/Plugins/IdfDashboard absent, run
--recover from the exact external backup directory and with the LibreNMS root
printed before activation.
HELP);
        fwrite(STDOUT, PHP_EOL);
    }

    private function fetchRelease(?string $tag): array
    {
        $url = $tag === null
            ? self::API_ROOT . '/releases?per_page=20'
            : self::API_ROOT . '/releases/tags/' . rawurlencode($tag);

        $payload = $this->requestJson($url);

        if ($tag !== null) {
            $this->validateRelease($payload, $tag);

            return $payload;
        }

        if (! array_is_list($payload)) {
            throw new RuntimeException('GitHub releases response is not a list.');
        }

        $stable = [];

        foreach ($payload as $release) {
            if (! is_array($release)) {
                continue;
            }

            try {
                $this->validateRelease($release);
                $stable[] = $release;
            } catch (RuntimeException) {
                continue;
            }
        }

        usort(
            $stable,
            fn (array $left, array $right): int => version_compare(
                self::versionFromTag((string) $right['tag_name']),
                self::versionFromTag((string) $left['tag_name'])
            )
        );

        if ($stable === []) {
            throw new RuntimeException('No stable semantic release was found.');
        }

        return $stable[0];
    }

    private function validateRelease(array $release, ?string $expectedTag = null): void
    {
        $tag = (string) ($release['tag_name'] ?? '');

        if (! self::isStableTag($tag)
            || ($release['draft'] ?? true)
            || ($release['prerelease'] ?? true)
            || ($expectedTag !== null && ! hash_equals($expectedTag, $tag))
        ) {
            throw new RuntimeException('Release is not an expected stable semantic release.');
        }

        $expectedReleaseUrl = 'https://github.com/' . Version::REPOSITORY . '/releases/tag/' . $tag;

        if (! hash_equals($expectedReleaseUrl, (string) ($release['html_url'] ?? ''))) {
            throw new RuntimeException('Release repository or owner validation failed.');
        }

        if (! isset($release['assets']) || ! is_array($release['assets'])) {
            throw new RuntimeException('Release assets are missing.');
        }
    }

    private function install(
        array $release,
        string $version,
        bool $dryRun,
        int $keepBackups
    ): int
    {
        $this->assertExecutionUser($dryRun);
        $this->initializeStorage();
        $lock = $this->acquireLock();
        $tempDirectory = null;

        try {
            if (! $dryRun) {
                $this->migrateLegacyPluginDirectories();
                $this->assertPluginTreeClean();
            }

            $tempDirectory = $this->createTempDirectory();
            $this->assertInstallPermissions($dryRun);
            $operation = $dryRun ? 'dry_run' : 'update';
            $this->audit($operation . '_started', Version::VERSION, $version, 'Stable CLI operation started.');

            $archiveName = self::archiveName($version);
            $archiveAsset = $this->releaseAsset($release, $archiveName);
            $checksumAsset = $this->releaseAsset($release, 'SHA256SUMS');
            $archivePath = $tempDirectory . DIRECTORY_SEPARATOR . $archiveName;
            $checksumPath = $tempDirectory . DIRECTORY_SEPARATOR . 'SHA256SUMS';

            $this->downloadAsset($checksumAsset, $checksumPath, self::MAX_CHECKSUM_BYTES);
            $this->downloadAsset($archiveAsset, $archivePath, self::MAX_ARCHIVE_BYTES);

            $expectedHash = self::checksumFor(
                (string) file_get_contents($checksumPath),
                $archiveName
            );
            $actualHash = hash_file('sha256', $archivePath);

            self::assertArchiveChecksum($expectedHash, $actualHash);

            $extracted = $tempDirectory . DIRECTORY_SEPARATOR . 'extracted';
            $this->extractArchive($archivePath, $extracted);
            $this->validatePackage($extracted, $version);
            $this->lintPhp($extracted);

            if ($dryRun) {
                $this->audit('dry_run_succeeded', Version::VERSION, $version, 'Package validation completed without activation.');
                fwrite(STDOUT, 'Dry run successful for v' . $version . '; no files were activated.' . PHP_EOL);

                return 0;
            }

            $backup = $this->activateAtomically($extracted, $version);
            $this->audit('update_succeeded', Version::VERSION, $version, 'Backup: ' . $backup);
            fwrite(STDOUT, 'Updated successfully to v' . $version . PHP_EOL);
            fwrite(STDOUT, 'Backup retained at: ' . $backup . PHP_EOL);

            try {
                $this->pruneBackups($keepBackups, $backup);
            } catch (Throwable $exception) {
                $this->audit('backup_retention_failed', Version::VERSION, $version, $exception->getMessage());
                fwrite(STDERR, 'Warning: backup retention failed: ' . $exception->getMessage() . PHP_EOL);
            }

            return 0;
        } catch (Throwable $exception) {
            $this->audit(($dryRun ? 'dry_run' : 'update') . '_failed', Version::VERSION, $version, $exception->getMessage());
            throw $exception;
        } finally {
            try {
                if ($tempDirectory !== null) {
                    $this->removeTree($tempDirectory, dirname($tempDirectory));
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function releaseAsset(array $release, string $name): array
    {
        $matches = array_values(array_filter(
            $release['assets'],
            fn (mixed $asset): bool => is_array($asset)
                && hash_equals($name, (string) ($asset['name'] ?? ''))
        ));

        if (count($matches) !== 1) {
            throw new RuntimeException('Required release asset must exist exactly once: ' . $name);
        }

        $asset = $matches[0];
        $url = (string) ($asset['browser_download_url'] ?? '');
        $tag = (string) $release['tag_name'];
        $expected = 'https://github.com/' . Version::REPOSITORY
            . '/releases/download/' . $tag . '/' . rawurlencode($name);

        if (! hash_equals($expected, $url)) {
            throw new RuntimeException('Unexpected release asset URL for ' . $name);
        }

        return $asset;
    }

    private function requestJson(string $url): array
    {
        $temp = tempnam(sys_get_temp_dir(), 'idf-api-');

        if ($temp === false) {
            throw new RuntimeException('Unable to allocate an API response file.');
        }

        try {
            $this->download($url, $temp, self::MAX_API_BYTES);
            $decoded = json_decode((string) file_get_contents($temp), true, 64, JSON_THROW_ON_ERROR);

            if (! is_array($decoded)) {
                throw new RuntimeException('GitHub API returned invalid JSON.');
            }

            return $decoded;
        } finally {
            @unlink($temp);
        }
    }

    private function downloadAsset(array $asset, string $destination, int $maxBytes): void
    {
        $declaredSize = (int) ($asset['size'] ?? 0);

        if ($declaredSize < 1 || $declaredSize > $maxBytes) {
            throw new RuntimeException('Release asset size is invalid or exceeds the safety limit.');
        }

        $this->download((string) $asset['browser_download_url'], $destination, $maxBytes);

        if (filesize($destination) !== $declaredSize) {
            @unlink($destination);
            throw new RuntimeException('Downloaded asset size does not match GitHub release metadata.');
        }
    }

    private function download(string $url, string $destination, int $maxBytes): void
    {
        if (! extension_loaded('curl')) {
            throw new RuntimeException('The PHP curl extension is required.');
        }

        $currentUrl = $url;

        for ($hop = 0; $hop <= 5; $hop++) {
            self::assertTrustedDownloadUrl($currentUrl);
            $redirect = null;
            $handle = fopen($destination, 'wb');

            if ($handle === false) {
                throw new RuntimeException('Unable to create download destination.');
            }

            $curl = curl_init($currentUrl);

            if ($curl === false) {
                fclose($handle);
                throw new RuntimeException('Unable to initialize curl.');
            }

            curl_setopt_array($curl, [
                CURLOPT_FILE => $handle,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FAILONERROR => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => 'IdfDashboard/' . Version::VERSION,
                CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
                CURLOPT_NOPROGRESS => false,
                CURLOPT_XFERINFOFUNCTION => static function ($curl, $total, $downloaded) use ($maxBytes): int {
                    return ($total > $maxBytes || $downloaded > $maxBytes) ? 1 : 0;
                },
                CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$redirect): int {
                    if (preg_match('/^Location:\s*(\S.*?)\s*$/i', trim($header), $matches)) {
                        $redirect = $matches[1];
                    }

                    return strlen($header);
                },
            ]);

            $success = curl_exec($curl);
            $error = curl_error($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            fclose($handle);

            if ($status >= 300 && $status < 400) {
                @unlink($destination);

                if (! is_string($redirect) || $redirect === '') {
                    throw new RuntimeException('GitHub redirect did not provide a destination.');
                }

                $currentUrl = self::resolveRedirectUrl($currentUrl, $redirect);
                continue;
            }

            $size = is_file($destination) ? filesize($destination) : false;

            if ($success !== true || $status < 200 || $status >= 300) {
                @unlink($destination);
                throw new RuntimeException('HTTPS download failed: ' . ($error !== '' ? $error : 'HTTP ' . $status));
            }

            if (! is_int($size) || $size < 1 || $size > $maxBytes) {
                @unlink($destination);
                throw new RuntimeException('Downloaded file size is invalid or exceeds the safety limit.');
            }

            return;
        }

        @unlink($destination);
        throw new RuntimeException('GitHub download exceeded the redirect limit.');
    }

    private function extractArchive(string $archive, string $destination): void
    {
        if (! extension_loaded('zip') || ! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required.');
        }

        if (! mkdir($destination, 0700, true) && ! is_dir($destination)) {
            throw new RuntimeException('Unable to create extraction directory.');
        }

        $zip = new ZipArchive();

        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Release archive is not a valid ZIP file.');
        }

        try {
            $entries = [];

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                $stat = $zip->statIndex($index);

                if (! is_string($name) || ! is_array($stat) || ! isset($stat['size']) || ! is_int($stat['size'])) {
                    throw new RuntimeException('Unable to read release archive entry metadata.');
                }

                $entries[] = ['name' => $name, 'size' => $stat['size']];

                if (method_exists($zip, 'getExternalAttributesIndex')) {
                    $operations = 0;
                    $attributes = 0;

                    if ($zip->getExternalAttributesIndex($index, $operations, $attributes)) {
                        $type = ($attributes >> 16) & 0170000;

                        if ($type === 0120000) {
                            throw new RuntimeException('Symbolic links are not allowed in releases.');
                        }
                    }
                }
            }

            self::validateArchiveEntryMetadata($entries);

            if (! $zip->extractTo($destination)) {
                throw new RuntimeException('Unable to extract the release archive.');
            }
        } finally {
            $zip->close();
        }
    }

    private static function isTrustedGithubHost(string $host): bool
    {
        $host = strtolower($host);

        return in_array($host, ['github.com', 'api.github.com'], true)
            || str_ends_with($host, '.githubusercontent.com');
    }

    private static function assertTrustedDownloadUrl(string $url): void
    {
        if (! self::isTrustedDownloadUrl($url)) {
            throw new RuntimeException('Only trusted GitHub HTTPS downloads are permitted.');
        }
    }

    private static function resolveRedirectUrl(string $currentUrl, string $location): string
    {
        if (str_starts_with($location, 'https://')) {
            self::assertTrustedDownloadUrl($location);

            return $location;
        }

        if (! str_starts_with($location, '/')) {
            throw new RuntimeException('Relative GitHub redirect format is not permitted.');
        }

        $current = parse_url($currentUrl);

        if (! is_array($current) || ! isset($current['host'])) {
            throw new RuntimeException('Unable to resolve GitHub redirect.');
        }

        $resolved = 'https://' . $current['host'] . $location;
        self::assertTrustedDownloadUrl($resolved);

        return $resolved;
    }

    private function validatePackage(string $root, string $expectedVersion): void
    {
        $profile = self::packageProfileForVersion($expectedVersion);

        foreach ($profile['required'] as $path) {
            if (! is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path))) {
                throw new RuntimeException('Release package is missing required file: ' . $path);
            }
        }

        $allowedFiles = array_merge($profile['required'], $profile['optional']);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $entry) {
            $relative = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($entry->getPathname(), strlen($root) + 1)
            );

            if ($entry->isLink()) {
                throw new RuntimeException('Symbolic links are not allowed in release packages.');
            }

            if ($entry->isFile() && ! in_array($relative, $allowedFiles, true)) {
                throw new RuntimeException('Unexpected release file: ' . $relative);
            }

            if ($entry->isDir() && ! in_array($relative, self::ALLOWED_DIRECTORIES, true)) {
                throw new RuntimeException('Unexpected release directory: ' . $relative);
            }
        }

        $versionFile = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'Version.php');

        if (! preg_match("/public const VERSION\s*=\s*'([^']+)'/", $versionFile, $matches)
            || ! hash_equals($expectedVersion, $matches[1])
        ) {
            throw new RuntimeException('Packaged version does not match the release tag.');
        }
    }

    private function lintPhp(string $root): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()
                || $file->isLink()
                || $file->getExtension() !== 'php'
                || str_ends_with($file->getFilename(), '.blade.php')
            ) {
                continue;
            }

            [$status, $output] = $this->runCommand([PHP_BINARY, '-l', $file->getPathname()], null, 20);

            if ($status !== 0) {
                throw new RuntimeException('PHP lint failed for ' . $file->getFilename() . ': ' . trim($output));
            }
        }
    }

    private function activateAtomically(string $extracted, string $version): string
    {
        $staging = $this->storageRoot . DIRECTORY_SEPARATOR
            . '.IdfDashboard.staging-' . bin2hex(random_bytes(6));
        $backup = $this->storageRoot . DIRECTORY_SEPARATOR
            . 'IdfDashboard.backup-' . gmdate('Ymd-His') . '-v' . Version::VERSION;

        if (file_exists($staging) || file_exists($backup)) {
            throw new RuntimeException('External staging or backup path already exists.');
        }

        try {
            $this->validatePackage($this->pluginRoot, Version::VERSION);
            $this->lintPhp($this->pluginRoot);
            $this->assertStagingCapacity($extracted);
            $this->copyTree($extracted, $staging);
            $this->applyMetadata($staging);
            $this->validatePackage($staging, $version);
            $this->lintPhp($staging);
            $this->assertSameFilesystem($staging);
            $this->assertPluginTreeClean();
        } catch (Throwable $exception) {
            $this->removeTree($staging, $this->storageRoot);
            throw $exception;
        }

        $recoveryCommand = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($backup . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'update.php')
            . ' --recover --librenms-root=' . escapeshellarg($this->libreNmsRoot);
        $this->audit('activation_prepared', Version::VERSION, $version, 'Interruption recovery: ' . $recoveryCommand);
        fwrite(STDOUT, 'Interruption recovery command: ' . $recoveryCommand . PHP_EOL);

        if (! rename($this->pluginRoot, $backup)) {
            $this->removeTree($staging, $this->storageRoot);
            throw new RuntimeException('Unable to move the active plugin to the validated external backup directory.');
        }

        try {
            $this->validatePackage($backup, Version::VERSION);
        } catch (Throwable $exception) {
            $restored = @rename($backup, $this->pluginRoot);
            $this->removeTree($staging, $this->storageRoot);

            if (! $restored) {
                throw new RuntimeException('External backup verification failed and the original plugin could not be restored: ' . $backup);
            }

            throw new RuntimeException('External backup verification failed; original plugin restored: ' . $exception->getMessage());
        }

        if (! rename($staging, $this->pluginRoot)) {
            $restored = @rename($backup, $this->pluginRoot);

            if (! $restored) {
                throw new RuntimeException('Unable to activate or restore the plugin. Manual recovery required: ' . $backup);
            }

            throw new RuntimeException('Unable to activate external staging; original plugin restored.');
        }

        try {
            $this->validateActiveInstallation($version);
        } catch (Throwable $exception) {
            $failed = $this->storageRoot . DIRECTORY_SEPARATOR
                . 'IdfDashboard.failed-' . gmdate('Ymd-His') . '-v' . $version;

            if (file_exists($failed) || ! rename($this->pluginRoot, $failed)) {
                $this->audit('rollback_failed', Version::VERSION, $version, $exception->getMessage());
                throw new RuntimeException('Activation failed and the failed package could not be moved outside app/Plugins. Manual recovery required: ' . $backup);
            }

            if (! rename($backup, $this->pluginRoot)) {
                $this->audit('rollback_failed', Version::VERSION, $version, $exception->getMessage());
                throw new RuntimeException('Activation failed and automatic rollback failed. Manual recovery required: ' . $backup);
            }

            try {
                $this->validateActiveInstallation(Version::VERSION);
            } catch (Throwable $rollbackException) {
                $this->audit('rollback_failed', $version, Version::VERSION, $rollbackException->getMessage());
                throw new RuntimeException(
                    'Original plugin was restored but rollback validation failed: ' . $rollbackException->getMessage()
                    . '. Failed package retained at: ' . $failed
                );
            }

            $this->audit('rollback_succeeded', $version, Version::VERSION, 'Failed package: ' . $failed . '; ' . $exception->getMessage());
            throw new RuntimeException(
                'Activation validation failed; automatic rollback succeeded. Failed package retained at: '
                . $failed . '. Cause: ' . $exception->getMessage()
            );
        }

        return $backup;
    }

    private function recoverActiveInstallation(): int
    {
        if (preg_match('/^IdfDashboard\.backup-\d{8}-\d{6}-v\d+\.\d+\.\d+$/', basename($this->pluginRoot)) !== 1) {
            throw new RuntimeException('--recover must be executed from an exact external IdfDashboard backup directory.');
        }

        $this->assertExecutionUser(false);
        $this->initializeStorage();
        $lock = $this->acquireLock();

        try {
            $backupParent = realpath(dirname($this->pluginRoot));
            $storage = realpath($this->storageRoot);
            $plugins = realpath($this->libreNmsRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Plugins');

            if ($backupParent === false || $storage === false || $backupParent !== $storage) {
                throw new RuntimeException('Recovery source is not inside the configured external backup directory.');
            }

            if ($plugins === false || is_link($plugins) || ! is_writable($plugins)) {
                throw new RuntimeException('LibreNMS app/Plugins is missing, unsafe or not writable.');
            }

            $active = $plugins . DIRECTORY_SEPARATOR . 'IdfDashboard';

            if (file_exists($active) || is_link($active)) {
                throw new RuntimeException('Recovery refused because app/Plugins/IdfDashboard already exists.');
            }

            $this->assertNoAlternatePluginDirectories($plugins);
            $backupStat = @stat($this->pluginRoot);
            $pluginsStat = @stat($plugins);

            if (! is_array($backupStat) || ! is_array($pluginsStat) || $backupStat['dev'] !== $pluginsStat['dev']) {
                throw new RuntimeException('Recovery backup must be on the same filesystem as app/Plugins.');
            }

            $this->validatePackage($this->pluginRoot, Version::VERSION);
            $this->lintPhp($this->pluginRoot);
            [$status, $output] = $this->runCommand(
                [PHP_BINARY, $this->pluginRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'update.php', '--self-test'],
                $this->libreNmsRoot,
                60
            );

            if ($status !== 0) {
                throw new RuntimeException('Recovery backup updater self-test failed: ' . trim($output));
            }

            $source = $this->pluginRoot;

            if (! rename($source, $active)) {
                throw new RuntimeException('Unable to restore the external backup into app/Plugins/IdfDashboard.');
            }

            $this->pluginRoot = $active;
            $this->parentDirectory = $plugins;
            $this->assertPluginTreeClean();

            try {
                $this->clearLibreNmsViewCache();
            } catch (Throwable $exception) {
                $this->audit('recovery_cache_clear_failed', Version::VERSION, Version::VERSION, $exception->getMessage());
                throw new RuntimeException(
                    'Backup restored and active, but LibreNMS view cache clear failed: ' . $exception->getMessage()
                );
            }

            $this->audit('recovery_succeeded', Version::VERSION, Version::VERSION, 'Restored from: ' . $source);
            fwrite(STDOUT, 'Recovered IdfDashboard v' . Version::VERSION . ' from external backup.' . PHP_EOL);

            return 0;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function configureRecoveryRoot(string $path): void
    {
        if (str_contains($path, "\0") || ! self::isAbsoluteFilesystemPath($path)) {
            throw new RuntimeException('--librenms-root must be an unambiguous absolute path.');
        }

        $resolved = realpath($path);

        if ($resolved === false || ! is_dir($resolved) || is_link($resolved)) {
            throw new RuntimeException('--librenms-root must resolve to a safe LibreNMS directory.');
        }

        $plugins = $resolved . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Plugins';

        if (! is_dir($plugins) || is_link($plugins)) {
            throw new RuntimeException('--librenms-root does not contain a safe app/Plugins directory.');
        }

        $this->libreNmsRoot = $resolved;
        $this->setStorageRoot(dirname($this->pluginRoot));
    }

    private function assertStagingCapacity(string $source): void
    {
        $packageBytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException('Symbolic links are not allowed while sizing update staging.');
            }

            if ($entry->isFile()) {
                $packageBytes += $entry->getSize();
            }
        }

        $freeBytes = disk_free_space($this->storageRoot);

        if ($freeBytes === false || ! self::hasSufficientStagingSpace($freeBytes, $packageBytes)) {
            throw new RuntimeException(
                'Insufficient free space for external staging; at least the package size plus 5 MiB is required.'
            );
        }
    }

    private function copyTree(string $source, string $destination): void
    {
        if (is_link($source)) {
            throw new RuntimeException('Symbolic links are not allowed while preparing an update.');
        }

        if (is_file($source)) {
            if (! copy($source, $destination)) {
                throw new RuntimeException('Unable to copy update file.');
            }

            return;
        }

        if (! mkdir($destination, 0700, true) && ! is_dir($destination)) {
            throw new RuntimeException('Unable to create prepared update directory.');
        }

        foreach (new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS) as $entry) {
            $this->copyTree(
                $entry->getPathname(),
                $destination . DIRECTORY_SEPARATOR . $entry->getFilename()
            );
        }
    }

    private function applyMetadata(string $root): void
    {
        $owner = fileowner($this->pluginRoot);
        $group = filegroup($this->pluginRoot);
        $directoryMode = fileperms($this->pluginRoot) & 0777;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $this->applyPathMetadata($root, $directoryMode, $owner, $group);

        foreach ($iterator as $entry) {
            $relative = substr($entry->getPathname(), strlen($root) + 1);
            $installedPath = $this->pluginRoot . DIRECTORY_SEPARATOR . $relative;
            $mode = file_exists($installedPath)
                ? fileperms($installedPath) & 0777
                : ($entry->isDir() ? $directoryMode : 0640);
            $this->applyPathMetadata($entry->getPathname(), $mode, $owner, $group);
        }
    }

    private function applyPathMetadata(string $path, int $mode, int|false $owner, int|false $group): void
    {
        if (! chmod($path, $mode)) {
            throw new RuntimeException('Unable to preserve plugin permissions on external staging.');
        }

        if (! function_exists('posix_geteuid')) {
            return;
        }

        if ($owner !== false && fileowner($path) !== $owner && ! @chown($path, $owner)) {
            throw new RuntimeException('Unable to preserve plugin owner on external staging.');
        }

        if ($group !== false && filegroup($path) !== $group && ! @chgrp($path, $group)) {
            throw new RuntimeException('Unable to preserve plugin group on external staging.');
        }
    }

    private function validateActiveInstallation(string $version): void
    {
        $this->assertPluginTreeClean();
        $this->validatePackage($this->pluginRoot, $version);
        $this->lintPhp($this->pluginRoot);

        [$status, $output] = $this->runCommand(
            [PHP_BINARY, $this->pluginRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'update.php', '--self-test'],
            $this->libreNmsRoot,
            60
        );

        if ($status !== 0) {
            throw new RuntimeException('Installed updater self-test failed: ' . trim($output));
        }

        $this->assertPluginTreeClean();
        $this->clearLibreNmsViewCache();
        $this->assertPluginTreeClean();
    }

    private function clearLibreNmsViewCache(): void
    {
        $this->assertPluginTreeClean();
        $artisan = $this->libreNmsRoot . DIRECTORY_SEPARATOR . 'artisan';

        if (! is_file($artisan)) {
            throw new RuntimeException('LibreNMS artisan executable was not found; cannot safely clear compiled views.');
        }

        [$status, $output] = $this->runCommand(
            [PHP_BINARY, $artisan, 'view:clear', '--no-interaction'],
            $this->libreNmsRoot,
            60
        );

        if ($status !== 0) {
            throw new RuntimeException('LibreNMS view cache clear failed: ' . trim($output));
        }
    }

    private function runCommand(array $command, ?string $cwd, int $timeoutSeconds): array
    {
        $pipes = [];
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            null,
            ['bypass_shell' => true]
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start validation process.');
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $output = '';
        $started = microtime(true);

        while (true) {
            $status = proc_get_status($process);

            foreach ($pipes as $pipe) {
                $output .= stream_get_contents($pipe) ?: '';
            }

            if (! $status['running']) {
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }

                $exitCode = proc_close($process);

                return [$exitCode === -1 ? (int) $status['exitcode'] : $exitCode, $output];
            }

            if ((microtime(true) - $started) > $timeoutSeconds) {
                proc_terminate($process, 9);

                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }

                proc_close($process);
                throw new RuntimeException('Validation process timed out.');
            }

            usleep(50000);
        }
    }

    private function setStorageRoot(string $path): void
    {
        if (str_contains($path, "\0") || ! self::isAbsoluteFilesystemPath($path)) {
            throw new RuntimeException('Backup directory must be an unambiguous absolute path.');
        }

        $normalized = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        $segments = preg_split('#[\\\\/]#', $normalized) ?: [];

        if ($normalized === ''
            || preg_match('/^[A-Za-z]:$/', $normalized) === 1
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
        ) {
            throw new RuntimeException('Backup directory must not contain dot path segments.');
        }

        $normalized = $this->canonicalizeCandidatePath($normalized);

        $pluginsRoot = $this->libreNmsRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Plugins';

        if ($this->pathIsWithin($normalized, $pluginsRoot)
            || $this->pathIsWithin($normalized, $this->libreNmsRoot . DIRECTORY_SEPARATOR . 'html')
        ) {
            throw new RuntimeException('Backup directory must be outside app/Plugins and the LibreNMS web root.');
        }

        $this->storageRoot = $normalized;
        $this->auditLog = $normalized . DIRECTORY_SEPARATOR . 'IdfDashboard-update.log';
    }

    private function canonicalizeCandidatePath(string $path): string
    {
        $current = $path;
        $suffix = [];

        while (! file_exists($current) && ! is_link($current) && dirname($current) !== $current) {
            array_unshift($suffix, basename($current));
            $current = dirname($current);
        }

        $resolved = realpath($current);

        if ($resolved === false) {
            return $path;
        }

        return $suffix === []
            ? $resolved
            : $resolved . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $suffix);
    }

    private static function isAbsoluteFilesystemPath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function pathIsWithin(string $path, string $parent): bool
    {
        $path = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        $parent = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $parent), DIRECTORY_SEPARATOR);

        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }

        return $path === $parent || str_starts_with($path, $parent . DIRECTORY_SEPARATOR);
    }

    private function initializeStorage(): void
    {
        $this->assertNoSymlinkPathComponents($this->storageRoot);

        if (is_link($this->storageRoot)) {
            throw new RuntimeException('External backup directory must not be a symbolic link.');
        }

        if (! is_dir($this->storageRoot)
            && ! mkdir($this->storageRoot, 0750, true)
            && ! is_dir($this->storageRoot)
        ) {
            throw new RuntimeException('Unable to create external backup directory: ' . $this->storageRoot);
        }

        $resolved = realpath($this->storageRoot);

        if ($resolved === false || is_link($resolved)) {
            throw new RuntimeException('Unable to resolve a safe external backup directory.');
        }

        $this->setStorageRoot($resolved);
        $this->assertNoSymlinkPathComponents($this->storageRoot);

        if (! is_writable($this->storageRoot)) {
            throw new RuntimeException('External backup directory is not writable by the LibreNMS OS user: ' . $this->storageRoot);
        }

        $this->assertSameFilesystem($this->storageRoot);
    }

    private function assertNoSymlinkPathComponents(string $path): void
    {
        $current = $path;

        while ($current !== '' && dirname($current) !== $current) {
            if (is_link($current)) {
                throw new RuntimeException('External backup path must not contain symbolic links: ' . $current);
            }

            $current = dirname($current);
        }
    }

    private function assertSameFilesystem(string $path): void
    {
        $pluginStat = @stat($this->pluginRoot);
        $pathStat = @stat($path);

        if (! is_array($pluginStat) || ! is_array($pathStat) || $pluginStat['dev'] !== $pathStat['dev']) {
            throw new RuntimeException('External backup and staging directory must be on the same filesystem as app/Plugins for atomic rename.');
        }
    }

    private function migrateLegacyPluginDirectories(): void
    {
        $moves = [];

        foreach (new FilesystemIterator($this->parentDirectory, FilesystemIterator::SKIP_DOTS) as $entry) {
            $name = $entry->getFilename();

            if ($name === 'IdfDashboard' || ! self::isLegacyPluginDirectoryName($name)) {
                continue;
            }

            if (! $entry->isDir() || $entry->isLink()) {
                throw new RuntimeException('Legacy plugin path is not a safe directory and was not moved: ' . $entry->getPathname());
            }

            $destination = $this->storageRoot . DIRECTORY_SEPARATOR . $name;

            if (file_exists($destination) || is_link($destination)) {
                throw new RuntimeException('Legacy plugin directory destination already exists; nothing was moved: ' . $destination);
            }

            $moves[] = [$entry->getPathname(), $destination];
        }

        foreach ($moves as [$source, $destination]) {
            if (! rename($source, $destination)) {
                throw new RuntimeException('Unable to move legacy plugin directory outside app/Plugins: ' . $source);
            }

            $message = 'Moved legacy plugin directory: ' . $source . ' -> ' . $destination;
            $this->audit('legacy_directory_moved', Version::VERSION, Version::VERSION, $message);
            fwrite(STDOUT, $message . PHP_EOL);
        }
    }

    public static function isLegacyPluginDirectoryName(string $name): bool
    {
        return preg_match('/^\\.?IdfDashboard\\.(?:backup-|old|new|failed|rollback|pending|staging)/', $name) === 1;
    }

    public static function executionUserIsAllowed(int $effectiveUser, int|false $owner, bool $diagnostic): bool
    {
        return $diagnostic || ($owner !== false && $effectiveUser !== 0 && $effectiveUser === $owner);
    }

    public static function lockOpenFailureMessage(?string $warning): string
    {
        $detail = $warning !== null && trim($warning) !== '' ? ': ' . trim($warning) : '';

        return 'Unable to create or open the external update lock' . $detail;
    }

    private function assertPluginTreeClean(): void
    {
        $this->assertNoAlternatePluginDirectories($this->parentDirectory);

        if (! is_dir($this->pluginRoot) || is_link($this->pluginRoot)) {
            throw new RuntimeException('The active IdfDashboard directory is missing or unsafe.');
        }
    }

    private function assertNoAlternatePluginDirectories(string $pluginsDirectory): void
    {
        foreach (new FilesystemIterator($pluginsDirectory, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (! $entry->isDir() && ! $entry->isLink()) {
                continue;
            }

            $name = $entry->getFilename();

            if ($name !== 'IdfDashboard'
                && preg_match('/^\\.?IdfDashboard(?:[.\\-_].*)?$/', $name) === 1
            ) {
                throw new RuntimeException('Unsafe alternate IdfDashboard directory remains inside app/Plugins: ' . $entry->getPathname());
            }
        }
    }

    private function acquireLock()
    {
        $path = $this->storageRoot . DIRECTORY_SEPARATOR . '.IdfDashboard-update.lock';

        if (is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new RuntimeException('IdfDashboard update lock is corrupt or unsafe: ' . $path);
        }

        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $handle = fopen($path, 'c+');
        } finally {
            restore_error_handler();
        }

        if ($handle === false) {
            throw new RuntimeException(self::lockOpenFailureMessage($warning));
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('Another IdfDashboard update is already running.');
        }

        rewind($handle);
        $existing = stream_get_contents($handle);

        if (is_string($existing) && trim($existing) !== '') {
            $metadata = json_decode($existing, true);

            if (! is_array($metadata) || ! isset($metadata['pid'], $metadata['started_at'])) {
                flock($handle, LOCK_UN);
                fclose($handle);
                throw new RuntimeException('IdfDashboard update lock is corrupt; remove it only after confirming no update is active: ' . $path);
            }
        }

        $metadata = json_encode([
            'pid' => getmypid(),
            'started_at' => gmdate(DATE_ATOM),
        ], JSON_UNESCAPED_SLASHES);
        ftruncate($handle, 0);
        rewind($handle);

        if (! is_string($metadata) || fwrite($handle, $metadata . PHP_EOL) === false || ! fflush($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw new RuntimeException('Unable to write metadata to the external update lock.');
        }

        if (! chmod($path, 0640)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw new RuntimeException('Unable to secure permissions on the external update lock.');
        }

        return $handle;
    }

    private function assertExecutionUser(bool $diagnostic): void
    {
        if ($diagnostic || ! function_exists('posix_geteuid')) {
            return;
        }

        $effectiveUser = posix_geteuid();
        $owner = fileowner($this->pluginRoot);

        if (! self::executionUserIsAllowed($effectiveUser, $owner, false)) {
            throw new RuntimeException('Install mode must run as the plugin owner (normally librenms), never as root or a web user.');
        }
    }

    private function assertInstallPermissions(bool $diagnostic): void
    {
        if (! is_readable($this->pluginRoot)) {
            throw new RuntimeException('Plugin directory must be readable by the LibreNMS OS user.');
        }

        if (! $diagnostic && (! is_writable($this->pluginRoot) || ! is_writable($this->parentDirectory))) {
            throw new RuntimeException('Plugin and app/Plugins must be writable by the LibreNMS OS user.');
        }
    }

    private function createTempDirectory(): string
    {
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'idf-dashboard-update-' . bin2hex(random_bytes(8));

        if (! mkdir($path, 0700, true)) {
            throw new RuntimeException('Unable to create temporary update directory.');
        }

        return $path;
    }

    private function pruneBackups(int $keep, string $protectedBackup): void
    {
        $backups = [];

        $this->assertNoSymlinkPathComponents($this->storageRoot);

        if (is_link($this->storageRoot)) {
            throw new RuntimeException('Refusing retention in a symbolic-link backup directory.');
        }

        foreach (new FilesystemIterator($this->storageRoot, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (preg_match(
                    '/^IdfDashboard\.backup-\d{8}-\d{6}-v\d+\.\d+\.\d+$/',
                    $entry->getFilename()
                ) === 1
            ) {
                if (! $entry->isDir() || $entry->isLink()) {
                    throw new RuntimeException('Refusing retention because a backup entry is unsafe: ' . $entry->getPathname());
                }

                $backups[$entry->getFilename()] = $entry->getPathname();
            }
        }

        $protectedName = basename($protectedBackup);

        foreach (self::backupsToPrune(array_keys($backups), $keep, $protectedName) as $name) {
            $backup = $backups[$name];
            $this->assertTreeContainsNoSymlinks($backup);
            $this->removeTree($backup, $this->storageRoot);
            $this->audit('backup_pruned', Version::VERSION, Version::VERSION, 'Removed: ' . basename($backup));
            fwrite(STDOUT, 'Removed expired external backup: ' . $backup . PHP_EOL);
        }
    }

    private function assertTreeContainsNoSymlinks(string $root): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException('Refusing retention because backup contains a symbolic link: ' . $entry->getPathname());
            }
        }
    }

    private function removeTree(string $path, string $expectedParent): void
    {
        if (! file_exists($path)) {
            return;
        }

        $parent = realpath(dirname($path));
        $allowed = realpath($expectedParent);

        if ($parent === false || $allowed === false || $parent !== $allowed || is_link($path)) {
            throw new RuntimeException('Refusing unsafe cleanup path.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            if ($entry->isLink() || $entry->isFile()) {
                if (! unlink($entry->getPathname())) {
                    throw new RuntimeException('Unable to remove cleanup file: ' . $entry->getPathname());
                }
            } else {
                if (! rmdir($entry->getPathname())) {
                    throw new RuntimeException('Unable to remove cleanup directory: ' . $entry->getPathname());
                }
            }
        }

        if (! rmdir($path)) {
            throw new RuntimeException('Unable to remove cleanup root: ' . $path);
        }
    }

    private function audit(string $event, string $from, string $to, string $message): void
    {
        $entry = json_encode([
            'timestamp' => gmdate(DATE_ATOM),
            'event' => $event,
            'from' => $from,
            'to' => $to,
            'message' => substr(str_replace(["\r", "\n"], ' ', $message), 0, 500),
        ], JSON_UNESCAPED_SLASHES);

        if (is_string($entry)) {
            @file_put_contents($this->auditLog, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
            @chmod($this->auditLog, 0640);
        }
    }

    private function selfTest(): int
    {
        $tests = [
            'stable_tag' => self::isStableTag('v1.2.3'),
            'reject_prerelease' => ! self::isStableTag('v1.2.3-beta.1'),
            'reject_branch' => ! self::isStableTag('main'),
            'archive_name' => self::archiveName('1.2.3') === 'IdfDashboard-v1.2.3.zip',
            'checksum' => self::checksumFor(str_repeat('a', 64) . '  IdfDashboard-v1.2.3.zip', 'IdfDashboard-v1.2.3.zip') === str_repeat('a', 64),
            'safe_archive_path' => self::isSafeArchivePath('Support/Version.php'),
            'reject_traversal' => ! self::isSafeArchivePath('../Settings.php'),
            'reject_ambiguous_path' => ! self::isSafeArchivePath('./Settings.php'),
            'reject_ci_directory' => ! self::isSafeArchivePath('.github/workflows/release.yml'),
            'reject_git_metadata' => ! self::isSafeArchivePath('.gitattributes'),
            'trusted_download' => self::isTrustedDownloadUrl('https://api.github.com/repos/devilrob/IdfDashboard/releases'),
            'reject_untrusted_download' => ! self::isTrustedDownloadUrl('https://example.com/payload.zip'),
            'legacy_backup_detected' => self::isLegacyPluginDirectoryName('IdfDashboard.backup-20260804-203726-v1.0.1'),
            'legacy_pending_detected' => self::isLegacyPluginDirectoryName('.IdfDashboard.pending-deadbeef'),
            'active_directory_preserved' => ! self::isLegacyPluginDirectoryName('IdfDashboard'),
            'permission_message' => str_contains(self::lockOpenFailureMessage('Permission denied'), 'Permission denied'),
            'wrong_user_rejected' => ! self::executionUserIsAllowed(1001, 1000, false),
            'diagnostic_user_allowed' => self::executionUserIsAllowed(1001, 1000, true),
            'backup_retention' => self::backupsToPrune([
                'IdfDashboard.backup-20260804-120000-v1.0.0',
                'IdfDashboard.backup-20260803-120000-v0.9.0',
            ], 1) === ['IdfDashboard.backup-20260803-120000-v0.9.0'],
        ];

        foreach ($tests as $name => $passed) {
            fwrite(STDOUT, $name . ': ' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL);
        }

        return in_array(false, $tests, true) ? 1 : 0;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        exit((new IdfDashboardUpdater())->run($argv));
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Update failed: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}
