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

    private const REQUIRED_PATHS = [
        'Menu.php',
        'Page.php',
        'Settings.php',
        'Support/Config.php',
        'Support/ProblemPolicy.php',
        'Support/UpdateStatus.php',
        'Support/Version.php',
        'resources/views/menu.blade.php',
        'resources/views/page.blade.php',
        'resources/views/settings.blade.php',
        'bin/update.php',
    ];

    private const PRESERVED_LOCAL_PATHS = [
        '.env',
        'config.local.php',
        'storage',
    ];

    private string $pluginRoot;

    private string $parentDirectory;

    private string $auditLog;

    public function __construct(?string $pluginRoot = null)
    {
        $resolved = realpath($pluginRoot ?? dirname(__DIR__));

        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException('Unable to resolve the installed plugin directory.');
        }

        $this->pluginRoot = $resolved;
        $this->parentDirectory = dirname($resolved);
        $this->auditLog = $this->parentDirectory . DIRECTORY_SEPARATOR . 'IdfDashboard-update.log';
    }

    public function run(array $arguments): int
    {
        if (PHP_VERSION_ID < 80200) {
            throw new RuntimeException('PHP 8.2 or newer is required.');
        }

        $options = $this->parseOptions($arguments);

        if ($options['self_test']) {
            return $this->selfTest();
        }

        $release = $this->fetchRelease($options['tag']);
        $version = self::versionFromTag((string) $release['tag_name']);
        $available = version_compare($version, Version::VERSION, '>');

        fwrite(STDOUT, 'Installed: v' . Version::VERSION . PHP_EOL);
        fwrite(STDOUT, 'Latest stable: v' . $version . PHP_EOL);
        fwrite(STDOUT, 'Channel: ' . Version::CHANNEL . PHP_EOL);

        if (! $options['install']) {
            fwrite(STDOUT, $available ? 'Update available.' . PHP_EOL : 'Already current.' . PHP_EOL);

            return 0;
        }

        if (! $available && $options['tag'] === null) {
            fwrite(STDOUT, 'No newer stable release to install.' . PHP_EOL);

            return 0;
        }

        return $this->install($release, $version);
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

    public static function archiveName(string $version): string
    {
        if (! self::isStableTag('v' . $version)) {
            throw new RuntimeException('Invalid release version: ' . $version);
        }

        return 'IdfDashboard-v' . $version . '.zip';
    }

    public static function checksumFor(string $contents, string $archiveName): string
    {
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (preg_match('/^([a-f0-9]{64})\s+\*?(.+)$/i', trim($line), $matches)
                && hash_equals($archiveName, trim($matches[2]))
            ) {
                return strtolower($matches[1]);
            }
        }

        throw new RuntimeException('SHA256SUMS does not contain the expected archive.');
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

        return ! in_array($top, ['.git', '.claude', 'backups'], true);
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

    private function parseOptions(array $arguments): array
    {
        $options = [
            'install' => false,
            'tag' => null,
            'self_test' => false,
        ];

        foreach (array_slice($arguments, 1) as $argument) {
            if ($argument === '--check') {
                continue;
            }

            if ($argument === '--install') {
                $options['install'] = true;
                continue;
            }

            if ($argument === '--self-test') {
                $options['self_test'] = true;
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

        return $options;
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

    private function install(array $release, string $version): int
    {
        $lock = $this->acquireLock();
        $tempDirectory = $this->createTempDirectory();

        try {
            $this->assertInstallPermissions();
            $this->audit('update_started', Version::VERSION, $version, 'Stable CLI update started.');

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

            if (! is_string($actualHash) || ! hash_equals($expectedHash, strtolower($actualHash))) {
                throw new RuntimeException('Release archive SHA-256 verification failed.');
            }

            $extracted = $tempDirectory . DIRECTORY_SEPARATOR . 'extracted';
            $this->extractArchive($archivePath, $extracted);
            $this->validatePackage($extracted, $version);
            $this->lintPhp($extracted);

            $backup = $this->activateAtomically($extracted, $version);
            $this->audit('update_succeeded', Version::VERSION, $version, 'Backup: ' . $backup);
            fwrite(STDOUT, 'Updated successfully to v' . $version . PHP_EOL);
            fwrite(STDOUT, 'Backup retained at: ' . $backup . PHP_EOL);

            return 0;
        } catch (Throwable $exception) {
            $this->audit('update_failed', Version::VERSION, $version, $exception->getMessage());
            throw $exception;
        } finally {
            $this->removeTree($tempDirectory, dirname($tempDirectory));
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function releaseAsset(array $release, string $name): array
    {
        foreach ($release['assets'] as $asset) {
            if (is_array($asset) && hash_equals($name, (string) ($asset['name'] ?? ''))) {
                $url = (string) ($asset['browser_download_url'] ?? '');
                $tag = (string) $release['tag_name'];
                $expected = 'https://github.com/' . Version::REPOSITORY
                    . '/releases/download/' . $tag . '/' . rawurlencode($name);

                if (! hash_equals($expected, $url)) {
                    throw new RuntimeException('Unexpected release asset URL for ' . $name);
                }

                return $asset;
            }
        }

        throw new RuntimeException('Required release asset is missing: ' . $name);
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
    }

    private function download(string $url, string $destination, int $maxBytes): void
    {
        if (! extension_loaded('curl')) {
            throw new RuntimeException('The PHP curl extension is required.');
        }

        $parts = parse_url($url);

        if (! is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || ! self::isTrustedGithubHost((string) ($parts['host'] ?? ''))
        ) {
            throw new RuntimeException('Only trusted GitHub HTTPS downloads are permitted.');
        }

        $handle = fopen($destination, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Unable to create download destination.');
        }

        $curl = curl_init($url);

        if ($curl === false) {
            fclose($handle);
            throw new RuntimeException('Unable to initialize curl.');
        }

        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FAILONERROR => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'IdfDashboard/' . Version::VERSION,
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
        ]);

        $success = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
        curl_close($curl);
        fclose($handle);

        $size = is_file($destination) ? filesize($destination) : false;

        if ($success !== true || $status < 200 || $status >= 300) {
            @unlink($destination);
            throw new RuntimeException('HTTPS download failed: ' . ($error !== '' ? $error : 'HTTP ' . $status));
        }

        $effectiveParts = parse_url($effectiveUrl);

        if (! is_array($effectiveParts)
            || ($effectiveParts['scheme'] ?? '') !== 'https'
            || ! self::isTrustedGithubHost((string) ($effectiveParts['host'] ?? ''))
        ) {
            @unlink($destination);
            throw new RuntimeException('Download redirected outside trusted GitHub HTTPS hosts.');
        }

        if (! is_int($size) || $size < 1 || $size > $maxBytes) {
            @unlink($destination);
            throw new RuntimeException('Downloaded file size is invalid or exceeds the safety limit.');
        }
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
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);

                if (! is_string($name) || ! self::isSafeArchivePath($name)) {
                    throw new RuntimeException('Unsafe path found in release archive.');
                }

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

    private function validatePackage(string $root, string $expectedVersion): void
    {
        foreach (self::REQUIRED_PATHS as $path) {
            if (! is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path))) {
                throw new RuntimeException('Release package is missing required file: ' . $path);
            }
        }

        foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (! in_array($entry->getFilename(), [
                'Menu.php',
                'Page.php',
                'Settings.php',
                'Support',
                'resources',
                'bin',
                'CHANGELOG.md',
                'README.md',
                'LICENSE',
            ], true)) {
                throw new RuntimeException('Unexpected top-level release path: ' . $entry->getFilename());
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
        $pending = $this->parentDirectory . DIRECTORY_SEPARATOR
            . '.IdfDashboard.pending-' . bin2hex(random_bytes(6));
        $backup = $this->parentDirectory . DIRECTORY_SEPARATOR
            . 'IdfDashboard.backup-' . gmdate('Ymd-His') . '-v' . Version::VERSION;

        if (file_exists($pending) || file_exists($backup)) {
            throw new RuntimeException('Prepared or backup update path already exists.');
        }

        $this->copyTree($extracted, $pending);
        $this->preserveLocalPaths($pending);
        $this->applyMetadata($pending);
        $this->validatePackage($pending, $version);
        $this->lintPhp($pending);

        if (! rename($this->pluginRoot, $backup)) {
            $this->removeTree($pending, $this->parentDirectory);
            throw new RuntimeException('Unable to create the atomic plugin backup.');
        }

        if (! rename($pending, $this->pluginRoot)) {
            @rename($backup, $this->pluginRoot);
            $this->removeTree($pending, $this->parentDirectory);
            throw new RuntimeException('Unable to activate the prepared plugin; original restored.');
        }

        try {
            $this->clearLibreNmsViewCache();
        } catch (Throwable $exception) {
            $failed = $this->parentDirectory . DIRECTORY_SEPARATOR
                . 'IdfDashboard.failed-' . gmdate('Ymd-His');
            @rename($this->pluginRoot, $failed);

            if (! rename($backup, $this->pluginRoot)) {
                $this->audit('rollback_failed', Version::VERSION, $version, $exception->getMessage());
                throw new RuntimeException('Activation failed and automatic rollback failed. Manual recovery required: ' . $backup);
            }

            try {
                $this->clearLibreNmsViewCache();
            } catch (Throwable) {
                // The original plugin is restored; retain the initial error.
            }

            $this->audit('rollback_succeeded', $version, Version::VERSION, $exception->getMessage());
            throw new RuntimeException('Activation validation failed; automatic rollback succeeded: ' . $exception->getMessage());
        }

        return $backup;
    }

    private function preserveLocalPaths(string $pending): void
    {
        foreach (self::PRESERVED_LOCAL_PATHS as $relative) {
            $source = $this->pluginRoot . DIRECTORY_SEPARATOR . $relative;
            $destination = $pending . DIRECTORY_SEPARATOR . $relative;

            if (! file_exists($source) || file_exists($destination)) {
                continue;
            }

            if (is_link($source)) {
                throw new RuntimeException('Refusing to preserve a symbolic link: ' . $relative);
            }

            if (is_dir($source)) {
                $this->copyTree($source, $destination);
            } elseif (! copy($source, $destination)) {
                throw new RuntimeException('Unable to preserve local file: ' . $relative);
            }
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
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        @chmod($root, fileperms($this->pluginRoot) & 0777);

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            @chown($root, $owner);
            @chgrp($root, $group);
        }

        foreach ($iterator as $entry) {
            @chmod($entry->getPathname(), $entry->isDir() ? 0750 : 0640);

            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                @chown($entry->getPathname(), $owner);
                @chgrp($entry->getPathname(), $group);
            }
        }
    }

    private function clearLibreNmsViewCache(): void
    {
        $libreNmsRoot = dirname($this->pluginRoot, 3);
        $artisan = $libreNmsRoot . DIRECTORY_SEPARATOR . 'artisan';

        if (! is_file($artisan)) {
            throw new RuntimeException('LibreNMS artisan executable was not found; cannot safely clear compiled views.');
        }

        [$status, $output] = $this->runCommand(
            [PHP_BINARY, $artisan, 'view:clear', '--no-interaction'],
            $libreNmsRoot,
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

    private function acquireLock()
    {
        $path = $this->parentDirectory . DIRECTORY_SEPARATOR . '.IdfDashboard-update.lock';
        $handle = fopen($path, 'c');

        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another IdfDashboard update is already running.');
        }

        @chmod($path, 0640);

        return $handle;
    }

    private function assertInstallPermissions(): void
    {
        if (! is_writable($this->pluginRoot) || ! is_writable($this->parentDirectory)) {
            throw new RuntimeException('Plugin and parent directory must be writable by the LibreNMS OS user.');
        }

        if (function_exists('posix_geteuid')) {
            $effectiveUser = posix_geteuid();
            $owner = fileowner($this->pluginRoot);

            if ($effectiveUser !== 0 && $effectiveUser !== $owner) {
                throw new RuntimeException('Run the updater as the plugin owner (normally librenms), not the web user.');
            }
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
                @unlink($entry->getPathname());
            } else {
                @rmdir($entry->getPathname());
            }
        }

        @rmdir($path);
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
