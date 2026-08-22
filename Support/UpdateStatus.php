<?php

namespace App\Plugins\IdfDashboard\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class UpdateStatus
{
    private const API_URL = 'https://api.github.com/repos/devilrob/IdfDashboard/releases?per_page=20';

    private const CACHE_KEY = 'idf-dashboard.stable-release-status.v1';

    private const SUCCESS_TTL_SECONDS = 21600;

    private const ERROR_TTL_SECONDS = 900;

    public static function get(bool $enabled, bool $force = false): array
    {
        $base = [
            'installed' => Version::VERSION,
            'latest' => null,
            'update_available' => false,
            // Gate 15/19 of the noise-reduction audit: installed vs.
            // published-stable is a real three-way comparison, not a
            // boolean. Previously only 'update_available' existed, so
            // "installed newer than the latest published tag" (e.g. a
            // merge to main with no corresponding release/tag pushed
            // yet) collapsed into the same generic "current" message as
            // "installed == stable" — technically not a lie (no update
            // IS available), but misleading: the installation is not
            // actually caught up to a matching release, it is ahead of
            // one. Computed once $latest is known, below.
            'ahead_of_stable' => false,
            'release_url' => null,
            'checked_at' => null,
            'error' => null,
            'enabled' => $enabled,
            'channel' => Version::CHANNEL,
        ];

        if (! $enabled && ! $force) {
            return $base;
        }

        if (! $force) {
            $cached = Cache::get(self::CACHE_KEY);

            if (is_array($cached)) {
                return array_replace($base, $cached);
            }
        }

        try {
            $response = Http::acceptJson()
                ->withUserAgent('IdfDashboard/' . Version::VERSION)
                ->connectTimeout(3)
                ->timeout(6)
                ->get(self::API_URL);

            if (! $response->successful()) {
                throw new \RuntimeException('GitHub API returned HTTP ' . $response->status());
            }

            $latest = self::latestStableRelease($response->json());
            $result = array_replace($base, ['enabled' => true]);
            $result['checked_at'] = now()->toIso8601String();

            if ($latest !== null) {
                $result['latest'] = $latest['version'];
                $result['release_url'] = $latest['url'];
                $result['update_available'] = version_compare(
                    $latest['version'],
                    Version::VERSION,
                    '>'
                );
                $result['ahead_of_stable'] = version_compare(
                    Version::VERSION,
                    $latest['version'],
                    '>'
                );
            }

            Cache::put(self::CACHE_KEY, $result, self::SUCCESS_TTL_SECONDS);

            return $result;
        } catch (Throwable $exception) {
            $result = array_replace($base, ['enabled' => true]);
            $result['checked_at'] = now()->toIso8601String();
            $result['error'] = Str::limit($exception->getMessage(), 180);
            Cache::put(self::CACHE_KEY, $result, self::ERROR_TTL_SECONDS);

            return $result;
        }
    }

    public static function latestStableRelease(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        $stable = [];

        foreach ($payload as $release) {
            if (! is_array($release)
                || ($release['draft'] ?? true)
                || ($release['prerelease'] ?? true)
            ) {
                continue;
            }

            $tag = (string) ($release['tag_name'] ?? '');
            $url = (string) ($release['html_url'] ?? '');

            if (! preg_match('/^v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/', $tag, $matches)
                || ! self::validReleaseUrl($url, $tag)
            ) {
                continue;
            }

            $stable[] = [
                'version' => $matches[1] . '.' . $matches[2] . '.' . $matches[3],
                'url' => $url,
            ];
        }

        usort(
            $stable,
            fn (array $left, array $right): int => version_compare(
                $right['version'],
                $left['version']
            )
        );

        return $stable[0] ?? null;
    }

    private static function validReleaseUrl(string $url, string $tag): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') === 'github.com'
            && ($parts['path'] ?? '') === '/devilrob/IdfDashboard/releases/tag/' . $tag;
    }
}
