<?php

namespace App\Plugins\IdfDashboard\Support;

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class DeviceAccess
{
    /**
     * Use LibreNMS's own authorization scope. It grants every device to
     * admin/global-read users and emits a device-permission SQL predicate
     * for itemized users. Downstream queries must use IDs from this query.
     */
    public static function query(User $user): Builder
    {
        return Device::query()->hasAccess($user);
    }
}
