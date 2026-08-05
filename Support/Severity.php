<?php

declare(strict_types=1);

namespace App\Plugins\IdfDashboard\Support;

final class Severity
{
    public const CRITICAL = 'critical';
    public const WARNING = 'warning';
    public const UNKNOWN = 'unknown';
    public const STALE = 'stale';
    public const MAINTENANCE = 'maintenance';
    public const DISABLED = 'disabled';
    public const IGNORED = 'ignored';
    public const HEALTHY = 'healthy';
    public const NO_SENSOR = 'no_sensor';

    /**
     * Lower ranks are operationally more important. Stale only becomes
     * actionable when the caller's policy says that loss of telemetry can
     * elevate the device; no_sensor is always informational.
     *
     * @return array<string, array{rank: int, label: string, icon: string, affects_counters: bool, actionable: bool, elevates: bool}>
     */
    public static function definitions(): array
    {
        return [
            self::CRITICAL => ['rank' => 0, 'label' => 'Critical', 'icon' => 'exclamation-circle', 'affects_counters' => true, 'actionable' => true, 'elevates' => true],
            self::WARNING => ['rank' => 10, 'label' => 'Warning', 'icon' => 'exclamation-triangle', 'affects_counters' => true, 'actionable' => true, 'elevates' => true],
            self::UNKNOWN => ['rank' => 20, 'label' => 'Unknown', 'icon' => 'question-circle', 'affects_counters' => true, 'actionable' => true, 'elevates' => true],
            self::STALE => ['rank' => 30, 'label' => 'Stale', 'icon' => 'clock-o', 'affects_counters' => false, 'actionable' => false, 'elevates' => false],
            self::MAINTENANCE => ['rank' => 40, 'label' => 'Maintenance', 'icon' => 'wrench', 'affects_counters' => false, 'actionable' => false, 'elevates' => true],
            self::DISABLED => ['rank' => 50, 'label' => 'Disabled', 'icon' => 'ban', 'affects_counters' => false, 'actionable' => false, 'elevates' => true],
            self::IGNORED => ['rank' => 60, 'label' => 'Ignored', 'icon' => 'eye-slash', 'affects_counters' => false, 'actionable' => false, 'elevates' => true],
            self::HEALTHY => ['rank' => 70, 'label' => 'Healthy', 'icon' => 'check-circle', 'affects_counters' => false, 'actionable' => false, 'elevates' => true],
            self::NO_SENSOR => ['rank' => 80, 'label' => 'No sensor installed', 'icon' => 'minus-circle', 'affects_counters' => false, 'actionable' => false, 'elevates' => false],
        ];
    }

    public static function normalize(?string $state): string
    {
        $normalized = strtolower(trim((string) $state));

        return array_key_exists($normalized, self::definitions())
            ? $normalized
            : self::UNKNOWN;
    }

    public static function fromLibreNmsGenericState(mixed $genericValue): string
    {
        if (! is_int($genericValue) && ! (is_string($genericValue) && ctype_digit($genericValue))) {
            return self::UNKNOWN;
        }

        return match ((int) $genericValue) {
            0 => self::HEALTHY,
            1 => self::WARNING,
            2 => self::CRITICAL,
            default => self::UNKNOWN,
        };
    }

    /** @return array{rank: int, label: string, icon: string, affects_counters: bool, actionable: bool, elevates: bool} */
    public static function metadata(?string $state, bool $actionableStale = false): array
    {
        $normalized = self::normalize($state);
        $metadata = self::definitions()[$normalized];

        if ($normalized === self::STALE && $actionableStale) {
            $metadata['actionable'] = true;
            $metadata['elevates'] = true;
        }

        return $metadata;
    }

    /**
     * @param  iterable<int, string|null>  $states
     */
    public static function worst(iterable $states, bool $actionableStale = false): string
    {
        $worst = self::HEALTHY;
        $worstRank = self::metadata($worst)['rank'];

        foreach ($states as $state) {
            $normalized = self::normalize($state);

            if ($normalized === self::NO_SENSOR) {
                continue;
            }

            if ($normalized === self::STALE && ! $actionableStale) {
                continue;
            }

            $rank = self::metadata($normalized, $actionableStale)['rank'];

            if ($rank < $worstRank) {
                $worst = $normalized;
                $worstRank = $rank;
            }
        }

        return $worst;
    }
}
