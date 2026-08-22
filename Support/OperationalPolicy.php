<?php

declare(strict_types=1);

namespace App\Plugins\IdfDashboard\Support;

use App\Plugins\IdfDashboard\Page;
use Illuminate\Support\Collection;

/**
 * Layer 3 — Operational Condition Policy. This class exists to answer
 * exactly one question, and nothing else: "given a technical condition
 * LibreNMS has already observed (Layer 1/2), what operational severity
 * should this dashboard show for it — without inventing a Critical that
 * was never asked for, and without ever hiding a real technical
 * failure?"
 *
 * Separates TECHNICAL CONDITION (a sensor/service/device state LibreNMS
 * has already observed) from OPERATIONAL PRIORITY (Critical/Warning/
 * Monitor/Ignore) — the redesign this class implements exists because
 * those two were previously conflated: a generic Alert Rule's own
 * severity (or, before that, this plugin's own numeric/state sensor
 * split) treated a humidity reading the same as a failed power supply.
 * Severity here is selected by WHICH CONDITION a reading represents
 * (Support\ConditionBucket), evaluated independently per technical
 * CONDITION rather than once per device or once per category, and is
 * the SOLE authority for sensor/service severity — see
 * resolveConditionIssues()'s own docblock for how this coexists with
 * Direct-severity and Condition-policy-Handling Alert Rules.
 *

 * Suppression requires EXACT structured identity, never a category
 * label. Device Down is the only condition where that exact identity is
 * available at all: there is only one "device unreachable" condition
 * per device, and an Alert-Rule-sourced issue already carries that same
 * real device_id — see $deviceDownCovered below and
 * AlertRules::DEVICE_DOWN_SETTING_KEY. A numeric/state sensor condition
 * and a service condition each have their OWN identity (a specific
 * sensor_id / service_id), but an Alert-Rule-sourced issue in this
 * schema carries none of those (see Page::buildDeviceIssues() — no
 * sensor_id/service_id survives onto an alert issue, only device_id and
 * the rule's own name/severity) — so this plugin does not offer a tag
 * for them at all (a control a user could reasonably read as
 * functional, but that could never safely suppress anything, is worse
 * than no control). Without a real per-entity identifier on both sides,
 * this class always prefers a safe, clearly-labeled visual duplicate
 * over a hidden incident.
 *
 * "Operationally critical" is intentionally NOT re-derived from device
 * type/role/hostname pattern-matching (that was tried once already, in
 * this plugin's very first design, and produced exactly the false
 * positives/negatives this redesign exists to fix — a printer is not
 * "technically a printer therefore never critical", a server is not
 * "technically a server therefore always critical"). It is read from
 * one or more real, administrator-selected LibreNMS Device Group
 * memberships (device_group_device), exactly matching how a real NOC
 * already decides "which of my devices actually page someone at 3am" —
 * see Support\DeviceGroups::memberDeviceIds().
 */
final class OperationalPolicy
{
    /**
     * The primary source of operational severity for Device Down,
     * Service, and every sensor condition — evaluated from CURRENTLY
     * LOADED, already-normalized data (telemetry/service rows this
     * request already fetched; no additional query, no N+1) — every
     * time, for every device, regardless of whether any LibreNMS Alert
     * Rule exists or fired for that condition. This is deliberately no
     * longer a "fallback that only runs when no Alert Rule covers a
     * condition": Direct-severity Alert Rules (Support\AlertRules::
     * HANDLING_DIRECT) still add their OWN separate device/hardware-
     * level issue alongside whatever this method produces (see
     * Page::buildDeviceIssues()) — the two do not conflict, because a
     * generic condition-bucket Warning/Monitor outcome here can never
     * outrank a real Direct-severity Critical alert issue in Priority
     * Attention ordering. What this method's output DOES fully replace
     * is a Condition-policy-Handling rule's own raw severity — see
     * Support\AlertRules::HANDLING_CONDITION_POLICY's own docblock and
     * Page::buildDeviceIssues()'s fail-safe.
     *
     * Suppression requires EXACT structured identity, never a category
     * label — see this class's own docblock. Device Down is the only
     * condition with that exact identity available (device_id shared
     * with a Device-Down-tagged Alert Rule); sensor/service conditions
     * have no equivalent, so nothing here can ever be suppressed by an
     * unrelated Alert Rule — only by this device's OWN configured
     * condition-bucket policy (Support\ConditionBucket).
     *
     * @param  bool  $deviceDownCovered  Whether an active, administrator-
     *     selected Alert Rule is tagged "Device Down" for this device
     *     (Support\AlertRules::DEVICE_DOWN_SETTING_KEY).
     * @param  array<string, mixed>  $policyConfig  The resolved
     *     Operational Priority policy slice of Support\Config — see
     *     Support\Config::FIELDS. Every severity decision below reads
     *     only from here, never a hardcoded literal, so an
     *     administrator can change this policy without editing code.
     */
    public static function resolveConditionIssues(
        int $deviceId,
        ?int $locationId,
        string $deviceUrl,
        int $deviceStatus,
        bool $maintenanceActive,
        bool $operationallyCritical,
        bool $deviceDownCovered,
        Collection $serviceRows,
        Collection $telemetry,
        array $policyConfig
    ): Collection {
        $issues = collect();

        // A maintenance window is LibreNMS's own explicit "do not treat
        // this device's current state as an incident" signal; this
        // policy stays silent for the same reason real Alert Rules are
        // expected to (via LibreNMS's own maintenance/schedule
        // suppression) — this dashboard must not manufacture new
        // Warning/Critical noise a NOC operator did not ask for during
        // an already-acknowledged maintenance window. Administrator-
        // configurable (fallback_suppress_during_maintenance) — default
        // true preserves this project's original behavior exactly.
        if ($maintenanceActive && ($policyConfig['fallback_suppress_during_maintenance'] ?? true)) {
            return $issues;
        }

        if ($deviceStatus === 0
            && ! $deviceDownCovered
            && ($policyConfig['fallback_device_down_enabled'] ?? true)
        ) {
            $outcome = self::resolveConditionOutcome($policyConfig, $operationallyCritical
                ? 'fallback_device_down_critical_group_severity'
                : 'fallback_device_down_normal_severity', Severity::CRITICAL);

            if ($outcome !== null) {
                $issues->push(IssueBuilder::make([
                    'key' => 'policy:' . $deviceId . ':device_down',
                    'device_id' => $deviceId,
                    'location_id' => $locationId,
                    'severity' => $outcome['severity'],
                    'priority' => self::priorityFor($outcome),
                    'source' => 'device',
                    'type' => 'device',
                    'condition_bucket' => ConditionBucket::DEVICE_DOWN,
                    'title' => 'Device unreachable',
                    'description' => self::describeCondition(
                        'Device unreachable',
                        $operationallyCritical ? 'Operational Critical device' : 'standard device'
                    ),
                    'actionable' => $outcome['actionable'],
                    'device_url' => $deviceUrl,
                ]));
            }
        }

        // No exact per-service identity exists on an Alert-Rule-sourced
        // issue (see this class's own docblock) — only each service's
        // own status and the condition-bucket policy govern whether an
        // issue is generated here.
        foreach ($serviceRows as $service) {
            $status = (int) ($service['status'] ?? 0);

            if ($status === 0) {
                continue;
            }

            // service_status: 1=Warning, 2=Critical, 3=Unknown (the same
            // validated Nagios mapping Page::serviceStatusClass()
            // already encodes). Status 3 (Unknown) deliberately keeps
            // its OWN separate, unchanged setting/semantics — see
            // Support\ConditionBucket::forServiceStatus()'s own
            // docblock — never part of the condition-bucket matrix.
            //
            // Deliberately NOT gated on $operationallyCritical the way
            // Device Down and sensor conditions are: a service check
            // that has already classified itself Critical (a specific
            // failing application/health-check, not raw ICMP/SNMP
            // reachability) is Critical for every device by default —
            // this is a stronger, more specific signal than the
            // device's own infrastructure-tier Device Group membership.
            $bucket = ConditionBucket::forServiceStatus($status);
            $rawSeverity = $status === 2 ? Severity::CRITICAL : Severity::WARNING;

            $outcome = $bucket !== null
                ? self::resolveConditionOutcome($policyConfig, match ($status) {
                    2 => 'fallback_service_critical_severity',
                    default => 'fallback_service_warning_severity',
                }, $rawSeverity)
                : self::resolveConditionOutcome($policyConfig, 'fallback_service_unknown_severity', Severity::WARNING);

            if ($outcome === null) {
                continue;
            }

            $name = trim((string) ($service['name'] ?? 'Service'));
            $message = trim((string) ($service['message'] ?? ''));

            $issues->push(IssueBuilder::make([
                'key' => 'policy:' . $deviceId . ':service:' . ($service['service_id'] ?? $name),
                'device_id' => $deviceId,
                'location_id' => $locationId,
                'severity' => $outcome['severity'],
                'priority' => self::priorityFor($outcome),
                'source' => 'service',
                'type' => 'service',
                'condition_bucket' => $bucket ?? ConditionBucket::OTHER,
                'title' => 'Service ' . $name,
                'description' => self::describeCondition(
                    $name . ($message !== '' ? ' — ' . $message : ''),
                    'a service check reporting status ' . $status . ' applies to every device by default'
                ),
                'timestamp' => isset($service['changed']) ? (string) $service['changed'] : null,
                'actionable' => $outcome['actionable'],
                'device_url' => $deviceUrl,
            ]));
        }

        // No exact per-sensor identity exists on an Alert-Rule-sourced
        // issue either. Severity is selected by WHICH CONDITION this
        // sensor represents (Support\ConditionBucket), never by whether
        // the underlying LibreNMS evaluation was numeric or state-
        // decoded — that distinction is now purely an internal
        // sensor-evaluation mechanic (Page::sensorState()), not an
        // operational severity authority.
        foreach ($telemetry as $metric) {
            $state = Severity::normalize($metric['state'] ?? null);

            if (! in_array($state, [Severity::CRITICAL, Severity::WARNING], true)) {
                continue;
            }

            $bucket = ConditionBucket::forMetricProblemType(Page::metricProblemType($metric));
            $settingKey = $operationallyCritical
                ? 'condition_policy_' . $bucket . '_critical_group_severity'
                : 'condition_policy_' . $bucket . '_normal_severity';
            $outcome = self::resolveConditionOutcome($policyConfig, $settingKey, $state);

            if ($outcome === null) {
                continue;
            }

            $sensorId = (int) ($metric['sensor_id'] ?? 0);
            $description = trim((string) ($metric['cause'] ?? ''));

            if ($description === '') {
                $description = trim((string) ($metric['label'] ?? 'Sensor'))
                    . ' — ' . trim((string) ($metric['value'] ?? 'Current value unavailable'));
            }

            $issues->push(IssueBuilder::make([
                'key' => 'policy:' . $deviceId . ':sensor:' . $sensorId,
                'device_id' => $deviceId,
                'location_id' => $locationId,
                'severity' => $outcome['severity'],
                'priority' => self::priorityFor($outcome),
                'source' => 'sensor',
                'type' => Page::metricProblemType($metric),
                'condition_bucket' => $bucket,
                'title' => (string) ($metric['label'] ?? 'Sensor'),
                'description' => self::describeCondition(
                    $description,
                    $operationallyCritical ? 'Operational Critical device' : 'standard device'
                ),
                'value' => $metric['current_value'] ?? null,
                'unit' => $metric['unit'] ?? null,
                'threshold' => $metric['threshold'] ?? null,
                'threshold_direction' => $metric['threshold_direction'] ?? null,
                'timestamp' => $metric['lastupdate'] ?? null,
                'age_seconds' => is_array($metric['freshness'] ?? null)
                    ? ($metric['freshness']['age_seconds'] ?? null)
                    : null,
                'actionable' => $outcome['actionable'],
                'device_url' => $deviceUrl,
            ]));
        }

        return $issues;
    }

    /**
     * Fail-safe outcome resolution: reads one 'critical'/'warning'/
     * 'monitor'/'disabled' choice out of the already-typed/validated
     * $policyConfig (Support\Config::resolve() already rejected
     * anything outside FIELDS' declared options — this is defense in
     * depth, never trusting a raw/untyped value here).
     *
     * - 'critical'/'warning' actionable outcome at that severity,
     *   REGARDLESS of $rawSeverity — a deliberate change from this
     *   plugin's pre-redesign behavior, where a raw Warning-severity
     *   reading always stayed Warning and could never be policy-
     *   escalated. That asymmetry was an artifact of the old numeric/
     *   state split, not an independent invariant: the whole point of
     *   an Operational Critical Device Group is that even a moderate
     *   technical Warning on that tier can deserve full Critical
     *   attention, and the admin is choosing that explicitly per
     *   condition bucket, from real per-sensor/per-service evidence —
     *   never from an unrelated generic Alert Rule's own severity
     *   (that remains prohibited — see this class's own docblock).
     * - 'monitor': the issue exists (visible in telemetry/details) but
     *   is non-actionable — severity is $rawSeverity (the real,
     *   truthful technical state), so a Monitor-tier issue's own badge
     *   still shows what LibreNMS actually observed; only its
     *   contribution to device health/Priority Attention/TV is
     *   suppressed, via the existing 'actionable' mechanism
     *   (IssueBuilder::make() / Severity::worst()'s actionable filter)
     *   — no second severity engine.
     * - 'disabled': null — no issue generated for this slot at all.
     * - Anything unrecognized: fails safe to an actionable Warning,
     *   never a malformed/silent state.
     *
     * Deliberately does NOT offer Severity::UNKNOWN as a policy choice:
     * UNKNOWN means "technically indeterminate / Needs Review" — a real,
     * distinct technical state (an unreadable/undecoded sensor, see
     * Page::buildDeviceIssues()'s own UNKNOWN handling) — not a synonym
     * for "low-urgency but confirmed."
     *
     * @return array{severity: string, actionable: bool}|null
     */
    private static function resolveConditionOutcome(array $policyConfig, string $settingKey, string $rawSeverity): ?array
    {
        $choice = (string) ($policyConfig[$settingKey] ?? Severity::WARNING);

        return match ($choice) {
            Severity::CRITICAL => ['severity' => Severity::CRITICAL, 'actionable' => true],
            Severity::WARNING => ['severity' => Severity::WARNING, 'actionable' => true],
            'monitor' => ['severity' => $rawSeverity, 'actionable' => false],
            'disabled' => null,
            default => ['severity' => Severity::WARNING, 'actionable' => true],
        };
    }

    /** @param array{severity: string, actionable: bool} $outcome */
    private static function priorityFor(array $outcome): int
    {
        if (! $outcome['actionable']) {
            return IssueBuilder::PRIORITY_INFORMATIONAL;
        }

        return $outcome['severity'] === Severity::CRITICAL
            ? IssueBuilder::PRIORITY_CRITICAL_POLICY_FALLBACK
            : IssueBuilder::PRIORITY_WARNING_POLICY_FALLBACK;
    }

    /**
     * A compact, resolved-config-driven summary of the effective
     * condition-bucket policy — used by Settings' "Operational
     * Priority" section so the main page can show real, live
     * severities instead of duplicating them as hardcoded Blade prose
     * that could drift from actual behavior. Reads only $policyConfig,
     * the exact same resolved slice resolveConditionIssues() itself
     * consumes, so this can never disagree with runtime behavior. One
     * row per Support\ConditionBucket, in that class's own declared
     * order.
     *
     * @param  array<string, mixed>  $policyConfig
     * @return array<int, array{condition: string, critical_groups: string, other_devices: string}>
     */
    public static function effectivePolicySummary(array $policyConfig): array
    {
        $labels = ConditionBucket::labels();
        $rows = [];

        $rows[] = [
            'condition' => $labels[ConditionBucket::DEVICE_DOWN],
            'critical_groups' => self::outcomeLabel($policyConfig, 'fallback_device_down_critical_group_severity'),
            'other_devices' => self::outcomeLabel($policyConfig, 'fallback_device_down_normal_severity'),
        ];

        foreach (ConditionBucket::CONDITION_POLICY_BUCKETS as $bucket) {
            $rows[] = [
                'condition' => $labels[$bucket],
                'critical_groups' => self::outcomeLabel($policyConfig, 'condition_policy_' . $bucket . '_critical_group_severity'),
                'other_devices' => self::outcomeLabel($policyConfig, 'condition_policy_' . $bucket . '_normal_severity'),
            ];
        }

        // Service severity is deliberately not gated by Operational
        // Critical membership (see resolveConditionIssues()'s own
        // comment) — both columns always show the same value, which
        // honestly reflects that this row does not vary by group.
        $serviceCritical = self::outcomeLabel($policyConfig, 'fallback_service_critical_severity');
        $rows[] = [
            'condition' => $labels[ConditionBucket::SERVICE_CRITICAL],
            'critical_groups' => $serviceCritical,
            'other_devices' => $serviceCritical,
        ];

        $serviceWarning = self::outcomeLabel($policyConfig, 'fallback_service_warning_severity');
        $rows[] = [
            'condition' => $labels[ConditionBucket::SERVICE_WARNING],
            'critical_groups' => $serviceWarning,
            'other_devices' => $serviceWarning,
        ];

        return $rows;
    }

    /** Human-readable label for one resolved condition outcome — see resolveConditionOutcome(). */
    private static function outcomeLabel(array $policyConfig, string $settingKey): string
    {
        $outcome = self::resolveConditionOutcome($policyConfig, $settingKey, Severity::CRITICAL);

        if ($outcome === null) {
            return 'Ignored';
        }

        if (! $outcome['actionable']) {
            return 'Monitor';
        }

        return $outcome['severity'] === Severity::CRITICAL ? 'Critical' : 'Warning';
    }

    /**
     * Every condition-policy issue's description is explicit about its
     * own basis — an administrator looking at a Warning/Critical badge
     * must be able to tell "this came from a real Alert Rule I
     * configured" apart from "this is this dashboard's own condition
     * policy for a technical reading no Direct-severity rule covers"
     * (Section 12/25's own requirement). $reason names the actual
     * policy basis for the chosen severity — never just a bare
     * true/false, since Device Down/sensor conditions and Service
     * status conditions are governed by genuinely different defaults
     * (see resolveConditionIssues()'s own comments) and the text shown
     * to an administrator must say which one actually applied here, not
     * a generic label.
     */
    private static function describeCondition(string $cause, string $reason): string
    {
        return $cause . ' (operational condition policy — default for a ' . $reason . ')';
    }
}
