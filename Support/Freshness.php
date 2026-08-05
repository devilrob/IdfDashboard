<?php

declare(strict_types=1);

namespace App\Plugins\IdfDashboard\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

final class Freshness
{
    public const FRESH = 'fresh';
    public const AGING = 'aging';
    public const STALE = 'stale';
    public const UNKNOWN = 'unknown';

    /**
     * Aging starts halfway to the configured stale threshold. A stale
     * result is actionable only when stale monitoring is enabled and the
     * caller identifies this telemetry as operationally important.
     *
     * @return array{state: string, age_seconds: ?int, age_minutes: ?int, timestamp: ?string, label: string, actionable: bool, reason: string}
     */
    public static function evaluate(
        mixed $timestamp,
        int $staleAfterMinutes,
        bool $staleEnabled = true,
        bool $important = false,
        ?DateTimeInterface $now = null
    ): array {
        $staleAfterMinutes = max(1, $staleAfterMinutes);
        $parsed = self::parse($timestamp);

        if ($parsed === null) {
            return [
                'state' => self::UNKNOWN,
                'age_seconds' => null,
                'age_minutes' => null,
                'timestamp' => null,
                'label' => 'Unknown',
                'actionable' => false,
                'reason' => 'Last poll unavailable',
            ];
        }

        $current = $now !== null
            ? DateTimeImmutable::createFromInterface($now)
            : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $ageSeconds = max(0, $current->getTimestamp() - $parsed->getTimestamp());
        $ageMinutes = intdiv($ageSeconds, 60);
        $staleSeconds = $staleAfterMinutes * 60;
        $agingSeconds = max(60, intdiv($staleSeconds, 2));

        if ($ageSeconds > $staleSeconds) {
            $state = self::STALE;
            $label = 'Stale';
            $reason = 'Last update ' . self::ageLabel($ageSeconds) . ' ago';
        } elseif ($ageSeconds > $agingSeconds) {
            $state = self::AGING;
            $label = 'Aging';
            $reason = 'Approaching stale threshold';
        } else {
            $state = self::FRESH;
            $label = 'Fresh';
            $reason = 'Updated within freshness window';
        }

        return [
            'state' => $state,
            'age_seconds' => $ageSeconds,
            'age_minutes' => $ageMinutes,
            'timestamp' => $parsed->format('Y-m-d H:i:s'),
            'label' => $label,
            'actionable' => $state === self::STALE && $staleEnabled && $important,
            'reason' => $reason,
        ];
    }

    private static function parse(mixed $timestamp): ?DateTimeImmutable
    {
        if ($timestamp instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($timestamp);
        }

        if (! is_string($timestamp) || trim($timestamp) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($timestamp);
        } catch (Throwable) {
            return null;
        }
    }

    private static function ageLabel(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
        }

        $hours = intdiv($minutes, 60);

        return $hours . ' hour' . ($hours === 1 ? '' : 's');
    }
}
