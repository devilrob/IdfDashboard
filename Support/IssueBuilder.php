<?php

declare(strict_types=1);

namespace App\Plugins\IdfDashboard\Support;

final class IssueBuilder
{
    public const PRIORITY_DEVICE_DOWN = 10;
    public const PRIORITY_CRITICAL_SENSOR = 20;
    public const PRIORITY_CRITICAL_SERVICE = 30;
    public const PRIORITY_CRITICAL_ALERT = 40;

    /**
     * Support\OperationalPolicy's own fallback tier — deliberately ranked
     * below a real administrator-selected Alert Rule issue of the same
     * severity (PRIORITY_CRITICAL_ALERT=40) and above the next severity
     * tier down. This is what guarantees that the moment an
     * administrator configures a real Alert Rule for a condition, that
     * rule's issue always outranks — and in practice fully replaces,
     * since Page.php only generates a fallback when a device has zero
     * alert-sourced issues at all — this class's own generic opinion.
     */
    public const PRIORITY_CRITICAL_POLICY_FALLBACK = 42;

    public const PRIORITY_WARNING_ALERT = 45;
    public const PRIORITY_WARNING_POLICY_FALLBACK = 47;
    public const PRIORITY_WARNING_SENSOR = 50;
    public const PRIORITY_WARNING_SERVICE = 60;
    public const PRIORITY_STALE = 70;
    public const PRIORITY_UNKNOWN = 80;
    public const PRIORITY_INFORMATIONAL = 90;

    /**
     * LibreNMS sensor_current and sensor_limit* are already normalized
     * values. This comparison deliberately never applies sensor_multiplier
     * or sensor_divisor a second time.
     *
     * @return array{severity: string, threshold: float|null, direction: string|null}
     */
    public static function evaluateNumericSensor(
        mixed $current,
        mixed $criticalHigh,
        mixed $warningHigh,
        mixed $criticalLow,
        mixed $warningLow,
        bool $lowerZeroIsUnset = false
    ): array {
        $value = self::finite($current);

        if ($value === null) {
            return ['severity' => Severity::UNKNOWN, 'threshold' => null, 'direction' => null];
        }

        $criticalHigh = self::finite($criticalHigh);
        $warningHigh = self::finite($warningHigh);
        $criticalLow = self::finite($criticalLow);
        $warningLow = self::finite($warningLow);

        if (($criticalHigh !== null && $warningHigh !== null && $criticalHigh < $warningHigh)
            || ($criticalLow !== null && $warningLow !== null && $criticalLow > $warningLow)
        ) {
            return ['severity' => Severity::UNKNOWN, 'threshold' => null, 'direction' => 'invalid threshold order'];
        }

        $checks = [
            [Severity::CRITICAL, $criticalHigh, 'above critical high', 'high'],
            [Severity::CRITICAL, $criticalLow, 'below critical low', 'low'],
            [Severity::WARNING, $warningHigh, 'above warning high', 'high'],
            [Severity::WARNING, $warningLow, 'below warning low', 'low'],
        ];

        foreach ($checks as [$severity, $threshold, $direction, $side]) {
            if ($threshold === null
                || ($side === 'high' && $threshold === 0.0)
                || ($side === 'low' && $lowerZeroIsUnset && $threshold === 0.0)
            ) {
                continue;
            }

            if (($side === 'high' && $value >= $threshold)
                || ($side === 'low' && $value <= $threshold)
            ) {
                return ['severity' => $severity, 'threshold' => $threshold, 'direction' => $direction];
            }
        }

        return ['severity' => Severity::HEALTHY, 'threshold' => null, 'direction' => null];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function make(array $values): array
    {
        $severity = Severity::normalize((string) ($values['severity'] ?? Severity::UNKNOWN));
        $description = trim((string) ($values['description'] ?? ''));

        if (in_array($severity, [Severity::CRITICAL, Severity::WARNING], true) && $description === '') {
            $description = self::fallbackCause($values);
        }

        return [
            'key' => (string) ($values['key'] ?? ''),
            'device_id' => (int) ($values['device_id'] ?? 0),
            'location_id' => isset($values['location_id']) ? (int) $values['location_id'] : null,
            'severity' => $severity,
            'priority' => (int) ($values['priority'] ?? self::priorityFor($severity, (string) ($values['source'] ?? ''))),
            'source' => (string) ($values['source'] ?? 'unknown'),
            'type' => (string) ($values['type'] ?? 'unknown'),
            'title' => trim((string) ($values['title'] ?? 'Operational issue')),
            'description' => $description !== '' ? $description : 'Current state unavailable',
            'value' => $values['value'] ?? null,
            'unit' => self::nullableString($values['unit'] ?? null),
            'threshold' => $values['threshold'] ?? null,
            'threshold_direction' => self::nullableString($values['threshold_direction'] ?? null),
            'timestamp' => self::nullableString($values['timestamp'] ?? null),
            'age_seconds' => isset($values['age_seconds']) ? max(0, (int) $values['age_seconds']) : null,
            'actionable' => (bool) ($values['actionable'] ?? Severity::metadata($severity)['actionable']),
            'device_url' => (string) ($values['device_url'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $values */
    private static function fallbackCause(array $values): string
    {
        if (! array_key_exists('value', $values)
            || $values['value'] === null
            || ! is_numeric($values['value'])
            || ! is_finite((float) $values['value'])
        ) {
            return 'Current value unavailable';
        }

        if (! array_key_exists('threshold', $values) || $values['threshold'] === null) {
            return 'Threshold not configured';
        }

        return 'Current value crossed the configured threshold';
    }

    /**
     * [Severity::CRITICAL, 'device']/'sensor'/'service' and
     * [Severity::WARNING, 'sensor']/'service' are no longer reachable in
     * practice — Page.php's buildDeviceIssues() replaced native Device
     * Down/Service Issue/per-sensor-threshold detection with
     * administrator-selected LibreNMS Alert Rules entirely (source
     * 'alert' only) — but this match is a general-purpose priority-
     * ordering contract for IssueBuilder::make() callers, not something
     * narrowly coupled to Page.php's current calling pattern, so those
     * arms (and their constants) are kept rather than pruned; see
     * tests/run.php's own standalone IssueBuilder coverage. The one arm
     * that genuinely had no equivalent until now was
     * [Severity::WARNING, 'alert']: it silently fell through to the
     * `default` (PRIORITY_INFORMATIONAL) catch-all, so a real
     * warning-severity Alert Rule issue was ranked below Stale/Unknown
     * and dropped out of Priority Attention entirely — caught only once
     * a real warning-severity Alert Rule fixture exercised this path in
     * CI (tests/librenms/DeviceAccessTest.php's
     * testCasosAToFRespectConfiguredSeverityPolicyAcrossSummaryPriorityAndTv).
     */
    private static function priorityFor(string $severity, string $source): int
    {
        return match ([$severity, $source]) {
            [Severity::CRITICAL, 'device'] => self::PRIORITY_DEVICE_DOWN,
            [Severity::CRITICAL, 'sensor'] => self::PRIORITY_CRITICAL_SENSOR,
            [Severity::CRITICAL, 'service'] => self::PRIORITY_CRITICAL_SERVICE,
            [Severity::CRITICAL, 'alert'] => self::PRIORITY_CRITICAL_ALERT,
            [Severity::WARNING, 'alert'] => self::PRIORITY_WARNING_ALERT,
            [Severity::WARNING, 'sensor'] => self::PRIORITY_WARNING_SENSOR,
            [Severity::WARNING, 'service'] => self::PRIORITY_WARNING_SERVICE,
            [Severity::STALE, 'sensor'] => self::PRIORITY_STALE,
            [Severity::UNKNOWN, 'sensor'] => self::PRIORITY_UNKNOWN,
            default => self::PRIORITY_INFORMATIONAL,
        };
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    private static function finite(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric(trim($value)))) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }
}
