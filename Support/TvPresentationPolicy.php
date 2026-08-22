<?php

declare(strict_types=1);

namespace App\Plugins\IdfDashboard\Support;

use Illuminate\Support\Collection;

/**
 * TV Mode is a wall/NOC projection, not the normal dashboard rendered
 * full-screen — its purpose is "show only what deserves immediate
 * visual attention," a narrower and different question than "is this
 * technically actionable." This class answers exactly that second
 * question, and nothing else:
 *
 *   Normal Dashboard (Support\OperationalPolicy + ProblemPolicy)
 *   → severity, actionable, Priority Attention — operational truth
 *           ↓
 *   TV Presentation Policy (this class)
 *   → Show / Do Not Show on TV — presentation only
 *
 * This is deliberately a PURE FILTER over already-computed, already-
 * normalized issues (Support\IssueBuilder::make() output, each already
 * carrying 'condition_bucket' — see Support\ConditionBucket). It never
 * re-derives severity, never re-classifies a condition, never queries
 * anything, and never mutates the issue it is given — TV exclusion can
 * therefore never change device health, location health, normal
 * Priority Attention, header counters, Alert Rule inclusion, or
 * telemetry, by construction (this class has no code path that touches
 * any of those).
 *
 * Filtering happens PER ISSUE ("cause"), not per device: a device with
 * three simultaneous conditions where only one is TV-eligible still
 * appears on TV, credited to that one eligible cause — see
 * eligibleCauses()'s own docblock.
 */
final class TvPresentationPolicy
{
    public const SEVERITY_SETTING_KEYS = [
        Severity::CRITICAL => 'tv_show_severity_critical',
        Severity::WARNING => 'tv_show_severity_warning',
    ];

    /**
     * Every issue in $issues that TV Mode is allowed to project:
     * actionable (Monitor issues — actionable=false — are excluded by
     * this check alone, with no dedicated "show Monitor on TV" setting
     * needed: Monitor's entire purpose is staying out of the actionable
     * rotation, on the normal dashboard and TV alike), severity is
     * Critical or Warning (Stale/Unknown/Healthy are governed by the
     * pre-existing tv_hide_* settings elsewhere, not this class — see
     * Support\Config's own docblock on that separation) and not
     * disabled via tv_show_severity_critical/warning, and the issue's
     * own condition_bucket is enabled for TV via
     * tv_show_condition_{bucket}.
     *
     * @param  Collection<int, array<string, mixed>>  $issues  A device's normalized issues (IssueBuilder::make() output).
     * @param  array<string, mixed>  $tvConditionConfig  The resolved tv_show_severity_ and tv_show_condition_ slice of Support\Config.
     * @return Collection<int, array<string, mixed>> sorted worst-first (same [priority, key] ordering Page::buildPriorityAttention() uses)
     */
    public static function eligibleCauses(Collection $issues, array $tvConditionConfig): Collection
    {
        return $issues
            ->filter(function (array $issue) use ($tvConditionConfig): bool {
                if (! (bool) ($issue['actionable'] ?? false)) {
                    return false;
                }

                $severity = (string) ($issue['severity'] ?? '');
                $severitySettingKey = self::SEVERITY_SETTING_KEYS[$severity] ?? null;

                if ($severitySettingKey === null) {
                    // Not Critical/Warning — governed by the pre-existing
                    // tv_hide_* settings, never by this class.
                    return false;
                }

                if (! (bool) ($tvConditionConfig[$severitySettingKey] ?? true)) {
                    return false;
                }

                $bucket = (string) ($issue['condition_bucket'] ?? ConditionBucket::OTHER);

                return (bool) ($tvConditionConfig['tv_show_condition_' . $bucket] ?? false);
            })
            ->sortBy(fn (array $issue): array => [$issue['priority'], $issue['key']])
            ->values();
    }

    /**
     * Whether at least one of this device's issues is TV-eligible — the
     * device-level admission decision for TV's card grid. A device is
     * never excluded from TV wholesale just because ONE of several
     * conditions is TV-disabled; it is excluded only when NONE of its
     * conditions are TV-eligible.
     *
     * @param  Collection<int, array<string, mixed>>  $issues
     * @param  array<string, mixed>  $tvConditionConfig
     */
    public static function deviceHasEligibleCause(Collection $issues, array $tvConditionConfig): bool
    {
        return self::eligibleCauses($issues, $tvConditionConfig)->isNotEmpty();
    }
}
