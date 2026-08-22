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
     * Persisted setting for the one Alert Rule tag with a real runtime
     * effect: an administrator marking a rule as covering "Device
     * Down". Device Down is the only technical condition whose "entity"
     * is the device itself, so this tag shares a real, exact device_id
     * with the fallback it replaces (see Support\OperationalPolicy's
     * own docblock) — every other condition (a specific sensor or
     * service) has no such exact identifier available on a fired
     * LibreNMS alert in this schema (see Page::loadActiveAlerts()), so
     * this plugin does not offer a tag for them: a tag a user could
     * reasonably read as functional, but that could never safely
     * suppress anything, is worse than no tag at all. Plain list of
     * rule IDs, same shape/semantics as SETTING_KEY above.
     */
    public const DEVICE_DOWN_SETTING_KEY = 'idf_device_down_alert_rule_ids';

    /**
     * Persisted per-rule setting for how a rule's own firing translates
     * into operational severity — the noise-reduction redesign's core
     * new control. See Page::buildDeviceIssues() for exactly how each
     * value is consumed.
     *
     * - HANDLING_DIRECT (the fail-safe default for any rule an admin
     *   has never declared a Handling for): the rule's own
     *   `alert_rules.severity` becomes the issue's severity as-is,
     *   unchanged from this plugin's behavior before this redesign.
     *   Reserved for rules whose condition already IS the
     *   device/hardware state itself (Device Down, Cluster/SNMP Down,
     *   Firewall rules) — there is no finer-grained technical condition
     *   to separate.
     * - HANDLING_CONDITION_POLICY: the rule's own severity is NOT used.
     *   The rule firing proves a real technical condition exists; the
     *   actual severity comes from Support\OperationalPolicy's
     *   condition-bucket policy, applied to whichever sensors/services
     *   are CURRENTLY violating on that device (live data already
     *   loaded for this request — no alert_log lookup, no N+1). If no
     *   recognized violation can be found at all, this fails safe to
     *   HANDLING_DIRECT for that one rule/device (Page::buildDeviceIssues()'s
     *   own fail-safe) — a real failure must never be silently dropped
     *   just because this plugin couldn't correlate it precisely.
     * - HANDLING_MONITOR: the rule firing creates a visible-but-
     *   non-actionable issue (actionable=false, same mechanism as
     *   Support\OperationalPolicy's Monitor outcome) — for
     *   informational/noisy rules (e.g. "Device rebooted") that should
     *   never contribute to device health or Priority Attention.
     *
     * Deliberately no "Ignore" value here — the existing Include column
     * (self::SETTING_KEY) already fully controls whether a rule's
     * alerts reach this plugin at all; a second "ignore" authority
     * would just compete with it.
     */
    public const HANDLING_DIRECT = 'direct';

    public const HANDLING_CONDITION_POLICY = 'condition_policy';

    public const HANDLING_MONITOR = 'monitor';

    public const HANDLING_VALUES = [
        self::HANDLING_DIRECT,
        self::HANDLING_CONDITION_POLICY,
        self::HANDLING_MONITOR,
    ];

    public const HANDLING_SETTING_KEY = 'idf_alert_rule_handling';

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

    /**
     * The administrator-declared set of rule IDs tagged "Device Down" —
     * absent/malformed input resolves to "none tagged" (the fail-safe
     * direction here is "OperationalPolicy still generates its own
     * Device Down fallback", a harmless, clearly-labeled duplicate,
     * never "OperationalPolicy silently stays quiet about a rule that
     * was never actually confirmed to cover it"). Same shape and
     * validation as resolveIncludedIds() above, deliberately: a
     * deleted rule's stale ID cannot linger.
     *
     * @param  mixed  $rawSetting
     * @param  array<int, array{id: int, name: string, severity: string}>  $availableRules
     * @return array<int, int>
     */
    public static function resolveDeviceDownTaggedIds(mixed $rawSetting, array $availableRules): array
    {
        $availableIds = array_map(static fn (array $rule): int => $rule['id'], $availableRules);

        if (! is_array($rawSetting)) {
            return [];
        }

        $selected = [];

        foreach ($rawSetting as $value) {
            if (is_numeric($value)) {
                $selected[] = (int) $value;
            }
        }

        return array_values(array_intersect($availableIds, array_unique($selected)));
    }

    /**
     * The administrator-declared Handling for every currently-available
     * rule — rule_id => one of self::HANDLING_VALUES. A rule with no
     * declared Handling at all (never saved, or a stale/deleted rule
     * ID in the raw setting) resolves to self::HANDLING_DIRECT, the
     * fail-safe default that reproduces this plugin's pre-redesign
     * behavior exactly: an administrator who never opens this control
     * gets no behavior change. An invalid/unrecognized persisted value
     * for a rule that DOES still exist also falls back to
     * HANDLING_DIRECT, never silently to Monitor or Condition policy —
     * the fail-safe direction is always "keep showing the rule's own
     * severity," never "start hiding it."
     *
     * @param  mixed  $rawSetting
     * @param  array<int, array{id: int, name: string, severity: string}>  $availableRules
     * @return array<int, string> rule_id => Handling value, one entry per available rule
     */
    public static function resolveHandling(mixed $rawSetting, array $availableRules): array
    {
        $raw = is_array($rawSetting) ? $rawSetting : [];
        $handling = [];

        foreach ($availableRules as $rule) {
            $ruleId = $rule['id'];
            $declared = $raw[(string) $ruleId] ?? $raw[$ruleId] ?? null;
            $declared = is_string($declared) ? $declared : '';

            $handling[$ruleId] = in_array($declared, self::HANDLING_VALUES, true)
                ? $declared
                : self::HANDLING_DIRECT;
        }

        return $handling;
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
