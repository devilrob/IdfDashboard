<?php

declare(strict_types=1);

namespace App\Plugins\IdfDashboard\Support;

use App\Plugins\IdfDashboard\Page;
use Illuminate\Support\Collection;

/**
 * Layer 3 — Operational Policy. This class exists to answer exactly one
 * question, and nothing else: "given a technical condition LibreNMS has
 * already observed (Layer 1/2), and given that no administrator-selected
 * LibreNMS Alert Rule already explains it, what operational severity
 * should this dashboard show — without inventing a Critical that was
 * never asked for, and without ever hiding a real technical failure?"
 *
 * This is deliberately NOT a second severity engine competing with
 * Support\AlertRules. It is the opposite: a narrow, clearly-labeled
 * safety net that only ever activates for a device with zero currently
 * active, administrator-included Alert Rule issues (see
 * Page::buildDeviceIssues()'s call site — $hasAlertIssue). The moment an
 * administrator configures the two Alert Rules this project recommends
 * ("Device Down — Critical Infrastructure" / "Device Down — Standard
 * Infrastructure", mapped to the Operational Critical Device Group and
 * to everyone else respectively — see docs on the "Operational Critical"
 * group below) this class stops having anything to do for that
 * condition at all; its output disappears in favor of the real alert.
 *
 * "Operationally critical" is intentionally NOT re-derived from device
 * type/role/hostname pattern-matching (that was tried once already, in
 * this plugin's very first design, and produced exactly the false
 * positives/negatives this redesign exists to fix — a printer is not
 * "technically a printer therefore never critical", a server is not
 * "technically a server therefore always critical"). It is read from
 * one real, administrator-controlled LibreNMS Device Group membership
 * (device_group_device), exactly matching how a real NOC already
 * decides "which of my devices actually page someone at 3am" — see
 * Page::loadOperationallyCriticalDeviceIds().
 */
final class OperationalPolicy
{
    /**
     * Every technical condition this policy knows how to fall back on.
     * Anything genuinely outside this list (a service_status value other
     * than 0/1/2/3, a sensor state this dashboard cannot decode) already
     * has its own, separate "Needs Review" handling in
     * Page::buildDeviceIssues() — this class does not need a matching
     * catch-all, because Severity::UNKNOWN already IS that catch-all.
     */
    public static function resolveFallbackIssues(
        int $deviceId,
        ?int $locationId,
        string $deviceUrl,
        int $deviceStatus,
        bool $maintenanceActive,
        bool $operationallyCritical,
        bool $hasAlertIssue,
        Collection $serviceRows,
        Collection $telemetry
    ): Collection {
        $issues = collect();

        // An administrator-selected Alert Rule is the preferred
        // authority (Section 3 of this redesign's own brief) — once one
        // is actively firing for this device, this dashboard trusts it
        // completely rather than layering a second, independently-
        // computed opinion on top. A maintenance window is LibreNMS's
        // own explicit "do not treat this device's current state as an
        // incident" signal; the fallback stays silent for the same
        // reason real Alert Rules are expected to (via LibreNMS's own
        // maintenance/schedule suppression) — this dashboard must not
        // manufacture new Warning/Critical noise a NOC operator did not
        // ask for during an already-acknowledged maintenance window.
        if ($hasAlertIssue || $maintenanceActive) {
            return $issues;
        }

        if ($deviceStatus === 0) {
            $issues->push(IssueBuilder::make([
                'key' => 'policy:' . $deviceId . ':device_down',
                'device_id' => $deviceId,
                'location_id' => $locationId,
                'severity' => $operationallyCritical ? Severity::CRITICAL : Severity::WARNING,
                'priority' => $operationallyCritical
                    ? IssueBuilder::PRIORITY_CRITICAL_POLICY_FALLBACK
                    : IssueBuilder::PRIORITY_WARNING_POLICY_FALLBACK,
                'source' => 'device',
                'type' => 'device',
                'title' => 'Device unreachable',
                'description' => self::describeFallback(
                    'Device unreachable',
                    $operationallyCritical
                ),
                'actionable' => true,
                'device_url' => $deviceUrl,
            ]));
        }

        foreach ($serviceRows as $service) {
            $status = (int) ($service['status'] ?? 0);

            if ($status === 0) {
                continue;
            }

            // service_status: 1=Warning, 2=Critical, 3=Unknown (the same
            // validated Nagios mapping Page::serviceStatusClass() already
            // encodes). Unknown deliberately never becomes Critical on
            // its own — a service check that could not determine its own
            // state is a real "needs a human to look" signal, not
            // evidence of an outage.
            $severity = match ($status) {
                2 => $operationallyCritical ? Severity::CRITICAL : Severity::WARNING,
                default => Severity::WARNING,
            };

            $name = trim((string) ($service['name'] ?? 'Service'));
            $message = trim((string) ($service['message'] ?? ''));

            $issues->push(IssueBuilder::make([
                'key' => 'policy:' . $deviceId . ':service:' . ($service['service_id'] ?? $name),
                'device_id' => $deviceId,
                'location_id' => $locationId,
                'severity' => $severity,
                'priority' => $severity === Severity::CRITICAL
                    ? IssueBuilder::PRIORITY_CRITICAL_POLICY_FALLBACK
                    : IssueBuilder::PRIORITY_WARNING_POLICY_FALLBACK,
                'source' => 'service',
                'type' => 'service',
                'title' => 'Service ' . $name,
                'description' => self::describeFallback(
                    $name . ($message !== '' ? ' — ' . $message : ''),
                    $status === 2 && $operationallyCritical
                ),
                'timestamp' => isset($service['changed']) ? (string) $service['changed'] : null,
                'actionable' => true,
                'device_url' => $deviceUrl,
            ]));
        }

        foreach ($telemetry as $metric) {
            $state = Severity::normalize($metric['state'] ?? null);

            if (! in_array($state, [Severity::CRITICAL, Severity::WARNING], true)) {
                continue;
            }

            $severity = $state === Severity::CRITICAL
                ? ($operationallyCritical ? Severity::CRITICAL : Severity::WARNING)
                : Severity::WARNING;

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
                'severity' => $severity,
                'priority' => $severity === Severity::CRITICAL
                    ? IssueBuilder::PRIORITY_CRITICAL_POLICY_FALLBACK
                    : IssueBuilder::PRIORITY_WARNING_POLICY_FALLBACK,
                'source' => 'sensor',
                'type' => Page::metricProblemType($metric),
                'title' => (string) ($metric['label'] ?? 'Sensor'),
                'description' => self::describeFallback(
                    $description,
                    $state === Severity::CRITICAL && $operationallyCritical
                ),
                'value' => $metric['current_value'] ?? null,
                'unit' => $metric['unit'] ?? null,
                'threshold' => $metric['threshold'] ?? null,
                'threshold_direction' => $metric['threshold_direction'] ?? null,
                'timestamp' => $metric['lastupdate'] ?? null,
                'age_seconds' => is_array($metric['freshness'] ?? null)
                    ? ($metric['freshness']['age_seconds'] ?? null)
                    : null,
                'actionable' => true,
                'device_url' => $deviceUrl,
            ]));
        }

        return $issues;
    }

    /**
     * Every fallback issue's description is explicit about being a
     * fallback — an administrator looking at a Warning/Critical badge
     * must be able to tell "this came from a real Alert Rule I
     * configured" apart from "this is the dashboard's own safety net
     * because no rule covers it yet" (Section 12/25's own requirement).
     */
    private static function describeFallback(string $cause, bool $operationallyCritical): string
    {
        $scope = $operationallyCritical ? 'Operational Critical device' : 'standard device';

        return $cause . ' (no active Alert Rule covers this — default policy for a ' . $scope . ')';
    }
}
