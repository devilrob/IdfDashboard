<?php

namespace App\Plugins\IdfDashboard;

use App\Plugins\Hooks\SettingsHook;
use App\Plugins\IdfDashboard\Support\Config;

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
    public function data(array $settings = []): array
    {
        return [
            'settings' => $settings,
            'resolved' => Config::resolve($settings),
            'groups' => Config::grouped(),
        ];
    }
}
