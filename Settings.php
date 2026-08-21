<?php

namespace App\Plugins\IdfDashboard;

use App\Models\User;
use App\Plugins\Hooks\SettingsHook;
use App\Plugins\IdfDashboard\Support\AlertRules;
use App\Plugins\IdfDashboard\Support\Config;
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
        $alertRuleConditionCoverage = AlertRules::resolveConditionCoverage(
            $settings[AlertRules::CONDITION_SETTING_KEY] ?? null,
            $availableAlertRules
        );

        return [
            'settings' => $settings,
            'resolved' => $resolved,
            'groups' => Config::grouped(),
            'availableAlertRules' => $availableAlertRules,
            'includedAlertRuleIds' => $includedAlertRuleIds,
            'alertRuleSettingKey' => AlertRules::SETTING_KEY,
            'alertRuleConditionCoverage' => $alertRuleConditionCoverage,
            'alertRuleConditionSettingKey' => AlertRules::CONDITION_SETTING_KEY,
            'alertRuleConditionCategories' => [
                AlertRules::CATEGORY_DEVICE_DOWN => 'Device Down',
                AlertRules::CATEGORY_SENSOR => 'Sensors',
                AlertRules::CATEGORY_SERVICE => 'Services',
            ],
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
