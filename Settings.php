<?php

namespace App\Plugins\IdfDashboard;

use App\Models\User;
use App\Plugins\Hooks\SettingsHook;
use App\Plugins\IdfDashboard\Support\AlertRules;
use App\Plugins\IdfDashboard\Support\Config;
use App\Plugins\IdfDashboard\Support\ConditionBucket;
use App\Plugins\IdfDashboard\Support\DeviceGroups;
use App\Plugins\IdfDashboard\Support\OperationalPolicy;
use App\Plugins\IdfDashboard\Support\UpdateStatus;
use App\Plugins\IdfDashboard\Support\Version;

/**
 * Implementing this hook is what makes the Settings button on the
 * plugin admin page work at all — without it, LibreNMS falls back to
 * the generic "plugins.missing" view ("Missing view."). See
 * vendor/librenms/plugin-interfaces/src/Hooks/SettingsHook.php and
 * App\Plugins\Hooks\SettingsHook (the abstract base every plugin
 * extends) for how $settings is resolved and injected — the same
 * array is also passed to Page::data(array $settings = []) by the
 * framework's dependency injection, which is how the dashboard reads
 * these values back.
 */
class Settings extends SettingsHook
{
    public function authorize(User $user): bool
    {
        // LibreNMS PluginSettingsController enforces plugin.admin on GET and POST.
        return true;
    }

    public function data(array $settings = []): array
    {
        $resolved = Config::resolve($settings);
        $forceUpdateCheck = request()->boolean('idf_check_updates');

        $availableAlertRules = AlertRules::available();
        $includedAlertRuleIds = AlertRules::resolveIncludedIds(
            $settings[AlertRules::SETTING_KEY] ?? null,
            $availableAlertRules
        );
        $deviceDownTaggedIds = AlertRules::resolveDeviceDownTaggedIds(
            $settings[AlertRules::DEVICE_DOWN_SETTING_KEY] ?? null,
            $availableAlertRules
        );
        $handlingByRuleId = AlertRules::resolveHandling(
            $settings[AlertRules::HANDLING_SETTING_KEY] ?? null,
            $availableAlertRules
        );

        $availableDeviceGroups = DeviceGroups::available();
        $selectedDeviceGroupIds = DeviceGroups::resolveEffectiveGroupIds(
            $settings,
            $availableDeviceGroups,
            (string) $resolved['operational_critical_group_name']
        );

        $policyConfig = array_intersect_key(
            $resolved,
            array_flip(array_keys(array_filter(
                Config::FIELDS,
                static fn (array $field): bool => in_array($field['group'], ['policy', 'advanced'], true)
            )))
        );

        return [
            'settings' => $settings,
            'resolved' => $resolved,
            'groups' => Config::grouped(),
            'availableAlertRules' => $availableAlertRules,
            'includedAlertRuleIds' => $includedAlertRuleIds,
            'alertRuleSettingKey' => AlertRules::SETTING_KEY,
            'deviceDownTaggedIds' => $deviceDownTaggedIds,
            'deviceDownSettingKey' => AlertRules::DEVICE_DOWN_SETTING_KEY,
            'handlingByRuleId' => $handlingByRuleId,
            'handlingSettingKey' => AlertRules::HANDLING_SETTING_KEY,
            'handlingOptions' => [
                AlertRules::HANDLING_DIRECT => 'Direct severity',
                AlertRules::HANDLING_CONDITION_POLICY => 'Condition policy',
                AlertRules::HANDLING_MONITOR => 'Monitor',
            ],
            'availableDeviceGroups' => $availableDeviceGroups,
            'selectedDeviceGroupIds' => $selectedDeviceGroupIds,
            'deviceGroupSettingKey' => DeviceGroups::SETTING_KEY,
            'effectivePolicySummary' => OperationalPolicy::effectivePolicySummary($policyConfig),
            'conditionBucketLabels' => ConditionBucket::labels(),
            'updateStatus' => UpdateStatus::get(
                (bool) $resolved['update_check_enabled'],
                $forceUpdateCheck
            ),
            'updateCheckUrl' => request()->fullUrlWithQuery(['idf_check_updates' => 1]),
            'updateDryRunCommand' => 'php '
                . base_path('app/Plugins/IdfDashboard/bin/update.php')
                . ' --dry-run',
            'updateCommand' => 'php '
                . base_path('app/Plugins/IdfDashboard/bin/update.php')
                . ' --install --keep-backups=5',
            'pluginVersion' => Version::VERSION,
        ];
    }
}
