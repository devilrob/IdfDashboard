<?php

declare(strict_types=1);

namespace LibreNMS\Tests\Feature\Plugins\IdfDashboard;

use App\Facades\Permissions;
use App\Http\Controllers\PluginSettingsController;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\AlertSchedule;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\DeviceOutage;
use App\Models\Location;
use App\Models\Plugin;
use App\Models\Sensor;
use App\Models\Service;
use App\Models\User;
use App\Plugins\IdfDashboard\Menu;
use App\Plugins\IdfDashboard\Page;
use App\Plugins\IdfDashboard\Settings;
use App\Plugins\IdfDashboard\Support\DeviceAccess;
use App\Plugins\IdfDashboard\Support\IssueBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        Device::factory()->create(['hostname' => 'ignored.example.com', 'disabled' => 0, 'ignore' => 1]);
        Device::factory()->create(['hostname' => 'disabled.example.com', 'disabled' => 1, 'ignore' => 0]);
        $user = User::factory()->create(['enabled' => 1]);
        $user->assignRole('admin');

        $this->assertTrue((new Page())->authorize($user));
        $this->assertEqualsCanonicalizing(
            Device::query()->pluck('device_id')->all(),
            DeviceAccess::query($user)->pluck('device_id')->all()
        );
        $request = Request::create('/plugin/IdfDashboard');
        $request->setUserResolver(fn (): User => $user);
        $payload = (new Page())->data([], $request);
        $this->assertSame($devices->count(), $payload['summary']['active_devices']);
        $this->assertSame(1, $payload['coverage']['ignored_count']);
        $this->assertSame(1, $payload['coverage']['disabled_count']);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('ignored.example.com', $encoded);
        $this->assertStringNotContainsString('disabled.example.com', $encoded);
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
        $hidden = Device::factory()->create([
            'hostname' => 'hidden-device.example.com',
            'ip' => inet_pton('10.250.250.250'),
            'location_id' => $hiddenLocation->id,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        Sensor::factory()->for($hidden)->create([
            'sensor_class' => 'temperature',
            'sensor_descr' => 'HIDDEN-SENSOR-EVIDENCE',
            'sensor_current' => 99,
            'sensor_limit' => 50,
            'sensor_alert' => 1,
            'lastupdate' => now(),
        ]);
        Service::factory()->for($hidden)->create([
            'service_name' => 'HIDDEN-SERVICE-EVIDENCE',
            'service_status' => 2,
            'service_message' => 'HIDDEN-SERVICE-MESSAGE',
        ]);
        $hiddenRule = AlertRule::factory()->create([
            'name' => 'HIDDEN-ALERT-EVIDENCE',
            'severity' => 'critical',
        ]);
        Alert::factory()->create([
            'device_id' => $hidden->device_id,
            'rule_id' => $hiddenRule->id,
        ]);
        $hiddenDownAt = now()->subDays(7)->timestamp;
        DeviceOutage::factory()->for($hidden)->open()->create([
            'going_down' => $hiddenDownAt,
        ]);
        $hiddenSchedule = AlertSchedule::factory()->create([
            'title' => 'HIDDEN-MAINTENANCE-EVIDENCE',
            'start' => now()->subHour(),
            'end' => now()->addHour(),
            'behavior' => 1,
        ]);
        $hiddenSchedule->devices()->attach($hidden->device_id);
        Device::factory()->create([
            'hostname' => 'hidden-ignored.example.com',
            'disabled' => 0,
            'ignore' => 1,
        ]);
        Device::factory()->create([
            'hostname' => 'hidden-disabled.example.com',
            'disabled' => 1,
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
        $this->assertStringNotContainsString('HIDDEN-SENSOR-EVIDENCE', $encoded);
        $this->assertStringNotContainsString('HIDDEN-MAINTENANCE-EVIDENCE', $encoded);
        $this->assertStringNotContainsString('HIDDEN-SERVICE-EVIDENCE', $encoded);
        $this->assertStringNotContainsString('HIDDEN-SERVICE-MESSAGE', $encoded);
        $this->assertStringNotContainsString('HIDDEN-ALERT-EVIDENCE', $encoded);
        $this->assertStringNotContainsString('10.250.250.250', $encoded);
        $this->assertStringNotContainsString((string) $hiddenDownAt, $encoded);
        $this->assertSame(0, $payload['coverage']['ignored_count']);
        $this->assertSame(0, $payload['coverage']['disabled_count']);
        $html = view()->file(
            app_path('Plugins/IdfDashboard/resources/views/page.blade.php'),
            $payload
        )->render();
        $this->writeVisualFixture('limited.html', $html);
        $this->assertStringNotContainsString('hidden-device.example.com', $html);
        $this->assertStringNotContainsString('HIDDEN-SENSOR-EVIDENCE', $html);
        $this->assertStringNotContainsString('HIDDEN-SERVICE-EVIDENCE', $html);
        $this->assertStringNotContainsString('HIDDEN-ALERT-EVIDENCE', $html);
        $this->assertStringNotContainsString((string) $hiddenLocation->location, $html);
    }

    public function testPhaseTwoDeepLinksFiltersAndPaginationNeverCrossDeviceAccess(): void
    {
        $allowedLocation = Location::factory()->create(['location' => 'Authorized Phase 2 Location']);
        $hiddenLocation = Location::factory()->create(['location' => 'SECRET PHASE 2 LOCATION']);
        $allowed = Device::factory()->create([
            'display' => 'Authorized Phase 2 Device',
            'hostname' => 'phase2-allowed.example.com',
            'ip' => '10.10.20.1',
            'location_id' => $allowedLocation->id,
            'type' => 'server',
            'status' => 1,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        $additional = Device::factory()->count(27)->create([
            'location_id' => $allowedLocation->id,
            'type' => 'network',
            'status' => 1,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        $hidden = Device::factory()->create([
            'display' => 'SECRET PHASE 2 DEVICE',
            'hostname' => 'secret-phase2.example.com',
            'ip' => '10.250.20.99',
            'location_id' => $hiddenLocation->id,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        $user = User::factory()->create(['enabled' => 1]);
        $user->assignRole('user');
        $user->devicesOwned()->attach([$allowed->device_id, ...$additional->pluck('device_id')->all()]);
        Permissions::invalidateCache();

        $payloadFor = static function (array $query) use ($user): array {
            $request = Request::create('/plugin/IdfDashboard', 'GET', $query);
            $request->setUserResolver(fn (): User => $user);

            return (new Page())->data([], $request);
        };

        $allowedDevice = $payloadFor(['view' => 'device', 'id' => $allowed->device_id]);
        $this->assertTrue($allowedDevice['viewData']['found']);
        $this->assertSame($allowed->device_id, $allowedDevice['viewData']['device']['device_id']);
        $this->assertSame('10.10.20.1', $allowedDevice['viewData']['device']['ip']);

        $hiddenDevice = $payloadFor(['view' => 'device', 'id' => $hidden->device_id]);
        $this->assertFalse($hiddenDevice['viewData']['found']);
        $this->assertStringNotContainsString('SECRET PHASE 2', json_encode($hiddenDevice, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('10.250.20.99', json_encode($hiddenDevice, JSON_THROW_ON_ERROR));

        $allowedLocationPayload = $payloadFor(['view' => 'location', 'id' => $allowedLocation->id]);
        $this->assertTrue($allowedLocationPayload['viewData']['found']);
        $this->assertSame(28, $allowedLocationPayload['viewData']['devices']['total']);
        $hiddenLocationPayload = $payloadFor(['view' => 'location', 'id' => $hiddenLocation->id]);
        $this->assertFalse($hiddenLocationPayload['viewData']['found']);
        $this->assertStringNotContainsString('SECRET PHASE 2', json_encode($hiddenLocationPayload, JSON_THROW_ON_ERROR));

        $searchPayload = $payloadFor(['view' => 'devices', 'search' => 'SECRET PHASE 2']);
        $this->assertSame(0, $searchPayload['viewData']['devices']['total']);
        $this->assertSame(0, $searchPayload['visibleSummary']['devices']);
        $this->assertStringNotContainsString('secret-phase2.example.com', json_encode($searchPayload, JSON_THROW_ON_ERROR));

        $pageTwo = $payloadFor(['view' => 'devices', 'page' => 2, 'per_page' => 25]);
        $this->assertSame(28, $pageTwo['viewData']['devices']['total']);
        $this->assertSame(28, $pageTwo['visibleSummary']['devices']);
        $this->assertSame(2, $pageTwo['viewData']['devices']['page']);
        $this->assertCount(3, $pageTwo['viewData']['devices']['items']);
        $this->assertStringNotContainsString('secret-phase2.example.com', json_encode($pageTwo, JSON_THROW_ON_ERROR));

        $html = view()->file(
            app_path('Plugins/IdfDashboard/resources/views/page.blade.php'),
            $pageTwo
        )->render();
        $this->assertStringContainsString('26–28 of 28', $html);
        $this->assertStringNotContainsString('secret-phase2.example.com', $html);
        $this->assertStringNotContainsString('<div class="phase2-tv-source">', $html, 'Desktop requests do not render the hidden TV fleet.');
    }

    public function testPhaseTwoLocationsUseDeterministicDefensivePagination(): void
    {
        $user = User::factory()->create(['enabled' => 1]);
        $user->assignRole('admin');

        foreach (range(1, 30) as $index) {
            $location = Location::factory()->create([
                'location' => sprintf('Phase 2 Location %02d', $index),
            ]);
            Device::factory()->create([
                'display' => sprintf('Phase 2 Device %02d', $index),
                'hostname' => sprintf('phase2-device-%02d.example.com', $index),
                'location_id' => $location->id,
                'status' => 1,
                'disabled' => 0,
                'ignore' => 0,
            ]);
        }

        $request = Request::create('/plugin/IdfDashboard', 'GET', [
            'view' => 'locations',
            'page' => 2,
            'per_page' => 25,
        ]);
        $request->setUserResolver(fn (): User => $user);
        $payload = (new Page())->data([], $request);

        $this->assertSame(30, $payload['viewData']['locations']['total']);
        $this->assertSame(2, $payload['viewData']['locations']['page']);
        $this->assertSame(25, $payload['viewData']['locations']['per_page']);
        $this->assertCount(5, $payload['viewData']['locations']['items']);
        $this->assertSame(
            ['Phase 2 Location 26', 'Phase 2 Location 27', 'Phase 2 Location 28', 'Phase 2 Location 29', 'Phase 2 Location 30'],
            collect($payload['viewData']['locations']['items'])->pluck('name')->all()
        );
    }

    public function testPhaseOneOperationalStatesUseAuthorizedLibreNmsData(): void
    {
        $location = Location::factory()->create(['location' => 'Phase 1 Lab']);
        $user = User::factory()->create(['enabled' => 1]);
        $user->assignRole('admin');

        $down = Device::factory()->create([
            'display' => 'Down Router',
            'hostname' => 'down-router.example.com',
            'location_id' => $location->id,
            'type' => 'network',
            'status' => 0,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        DeviceOutage::factory()->for($down)->open()->create([
            'going_down' => now()->subMinutes(12)->timestamp,
        ]);
        DeviceOutage::factory()->for($down)->open()->create([
            'going_down' => now()->subHour()->timestamp,
        ]);

        $maintained = Device::factory()->create([
            'display' => 'Maintained Switch',
            'hostname' => 'maintenance.example.com',
            'location_id' => $location->id,
            'type' => 'network',
            'status' => 0,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        $schedule = AlertSchedule::factory()->create([
            'title' => 'Approved maintenance window',
            'start' => now()->subHour(),
            'end' => now()->addHour(),
            'behavior' => 1,
        ]);
        $schedule->devices()->attach($maintained->device_id);

        $recovered = Device::factory()->create([
            'display' => 'Recovered Server',
            'hostname' => 'recovered.example.com',
            'location_id' => $location->id,
            'type' => 'server',
            'status' => 1,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        DeviceOutage::factory()->for($recovered)->closed()->create([
            'going_down' => now()->subMinutes(20)->timestamp,
            'up_again' => now()->subMinutes(5)->timestamp,
        ]);
        DeviceOutage::factory()->for($recovered)->closed()->create([
            'going_down' => now()->subDays(3)->timestamp,
            'up_again' => now()->subDays(2)->timestamp,
        ]);
        $oldRecovery = Device::factory()->create([
            'display' => 'Old Recovery',
            'hostname' => 'old-recovery.example.com',
            'location_id' => $location->id,
            'type' => 'server',
            'status' => 1,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        DeviceOutage::factory()->for($oldRecovery)->closed()->create([
            'going_down' => now()->subDays(4)->timestamp,
            'up_again' => now()->subDays(3)->timestamp,
        ]);

        $ups = Device::factory()->create([
            'display' => 'UPS Lab',
            'hostname' => 'ups-lab.example.com',
            'location_id' => $location->id,
            'type' => 'power',
            'status' => 1,
            'disabled' => 0,
            'ignore' => 0,
        ]);

        $sensorDevice = Device::factory()->create([
            'display' => 'Sensor Host',
            'hostname' => 'sensor-host.example.com',
            'location_id' => $location->id,
            'type' => 'server',
            'status' => 1,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        Sensor::factory()->for($sensorDevice)->create([
            'sensor_class' => 'temperature',
            'sensor_descr' => 'CPU Temperature',
            'sensor_current' => 40,
            'sensor_limit' => 35,
            'sensor_limit_warn' => 30,
            'sensor_alert' => 1,
            'lastupdate' => now(),
        ]);
        Sensor::factory()->for($sensorDevice)->create([
            'sensor_class' => 'humidity',
            'sensor_descr' => 'Room Humidity',
            'sensor_current' => 72,
            'sensor_limit' => 80,
            'sensor_limit_warn' => 70,
            'sensor_alert' => 1,
            'lastupdate' => now(),
        ]);
        Service::factory()->for($sensorDevice)->create([
            'service_name' => 'SQL Server',
            'service_status' => 2,
            'service_message' => 'Connection refused',
            'service_changed' => now()->subMinutes(8)->timestamp,
        ]);
        Service::factory()->for($sensorDevice)->create([
            'service_name' => 'Backup Agent',
            'service_status' => 1,
            'service_message' => 'Slow response',
            'service_changed' => now()->subMinutes(4)->timestamp,
        ]);
        $criticalRule = AlertRule::factory()->create([
            'name' => 'Vendor power alarm',
            'severity' => 'critical',
        ]);
        Alert::factory()->create([
            'device_id' => $sensorDevice->device_id,
            'rule_id' => $criticalRule->id,
        ]);
        $warningRule = AlertRule::factory()->create([
            'name' => 'Vendor warning alarm',
            'severity' => 'warning',
        ]);
        Alert::factory()->create([
            'device_id' => $sensorDevice->device_id,
            'rule_id' => $warningRule->id,
        ]);
        $duplicateSensorRule = AlertRule::factory()->create([
            'name' => 'Sensor over limit - Check Device Health Settings',
            'severity' => 'critical',
        ]);
        Alert::factory()->create([
            'device_id' => $sensorDevice->device_id,
            'rule_id' => $duplicateSensorRule->id,
        ]);
        $duplicateDownRule = AlertRule::factory()->create([
            'name' => 'Device Down',
            'severity' => 'critical',
        ]);
        Alert::factory()->create([
            'device_id' => $down->device_id,
            'rule_id' => $duplicateDownRule->id,
        ]);

        $warningAlertOnly = Device::factory()->create([
            'display' => 'Warning Alert Only',
            'hostname' => 'warning-alert.example.com',
            'location_id' => $location->id,
            'type' => 'appliance',
            'status' => 1,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        $warningOnlyRule = AlertRule::factory()->create([
            'name' => 'Noncritical vendor warning',
            'severity' => 'warning',
        ]);
        Alert::factory()->create([
            'device_id' => $warningAlertOnly->device_id,
            'rule_id' => $warningOnlyRule->id,
        ]);

        $stateSensor = Sensor::factory()->for($sensorDevice)->create([
            'sensor_class' => 'state',
            'sensor_descr' => 'System Status',
            'sensor_current' => 2,
            'sensor_alert' => 1,
            'lastupdate' => now(),
        ]);
        $stateIndexId = DB::table('state_indexes')->insertGetId(['state_name' => 'phase1-state']);
        DB::table('sensors_to_state_indexes')->insert([
            'sensor_id' => $stateSensor->sensor_id,
            'state_index_id' => $stateIndexId,
        ]);
        DB::table('state_translations')->insert([
            'state_index_id' => $stateIndexId,
            'state_descr' => 'failed',
            'state_value' => 2,
            'state_generic_value' => 2,
        ]);

        $queryCount = 0;
        DB::listen(static function () use (&$queryCount): void {
            $queryCount++;
        });
        $start = hrtime(true);
        $memoryBefore = memory_get_usage(true);
        $request = Request::create('/plugin/IdfDashboard');
        $request->setUserResolver(fn (): User => $user);
        $payload = (new Page())->data([], $request);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        $memoryDelta = max(0, memory_get_peak_usage(true) - $memoryBefore);
        $dataQueryCount = $queryCount;
        $html = view()->file(
            app_path('Plugins/IdfDashboard/resources/views/page.blade.php'),
            $payload
        )->render();
        $this->writeVisualFixture('global.html', $html);
        $htmlBytes = strlen($html);

        $devices = collect($payload['otherLocations'])
            ->flatMap(fn (array $group) => $group['devices'])
            ->keyBy('device_id');

        $downData = $devices->get($down->device_id);
        $maintenanceData = $devices->get($maintained->device_id);
        $recoveredData = $devices->get($recovered->device_id);
        $oldRecoveryData = $devices->get($oldRecovery->device_id);
        $upsData = $devices->get($ups->device_id);
        $sensorData = $devices->get($sensorDevice->device_id);
        $warningAlertData = $devices->get($warningAlertOnly->device_id);

        $this->assertSame('critical', $downData['health']);
        $this->assertGreaterThanOrEqual(660, $downData['down_age_seconds']);
        $this->assertLessThan(900, $downData['down_age_seconds']);
        $this->assertStringContainsString('unavailable for', $downData['issues']->first()['description']);
        $this->assertSame('maintenance', $maintenanceData['health']);
        $this->assertFalse($maintenanceData['issues']->contains('type', 'device_down'));
        $this->assertTrue($recoveredData['recovered_recently']);
        $this->assertFalse($oldRecoveryData['recovered_recently']);
        $this->assertSame('Power', $upsData['category']);
        $this->assertGreaterThan(0, $upsData['no_sensor_count']);
        $this->assertTrue($sensorData['issues']->contains(
            fn (array $issue): bool => str_contains($issue['description'], '104')
                && str_contains($issue['description'], '95')
        ));
        $this->assertTrue($sensorData['issues']->contains(
            fn (array $issue): bool => $issue['type'] === 'state'
                && str_contains($issue['description'], 'failed')
        ));
        $this->assertTrue($sensorData['issues']->contains(
            fn (array $issue): bool => $issue['source'] === 'service'
                && $issue['severity'] === 'critical'
                && str_contains($issue['description'], 'Connection refused')
        ));
        $this->assertTrue($sensorData['issues']->contains(
            fn (array $issue): bool => $issue['source'] === 'alert'
                && $issue['severity'] === 'critical'
                && str_contains($issue['description'], 'Vendor power alarm')
        ));
        $this->assertFalse($sensorData['issues']->contains(
            fn (array $issue): bool => str_contains($issue['description'], 'Sensor over limit')
        ));
        $this->assertCount(1, $downData['issues']->where('type', 'device_down'));
        $this->assertFalse($downData['issues']->contains(
            fn (array $issue): bool => str_contains($issue['description'], 'Device Down')
        ));
        $this->assertSame('warning', $warningAlertData['health']);
        $this->assertFalse(collect($payload['priorityAttention']['items'])->contains(
            'device_id',
            $warningAlertOnly->device_id
        ));
        $requiredIssueFields = [
            'key', 'device_id', 'severity', 'priority', 'source', 'type',
            'title', 'description', 'timestamp', 'actionable', 'device_url',
        ];

        foreach ($devices as $deviceData) {
            foreach ($deviceData['issues']->whereIn('severity', ['critical', 'warning']) as $issue) {
                $this->assertEqualsCanonicalizing(
                    $requiredIssueFields,
                    array_values(array_intersect($requiredIssueFields, array_keys($issue)))
                );
                $this->assertNotSame('', $issue['title']);
                $this->assertNotSame('', $issue['description']);
                $this->assertSame($deviceData['device_id'], $issue['device_id']);
            }
        }
        $this->assertSame('device_down', $payload['priorityAttention']['items'][0]['type']);
        $this->assertSame(IssueBuilder::PRIORITY_DEVICE_DOWN, $payload['priorityAttention']['items'][0]['priority']);
        $this->assertLessThan(80, $dataQueryCount, 'Phase 1 remains fixed-query and avoids N+1 behavior.');
        $this->assertLessThan(5000, $elapsedMs, 'Fixture Page::data() remains within a defensive local ceiling.');
        $this->assertLessThan(64 * 1024 * 1024, $memoryDelta, 'Fixture Page::data() memory delta remains bounded.');
        $this->assertLessThan(2 * 1024 * 1024, $htmlBytes, 'Fixture HTML remains within a defensive ceiling.');
        $this->assertStringContainsString('Device down — unavailable for', $html);
        $this->assertStringContainsString('No sensor installed', $html);

        fwrite(STDOUT, sprintf(
            "Phase 1 metrics: devices=%d sensors=%d issues=%d queries=%d data_ms=%.2f memory_delta=%d html_bytes=%d\n",
            $payload['summary']['active_devices'],
            Sensor::query()->whereIn('device_id', $devices->keys())->count(),
            collect($devices)->sum(fn (array $row): int => $row['issues']->count()),
            $dataQueryCount,
            $elapsedMs,
            $memoryDelta,
            $htmlBytes
        ));
    }

    public function testUserWithoutDevicePermissionGetsAnEmptyDeviceSet(): void
    {
        $hidden = Device::factory()->create([
            'hostname' => 'NO-ACCESS-DEVICE.example.com',
            'disabled' => 0,
            'ignore' => 0,
        ]);
        Sensor::factory()->for($hidden)->create([
            'sensor_descr' => 'NO-ACCESS-SENSOR',
            'sensor_current' => 123,
            'lastupdate' => now(),
        ]);
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
        $this->assertSame(0, $payload['summary']['no_sensor_installed']);
        $this->assertSame(0, $payload['summary']['recent_recoveries']);
        $this->assertSame(0, $payload['coverage']['ignored_count']);
        $this->assertSame(0, $payload['coverage']['disabled_count']);
        $this->assertStringNotContainsString('sensor_current', json_encode($payload, JSON_THROW_ON_ERROR));
        $html = view()->file(
            app_path('Plugins/IdfDashboard/resources/views/page.blade.php'),
            $payload
        )->render();
        $this->writeVisualFixture('empty.html', $html);
        $this->assertStringNotContainsString('NO-ACCESS-DEVICE', $html);
        $this->assertStringNotContainsString('NO-ACCESS-SENSOR', $html);
    }

    public function testMaintenanceAliasesAndWindowsStayInsideAuthorizedDevices(): void
    {
        $location = Location::factory()->create(['location' => 'Authorized Maintenance Location']);
        $hiddenLocation = Location::factory()->create(['location' => 'HIDDEN MAINTENANCE LOCATION']);
        $makeDevice = static fn (string $hostname, ?int $locationId = null): Device => Device::factory()->create([
            'hostname' => $hostname,
            'location_id' => $locationId,
            'type' => 'network',
            'status' => 0,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        $direct = $makeDevice('maintenance-direct.example.com');
        $byLocation = $makeDevice('maintenance-location.example.com', $location->id);
        $byGroup = $makeDevice('maintenance-group.example.com');
        $expired = $makeDevice('maintenance-expired.example.com');
        $future = $makeDevice('maintenance-future.example.com');
        $hidden = $makeDevice('HIDDEN-MAINTENANCE-DEVICE.example.com', $hiddenLocation->id);

        $activeValues = [
            'start' => now()->subHour(),
            'end' => now()->addHour(),
            'behavior' => 1,
        ];
        $directSchedule = AlertSchedule::factory()->create(['title' => 'Direct device'] + $activeValues);
        $directSchedule->devices()->attach($direct->device_id);
        $locationSchedule = AlertSchedule::factory()->create(['title' => 'Inherited location'] + $activeValues);
        $locationSchedule->locations()->attach($location->id);
        $group = DeviceGroup::factory()->create(['name' => 'Phase 1 maintenance group']);
        $group->devices()->attach($byGroup->device_id);
        $groupSchedule = AlertSchedule::factory()->create(['title' => 'Inherited group'] + $activeValues);
        $groupSchedule->deviceGroups()->attach($group->id);
        $expiredSchedule = AlertSchedule::factory()->create([
            'title' => 'Expired window',
            'start' => now()->subHours(2),
            'end' => now()->subHour(),
            'behavior' => 1,
        ]);
        $expiredSchedule->devices()->attach($expired->device_id);
        $futureSchedule = AlertSchedule::factory()->create([
            'title' => 'Future window',
            'start' => now()->addHour(),
            'end' => now()->addHours(2),
            'behavior' => 1,
        ]);
        $futureSchedule->devices()->attach($future->device_id);
        $hiddenSchedule = AlertSchedule::factory()->create(['title' => 'HIDDEN SCHEDULE'] + $activeValues);
        $hiddenSchedule->locations()->attach($hiddenLocation->id);

        $this->assertEqualsCanonicalizing(
            ['device', 'device_group', 'location'],
            DB::table('alert_schedulables')->distinct()->pluck('alert_schedulable_type')->all()
        );

        $user = User::factory()->create(['enabled' => 1]);
        $user->assignRole('user');
        $user->devicesOwned()->attach([
            $direct->device_id,
            $byLocation->device_id,
            $byGroup->device_id,
            $expired->device_id,
            $future->device_id,
        ]);
        Permissions::invalidateCache();
        $request = Request::create('/plugin/IdfDashboard');
        $request->setUserResolver(fn (): User => $user);
        $payload = (new Page())->data([], $request);
        $devices = collect($payload['otherLocations'])
            ->flatMap(fn (array $group): mixed => $group['devices'])
            ->keyBy('device_id');

        $this->assertTrue($devices->get($direct->device_id)['maintenance']);
        $this->assertTrue($devices->get($byLocation->device_id)['maintenance']);
        $this->assertTrue($devices->get($byGroup->device_id)['maintenance']);
        $this->assertFalse($devices->get($expired->device_id)['maintenance']);
        $this->assertFalse($devices->get($future->device_id)['maintenance']);
        $this->assertSame('critical', $devices->get($expired->device_id)['health']);
        $this->assertSame('critical', $devices->get($future->device_id)['health']);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('HIDDEN-MAINTENANCE-DEVICE', $encoded);
        $this->assertStringNotContainsString('HIDDEN MAINTENANCE LOCATION', $encoded);
        $this->assertStringNotContainsString('HIDDEN SCHEDULE', $encoded);
    }

    public function testRepresentativePerformanceScaleHasFixedQueryCount(): void
    {
        $scale = getenv('IDF_PERFORMANCE_SCALE') ?: 'small';
        $scales = [
            'small' => ['devices' => 20, 'sensors' => 500, 'problems' => 1, 'max_ms' => 5000],
            'medium' => ['devices' => 200, 'sensors' => 6000, 'problems' => 10, 'max_ms' => 20000],
            'large' => ['devices' => 1000, 'sensors' => 30000, 'problems' => 50, 'max_ms' => 90000],
        ];
        $this->assertArrayHasKey($scale, $scales, 'IDF_PERFORMANCE_SCALE must be small, medium or large.');
        $target = $scales[$scale];
        $devices = Device::factory()->count($target['devices'])->create([
            'type' => 'network',
            'status' => 1,
            'disabled' => 0,
            'ignore' => 0,
            'last_polled' => now(),
        ]);
        $perDevice = intdiv($target['sensors'], $target['devices']);
        $rows = [];

        foreach ($devices as $device) {
            for ($index = 0; $index < $perDevice; $index++) {
                $rows[] = [
                    'device_id' => $device->device_id,
                    'sensor_class' => 'temperature',
                    'sensor_oid' => '.1.3.6.1.4.1.99999.' . $device->device_id . '.' . $index,
                    'sensor_index' => (string) $index,
                    'sensor_type' => 'phase1-performance',
                    'sensor_descr' => 'Temperature ' . $index,
                    'sensor_current' => 22,
                    'sensor_limit' => 45,
                    'sensor_limit_warn' => 40,
                    'sensor_alert' => 1,
                    'lastupdate' => now(),
                ];

                if (count($rows) === 1000) {
                    DB::table('sensors')->insert($rows);
                    $rows = [];
                }
            }
        }

        if ($rows !== []) {
            DB::table('sensors')->insert($rows);
        }

        $problemDevices = $devices->take($target['problems']);
        $maintenanceDevices = $devices->slice($target['problems'], $target['problems']);
        $criticalRule = AlertRule::factory()->create([
            'name' => 'Performance fixture critical alert',
            'severity' => 'critical',
        ]);
        $maintenance = AlertSchedule::factory()->create([
            'title' => 'Performance fixture maintenance',
            'start' => now()->subHour(),
            'end' => now()->addHour(),
            'behavior' => 1,
        ]);
        $maintenance->devices()->attach($maintenanceDevices->pluck('device_id')->all());

        foreach ($problemDevices as $device) {
            $device->update(['status' => 0]);
            DeviceOutage::factory()->for($device)->open()->create([
                'going_down' => now()->subMinutes(15)->timestamp,
            ]);
            Service::factory()->for($device)->create([
                'service_name' => 'Performance service',
                'service_status' => 2,
                'service_message' => 'Connection refused',
                'service_changed' => now()->subMinutes(10)->timestamp,
            ]);
            Alert::factory()->create([
                'device_id' => $device->device_id,
                'rule_id' => $criticalRule->id,
            ]);
        }

        $user = User::factory()->create(['enabled' => 1]);
        $user->assignRole('admin');
        $queryCount = 0;
        DB::listen(static function () use (&$queryCount): void {
            $queryCount++;
        });
        $request = Request::create('/plugin/IdfDashboard');
        $request->setUserResolver(fn (): User => $user);
        $memoryBefore = memory_get_usage(true);
        $start = hrtime(true);
        $payload = (new Page())->data([], $request);
        $dataMs = (hrtime(true) - $start) / 1_000_000;
        $memoryAfterData = memory_get_usage(true);
        $dataQueryCount = $queryCount;
        $html = view()->file(
            app_path('Plugins/IdfDashboard/resources/views/page.blade.php'),
            $payload
        )->render();
        $issueCount = collect($payload['otherLocations'])
            ->flatMap(fn (array $location): iterable => $location['devices'])
            ->sum(fn (array $device): int => $device['issues']->count());

        $this->assertSame($target['devices'], $payload['summary']['active_devices']);
        $this->assertSame($target['sensors'], DB::table('sensors')->count());
        $this->assertGreaterThanOrEqual($target['problems'], $issueCount);
        $this->assertLessThan(60, $dataQueryCount, 'Query count must stay fixed and independent of device count.');
        $this->assertLessThan($target['max_ms'], $dataMs, 'Page::data() exceeded the defensive scale ceiling.');
        $this->assertLessThan(256 * 1024 * 1024, max(0, $memoryAfterData - $memoryBefore), 'Page::data() memory growth is excessive.');

        fwrite(STDOUT, sprintf(
            "Phase 1 performance: scale=%s devices=%d sensors=%d services=%d alerts=%d outages=%d maintenance=%d issues=%d queries=%d data_ms=%.2f memory_growth=%d peak_memory=%d html_bytes=%d\n",
            $scale,
            $target['devices'],
            $target['sensors'],
            $target['problems'],
            $target['problems'],
            $target['problems'],
            $maintenanceDevices->count(),
            $issueCount,
            $dataQueryCount,
            $dataMs,
            max(0, $memoryAfterData - $memoryBefore),
            memory_get_peak_usage(true),
            strlen($html)
        ));

        $viewQueries = [
            'overview' => ['view' => 'overview'],
            'locations' => ['view' => 'locations'],
            'devices' => ['view' => 'devices', 'per_page' => 25],
            'location' => ['view' => 'location', 'id' => (int) ($devices->first()->location_id ?? 0), 'per_page' => 25],
            'device' => ['view' => 'device', 'id' => (int) $devices->first()->device_id],
            'tv' => ['view' => 'overview', 'tv' => 1],
        ];

        foreach ($viewQueries as $viewName => $query) {
            $queryCount = 0;
            $viewRequest = Request::create('/plugin/IdfDashboard', 'GET', $query);
            $viewRequest->setUserResolver(fn (): User => $user);
            $viewMemoryBefore = memory_get_usage(true);
            $viewStart = hrtime(true);
            $viewPayload = (new Page())->data([], $viewRequest);
            $viewMs = (hrtime(true) - $viewStart) / 1_000_000;
            $viewMemoryGrowth = max(0, memory_get_usage(true) - $viewMemoryBefore);
            $viewHtml = view()->file(
                app_path('Plugins/IdfDashboard/resources/views/page.blade.php'),
                $viewPayload
            )->render();
            $dom = new \DOMDocument();
            @$dom->loadHTML($viewHtml);
            $domNodes = $dom->getElementsByTagName('*')->length;
            $renderedDevices = match ($viewPayload['viewData']['kind']) {
                'devices', 'location' => count($viewPayload['viewData']['devices']['items'] ?? []),
                'device' => ($viewPayload['viewData']['found'] ?? false) ? 1 : 0,
                default => 0,
            };
            $renderedIssues = $viewPayload['viewData']['kind'] === 'overview'
                ? count($viewPayload['viewData']['priority']['items'])
                : ($viewPayload['viewData']['kind'] === 'device' && ($viewPayload['viewData']['found'] ?? false)
                    ? $viewPayload['viewData']['device']['issues']->take(10)->count()
                    : 0);

            $this->assertLessThan(65, $queryCount, "$viewName query count must remain fixed.");
            $this->assertLessThan(1024 * 1024, strlen($viewHtml), "$viewName HTML must remain below 1 MiB.");

            if ($viewName === 'overview') {
                $this->assertLessThan(750 * 1024, strlen($viewHtml), 'Large overview target is below 750 KiB.');
            }

            if (in_array($viewName, ['devices', 'location'], true)) {
                $this->assertLessThanOrEqual(25, $renderedDevices, "$viewName renders only one defensive page.");
            }

            fwrite(STDOUT, sprintf(
                "Phase 2 performance: scale=%s view=%s queries=%d data_ms=%.2f memory_growth=%d peak_memory=%d html_bytes=%d dom_nodes=%d devices_rendered=%d issues_rendered=%d\n",
                $scale,
                $viewName,
                $queryCount,
                $viewMs,
                $viewMemoryGrowth,
                memory_get_peak_usage(true),
                strlen($viewHtml),
                $domNodes,
                $renderedDevices,
                $renderedIssues
            ));
        }
    }

    public function testRealDeviceRowsReceiveExactlyOneSupportedClassification(): void
    {
        $fixtures = [
            'ups-real.example.com' => [['type' => 'network', 'hardware' => 'APC Smart-UPS'], 'Power'],
            'pdu-real.example.com' => [['type' => 'power', 'hardware' => 'Rack PDU'], 'Power'],
            'switch-real.example.com' => [['type' => 'network', 'hardware' => 'Ethernet switch'], 'Network'],
            'server-real.example.com' => [['type' => 'server'], 'Server'],
            'ap-real.example.com' => [['type' => 'wireless', 'hardware' => 'Access Point'], 'Wireless'],
            'printer-real.example.com' => [['type' => 'printer', 'hardware' => 'LaserJet'], 'Printer'],
            'camera-real.example.com' => [['type' => 'appliance', 'purpose' => 'CCTV camera'], 'Camera'],
            'pos-real.example.com' => [['type' => 'appliance', 'purpose' => 'Oracle MICROS workstation'], 'POS'],
            'controller-real.example.com' => [['type' => 'appliance', 'purpose' => 'BMS controller'], 'Controller'],
            'firewall-real.example.com' => [['type' => 'firewall', 'os' => 'fortios'], 'Security'],
            '10.20.30.40' => [['type' => 'appliance'], 'Other'],
        ];

        foreach ($fixtures as $hostname => [$attributes]) {
            Device::factory()->create($attributes + [
                'hostname' => $hostname,
                'status' => 1,
                'disabled' => 0,
                'ignore' => 0,
            ]);
        }

        $user = User::factory()->create(['enabled' => 1]);
        $user->assignRole('admin');
        $request = Request::create('/plugin/IdfDashboard');
        $request->setUserResolver(fn (): User => $user);
        $payload = (new Page())->data([], $request);
        $devices = collect($payload['otherLocations'])
            ->flatMap(fn (array $group): mixed => $group['devices'])
            ->keyBy('hostname');

        foreach ($fixtures as $hostname => [, $expected]) {
            $device = $devices->get($hostname);
            $this->assertSame($expected, $device['category'], $hostname);
            $this->assertSame($expected, $device['classification']['category'], $hostname);
            $this->assertNotSame('', $device['classification']['reason'], $hostname);
            $this->assertContains($device['classification']['confidence'], ['high', 'medium', 'low']);
            $this->assertIsArray($device['classification']['signals']);
        }
    }

    public function testLibreNmsControllerStillProtectsPluginSettings(): void
    {
        $plugin = Plugin::query()->firstOrCreate([
            'plugin_name' => 'IdfDashboard',
            'version' => 2,
        ], [
            'plugin_active' => 1,
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

    /**
     * Casos A-F: real fixtures (one shared device set) exercised against
     * six distinct Settings combinations, proving visibleSummary,
     * priorityAttention and the tv.* collections stay consistent with
     * each other and with the resolved policy — not four independent
     * "is this severity shown" implementations that could drift apart.
     * All devices live in one non-IDF/non-MDF location (so they land in
     * $otherLocations/$tv['otherLocations']) except $healthyOnly, which
     * gets its own dedicated all-healthy location for the Caso E
     * "zero Healthy locations" assertion.
     */
    public function testCasosAToFRespectConfiguredSeverityPolicyAcrossSummaryPriorityAndTv(): void
    {
        $location = Location::factory()->create(['location' => 'Casos Fixture Main']);
        $healthyOnlyLocation = Location::factory()->create(['location' => 'Casos Fixture Healthy Only']);

        $critical = Device::factory()->create([
            'display' => 'Caso Critical',
            'hostname' => 'caso-critical.example.com',
            'location_id' => $location->id,
            'type' => 'network',
            'status' => 1,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        Sensor::factory()->for($critical)->create([
            'sensor_class' => 'temperature',
            'sensor_descr' => 'Ambient Temperature',
            'sensor_current' => 90,
            'sensor_limit' => 80,
            'sensor_limit_warn' => 70,
            'sensor_alert' => 1,
            'lastupdate' => now(),
        ]);

        $warning = Device::factory()->create([
            'display' => 'Caso Warning',
            'hostname' => 'caso-warning.example.com',
            'location_id' => $location->id,
            'type' => 'network',
            'status' => 1,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        Sensor::factory()->for($warning)->create([
            'sensor_class' => 'humidity',
            'sensor_descr' => 'Ambient Humidity',
            'sensor_current' => 75,
            'sensor_limit' => 95,
            'sensor_limit_warn' => 70,
            'sensor_alert' => 1,
            'lastupdate' => now(),
        ]);

        $unknown = Device::factory()->create([
            'display' => 'Caso Unknown',
            'hostname' => 'caso-unknown.example.com',
            'location_id' => $location->id,
            'type' => 'server',
            'status' => 1,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        // A "state" sensor with no matching state_translations row: real
        // LibreNMS 26.8 has no threshold columns for this class at all,
        // so an untranslated value must resolve to Unknown, never a
        // silent Healthy — see sensorState()'s own comment.
        Sensor::factory()->for($unknown)->create([
            'sensor_class' => 'state',
            'sensor_descr' => 'System Status',
            'sensor_current' => 9,
            'sensor_alert' => 1,
            'lastupdate' => now(),
        ]);

        $maintenance = Device::factory()->create([
            'display' => 'Caso Maintenance',
            'hostname' => 'caso-maintenance.example.com',
            'location_id' => $location->id,
            'type' => 'network',
            'status' => 0,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        $maintenanceSchedule = AlertSchedule::factory()->create([
            'title' => 'Caso maintenance window',
            'start' => now()->subHour(),
            'end' => now()->addHour(),
            'behavior' => 1,
        ]);
        $maintenanceSchedule->devices()->attach($maintenance->device_id);

        // Role is derived from hostname/hardware/sysDescr containing the
        // word "PDU" (see deviceRole()) — no explicit role column exists.
        $noSensor = Device::factory()->create([
            'display' => 'Caso PDU No Sensor',
            'hostname' => 'caso-pdu-nosensor.example.com',
            'location_id' => $location->id,
            'type' => 'power',
            'status' => 1,
            'disabled' => 0,
            'ignore' => 0,
        ]);

        $stale = Device::factory()->create([
            'display' => 'Caso PDU Stale',
            'hostname' => 'caso-pdu-stale.example.com',
            'location_id' => $location->id,
            'type' => 'power',
            'status' => 1,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        Sensor::factory()->for($stale)->create([
            'sensor_class' => 'temperature',
            'sensor_descr' => 'PDU Ambient',
            'sensor_current' => 20,
            'sensor_limit' => 80,
            'sensor_limit_warn' => 70,
            'sensor_alert' => 1,
            'lastupdate' => now()->subDays(2),
        ]);

        $staleCritical = Device::factory()->create([
            'display' => 'Caso PDU Stale Critical',
            'hostname' => 'caso-pdu-stale-critical.example.com',
            'location_id' => $location->id,
            'type' => 'power',
            'status' => 1,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        Sensor::factory()->for($staleCritical)->create([
            'sensor_class' => 'temperature',
            'sensor_descr' => 'PDU Ambient Critical',
            'sensor_current' => 95,
            'sensor_limit' => 80,
            'sensor_limit_warn' => 70,
            'sensor_alert' => 1,
            'lastupdate' => now()->subDays(2),
        ]);

        $healthy = Device::factory()->create([
            'display' => 'Caso Healthy',
            'hostname' => 'caso-healthy.example.com',
            'location_id' => $location->id,
            'type' => 'server',
            'status' => 1,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        Sensor::factory()->for($healthy)->create([
            'sensor_class' => 'temperature',
            'sensor_descr' => 'Ambient',
            'sensor_current' => 20,
            'sensor_limit' => 80,
            'sensor_limit_warn' => 70,
            'sensor_alert' => 1,
            'lastupdate' => now(),
        ]);

        $healthyOnly = Device::factory()->create([
            'display' => 'Caso Healthy Only',
            'hostname' => 'caso-healthy-only.example.com',
            'location_id' => $healthyOnlyLocation->id,
            'type' => 'server',
            'status' => 1,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        Sensor::factory()->for($healthyOnly)->create([
            'sensor_class' => 'temperature',
            'sensor_descr' => 'Ambient',
            'sensor_current' => 20,
            'sensor_limit' => 80,
            'sensor_limit_warn' => 70,
            'sensor_alert' => 1,
            'lastupdate' => now(),
        ]);

        $user = User::factory()->create(['enabled' => 1]);
        $user->assignRole('admin');

        $run = function (array $settings, array $query = []) use ($user): array {
            $request = Request::create('/plugin/IdfDashboard', 'GET', $query);
            $request->setUserResolver(fn (): User => $user);

            return (new Page())->data($settings, $request);
        };

        $otherLocationDevices = function (array $payload, bool $tv = true): \Illuminate\Support\Collection {
            $source = $tv ? $payload['tv']['otherLocations'] : $payload['otherLocations'];

            return collect($source)
                ->flatMap(fn (array $group): mixed => $group['devices'])
                ->keyBy('device_id');
        };

        // --- Caso A: Critical+Warning ON, everything else OFF ----------
        $a = $run([
            'default_severity_unknown' => '0',
            'default_severity_stale' => '0',
            'default_severity_maintenance' => '0',
            'default_severity_no_sensor' => '0',
        ]);
        $this->assertSame(2, $a['visibleSummary']['critical_devices'], 'Caso A: critical (caso-critical + caso-pdu-stale-critical).');
        $this->assertSame(1, $a['visibleSummary']['warning_devices']);
        $this->assertSame(3, $a['visibleSummary']['devices'], 'Caso A: only critical/warning devices are visible.');
        $visibleIdsA = collect($a['priorityAttention']['items'])->pluck('device_id')->all();
        $this->assertEqualsCanonicalizing(
            [$critical->device_id, $warning->device_id, $staleCritical->device_id],
            $visibleIdsA,
            'Caso A: Priority Attention shows exactly the critical/warning devices, nothing disabled.'
        );
        $tvDevicesA = $otherLocationDevices($a);
        $this->assertEqualsCanonicalizing(
            [$critical->device_id, $warning->device_id, $staleCritical->device_id],
            $tvDevicesA->keys()->all(),
            'Caso A: TV renders exactly the critical/warning devices.'
        );
        $this->assertTrue($tvDevicesA->every(fn (array $d): bool => in_array($d['health'], ['critical', 'warning'], true)));

        // --- Caso B: Critical ON, Warning OFF ---------------------------
        $b = $run([
            'default_severity_warning' => '0',
            'default_severity_unknown' => '0',
            'default_severity_stale' => '0',
            'default_severity_maintenance' => '0',
            'default_severity_no_sensor' => '0',
        ]);
        $this->assertSame(2, $b['visibleSummary']['critical_devices']);
        $this->assertSame(0, $b['visibleSummary']['warning_devices']);
        $this->assertSame(2, $b['visibleSummary']['devices']);
        $tvDevicesB = $otherLocationDevices($b);
        $this->assertEqualsCanonicalizing([$critical->device_id, $staleCritical->device_id], $tvDevicesB->keys()->all());
        $this->assertTrue($tvDevicesB->every(fn (array $d): bool => $d['health'] === 'critical'));
        $this->assertFalse(collect($b['priorityAttention']['items'])->contains('device_id', $warning->device_id));

        // --- Caso C: Critical OFF, Warning ON ---------------------------
        $c = $run([
            'default_severity_critical' => '0',
            'default_severity_unknown' => '0',
            'default_severity_stale' => '0',
            'default_severity_maintenance' => '0',
            'default_severity_no_sensor' => '0',
        ]);
        $this->assertSame(0, $c['visibleSummary']['critical_devices']);
        $this->assertSame(1, $c['visibleSummary']['warning_devices']);
        $this->assertSame(1, $c['visibleSummary']['devices']);
        $tvDevicesC = $otherLocationDevices($c);
        $this->assertEqualsCanonicalizing([$warning->device_id], $tvDevicesC->keys()->all());
        $this->assertFalse(collect($c['priorityAttention']['items'])->contains('device_id', $critical->device_id));
        $this->assertFalse(collect($c['priorityAttention']['items'])->contains('device_id', $staleCritical->device_id));

        // --- Caso D: Healthy ON, everything else OFF --------------------
        $d = $run([
            'default_severity_critical' => '0',
            'default_severity_warning' => '0',
            'default_severity_unknown' => '0',
            'default_severity_stale' => '0',
            'default_severity_maintenance' => '0',
            'default_severity_healthy' => '1',
        ]);
        $this->assertSame(0, $d['visibleSummary']['critical_devices']);
        $this->assertSame(0, $d['visibleSummary']['warning_devices']);
        // healthy, healthyOnly and noSensor (no_sensor never elevates
        // health above healthy) are the only devices whose health is
        // literally 'healthy'.
        $this->assertSame(3, $d['visibleSummary']['devices'], 'Caso D: only the three Healthy-health devices are visible.');
        $this->assertSame([], $d['priorityAttention']['items'], 'Caso D: Priority Attention is empty — Healthy devices have no actionable issue.');
        $this->assertSame(0, $d['priorityAttention']['total']);
        $tvDevicesD = $otherLocationDevices($d);
        $this->assertEqualsCanonicalizing([$healthy->device_id, $noSensor->device_id], $tvDevicesD->keys()->all());
        $this->assertTrue($tvDevicesD->every(fn (array $dv): bool => $dv['health'] === 'healthy'));
        $tvIdfLocationsD = collect($d['tv']['idfLocations']);
        $this->assertTrue($tvIdfLocationsD->isEmpty(), 'Caso D: fixture has no IDF-pattern locations.');

        // --- Caso E: Problems only (per-viewer) + healthy locations OFF -
        $eLocations = $run(
            ['show_healthy_locations' => '0'],
            ['view' => 'locations']
        );
        $locationNamesE = collect($eLocations['viewData']['locations']['items'] ?? [])->pluck('name')->all();
        $this->assertNotContains(
            'Casos Fixture Healthy Only',
            $locationNamesE,
            'Caso E: an all-Healthy location is hidden when show_healthy_locations is off.'
        );
        $eDevices = $run(
            ['show_healthy_locations' => '0'],
            ['view' => 'devices', 'problems_only' => '1', 'per_page' => 100]
        );
        $deviceNamesE = collect($eDevices['viewData']['devices']['items'] ?? [])->pluck('device_id')->all();
        $this->assertNotContains($healthy->device_id, $deviceNamesE, 'Caso E: problems_only excludes the plain Healthy device.');
        $this->assertNotContains($healthyOnly->device_id, $deviceNamesE, 'Caso E: problems_only excludes the dedicated healthy-only device.');
        $this->assertNotContains($noSensor->device_id, $deviceNamesE, 'Caso E: problems_only excludes the no-sensor (healthy) device.');
        $this->assertContains($critical->device_id, $deviceNamesE, 'Caso E: a genuinely unhealthy device remains visible.');

        // --- Caso F: Stale (data quality) problem type OFF --------------
        $f = $run(['default_problem_stale' => '0']);
        $tvDevicesF = $otherLocationDevices($f);
        $this->assertSame(
            'healthy',
            $tvDevicesF->get($stale->device_id)['health'],
            'Caso F: a stale-but-otherwise-healthy PDU reading is hidden entirely (not shown as Stale, not elevated) when Stale is off.'
        );
        $this->assertSame(
            'critical',
            $tvDevicesF->get($staleCritical->device_id)['health'],
            'Caso F: an old-but-critical reading still shows Critical even when Stale is off.'
        );
        $this->assertTrue(
            $tvDevicesF->get($staleCritical->device_id)['issues']->contains(
                fn (array $issue): bool => $issue['severity'] === 'critical' && $issue['type'] === 'temperature'
            )
        );
        // Baseline: with default_problem_stale left on (the default), the
        // same reading elevates the device to Stale, proving Caso F's
        // "hidden" result above is a real effect of the setting, not the
        // fixture always producing Healthy regardless.
        $baseline = $run([]);
        $tvDevicesBaseline = $otherLocationDevices($baseline);
        $this->assertSame('stale', $tvDevicesBaseline->get($stale->device_id)['health']);
    }

    /**
     * The tv_maximum_devices_rendered ceiling only bounds the three flat
     * MDF device sections (tv.mdfServers/mdfPower/mdfInfrastructure) —
     * tv.idfLocations/otherLocations are location-grouped and are not
     * subject to this cap. With 5 MDF Server-category devices (2
     * Critical, 1 Warning, 2 Healthy) and a ceiling of 3, exactly the 2
     * Critical + 1 Warning devices must survive (worst-first truncation
     * — a Critical device can never be dropped to fit a Healthy one),
     * omittedDeviceCount must report the 2 that didn't fit, and the
     * ceiling itself must apply per section independently, not as one
     * combined budget across all three MDF sections.
     */
    public function testTvMaximumDevicesRenderedTruncatesWorstFirstAndReportsOmittedCount(): void
    {
        $mdf = Location::factory()->create(['location' => 'MDF']);

        $makeServer = function (string $hostname) use ($mdf): Device {
            return Device::factory()->create([
                'display' => $hostname,
                'hostname' => $hostname,
                'location_id' => $mdf->id,
                'type' => 'server',
                'status' => 1,
                'disabled' => 0,
                'ignore' => 0,
            ]);
        };

        $critical1 = $makeServer('tv-ceiling-critical-1.example.com');
        Sensor::factory()->for($critical1)->create([
            'sensor_class' => 'temperature', 'sensor_descr' => 'Ambient',
            'sensor_current' => 95, 'sensor_limit' => 80, 'sensor_limit_warn' => 70,
            'sensor_alert' => 1, 'lastupdate' => now(),
        ]);

        $critical2 = $makeServer('tv-ceiling-critical-2.example.com');
        Sensor::factory()->for($critical2)->create([
            'sensor_class' => 'temperature', 'sensor_descr' => 'Ambient',
            'sensor_current' => 96, 'sensor_limit' => 80, 'sensor_limit_warn' => 70,
            'sensor_alert' => 1, 'lastupdate' => now(),
        ]);

        $warning = $makeServer('tv-ceiling-warning.example.com');
        Sensor::factory()->for($warning)->create([
            'sensor_class' => 'humidity', 'sensor_descr' => 'Ambient',
            'sensor_current' => 75, 'sensor_limit' => 95, 'sensor_limit_warn' => 70,
            'sensor_alert' => 1, 'lastupdate' => now(),
        ]);

        $healthy1 = $makeServer('tv-ceiling-healthy-1.example.com');
        Sensor::factory()->for($healthy1)->create([
            'sensor_class' => 'temperature', 'sensor_descr' => 'Ambient',
            'sensor_current' => 20, 'sensor_limit' => 80, 'sensor_limit_warn' => 70,
            'sensor_alert' => 1, 'lastupdate' => now(),
        ]);

        $healthy2 = $makeServer('tv-ceiling-healthy-2.example.com');
        Sensor::factory()->for($healthy2)->create([
            'sensor_class' => 'temperature', 'sensor_descr' => 'Ambient',
            'sensor_current' => 21, 'sensor_limit' => 80, 'sensor_limit_warn' => 70,
            'sensor_alert' => 1, 'lastupdate' => now(),
        ]);

        $user = User::factory()->create(['enabled' => 1]);
        $user->assignRole('admin');
        $request = Request::create('/plugin/IdfDashboard');
        $request->setUserResolver(fn (): User => $user);

        // default_severity_healthy is turned on so the two Healthy
        // devices genuinely compete for a truncated slot — with it left
        // at its default (off), they would never reach the pre-
        // truncation collection at all, making the "never drops
        // Critical to fit Healthy" assertion vacuous.
        $payload = (new Page())->data([
            'tv_maximum_devices_rendered' => '3',
            'default_severity_healthy' => '1',
        ], $request);

        $this->assertSame(5, $payload['mdf']['server_count'], 'Desktop MDF Servers section remains the full, unfiltered/untruncated set.');
        $this->assertCount(3, $payload['tv']['mdfServers'], 'TV MDF Servers is truncated to the configured ceiling.');
        $this->assertSame(2, $payload['tv']['omittedDeviceCount'], 'Exactly the 2 devices that did not fit are reported, never silently dropped.');

        $renderedHealths = collect($payload['tv']['mdfServers'])->pluck('health')->all();
        $this->assertEqualsCanonicalizing(
            ['critical', 'critical', 'warning'],
            $renderedHealths,
            'Worst-first truncation keeps both Critical devices and the Warning device; neither Healthy device displaces them.'
        );
        $this->assertFalse(
            collect($payload['tv']['mdfServers'])->contains('device_id', $healthy1->device_id),
            'A Critical device is never dropped from the rendered set to make room for a Healthy one.'
        );
        $this->assertFalse(collect($payload['tv']['mdfServers'])->contains('device_id', $healthy2->device_id));

        // A default ceiling (200) comfortably fits all 5 — proves the
        // truncation above is a real effect of the low configured
        // ceiling, not something that always happens regardless.
        $unbounded = (new Page())->data(['default_severity_healthy' => '1'], $request);
        $this->assertCount(5, $unbounded['tv']['mdfServers']);
        $this->assertSame(0, $unbounded['tv']['omittedDeviceCount']);

        // The ceiling is per-section, not one shared budget: a second
        // MDF Power device at the same low ceiling must not be affected
        // by mdfServers already being full.
        $power = Device::factory()->create([
            'display' => 'TV Ceiling Power',
            'hostname' => 'tv-ceiling-power.example.com',
            'location_id' => $mdf->id,
            'type' => 'power',
            'status' => 1,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        Sensor::factory()->for($power)->create([
            'sensor_class' => 'voltage', 'sensor_descr' => 'Input Voltage',
            'sensor_current' => 400, 'sensor_limit' => 300, 'sensor_limit_warn' => 250,
            'sensor_alert' => 1, 'lastupdate' => now(),
        ]);
        $withPower = (new Page())->data([
            'tv_maximum_devices_rendered' => '3',
            'default_severity_healthy' => '1',
        ], $request);
        $this->assertCount(3, $withPower['tv']['mdfServers'], 'mdfServers ceiling is unaffected by mdfPower having its own device.');
        $this->assertCount(1, $withPower['tv']['mdfPower'], 'mdfPower has its own independent budget under the same ceiling.');
        $this->assertSame(2, $withPower['tv']['omittedDeviceCount'], 'omittedDeviceCount sums across sections but mdfPower contributed zero (1 device, ceiling 3).');
    }

    private function writeVisualFixture(string $name, string $html): void
    {
        $directory = getenv('IDF_VISUAL_OUTPUT_DIR');

        if (is_string($directory) && is_dir($directory)) {
            file_put_contents($directory . DIRECTORY_SEPARATOR . $name, $html);
        }
    }
}
