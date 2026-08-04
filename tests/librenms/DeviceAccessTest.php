<?php

declare(strict_types=1);

namespace LibreNMS\Tests\Feature\Plugins\IdfDashboard;

use App\Facades\Permissions;
use App\Models\Device;
use App\Models\Location;
use App\Models\User;
use App\Plugins\IdfDashboard\Page;
use App\Plugins\IdfDashboard\Support\DeviceAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LibreNMS\Tests\TestCase;
use Spatie\Permission\Models\Role;

class DeviceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');
        Role::findOrCreate('global-read');
        Role::findOrCreate('user');
    }

    public function testAdministratorCanSeeEveryDevice(): void
    {
        $devices = Device::factory()->count(2)->create();
        $user = User::factory()->create(['enabled' => 1]);
        $user->assignRole('admin');

        $this->assertTrue((new Page())->authorize($user));
        $this->assertEqualsCanonicalizing(
            $devices->pluck('device_id')->all(),
            DeviceAccess::query($user)->pluck('device_id')->all()
        );
    }

    public function testGlobalReadUserCanSeeEveryDevice(): void
    {
        $devices = Device::factory()->count(2)->create();
        $user = User::factory()->create(['enabled' => 1]);
        $user->assignRole('global-read');

        $this->assertTrue((new Page())->authorize($user));
        $this->assertEqualsCanonicalizing(
            $devices->pluck('device_id')->all(),
            DeviceAccess::query($user)->pluck('device_id')->all()
        );
    }

    public function testLimitedUserSeesOnlyAuthorizedDeviceAndLocation(): void
    {
        $allowedLocation = Location::factory()->create();
        $hiddenLocation = Location::factory()->create();
        $allowed = Device::factory()->create(['location_id' => $allowedLocation->id]);
        Device::factory()->create(['location_id' => $hiddenLocation->id]);
        $user = User::factory()->create(['enabled' => 1]);
        $user->assignRole('user');
        $user->devicesOwned()->attach($allowed->device_id);
        Permissions::invalidateCache();

        $query = DeviceAccess::query($user);

        $this->assertTrue((new Page())->authorize($user));
        $this->assertSame([$allowed->device_id], (clone $query)->pluck('device_id')->all());
        $this->assertSame([$allowedLocation->id], (clone $query)->pluck('location_id')->all());
    }

    public function testUserWithoutDevicePermissionGetsAnEmptyDeviceSet(): void
    {
        Device::factory()->count(2)->create();
        $user = User::factory()->create(['enabled' => 1]);
        $user->assignRole('user');

        Permissions::invalidateCache();

        $this->assertTrue((new Page())->authorize($user));
        $this->assertSame([], DeviceAccess::query($user)->pluck('device_id')->all());

        $unprivileged = User::factory()->create(['enabled' => 1]);
        $this->assertFalse((new Page())->authorize($unprivileged));
    }
}
