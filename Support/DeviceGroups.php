<?php

declare(strict_types=1);

namespace App\Plugins\IdfDashboard\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Real LibreNMS Device Groups (`device_groups`), exposed as the single
 * source of truth for "which of the organization's own real Device
 * Groups count as Operational Critical" — replacing the old free-text,
 * exact-name-match setting (a single group, matched case-insensitively
 * by name, silently broken by a rename) with an explicit multi-select
 * of real group IDs.
 *
 * `device_group_device` is already correctly populated for BOTH static
 * and dynamic LibreNMS Device Groups (LibreNMS's own DeviceGroup model
 * calls updateDevices()->sync() for dynamic groups whenever their rules
 * or membership change), so reading that join table directly — exactly
 * as this class does — is correct for either group type without this
 * plugin needing to evaluate a dynamic group's own rule query itself.
 *
 * This is the single authority for Device Group parsing/resolution —
 * Page.php, Settings.php and Support\PolicyHealth all call into this
 * class rather than each re-implementing name/ID resolution.
 */
final class DeviceGroups
{
    public const SETTING_KEY = 'operational_critical_group_ids';

    /**
     * Every real Device Group currently defined in LibreNMS, ordered by
     * name. Defensive like Support\AlertRules::available() — degrades
     * to an empty list instead of throwing if the schema does not
     * match what is expected.
     *
     * @return array<int, array{id: int, name: string, type: string}>
     */
    public static function available(): array
    {
        if (! self::tableExists('device_groups')) {
            return [];
        }

        $idCol = self::firstExistingColumn('device_groups', ['id']);
        $nameCol = self::firstExistingColumn('device_groups', ['name']);

        if ($idCol === null || $nameCol === null) {
            return [];
        }

        $typeCol = self::firstExistingColumn('device_groups', ['type']);
        $select = ["$idCol as id", "$nameCol as name"];

        if ($typeCol !== null) {
            $select[] = "$typeCol as type";
        }

        try {
            $rows = DB::table('device_groups')->select($select)->orderBy($nameCol)->get();
        } catch (\Throwable) {
            return [];
        }

        return $rows
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'name' => trim((string) $row->name) !== ''
                    ? trim((string) $row->name)
                    : ('Group #' . (int) $row->id),
                'type' => trim((string) ($row->type ?? '')),
            ])
            ->values()
            ->all();
    }

    /**
     * The effective set of Device Group IDs whose membership counts as
     * Operational Critical, for one request. Single authority for the
     * "new multi-select vs. legacy single-name setting" decision (see
     * this class's own docblock):
     *
     * - If an administrator has ever saved the new multi-select (the
     *   key is present in `$settings`, even as an explicitly-empty
     *   selection — the Settings form always posts a hidden placeholder
     *   so "chose nothing" is distinguishable from "never saved"), that
     *   selection is the sole authority from then on. The legacy name
     *   is never consulted again once this key exists.
     * - Otherwise, if the legacy `operational_critical_group_name`
     *   resolves to EXACTLY ONE real group (case-insensitive match),
     *   that group is used for this request only — nothing is written
     *   back, no migration runs on a page read.
     * - Any other case (legacy name blank, matches zero groups, or
     *   matches more than one) resolves to an empty selection — the
     *   same fail-safe direction the old name-based lookup already
     *   had (every device defaults to the "normal" tier, never a
     *   silently wrong Critical).
     *
     * @param  array<string, mixed>  $settings  The raw, unresolved settings array (not Config::resolve()'d) — this is the only place that needs to distinguish "key absent" from "key present but empty".
     * @param  array<int, array{id: int, name: string, type: string}>  $availableGroups
     * @return array<int, int>
     */
    public static function resolveEffectiveGroupIds(array $settings, array $availableGroups, string $legacyGroupName): array
    {
        if (array_key_exists(self::SETTING_KEY, $settings)) {
            return self::resolveSelectedIds($settings[self::SETTING_KEY], $availableGroups);
        }

        $legacyGroupName = trim($legacyGroupName);

        if ($legacyGroupName === '') {
            return [];
        }

        $legacyId = self::resolveLegacyNameGroupId($legacyGroupName, $availableGroups);

        return $legacyId !== null ? [$legacyId] : [];
    }

    /**
     * Normalizes a raw persisted multi-select value into a de-duplicated
     * list of real, currently-existing group IDs. Invalid entries
     * (non-numeric, or an ID that no longer exists — e.g. a deleted
     * group) are silently dropped rather than causing an error; a
     * duplicate ID appears only once.
     *
     * @param  mixed  $rawSetting
     * @param  array<int, array{id: int, name: string, type: string}>  $availableGroups
     * @return array<int, int>
     */
    public static function resolveSelectedIds(mixed $rawSetting, array $availableGroups): array
    {
        $availableIds = array_map(static fn (array $group): int => $group['id'], $availableGroups);

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
     * Case-insensitive exact-name match against currently-available
     * groups, purely in-memory (no extra query — callers already have
     * `$availableGroups` from available()). Returns null unless exactly
     * one group matches, so an ambiguous or renamed-away legacy name
     * fails safe rather than guessing.
     *
     * @param  array<int, array{id: int, name: string, type: string}>  $availableGroups
     */
    public static function resolveLegacyNameGroupId(string $name, array $availableGroups): ?int
    {
        $matches = array_values(array_filter(
            $availableGroups,
            static fn (array $group): bool => strcasecmp($group['name'], $name) === 0
        ));

        return count($matches) === 1 ? $matches[0]['id'] : null;
    }

    /**
     * Every authorized device ID belonging to ANY of the given group
     * IDs — one bounded query (`whereIn` on both sides), never a
     * per-group or per-device query. A deleted/nonexistent group ID
     * simply matches zero rows; this method never needs to check group
     * existence first.
     *
     * @param  array<int, int>  $groupIds
     * @param  Collection<int, int>  $authorizedDeviceIds
     * @return Collection<int, int>
     */
    public static function memberDeviceIds(array $groupIds, Collection $authorizedDeviceIds): Collection
    {
        if ($groupIds === [] || $authorizedDeviceIds->isEmpty() || ! self::tableExists('device_group_device')) {
            return collect();
        }

        try {
            return DB::table('device_group_device')
                ->whereIn('device_group_id', $groupIds)
                ->whereIn('device_id', $authorizedDeviceIds)
                ->distinct()
                ->pluck('device_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->values();
        } catch (\Throwable) {
            return collect();
        }
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
