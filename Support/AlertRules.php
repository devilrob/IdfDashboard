<?php

declare(strict_types=1);

namespace App\Plugins\IdfDashboard\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Real LibreNMS Alert Rules (`alert_rules`), exposed as the single
 * source of truth for "which of the organization's own configured
 * rules should feed this dashboard's Alert issues" — replacing the
 * plugin's own hardcoded rule-name guessing with an explicit,
 * admin-chosen selection of real rule IDs.
 *
 * Before this class existed, the plugin silently excluded any alert
 * whose rule *name* contained "sensor over limit" / "sensor under
 * limit" (assumed redundant with this dashboard's own per-sensor
 * telemetry) and silently excluded any alert whose name matched a
 * device/host-down regex (assumed redundant with this dashboard's own
 * device-status detection). Both were guesses based on how one real
 * fleet happened to name its rules — a rename in LibreNMS silently
 * broke the exclusion with no warning, and there was no way for an
 * admin to see or override the guess. That logic is gone; every real
 * rule is now either explicitly included or explicitly excluded by an
 * administrator in Settings, defaulting to "included" until an
 * explicit choice has been saved, so nothing is hidden without the
 * admin knowing.
 */
final class AlertRules
{
    public const SETTING_KEY = 'idf_included_alert_rule_ids';

    /**
     * Every real alert rule currently defined in LibreNMS, ordered by
     * name. Defensive like the rest of this plugin's raw-table reads:
     * degrades to an empty list instead of throwing if the schema
     * does not match what is expected.
     *
     * @return array<int, array{id: int, name: string, severity: string}>
     */
    public static function available(): array
    {
        if (! self::tableExists('alert_rules')) {
            return [];
        }

        $idCol = self::firstExistingColumn('alert_rules', ['id']);
        $nameCol = self::firstExistingColumn('alert_rules', ['name']);

        if ($idCol === null || $nameCol === null) {
            return [];
        }

        $severityCol = self::firstExistingColumn('alert_rules', ['severity']);
        $select = ["$idCol as id", "$nameCol as name"];

        if ($severityCol !== null) {
            $select[] = "$severityCol as severity";
        }

        try {
            $rows = DB::table('alert_rules')->select($select)->orderBy($nameCol)->get();
        } catch (\Throwable) {
            return [];
        }

        return $rows
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'name' => trim((string) $row->name) !== ''
                    ? trim((string) $row->name)
                    : ('Rule #' . (int) $row->id),
                'severity' => trim((string) ($row->severity ?? '')),
            ])
            ->values()
            ->all();
    }

    /**
     * The effective set of rule IDs whose alerts should feed this
     * dashboard. `$rawSetting` is whatever was persisted under
     * self::SETTING_KEY. Absent/null/not-an-array means "no explicit
     * choice has ever been saved", which resolves to every currently
     * known rule (nothing hidden by default). Once a choice has been
     * saved — even an empty one, submitted as an array containing only
     * the settings form's hidden placeholder value — it is respected
     * exactly, intersected against the rules that still exist so a
     * deleted rule's stale ID cannot linger forever.
     *
     * @param  mixed  $rawSetting
     * @param  array<int, array{id: int, name: string, severity: string}>  $availableRules
     * @return array<int, int>
     */
    public static function resolveIncludedIds(mixed $rawSetting, array $availableRules): array
    {
        $availableIds = array_map(static fn (array $rule): int => $rule['id'], $availableRules);

        if (! is_array($rawSetting)) {
            return $availableIds;
        }

        $selected = [];

        foreach ($rawSetting as $value) {
            if (is_numeric($value)) {
                $selected[] = (int) $value;
            }
        }

        return array_values(array_intersect($availableIds, array_unique($selected)));
    }

    private static function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function firstExistingColumn(string $table, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            try {
                if (Schema::hasColumn($table, $candidate)) {
                    return $candidate;
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
