<?php

namespace App\Plugins\IdfDashboard;

use App\Models\User;
use App\Plugins\Hooks\MenuEntryHook;

class Menu extends MenuEntryHook
{
    public function authorize(
        User $user,
        array $settings = []
    ): bool {
        return $user->can('device.viewAny');
    }

    public function data(array $settings = []): array
    {
        return [];
    }
}
