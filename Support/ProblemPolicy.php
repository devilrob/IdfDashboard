<?php

namespace App\Plugins\IdfDashboard\Support;

final class ProblemPolicy
{
    public static function metricEnabled(
        array $enabledProblemTypes,
        string $problemType,
        bool $stale
    ): bool {
        if (! ($enabledProblemTypes[$problemType] ?? true)) {
            return false;
        }

        return self::staleEnabled($enabledProblemTypes) || ! $stale;
    }

    public static function staleEscalates(
        array $enabledProblemTypes,
        string $role,
        bool $hasStaleHealthyCuratedMetric
    ): bool {
        return self::staleEnabled($enabledProblemTypes)
            && in_array($role, ['pdu', 'ups'], true)
            && $hasStaleHealthyCuratedMetric;
    }

    public static function staleEnabled(array $enabledProblemTypes): bool
    {
        return $enabledProblemTypes['stale'] ?? true;
    }

    /**
     * Single centralized visibility decision, consumed identically by
     * Priority Attention, TV Mode's server-filtered collections and any
     * future view — instead of each independently re-deriving whether a
     * device belongs on screen from the raw config array. `$policy` is
     * `Config::visibilityPolicy()`'s output for the calling context
     * ('global' for Overview/Priority Attention, 'tv' for TV Mode, which
     * additionally intersects the `tv_hide_*` restrictions).
     *
     * @param  array<string, mixed>  $device  A normalizeDevice() row: needs
     *                                        'health' and 'problem_types'.
     * @param  array<string, bool|int>  $policy
     */
    public static function deviceVisible(array $device, array $policy): bool
    {
        $health = (string) ($device['health'] ?? 'healthy');

        if (! (bool) ($policy[$health] ?? true)) {
            return false;
        }

        if ($health === 'healthy') {
            return true;
        }

        $problemTypes = $device['problem_types'] ?? [];

        if ($problemTypes === []) {
            return (bool) ($policy['other'] ?? true);
        }

        foreach ($problemTypes as $problemType) {
            if ((bool) ($policy[self::policyKey($problemType)] ?? true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 'stale' is deliberately two different policy keys: the device-level
     * severity toggle (`$policy['stale']`, from default_severity_stale)
     * and the per-issue problem-type toggle (`$policy['problem_stale']`,
     * from default_problem_stale) mean different things but share the
     * same string inside a device's `problem_types` array. Every other
     * problem type has no such collision. See the identical
     * `problemPolicyKey()` mapping in page.blade.php's embedded JS.
     */
    private static function policyKey(string $problemType): string
    {
        return $problemType === 'stale' ? 'problem_stale' : $problemType;
    }
}
