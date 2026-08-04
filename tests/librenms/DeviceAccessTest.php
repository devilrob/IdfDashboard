<?php

declare(strict_types=1);

namespace LibreNMS\Tests\Feature\Plugins\IdfDashboard;

use App\Facades\Permissions;
use App\Http\Controllers\PluginSettingsController;
use App\Models\Device;
use App\Models\Location;
use App\Models\Plugin;
use App\Models\User;
use App\Plugins\IdfDashboard\Menu;
use App\Plugins\IdfDashboard\Page;
use App\Plugins\IdfDashboard\Settings;
use App\Plugins\IdfDashboard\Support\DeviceAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use LibreNMS\Tests\TestCase;
use Mockery;
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
        config()->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
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
        $allowed = Device::factory()->create([
            'hostname' => 'allowed-device.example.com',
            'location_id' => $allowedLocation->id,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        Device::factory()->create([
            'hostname' => 'hidden-device.example.com',
            'location_id' => $hiddenLocation->id,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        $user = User::factory()->create(['enabled' => 1]);
        $user->assignRole('user');
        $user->devicesOwned()->attach($allowed->device_id);
        Permissions::invalidateCache();

        $query = DeviceAccess::query($user);

        $this->assertTrue((new Page())->authorize($user));
        $this->assertSame([$allowed->device_id], (clone $query)->pluck('device_id')->all());
        $this->assertSame([$allowedLocation->id], (clone $query)->pluck('location_id')->all());

        $request = Request::create('/plugin/IdfDashboard');
        $request->setUserResolver(fn (): User => $user);
        $payload = (new Page())->data([], $request);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $payload['summary']['active_devices']);
        $this->assertStringContainsString('allowed-device.example.com', $encoded);
        $this->assertStringNotContainsString('hidden-device.example.com', $encoded);
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
        $this->assertTrue((new Menu())->authorize($unprivileged));
        $this->assertTrue((new Page())->authorize($unprivileged));
        $this->assertTrue((new Settings())->authorize($unprivileged));

        $request = Request::create('/plugin/IdfDashboard');
        $request->setUserResolver(fn (): User => $user);
        $payload = (new Page())->data([], $request);

        $this->assertSame(0, $payload['summary']['active_devices']);
        $this->assertCount(0, $payload['locations']);
        $this->assertCount(0, $payload['otherLocations']);
        $this->assertSame(0, $payload['priorityAttention']['total']);
        $this->assertSame([], $payload['priorityAttention']['items']);
    }

    public function testLibreNmsControllerStillProtectsPluginSettings(): void
    {
        $plugin = Plugin::query()->create([
            'plugin_name' => 'IdfDashboard',
            'plugin_active' => 1,
            'version' => 2,
            'settings' => [],
        ]);
        $user = User::factory()->create(['enabled' => 1]);
        $user->assignRole('user');

        $this->actingAs($user);
        $this->expectException(AuthorizationException::class);

        app(PluginSettingsController::class)(
            Mockery::mock(PluginManagerInterface::class),
            $plugin
        );
    }
}
