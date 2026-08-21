<?php

declare(strict_types=1);

namespace App\Plugins\IdfDashboard\Support;

use Illuminate\Support\Collection;

/**
 * Section 25's read-only "Policy Health" diagnostic. This class never
 * creates, modifies or deletes an Alert Rule, Device Group, or any
 * other LibreNMS resource — it only reads data Page::data() has
 * already loaded for the current request and reports what it finds,
 * so an administrator can self-audit their own Alert Rule/Device
 * Group configuration against real live evidence instead of guessing.
 *
 * Deliberately does NOT attempt to parse an Alert Rule's condition
 * string to simulate whether it would fire for a given device — that
 * would be this plugin re-implementing LibreNMS's own rule-evaluation
 * engine a second time, exactly the kind of duplicate logic this
 * whole redesign exists to remove. Every check here is either a
 * direct table-existence/membership fact, or an aggregate computed
 * from the ALREADY-COMPUTED, already-correct $device['issues'] every
 * device on this dashboard carries — never a new independent opinion
 * about what should be Critical/Warning.
 */
final class PolicyHealth
{
    public const STATUS_OK = 'ok';
    public const STATUS_WARNING = 'warning';
    public const STATUS_INFO = 'info';

    /**
     * @param  array<int, array{id: int, name: string, severity: string}>  $availableAlertRules
     * @param  array<int, int>  $includedAlertRuleIds
     * @param  Collection<int, array<string, mixed>>  $devices  Every currently authorized, already-normalized device.
     * @return array<int, array{status: string, label: string}>
     */
    public static function evaluate(
        array $availableAlertRules,
        array $includedAlertRuleIds,
        string $operationalCriticalGroupName,
        bool $operationalCriticalGroupResolved,
        int $operationalCriticalDeviceCount,
        Collection $devices
    ): array {
        $checks = [];

        $checks[] = self::operationalCriticalGroupCheck(
            $operationalCriticalGroupName,
            $operationalCriticalGroupResolved,
            $operationalCriticalDeviceCount
        );

        $checks[] = self::alertRuleInventoryCheck($availableAlertRules, $includedAlertRuleIds);
        $checks[] = self::deviceDownRuleCheck($availableAlertRules);
        $checks[] = self::serviceRuleCheck($availableAlertRules);
        $checks[] = self::fallbackCoverageCheck($devices);
        $checks[] = self::overlappingAlertCheck($devices);

        return $checks;
    }

    private static function operationalCriticalGroupCheck(
        string $name,
        bool $resolved,
        int $memberCount
    ): array {
        if ($name === '') {
            return [
                'status' => self::STATUS_INFO,
                'label' => 'No Operational Critical Device Group name is configured — every device currently uses the standard-device default policy.',
            ];
        }

        if (! $resolved) {
            return [
                'status' => self::STATUS_WARNING,
                'label' => 'Operational Critical Device Group "' . $name . '" is configured but does not currently exist in LibreNMS — create it under Devices > Device Groups, or every device will keep using the standard-device default policy.',
            ];
        }

        if ($memberCount === 0) {
            return [
                'status' => self::STATUS_WARNING,
                'label' => 'Operational Critical Device Group "' . $name . '" exists but has no members — add your clusters, critical servers and other must-page infrastructure to it.',
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'label' => 'Operational Critical Device Group "' . $name . '" exists with ' . $memberCount . ' member device' . ($memberCount === 1 ? '' : 's') . '.',
        ];
    }

    /** @param array<int, array{id: int, name: string, severity: string}> $availableAlertRules */
    private static function alertRuleInventoryCheck(array $availableAlertRules, array $includedAlertRuleIds): array
    {
        $total = count($availableAlertRules);

        if ($total === 0) {
            return [
                'status' => self::STATUS_WARNING,
                'label' => 'No LibreNMS Alert Rules were found at all — every Critical/Warning severity on this dashboard currently comes from the default fallback policy, never an administrator-selected rule.',
            ];
        }

        $includedCount = count($includedAlertRuleIds);

        return [
            'status' => self::STATUS_OK,
            'label' => $includedCount . ' of ' . $total . ' LibreNMS Alert Rule' . ($total === 1 ? '' : 's') . ' currently feed this dashboard.',
        ];
    }

    /** @param array<int, array{id: int, name: string, severity: string}> $availableAlertRules */
    private static function deviceDownRuleCheck(array $availableAlertRules): array
    {
        $matches = array_filter(
            $availableAlertRules,
            static fn (array $rule): bool => str_contains(strtolower($rule['name']), 'down')
        );

        if ($matches === []) {
            return [
                'status' => self::STATUS_INFO,
                'label' => 'No Alert Rule name contains "down" — Device Down severity currently comes entirely from the default fallback policy. This is only informational: a rule may exist under a different name.',
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'label' => count($matches) . ' possible Device Down Alert Rule(s) found by name: ' . implode(', ', array_map(static fn (array $rule): string => $rule['name'], $matches)) . '.',
        ];
    }

    /** @param array<int, array{id: int, name: string, severity: string}> $availableAlertRules */
    private static function serviceRuleCheck(array $availableAlertRules): array
    {
        $matches = array_filter(
            $availableAlertRules,
            static fn (array $rule): bool => str_contains(strtolower($rule['name']), 'service')
        );

        if ($matches === []) {
            return [
                'status' => self::STATUS_INFO,
                'label' => 'No Alert Rule name contains "service" — service check severity currently comes entirely from the default fallback policy (service_status is always shown regardless). This is only informational: a rule may exist under a different name.',
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'label' => count($matches) . ' possible Service Alert Rule(s) found by name: ' . implode(', ', array_map(static fn (array $rule): string => $rule['name'], $matches)) . '.',
        ];
    }

    /** @param Collection<int, array<string, mixed>> $devices */
    private static function fallbackCoverageCheck(Collection $devices): array
    {
        $fallbackDeviceCount = $devices->filter(
            fn (array $device): bool => $device['issues']->contains(
                fn (array $issue): bool => $issue['actionable']
                    && in_array($issue['source'], ['device', 'sensor', 'service'], true)
                    && in_array($issue['severity'], ['critical', 'warning'], true)
            )
        )->count();

        if ($fallbackDeviceCount === 0) {
            return [
                'status' => self::STATUS_OK,
                'label' => 'No currently visible technical condition is relying on the default fallback policy — every Critical/Warning issue right now comes from a real Alert Rule.',
            ];
        }

        return [
            'status' => self::STATUS_INFO,
            'label' => $fallbackDeviceCount . ' device' . ($fallbackDeviceCount === 1 ? '' : 's') . ' currently ' . ($fallbackDeviceCount === 1 ? 'has' : 'have') . ' at least one Critical/Warning condition with no matching Alert Rule yet — visible on the dashboard via the default fallback policy, never hidden.',
        ];
    }

    /** @param Collection<int, array<string, mixed>> $devices */
    private static function overlappingAlertCheck(Collection $devices): array
    {
        $overlapping = $devices->filter(
            fn (array $device): bool => $device['issues']->filter(
                fn (array $issue): bool => $issue['source'] === 'alert'
            )->count() > 1
        )->count();

        if ($overlapping === 0) {
            return [
                'status' => self::STATUS_OK,
                'label' => 'No device currently has more than one active Alert Rule firing at the same time.',
            ];
        }

        return [
            'status' => self::STATUS_WARNING,
            'label' => $overlapping . ' device' . ($overlapping === 1 ? '' : 's') . ($overlapping === 1 ? ' has' : ' have') . ' more than one active Alert Rule firing at once — verify this is intentional and not two overlapping rules covering the same condition.',
        ];
    }
}
