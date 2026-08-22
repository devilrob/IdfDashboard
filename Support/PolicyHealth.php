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
 * Group/condition-policy configuration against real live evidence
 * instead of guessing.
 *
 * Deliberately does NOT attempt to parse an Alert Rule's condition
 * string to simulate whether it would fire for a given device — that
 * would be this plugin re-implementing LibreNMS's own rule-evaluation
 * engine a second time, exactly the kind of duplicate logic this
 * whole redesign exists to remove. Every check here is either a
 * direct table-existence/membership fact, a declared administrator
 * setting, or an aggregate computed from the ALREADY-COMPUTED,
 * already-correct $device['issues']/$device['condition_policy_fail_safe_rule_names']
 * every device on this dashboard carries — never a new independent
 * opinion about what should be Critical/Warning.
 *
 * The old rule-NAME-substring heuristics ("does a rule's name contain
 * 'down'/'service'?") are gone entirely — Support\AlertRules::
 * HANDLING_* is now an explicit, administrator-declared fact per rule,
 * a strictly better signal than guessing from a name, so keeping both
 * would be exactly the duplicated-authority this redesign forbids.
 */
final class PolicyHealth
{
    public const STATUS_OK = 'ok';
    public const STATUS_WARNING = 'warning';
    public const STATUS_INFO = 'info';

    /**
     * @param  array<int, array{id: int, name: string, severity: string}>  $availableAlertRules
     * @param  array<int, int>  $includedAlertRuleIds
     * @param  array<int, string>  $selectedOperationalCriticalGroupNames  Names of the currently selected Operational Critical Device Groups that still resolve to a real group — see Support\DeviceGroups::resolveEffectiveGroupIds().
     * @param  array<string, mixed>  $policyConfig  The resolved Operational Priority policy slice of Support\Config — see Support\Config::FIELDS.
     * @param  Collection<int, array<string, mixed>>  $devices  Every currently authorized, already-normalized device.
     * @param  array<int, int>  $deviceDownTaggedIds  See Support\AlertRules::resolveDeviceDownTaggedIds().
     * @param  array<int, string>  $handlingByRuleId  rule_id => Handling value, see Support\AlertRules::resolveHandling().
     * @return array<int, array{status: string, label: string}>
     */
    public static function evaluate(
        array $availableAlertRules,
        array $includedAlertRuleIds,
        array $selectedOperationalCriticalGroupNames,
        int $operationalCriticalDeviceCount,
        Collection $devices,
        array $deviceDownTaggedIds = [],
        array $handlingByRuleId = [],
        array $policyConfig = []
    ): array {
        $checks = [];

        $checks[] = self::operationalCriticalGroupCheck(
            $selectedOperationalCriticalGroupNames,
            $operationalCriticalDeviceCount
        );

        $checks[] = self::alertRuleInventoryCheck($availableAlertRules, $includedAlertRuleIds);
        $checks[] = self::handlingBreakdownCheck($includedAlertRuleIds, $handlingByRuleId);
        $checks[] = self::deviceDownCoverageCheck($includedAlertRuleIds, $deviceDownTaggedIds);
        $checks[] = self::conditionPolicyCorrelationCheck($devices);
        $checks[] = self::ignoredConditionsCheck($policyConfig);
        $checks[] = self::fallbackCoverageCheck($devices);
        $checks[] = self::overlappingAlertCheck($devices);

        return $checks;
    }

    /** @param array<int, string> $selectedGroupNames */
    private static function operationalCriticalGroupCheck(
        array $selectedGroupNames,
        int $memberCount
    ): array {
        if ($selectedGroupNames === []) {
            return [
                'status' => self::STATUS_INFO,
                'label' => 'No Operational Critical Device Groups are selected — every device currently uses the standard-device default policy.',
            ];
        }

        $names = implode(', ', $selectedGroupNames);

        if ($memberCount === 0) {
            return [
                'status' => self::STATUS_WARNING,
                'label' => 'Operational Critical Device Group(s) ' . $names . ' are selected but have no authorized members — add your clusters, critical servers and other must-page infrastructure to them.',
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'label' => 'Operational Critical Device Group(s) ' . $names . ' resolve to ' . $memberCount . ' member device' . ($memberCount === 1 ? '' : 's') . '.',
        ];
    }

    /** @param array<int, array{id: int, name: string, severity: string}> $availableAlertRules */
    private static function alertRuleInventoryCheck(array $availableAlertRules, array $includedAlertRuleIds): array
    {
        $total = count($availableAlertRules);

        if ($total === 0) {
            return [
                'status' => self::STATUS_WARNING,
                'label' => 'No LibreNMS Alert Rules were found at all — every Critical/Warning severity on this dashboard currently comes from the condition policy, never an administrator-selected rule.',
            ];
        }

        $includedCount = count($includedAlertRuleIds);

        return [
            'status' => self::STATUS_OK,
            'label' => $includedCount . ' of ' . $total . ' LibreNMS Alert Rule' . ($total === 1 ? '' : 's') . ' currently feed this dashboard.',
        ];
    }

    /**
     * How many currently-included rules use each Handling — the
     * explicit, administrator-declared replacement for the old
     * rule-name-substring guessing.
     *
     * @param  array<int, int>  $includedAlertRuleIds
     * @param  array<int, string>  $handlingByRuleId
     */
    private static function handlingBreakdownCheck(array $includedAlertRuleIds, array $handlingByRuleId): array
    {
        if ($includedAlertRuleIds === []) {
            return [
                'status' => self::STATUS_INFO,
                'label' => 'No Alert Rules are currently included, so there is no Handling to report.',
            ];
        }

        $counts = [
            AlertRules::HANDLING_DIRECT => 0,
            AlertRules::HANDLING_CONDITION_POLICY => 0,
            AlertRules::HANDLING_MONITOR => 0,
        ];

        foreach ($includedAlertRuleIds as $ruleId) {
            $handling = $handlingByRuleId[$ruleId] ?? AlertRules::HANDLING_DIRECT;
            $counts[$handling] = ($counts[$handling] ?? 0) + 1;
        }

        return [
            'status' => self::STATUS_OK,
            'label' => $counts[AlertRules::HANDLING_DIRECT] . ' rule(s) Direct severity, '
                . $counts[AlertRules::HANDLING_CONDITION_POLICY] . ' Condition policy, '
                . $counts[AlertRules::HANDLING_MONITOR] . ' Monitor.',
        ];
    }

    /**
     * Reports how many currently-included Alert Rules an administrator
     * has tagged "Device Down" — the only tag this plugin offers, and
     * the only one that ever suppresses a condition-policy issue,
     * because it shares a real, exact device_id with the issue it
     * replaces (see Support\OperationalPolicy's own docblock). This
     * check never treats the tag as proof a rule actually fires for a
     * given device — it simply reports declared intent.
     *
     * @param  array<int, int>  $includedAlertRuleIds
     * @param  array<int, int>  $deviceDownTaggedIds
     */
    private static function deviceDownCoverageCheck(array $includedAlertRuleIds, array $deviceDownTaggedIds): array
    {
        if ($includedAlertRuleIds === []) {
            return [
                'status' => self::STATUS_INFO,
                'label' => 'No Alert Rules are currently included, so there is nothing to tag "Device Down" yet.',
            ];
        }

        $taggedCount = count(array_intersect($includedAlertRuleIds, $deviceDownTaggedIds));

        if ($taggedCount === 0) {
            return [
                'status' => self::STATUS_INFO,
                'label' => 'No included Alert Rule is tagged "Device Down" yet — the Device Down condition still runs for every device until one is tagged, never hidden.',
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'label' => $taggedCount . ' included Alert Rule' . ($taggedCount === 1 ? '' : 's') . ' currently tagged "Device Down".',
        ];
    }

    /**
     * Whether Condition-policy-Handling rules are actually correlating
     * to a currently-violating sensor/service on the devices they fire
     * for — see Page::buildDeviceIssues()'s own fail-safe docblock. A
     * device carries a non-empty
     * 'condition_policy_fail_safe_rule_names' only when correlation
     * failed and this plugin fell back to that rule's own Direct
     * severity for that one device, so the underlying failure was never
     * silently hidden either way — this check is purely diagnostic,
     * for an administrator to notice a Condition-policy rule that may
     * be scoped to a condition this plugin's own sensor classification
     * cannot recognize.
     *
     * @param  Collection<int, array<string, mixed>>  $devices
     */
    private static function conditionPolicyCorrelationCheck(Collection $devices): array
    {
        $failingRuleNames = $devices
            ->flatMap(fn (array $device): array => $device['condition_policy_fail_safe_rule_names'] ?? [])
            ->unique()
            ->values();

        if ($failingRuleNames->isEmpty()) {
            return [
                'status' => self::STATUS_OK,
                'label' => 'Every currently firing Condition-policy Alert Rule is correlating to at least one currently-violating sensor/service.',
            ];
        }

        return [
            'status' => self::STATUS_WARNING,
            'label' => $failingRuleNames->count() . ' Condition-policy Alert Rule(s) currently failing safe to their own Direct severity (could not correlate to a currently-violating sensor/service on the device they fired for): '
                . $failingRuleNames->implode(', ') . '. The underlying condition is still shown, never hidden.',
        ];
    }

    /**
     * How many condition buckets are configured as "Ignore" for the
     * Operational Critical tier — the closest equivalent to the old
     * per-category enabled/disabled toggles, now expressed per bucket
     * rather than per numeric/state axis.
     *
     * @param  array<string, mixed>  $policyConfig
     */
    private static function ignoredConditionsCheck(array $policyConfig): array
    {
        $ignoredLabels = [];

        foreach (ConditionBucket::CONDITION_POLICY_BUCKETS as $bucket) {
            $choice = (string) ($policyConfig['condition_policy_' . $bucket . '_critical_group_severity'] ?? '');

            if ($choice === 'disabled') {
                $ignoredLabels[] = ConditionBucket::labels()[$bucket];
            }
        }

        if ($ignoredLabels === []) {
            return [
                'status' => self::STATUS_OK,
                'label' => 'No condition bucket is set to Ignore for Operational Critical devices.',
            ];
        }

        return [
            'status' => self::STATUS_INFO,
            'label' => 'Ignored for Operational Critical devices: ' . implode(', ', $ignoredLabels) . ' — the underlying technical telemetry remains visible regardless.',
        ];
    }

    /** @param Collection<int, array<string, mixed>> $devices */
    private static function fallbackCoverageCheck(Collection $devices): array
    {
        $conditionPolicyDeviceCount = $devices->filter(
            fn (array $device): bool => $device['issues']->contains(
                fn (array $issue): bool => $issue['actionable']
                    && in_array($issue['source'], ['device', 'sensor', 'service'], true)
                    && in_array($issue['severity'], ['critical', 'warning'], true)
            )
        )->count();

        if ($conditionPolicyDeviceCount === 0) {
            return [
                'status' => self::STATUS_OK,
                'label' => 'No currently visible technical condition is relying on the condition policy alone — every Critical/Warning issue right now also comes from a real Direct-severity Alert Rule.',
            ];
        }

        return [
            'status' => self::STATUS_INFO,
            'label' => $conditionPolicyDeviceCount . ' device' . ($conditionPolicyDeviceCount === 1 ? '' : 's') . ' currently ' . ($conditionPolicyDeviceCount === 1 ? 'has' : 'have') . ' at least one Critical/Warning condition sourced from the condition policy (device/sensor/service) — visible on the dashboard, never hidden.',
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
