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
}
