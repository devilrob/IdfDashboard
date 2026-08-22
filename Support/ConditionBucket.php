<?php

declare(strict_types=1);

namespace App\Plugins\IdfDashboard\Support;

/**
 * The single, centralized vocabulary of "operational condition types"
 * this dashboard reasons about — the missing layer identified in the
 * noise-reduction audit: LibreNMS/this plugin already know a reading is
 * "humidity" vs "voltage" (Page::metricProblemType()), but nothing
 * previously used that distinction to vary operational priority. Every
 * consumer that needs to know "what kind of condition is this issue"
 * (Support\OperationalPolicy for severity, Support\TvPresentationPolicy
 * for TV curation, Support\PolicyHealth for diagnostics,
 * settings.blade.php for the condition matrix/TV checkboxes) reads the
 * bucket this class assigned — never re-derives it independently. That
 * single rule is what keeps TV Mode's presentation filtering and the
 * operational severity engine from ever disagreeing about what a given
 * issue "is."
 *
 * Deliberately NOT one bucket per raw `sensors.sensor_class` value
 * (there are 15+ of those) — buckets are the same coarse, meaningful
 * groups Page::sensorPresentation()/metricProblemType() already
 * collapse raw classes into, so this stays a handful of admin-facing
 * settings, not dozens.
 */
final class ConditionBucket
{
    public const DEVICE_DOWN = 'device_down';
    public const HARDWARE_STATE = 'hardware_state';
    public const VOLTAGE = 'voltage';
    public const BATTERY = 'battery';
    public const FAN = 'fan';
    public const TEMPERATURE = 'temperature';
    public const HUMIDITY = 'humidity';
    public const CPU = 'cpu';
    public const MEMORY = 'memory';
    public const STORAGE = 'storage';
    public const SERVICE_CRITICAL = 'service_critical';
    public const SERVICE_WARNING = 'service_warning';
    public const OTHER = 'other';

    /**
     * Declaration order doubles as display order in Settings' condition
     * matrix and TV checkbox list — device-level/hardware conditions
     * first, then sensor conditions roughly most-to-least urgent by
     * default policy, services, 'other' last.
     *
     * @var array<int, string>
     */
    public const ALL = [
        self::DEVICE_DOWN,
        self::HARDWARE_STATE,
        self::VOLTAGE,
        self::BATTERY,
        self::FAN,
        self::TEMPERATURE,
        self::HUMIDITY,
        self::CPU,
        self::MEMORY,
        self::STORAGE,
        self::SERVICE_CRITICAL,
        self::SERVICE_WARNING,
        self::OTHER,
    ];

    /**
     * Buckets a per-condition-bucket OPERATIONAL SEVERITY setting is
     * offered for (Support\Config::FIELDS' condition_policy_* fields,
     * consumed by Support\OperationalPolicy). Device Down, Service
     * Critical and Service Warning are deliberately excluded here —
     * they already have their own, older, working exact-correlation
     * settings (fallback_device_down_*, fallback_service_*) which this
     * redesign extends (adds a 'monitor' choice) rather than
     * duplicates. See Support\OperationalPolicy's own docblock.
     *
     * @var array<int, string>
     */
    public const CONDITION_POLICY_BUCKETS = [
        self::HARDWARE_STATE,
        self::VOLTAGE,
        self::BATTERY,
        self::FAN,
        self::TEMPERATURE,
        self::HUMIDITY,
        self::CPU,
        self::MEMORY,
        self::STORAGE,
        self::OTHER,
    ];

    /** @return array<string, string> bucket => human label, declaration order */
    public static function labels(): array
    {
        return [
            self::DEVICE_DOWN => 'Device Down',
            self::HARDWARE_STATE => 'Hardware / State Failure',
            self::VOLTAGE => 'Voltage',
            self::BATTERY => 'Battery',
            self::FAN => 'Fan',
            self::TEMPERATURE => 'Temperature',
            self::HUMIDITY => 'Humidity',
            self::CPU => 'CPU',
            self::MEMORY => 'Memory',
            self::STORAGE => 'Storage',
            self::SERVICE_CRITICAL => 'Service Critical',
            self::SERVICE_WARNING => 'Service Warning',
            self::OTHER => 'Other',
        ];
    }

    /**
     * Maps Page::metricProblemType()'s existing, already-centralized
     * sensor-label classification (temperature/humidity/battery/
     * voltage/fan/state/storage/memory/processor/other — itself derived
     * from Page::sensorPresentation()'s sensor_class mapping) onto this
     * class's operational bucket vocabulary. This is the ONLY place
     * that translation happens; every telemetry-sourced issue's bucket
     * goes through this one function.
     */
    public static function forMetricProblemType(string $problemType): string
    {
        return match ($problemType) {
            'temperature' => self::TEMPERATURE,
            'humidity' => self::HUMIDITY,
            'battery' => self::BATTERY,
            'voltage' => self::VOLTAGE,
            'fan' => self::FAN,
            'state' => self::HARDWARE_STATE,
            'storage' => self::STORAGE,
            'memory' => self::MEMORY,
            'processor' => self::CPU,
            default => self::OTHER,
        };
    }

    /**
     * service_status: 1=Warning, 2=Critical (the same Nagios mapping
     * Page::serviceStatusClass() already encodes). Status 3 (Unknown)
     * deliberately returns null — Unknown keeps its own existing,
     * separate severity setting (fallback_service_unknown_severity)
     * and is not part of the condition-bucket matrix at all (see
     * Support\OperationalPolicy's own docblock for why: "never Critical
     * by default, since that would manufacture an outage signal from a
     * data-quality gap" — a distinction orthogonal to operational
     * condition type).
     */
    public static function forServiceStatus(int $status): ?string
    {
        return match ($status) {
            2 => self::SERVICE_CRITICAL,
            1 => self::SERVICE_WARNING,
            default => null,
        };
    }
}
