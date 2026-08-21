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
use App\Plugins\IdfDashboard\Support\AlertRules;
use App\Plugins\IdfDashboard\Support\Config;
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

        $payloadFor = static function (array $query, array $settings = []) use ($user): array {
            $request = Request::create('/plugin/IdfDashboard', 'GET', $query);
            $request->setUserResolver(fn (): User => $user);

            return (new Page())->data($settings, $request);
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

        // default_severity_healthy is turned on for this call only:
        // $allowed and all 27 $additional devices are plain-healthy
        // with no sensors/issues, and visibleSummary (unlike
        // viewData.devices.total, which is an access-filtered raw
        // device list unaffected by display policy) is policy-aware —
        // under the default (Healthy hidden) policy it correctly
        // reports 0, not 28. This assertion predates that
        // visibleSummary policy-awareness fix and would otherwise be
        // asserting stale, pre-fix behavior rather than genuinely
        // exercising pagination.
        $pageTwo = $payloadFor(['view' => 'devices', 'page' => 2, 'per_page' => 25], ['default_severity_healthy' => '1']);
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

        // $down's own device-status=0 severity is now purely from the
        // "Device Down"-named Alert Rule attached to it below, not any
        // native computation — down_since/down_age_seconds are separate,
        // real-outage-timestamp data untouched by that removal (see
        // Page.php's normalizeDevice()), so they stay asserted directly.
        $this->assertSame('critical', $downData['health']);
        $this->assertGreaterThanOrEqual(660, $downData['down_age_seconds']);
        $this->assertLessThan(900, $downData['down_age_seconds']);
        $this->assertSame('maintenance', $maintenanceData['health']);
        $this->assertTrue($recoveredData['recovered_recently']);
        $this->assertFalse($oldRecoveryData['recovered_recently']);
        $this->assertSame('Power', $upsData['category']);
        $this->assertGreaterThan(0, $upsData['no_sensor_count']);
        // REGRESSION NOTE (final correlation hardening): this fixture
        // attaches three Alert Rules to $sensorDevice ("Vendor power
        // alarm", "Vendor warning alarm", "Sensor over limit - Check
        // Device Health Settings") — none of them tagged, in Settings,
        // as covering any technical-condition category, and none of
        // them the real cause of the temperature/state/service
        // conditions below. This assertion block used to expect all
        // three conditions to stay invisible, on the theory that "a
        // real LibreNMS Alert Rule is now the only way any of these
        // become an issue". That theory relied on the pre-hardening
        // device-wide gate (`$hasAlertIssue` — any active alert at all
        // blanked out every fallback on the device), which was exactly
        // the false-negative bug this project's "no exact identity = no
        // suppression" hardening exists to fix: an unrelated Alert Rule
        // must never hide a real, different technical failure. With
        // that gate removed, OperationalPolicy correctly generates its
        // own fallback for each of these three genuinely-uncovered
        // conditions (no exact device_id/sensor_id/service_id
        // correlation ties any of the three unrelated alerts to any of
        // them), so all three now correctly DO appear.
        $this->assertTrue($sensorData['issues']->contains(
            fn (array $issue): bool => str_contains($issue['description'], '104')
                && str_contains($issue['description'], '95')
        ), 'The uncovered CPU Temperature threshold breach must surface as a fallback issue — three unrelated Alert Rules on this device must not hide it.');
        $this->assertTrue($sensorData['issues']->contains(
            fn (array $issue): bool => $issue['type'] === 'state'
                && str_contains($issue['description'], 'failed')
        ), 'The uncovered System Status state-sensor failure must surface as a fallback issue — three unrelated Alert Rules on this device must not hide it.');
        $this->assertTrue($sensorData['issues']->contains(
            fn (array $issue): bool => $issue['source'] === 'service'
        ), 'The uncovered SQL Server service failure must surface as a fallback issue — three unrelated Alert Rules on this device must not hide it.');
        // The full per-sensor telemetry array is no longer exposed to
        // the frontend as chip data (see page.blade.php's own removal
        // of $renderTelemetry/$metricIcon) — locking that in here.
        $this->assertArrayNotHasKey('issue_telemetry', $sensorData);
        $this->assertTrue($sensorData['issues']->contains(
            fn (array $issue): bool => $issue['source'] === 'alert'
                && $issue['severity'] === 'critical'
                && str_contains($issue['description'], 'Vendor power alarm')
        ));
        // No idf_included_alert_rule_ids setting was saved for this
        // request ($settings === []), so Support\AlertRules defaults to
        // "every currently defined rule is included" — there is no more
        // hardcoded exclusion of rules named "Sensor over limit"/
        // "Device Down"; an administrator now has to explicitly
        // uncheck a rule in Settings for it to disappear (see the
        // dedicated exclusion assertions further below).
        $this->assertTrue($sensorData['issues']->contains(
            fn (array $issue): bool => $issue['source'] === 'alert'
                && str_contains($issue['description'], 'Sensor over limit')
        ));
        $this->assertCount(0, $downData['issues']->where('type', 'device_down'), 'Native "device_down" issue type no longer exists at all — see buildDeviceIssues().');
        $this->assertTrue($downData['issues']->contains(
            fn (array $issue): bool => $issue['source'] === 'alert'
                && str_contains($issue['description'], 'Device Down')
        ));
        $this->assertSame('warning', $warningAlertData['health']);
        // Priority Attention used to blanket-exclude every warning-severity
        // alert issue — a conservative noise-reduction measure from before
        // Support\AlertRules gave administrators explicit per-rule
        // curation (see buildPriorityAttention()'s own updated comment).
        // Now that severity comes only from administrator-selected Alert
        // Rules, a device whose only cause is an included Warning Alert
        // Rule must be able to appear here like any other actionable
        // issue — hiding it would silently defeat the point of checking
        // that rule in Settings in the first place.
        $this->assertTrue(collect($payload['priorityAttention']['items'])->contains(
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
        // PRIORITY_DEVICE_DOWN/PRIORITY_CRITICAL_SENSOR/PRIORITY_CRITICAL_SERVICE
        // are all unreachable now (their issue sources were removed) —
        // PRIORITY_CRITICAL_ALERT is deterministically the best priority
        // any issue can have across this whole fixture, regardless of
        // which specific device/alert wins the device_name tie-break in
        // buildPriorityAttention()'s final cross-device sort.
        $this->assertSame('alert', $payload['priorityAttention']['items'][0]['type']);
        $this->assertSame(IssueBuilder::PRIORITY_CRITICAL_ALERT, $payload['priorityAttention']['items'][0]['priority']);
        $this->assertLessThan(80, $dataQueryCount, 'Phase 1 remains fixed-query and avoids N+1 behavior.');
        $this->assertLessThan(5000, $elapsedMs, 'Fixture Page::data() remains within a defensive local ceiling.');
        $this->assertLessThan(64 * 1024 * 1024, $memoryDelta, 'Fixture Page::data() memory delta remains bounded.');
        $this->assertLessThan(2 * 1024 * 1024, $htmlBytes, 'Fixture HTML remains within a defensive ceiling.');
        // The native "Device down — unavailable for ..." description no
        // longer exists at all (see buildDeviceIssues()'s own removal) —
        // $down's rendered card now carries its alert-sourced description
        // instead, matching the structured $downData['issues'] assertion
        // above at the actual rendered-HTML level.
        $this->assertStringContainsString('Active alert — Device Down', $html);
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

    /**
     * Support\AlertRules replaced the old hardcoded rule-*name*
     * guessing (REDUNDANT_ALERT_RULE_NAMES / the device-down regex)
     * with an explicit, administrator-chosen selection of real
     * alert_rules.id values. This proves both halves: nothing is
     * hidden until an administrator explicitly says so, and once they
     * do, the exclusion is exact and reflected in both the issue list
     * and the device's own computed health/problem_types.
     */
    public function testAlertRuleInclusionDefaultsToEverythingAndRespectsAnExplicitAdministratorSelection(): void
    {
        $device = Device::factory()->create([
            'hostname' => 'alert-rule-selection.example.com',
            'status' => 1,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        $user = User::factory()->create(['enabled' => 1]);
        $user->assignRole('admin');
        $request = Request::create('/plugin/IdfDashboard');
        $request->setUserResolver(fn (): User => $user);

        $keepRule = AlertRule::factory()->create([
            'name' => 'Vendor power alarm',
            'severity' => 'critical',
        ]);
        Alert::factory()->create([
            'device_id' => $device->device_id,
            'rule_id' => $keepRule->id,
        ]);
        $excludableRule = AlertRule::factory()->create([
            'name' => 'Sensor over limit - Check Device Health Settings',
            'severity' => 'critical',
        ]);
        Alert::factory()->create([
            'device_id' => $device->device_id,
            'rule_id' => $excludableRule->id,
        ]);

        $issuesFor = static function (array $payload, int $deviceId): \Illuminate\Support\Collection {
            return collect($payload['otherLocations'])
                ->flatMap(fn (array $group) => $group['devices'])
                ->firstWhere('device_id', $deviceId)['issues'];
        };

        // No idf_included_alert_rule_ids setting has ever been saved
        // ($settings === []) -- both rules are included by default,
        // Support\AlertRules::resolveIncludedIds()'s documented
        // behavior for "no explicit choice yet".
        $defaultIssues = $issuesFor((new Page())->data([], $request), $device->device_id);
        $this->assertTrue($defaultIssues->contains(
            fn (array $issue): bool => $issue['source'] === 'alert'
                && str_contains($issue['description'], 'Vendor power alarm')
        ));
        $this->assertTrue($defaultIssues->contains(
            fn (array $issue): bool => $issue['source'] === 'alert'
                && str_contains($issue['description'], 'Sensor over limit')
        ));

        // An administrator explicitly includes only $keepRule, exactly
        // the shape settings.blade.php's checkbox list submits
        // (settings[idf_included_alert_rule_ids][] per checked box).
        $filteredIssues = $issuesFor((new Page())->data(
            ['idf_included_alert_rule_ids' => [(string) $keepRule->id]],
            $request
        ), $device->device_id);
        $this->assertTrue($filteredIssues->contains(
            fn (array $issue): bool => $issue['source'] === 'alert'
                && str_contains($issue['description'], 'Vendor power alarm')
        ));
        $this->assertFalse($filteredIssues->contains(
            fn (array $issue): bool => str_contains($issue['description'], 'Sensor over limit')
        ));

        // An administrator explicitly excludes every rule -- submitted
        // as the settings form's hidden-fallback placeholder value
        // (an array containing only '') when every checkbox is left
        // unchecked. The device has no other issue, so this must
        // clear its health/problem_types entirely, not merely drop
        // the alert-sourced issue rows while leaving it flagged.
        $emptyPayload = (new Page())->data(['idf_included_alert_rule_ids' => ['']], $request);
        $emptyDevice = collect($emptyPayload['otherLocations'])
            ->flatMap(fn (array $group) => $group['devices'])
            ->firstWhere('device_id', $device->device_id);
        $this->assertSame('healthy', $emptyDevice['health']);
        $this->assertTrue($emptyDevice['issues']->isEmpty());
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

        // $expired/$future both have status=0 like every other device
        // fixture here, but native Device Down detection was removed
        // entirely (see Page.php's buildDeviceIssues()) — a real
        // critical-severity Alert Rule is now required for either to
        // show as anything other than Healthy, so this test's actual
        // subject (expired/future maintenance windows must not
        // suppress a real Critical severity) stays meaningful.
        $windowRule = AlertRule::factory()->create([
            'name' => 'Maintenance window fixture alert',
            'severity' => 'critical',
        ]);
        Alert::factory()->create(['device_id' => $expired->device_id, 'rule_id' => $windowRule->id]);
        Alert::factory()->create(['device_id' => $future->device_id, 'rule_id' => $windowRule->id]);

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
        // max_html_kib bounds the *unbounded-by-design* classic MDF Servers/
        // Power/Infrastructure/IDF/Other Locations device grid (there is no
        // count-limiting config for it, unlike TV Mode's
        // tv_maximum_devices_rendered or the desktop Priority Attention
        // list's maximum_priority_issues — the classic grid's
        // default_section_* settings are whole-section show/hide toggles,
        // never per-count limits, and it renders on every Phase 2 view
        // regardless of $viewData['kind'], so its size scales with device
        // count essentially linearly). These ceilings were calibrated with
        // real headroom above what this scale's device count actually
        // measures once rendered — not aspirational/guessed numbers — the
        // first genuine measurement was only possible once this file's own
        // long-standing @php-directive Blade-compile bug (unrelated to
        // Phase 3A) was fixed, since that bug had made this exact section
        // permanently uncompilable, and so never previously rendered for
        // any performance assertion to observe.
        $scales = [
            'small' => ['devices' => 20, 'sensors' => 500, 'problems' => 1, 'max_ms' => 5000, 'max_html_kib' => 512],
            'medium' => ['devices' => 200, 'sensors' => 6000, 'problems' => 10, 'max_ms' => 20000, 'max_html_kib' => 1536],
            'large' => ['devices' => 1000, 'sensors' => 30000, 'problems' => 50, 'max_ms' => 90000, 'max_html_kib' => 3072],
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
            $this->assertLessThan(
                $target['max_html_kib'] * 1024,
                strlen($viewHtml),
                "$viewName HTML must remain below the calibrated {$scale}-scale ceiling ({$target['max_html_kib']} KiB)."
            );

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
        // This sensor reading alone no longer produces any issue at all
        // (native per-sensor threshold breach was removed — see Page.php's
        // buildDeviceIssues()); a real critical-severity Alert Rule is what
        // makes $critical actually 'critical' throughout every Caso below.
        $criticalRule = AlertRule::factory()->create([
            'name' => 'Caso critical fixture alert',
            'severity' => 'critical',
        ]);
        Alert::factory()->create(['device_id' => $critical->device_id, 'rule_id' => $criticalRule->id]);

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
        $warningRule = AlertRule::factory()->create([
            'name' => 'Caso warning fixture alert',
            'severity' => 'warning',
        ]);
        Alert::factory()->create(['device_id' => $warning->device_id, 'rule_id' => $warningRule->id]);

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
        // $staleCritical's old sensor reading alone no longer produces any
        // issue either — its Critical severity below (which must survive
        // even when Stale is toggled off in Caso F, since the two are
        // independent conditions) comes entirely from this Alert. Alerts
        // are not subject to the "stale data" staleness check at all
        // (that check applies only to $stale's own PDU-freshness escalation,
        // which stays native — see Config::FIELDS' default_problem_stale).
        $staleCriticalRule = AlertRule::factory()->create([
            'name' => 'Caso stale-critical fixture alert',
            'severity' => 'critical',
        ]);
        Alert::factory()->create(['device_id' => $staleCritical->device_id, 'rule_id' => $staleCriticalRule->id]);

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

        // $run() with no explicit 'view' query defaults to
        // default_view ('overview'), so every $a/$b/$c/$d payload
        // below already carries a real, live viewData['kind']
        // === 'overview'. This closure surfaces its inline Priority
        // Attention panel's device IDs (page.blade.php's
        // main#phase2-content, distinct from the persistent
        // .priority-panel banner the top-level 'priorityAttention'
        // key drives) so both can be asserted identical — proving
        // the Overview view's own on-page panel respects the exact
        // same severity policy the banner/visibleSummary already do,
        // not a fourth independent answer to "is this severity shown".
        $overviewPriorityIds = fn (array $payload): array => collect($payload['viewData']['priority']['items'])
            ->pluck('device_id')
            ->all();

        // --- Caso A: Critical+Warning ON, everything else OFF ----------
        $a = $run([
            'default_severity_unknown' => '0',
            'default_severity_stale' => '0',
            'default_severity_maintenance' => '0',
            'default_severity_no_sensor' => '0',
        ]);
        $this->assertSame(2, $a['visibleSummary']['critical_devices'], 'Caso A: critical (caso-critical + caso-pdu-stale-critical).');
        $this->assertSame(1, $a['visibleSummary']['warning_devices']);
        $this->assertSame(0, $a['visibleSummary']['unknown_devices'], 'Caso A: Unknown is off — the desktop summary-panel\'s "Needs review" card must read 0, not the raw fleet count.');
        $this->assertSame(3, $a['visibleSummary']['devices'], 'Caso A: only critical/warning devices are visible.');
        $visibleIdsA = collect($a['priorityAttention']['items'])->pluck('device_id')->all();
        $this->assertEqualsCanonicalizing(
            [$critical->device_id, $warning->device_id, $staleCritical->device_id],
            $visibleIdsA,
            'Caso A: Priority Attention shows exactly the critical/warning devices, nothing disabled.'
        );
        $this->assertFalse(in_array($unknown->device_id, $visibleIdsA, true), 'Caso A: Unknown is off — the Unknown-classified device must not appear in Priority Attention.');
        $this->assertFalse(in_array($maintenance->device_id, $visibleIdsA, true), 'Caso A: Maintenance is off — the maintenance-window device must not appear in Priority Attention.');
        $tvDevicesA = $otherLocationDevices($a);
        $this->assertEqualsCanonicalizing(
            [$critical->device_id, $warning->device_id, $staleCritical->device_id],
            $tvDevicesA->keys()->all(),
            'Caso A: TV renders exactly the critical/warning devices.'
        );
        $this->assertTrue($tvDevicesA->every(fn (array $d): bool => in_array($d['health'], ['critical', 'warning'], true)));
        $this->assertEqualsCanonicalizing(
            $visibleIdsA,
            $overviewPriorityIds($a),
            'Caso A: the Overview view\'s own inline Priority Attention panel shows exactly the same devices as the persistent banner.'
        );

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
        // The specific regression this guards: before the Overview
        // view's inline panel consulted ProblemPolicy::deviceVisible(),
        // it was built from Phase 2's search/category/problem filter
        // only, so a Warning device stayed visible here even with
        // Warning explicitly off in Settings, disagreeing with the
        // persistent banner and visibleSummary on the very same page.
        $this->assertFalse(
            in_array($warning->device_id, $overviewPriorityIds($b), true),
            'Caso B: the Overview view\'s inline Priority Attention panel must not show a device whose severity is off in Settings.'
        );
        $this->assertEqualsCanonicalizing(
            collect($b['priorityAttention']['items'])->pluck('device_id')->all(),
            $overviewPriorityIds($b),
            'Caso B: the Overview view\'s inline panel matches the persistent banner exactly.'
        );

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
        $this->assertFalse(
            in_array($critical->device_id, $overviewPriorityIds($c), true),
            'Caso C: the Overview view\'s inline panel must not show a Critical device while Critical is off in Settings.'
        );
        $this->assertEqualsCanonicalizing([$warning->device_id], $overviewPriorityIds($c), 'Caso C: the Overview view\'s inline panel shows exactly the visible Warning device.');

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
        $this->assertSame([], $overviewPriorityIds($d), 'Caso D: the Overview view\'s inline panel is also empty — no actionable issue exists to disagree about.');
        // critical_locations is an Illuminate\Support\Collection (built via
        // ->where(...)->take(8)->values() in Page.php's buildViewData()),
        // not a plain array — assertSame([], ...) fails strict-identity
        // type comparison even when the collection is genuinely empty, so
        // emptiness is asserted via assertCount() instead.
        $this->assertCount(0, $d['viewData']['critical_locations'], 'Caso D: no location has an open issue once Critical/Warning/Unknown/Stale/Maintenance are all off.');
        // healthy['devices']/['locations'] are deliberately NOT policy-
        // filtered (see the buildViewData() comment) — this reads 3
        // regardless of which severities are toggled, the same
        // informational-count design as visibleSummary's
        // no_sensor_installed.
        $this->assertSame(3, $d['viewData']['healthy']['devices'], 'Caso D: the Healthy Overview panel counts all three Healthy-classified devices in the fixture, independent of severity policy.');
        $tvDevicesD = $otherLocationDevices($d);
        $this->assertEqualsCanonicalizing([$healthy->device_id, $healthyOnly->device_id, $noSensor->device_id], $tvDevicesD->keys()->all());
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
        // default_severity_healthy is turned on for this call: once Stale
        // is disabled, $stale's only reading no longer elevates it at
        // all, so it becomes a plain Healthy device — under the default
        // (Healthy hidden) policy it would be entirely absent from
        // $tvDevicesF, not merely showing 'healthy', making the first
        // assertion below meaningless (get() returning null threw
        // "Trying to access array offset on null" in real CI, the first
        // run this branch's Blade fix let this fixture actually execute).
        // The baseline check further down deliberately does NOT set this
        // — a Stale-classified device remains visible under the default
        // policy regardless (a different, default-on toggle), which is
        // exactly what proves this Caso's "hidden once Stale is off" is a
        // real effect of the setting rather than the fixture always
        // being invisible.
        $f = $run(['default_problem_stale' => '0', 'default_severity_healthy' => '1']);
        $tvDevicesF = $otherLocationDevices($f);
        $this->assertSame(
            'healthy',
            $tvDevicesF->get($stale->device_id)['health'],
            'Caso F: a stale-but-otherwise-healthy PDU reading no longer shows as Stale (and is not elevated) once Stale is off.'
        );
        $this->assertSame(
            'critical',
            $tvDevicesF->get($staleCritical->device_id)['health'],
            'Caso F: an old-but-critical reading still shows Critical even when Stale is off.'
        );
        // $staleCritical's Critical issue is now alert-sourced (see the
        // Alert Rule fixture above) — its type/source are 'alert', not
        // the removed native 'temperature'/'sensor' shape.
        $this->assertTrue(
            $tvDevicesF->get($staleCritical->device_id)['issues']->contains(
                fn (array $issue): bool => $issue['severity'] === 'critical' && $issue['type'] === 'alert' && $issue['source'] === 'alert'
            )
        );
        // Priority Attention (both the persistent banner and the
        // Overview view's own inline panel) must agree with TV here:
        // buildPriorityAttention() only ever surfaces a device with an
        // actionable issue, and $stale's Stale-classified issue is
        // disabled at the source (default_problem_stale), so it has no
        // actionable issue left at all — not merely a hidden severity.
        $this->assertFalse(
            collect($f['priorityAttention']['items'])->contains('device_id', $stale->device_id),
            'Caso F: Stale is off, so the stale-but-otherwise-healthy device has no actionable issue and is absent from Priority Attention.'
        );
        $this->assertTrue(
            collect($f['priorityAttention']['items'])->contains('device_id', $staleCritical->device_id),
            'Caso F: the genuinely Critical device remains in Priority Attention even with Stale off — its Critical issue is a separate reading, not the disabled Stale one.'
        );
        $this->assertFalse(in_array($stale->device_id, $overviewPriorityIds($f), true), 'Caso F: the Overview inline panel agrees.');
        $this->assertTrue(in_array($staleCritical->device_id, $overviewPriorityIds($f), true), 'Caso F: the Overview inline panel agrees.');
        // Baseline: with default_problem_stale left on (the default), the
        // same reading elevates the device to Stale, proving Caso F's
        // "hidden" result above is a real effect of the setting, not the
        // fixture always producing Healthy regardless.
        $baseline = $run([]);
        $tvDevicesBaseline = $otherLocationDevices($baseline);
        $this->assertSame('stale', $tvDevicesBaseline->get($stale->device_id)['health']);
        // Unknown is on by default (Config::visibilityPolicy()'s
        // 'unknown' => true), so the desktop summary-panel's "Needs
        // review" card must count the real Unknown-classified device
        // here, not silently read 0 the way it would if the panel were
        // still wired to a policy-unaware source.
        $this->assertSame(1, $baseline['visibleSummary']['unknown_devices'], 'Baseline: Unknown is on by default, so the one Unknown-classified fixture device is counted.');
    }

    /**
     * The tv_maximum_devices_rendered ceiling only bounds the three flat
     * MDF device sections (tv.mdfServers/mdfPower/mdfInfrastructure) —
     * tv.idfLocations/otherLocations are location-grouped and are not
     * subject to this cap. Config::FIELDS clamps this setting to a
     * minimum of 10 (proven separately by tests/run.php's pure-config
     * assertions), so any fixture meant to exercise real truncation
     * must configure a ceiling of at least 10 and supply more than 10
     * eligible devices — an earlier version of this test configured a
     * ceiling of 3, which Config::resolve() silently clamped back up
     * to 10, and with only 5 fixture devices nothing was ever actually
     * truncated (caught only once this test ran for real in CI).
     *
     * With 7 MDF Server-category devices Critical, 3 Warning, and 2
     * Healthy (12 total, ceiling 10), exactly the 7 Critical + 3
     * Warning devices must survive (worst-first truncation — a
     * Critical/Warning device can never be dropped to fit a Healthy
     * one), omittedDeviceCount must report the 2 Healthy devices that
     * didn't fit, and the ceiling itself must apply per section
     * independently, not as one combined budget across all three MDF
     * sections.
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

        // Native per-sensor threshold breach was removed entirely (see
        // Page.php's buildDeviceIssues()) — these sensor readings alone no
        // longer produce any issue, so every Critical/Warning device below
        // is given a matching Alert Rule fixture; the sensor rows stay
        // (harmless, unused-for-severity) purely as realistic fixture data.
        $criticalRule = AlertRule::factory()->create([
            'name' => 'TV ceiling fixture critical alert',
            'severity' => 'critical',
        ]);
        $warningRule = AlertRule::factory()->create([
            'name' => 'TV ceiling fixture warning alert',
            'severity' => 'warning',
        ]);

        $criticalIds = [];
        for ($i = 1; $i <= 7; $i++) {
            $critical = $makeServer("tv-ceiling-critical-{$i}.example.com");
            Sensor::factory()->for($critical)->create([
                'sensor_class' => 'temperature', 'sensor_descr' => 'Ambient',
                'sensor_current' => 90 + $i, 'sensor_limit' => 80, 'sensor_limit_warn' => 70,
                'sensor_alert' => 1, 'lastupdate' => now(),
            ]);
            Alert::factory()->create(['device_id' => $critical->device_id, 'rule_id' => $criticalRule->id]);
            $criticalIds[] = $critical->device_id;
        }

        $warningIds = [];
        for ($i = 1; $i <= 3; $i++) {
            $warning = $makeServer("tv-ceiling-warning-{$i}.example.com");
            Sensor::factory()->for($warning)->create([
                'sensor_class' => 'humidity', 'sensor_descr' => 'Ambient',
                'sensor_current' => 75, 'sensor_limit' => 95, 'sensor_limit_warn' => 70,
                'sensor_alert' => 1, 'lastupdate' => now(),
            ]);
            Alert::factory()->create(['device_id' => $warning->device_id, 'rule_id' => $warningRule->id]);
            $warningIds[] = $warning->device_id;
        }

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
        // Critical/Warning to fit Healthy" assertion vacuous.
        $payload = (new Page())->data([
            'tv_maximum_devices_rendered' => '10',
            'default_severity_healthy' => '1',
        ], $request);

        $this->assertSame(12, $payload['mdf']['server_count'], 'Desktop MDF Servers section remains the full, unfiltered/untruncated set.');
        $this->assertCount(10, $payload['tv']['mdfServers'], 'TV MDF Servers is truncated to the configured ceiling.');
        $this->assertSame(2, $payload['tv']['omittedDeviceCount'], 'Exactly the 2 devices that did not fit are reported, never silently dropped.');

        $renderedHealths = collect($payload['tv']['mdfServers'])->pluck('health')->all();
        $this->assertEqualsCanonicalizing(
            [...array_fill(0, 7, 'critical'), ...array_fill(0, 3, 'warning')],
            $renderedHealths,
            'Worst-first truncation keeps all 7 Critical devices and all 3 Warning devices; neither Healthy device displaces them.'
        );
        $this->assertFalse(
            collect($payload['tv']['mdfServers'])->contains('device_id', $healthy1->device_id),
            'A Critical/Warning device is never dropped from the rendered set to make room for a Healthy one.'
        );
        $this->assertFalse(collect($payload['tv']['mdfServers'])->contains('device_id', $healthy2->device_id));

        // A default ceiling (200) comfortably fits all 12 — proves the
        // truncation above is a real effect of the low configured
        // ceiling, not something that always happens regardless.
        $unbounded = (new Page())->data(['default_severity_healthy' => '1'], $request);
        $this->assertCount(12, $unbounded['tv']['mdfServers']);
        $this->assertSame(0, $unbounded['tv']['omittedDeviceCount']);

        // The ceiling is per-section, not one shared budget: a second
        // MDF Power device at the same low ceiling must not be starved
        // by mdfServers already having fully consumed its own budget.
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
            'tv_maximum_devices_rendered' => '10',
            'default_severity_healthy' => '1',
        ], $request);
        $this->assertCount(10, $withPower['tv']['mdfServers'], 'mdfServers ceiling is unaffected by mdfPower having its own device.');
        $this->assertCount(1, $withPower['tv']['mdfPower'], 'mdfPower has its own independent budget under the same ceiling — it is not starved by mdfServers already being full.');
        $this->assertSame(2, $withPower['tv']['omittedDeviceCount'], 'omittedDeviceCount sums across sections but mdfPower contributed zero (1 device, ceiling 10).');
    }

    /**
     * Regression guard for the real Blade compilation bug found while
     * closing out Phase 3A: BladeCompiler::storeUncompiledBlocks() ->
     * storePhpBlocks() uses the regex `(?<!@)@php(.*?)@endphp` to
     * protect raw PHP blocks before Blade's main directive compiler
     * runs. That regex does not distinguish the self-terminating
     * inline form (`@php($expr)`, used by this file's Phase 2
     * view-switch content) from the block form (`@php ... @endphp`) —
     * an inline statement with no @endphp of its own will match
     * forward to the *next* @endphp anywhere later in the file,
     * silently swallowing every line of markup in between (hundreds
     * of lines here: the rest of the view-switch, .tv-clock/
     * .tv-status-banner, the toolbar, and the entire MDF Servers/
     * Power/Infrastructure/IDF Locations/Other Locations device grid)
     * into one opaque, never-compiled raw block.
     *
     * A plain grep for "@php"/"@endphp" cannot catch this — the file
     * looks perfectly reasonable directive-by-directive; it only
     * manifests once the *compiled* output is actually parsed/
     * rendered, which is also why this went unnoticed for as long as
     * it did (the swallowed section had independently been dead code
     * behind an unrelated `@if(false)` since commit 5b44ff8, so
     * nothing ever exercised a real render of it until both bugs were
     * found and fixed together in this same effort).
     *
     * This test compiles the real page.blade.php through the actual
     * Illuminate\View\Compilers\BladeCompiler LibreNMS's own container
     * already provides (not a hand-rolled parser), then separately
     * renders it end to end, and asserts that markers belonging to
     * clearly distinct sections positioned on both sides of the
     * historically-vulnerable region (the Phase 2 view-switch's inline
     * @php($pager = ...) statements around lines 1864-1899, through to
     * the MDF/IDF/Other Locations grid a few hundred lines later) are
     * ALL present together. If a future edit reintroduces an
     * unterminated inline @php(...) ahead of some later @endphp, the
     * swallowed markers would simply be absent from the compiled/
     * rendered output and these assertions would fail — deliberately
     * not a whitespace-exact/full-HTML-snapshot comparison, which
     * would be fragile against any unrelated, legitimate markup
     * change.
     */
    public function testBladeCompilerNeverSwallowsMarkupBetweenPhpDirectives(): void
    {
        // Page.php's MDF membership check is an EXACT string match
        // (->where('location', 'MDF')), and its IDF membership check is a
        // strict format regex (^IDF[0-9]{2}(?:[A-Z]|M)?$ via isIdfLocation()).
        // The literal values below are required for these fixture devices to
        // actually land in the MDF Servers / IDF Locations sections rather
        // than silently falling into Other Locations.
        $mdfLocation = Location::factory()->create(['location' => 'MDF']);
        $idfLocation = Location::factory()->create(['location' => 'IDF01']);
        $otherLocation = Location::factory()->create(['location' => 'Warehouse Compile Guard']);

        $critical = Device::factory()->create([
            'display' => 'Compile Guard Critical',
            'hostname' => 'compile-guard-critical.example.com',
            'location_id' => $mdfLocation->id,
            'type' => 'server',
            'status' => 1,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        Sensor::factory()->for($critical)->create([
            'sensor_class' => 'temperature',
            'sensor_descr' => 'Ambient',
            'sensor_current' => 95,
            'sensor_limit' => 80,
            'sensor_limit_warn' => 70,
            'sensor_alert' => 1,
            'lastupdate' => now(),
        ]);

        $idfDevice = Device::factory()->create([
            'display' => 'Compile Guard IDF Switch',
            'hostname' => 'compile-guard-idf.example.com',
            'location_id' => $idfLocation->id,
            'type' => 'network',
            'status' => 1,
            'disabled' => 0,
            'ignore' => 0,
        ]);

        $otherDevice = Device::factory()->create([
            'display' => 'Compile Guard Other Device',
            'hostname' => 'compile-guard-other.example.com',
            'location_id' => $otherLocation->id,
            'type' => 'network',
            'status' => 1,
            'disabled' => 0,
            'ignore' => 0,
        ]);

        $user = User::factory()->create(['enabled' => 1]);
        $user->assignRole('admin');

        $rawSource = file_get_contents(
            app_path('Plugins/IdfDashboard/resources/views/page.blade.php')
        );
        $this->assertIsString($rawSource, 'page.blade.php must be readable for direct compilation.');

        // Compile the raw source directly through the real compiler
        // LibreNMS's own container already configures — not a fresh,
        // hand-rolled instance — so this exercises the exact same
        // storePhpBlocks()/compileStatements() pipeline a real request
        // does.
        $compiler = app(\Illuminate\View\Compilers\BladeCompiler::class);
        $compiled = $compiler->compileString($rawSource);

        // Blade replaces every stored raw block (from @php...@endphp,
        // @verbatim, and component-tag precompilation) with a
        // "@__raw_block_<N>__@" placeholder, then restores the real
        // content back in as its very last compilation step. A
        // placeholder that failed to resolve — the direct fingerprint
        // of exactly this class of bug — would leak into the compiled
        // output as literal, never-executed text.
        $this->assertStringNotContainsString(
            '@__raw_block_',
            $compiled,
            'Compiled output must not contain an unresolved Blade raw-block placeholder.'
        );

        foreach (['overview' => [], 'tv' => ['tv' => 1]] as $viewLabel => $extraQuery) {
            $request = Request::create('/plugin/IdfDashboard', 'GET', array_merge(['view' => 'overview'], $extraQuery));
            $request->setUserResolver(fn (): User => $user);
            $payload = (new Page())->data(['default_severity_healthy' => '1'], $request);
            $html = view()->file(
                app_path('Plugins/IdfDashboard/resources/views/page.blade.php'),
                $payload
            )->render();

            // The only fixture in this file that actually exercises the
            // restored MDF Servers/Power/Infrastructure/IDF/Other Locations
            // split (via real, differently-classified location fixtures)
            // — the other three writeVisualFixture() callers in this class
            // use a single generic location, so none of them shows this
            // specific grid. Persisted for manual/CI-artifact visual review
            // alongside the programmatic marker-order/section-boundary
            // assertions below.
            $this->writeVisualFixture("swallow-guard-$viewLabel.html", $html);

            $this->assertStringNotContainsString(
                '@__raw_block_',
                $html,
                "Rendered $viewLabel HTML must not contain an unresolved Blade raw-block placeholder."
            );

            // Markers spanning both sides of the historically-vulnerable
            // region, in file order: Priority Attention (inside the
            // Phase 2 view-switch, BEFORE the inline @php($pager=...)
            // statements starting a few dozen lines later) through to
            // MDF Servers/Power/Infrastructure, IDF Locations, and
            // Other Locations — all AFTER the block-form @php that
            // used to wrongly claim the earlier inline statements'
            // close tag. Present in both the plain and TV renders
            // (none of these headings are themselves TV-gated), so
            // checked for every $viewLabel. Every one of these must
            // survive together, in the same render, for the swallow
            // bug to be conclusively absent.
            $expectedMarkers = [
                'Priority Attention',
                'MDF Servers',
                'MDF Power',
                'MDF Infrastructure',
                'IDF Locations',
                'Other Locations',
            ];

            $markerPosition = [];
            foreach ($expectedMarkers as $marker) {
                $position = strpos($html, $marker);
                $this->assertNotFalse(
                    $position,
                    "Rendered $viewLabel HTML is missing expected marker \"$marker\" — a section between the Phase 2 view-switch and the MDF/IDF/Other Locations grid may have been swallowed."
                );
                $markerPosition[$marker] = $position;
            }

            // Relative order (not exact whitespace) proves the swallowed
            // region's sections are still emitted in their real, intended
            // sequence rather than merely all being present somewhere.
            $this->assertLessThan($markerPosition['MDF Servers'], $markerPosition['Priority Attention'], "$viewLabel HTML: Priority Attention must render before MDF Servers.");
            $this->assertLessThan($markerPosition['MDF Power'], $markerPosition['MDF Servers'], "$viewLabel HTML: MDF Servers must render before MDF Power.");
            $this->assertLessThan($markerPosition['MDF Infrastructure'], $markerPosition['MDF Power'], "$viewLabel HTML: MDF Power must render before MDF Infrastructure.");
            $this->assertLessThan($markerPosition['IDF Locations'], $markerPosition['MDF Infrastructure'], "$viewLabel HTML: MDF Infrastructure must render before IDF Locations.");
            $this->assertLessThan($markerPosition['Other Locations'], $markerPosition['IDF Locations'], "$viewLabel HTML: IDF Locations must render before Other Locations.");

            // data-tv-combined-slide is deliberately TV-only
            // (`@if($filters['tv'])`, rendered just before the
            // vulnerable region begins) — checked only for the tv
            // render, both to confirm it renders correctly there and
            // to confirm the plain render correctly does NOT include
            // TV-only markup.
            // The bare attribute name alone is not a safe marker here: the
            // embedded <script> block's client-side TV-toggle handling
            // calls document.querySelector('[data-tv-combined-slide]')
            // unconditionally (so it can gracefully no-op when the
            // element is absent), and that JS source text is present in
            // every render regardless of $filters['tv']. The real,
            // TV-gated signal is whether the actual DOM element — its
            // full opening tag — was rendered.
            $combinedSlideTag = '<div class="tv-combined-slide" data-tv-combined-slide>';

            if ($viewLabel === 'tv') {
                $this->assertStringContainsString($combinedSlideTag, $html, 'TV render must include the TV combined-fleet slide element.');
            } else {
                $this->assertStringNotContainsString($combinedSlideTag, $html, 'Plain overview render must not include the TV-only combined-fleet slide element.');
            }

            // The actual fixture devices themselves must also survive
            // rendering (not just their section's static heading), and
            // each must land INSIDE its intended section's boundaries —
            // proving the swallowed region's real @foreach loops, not
            // only its literal HTML scaffolding, executed correctly,
            // and that classification into MDF/IDF/Other genuinely
            // routed each device to the right place.
            //
            // Each search starts from its own section's already-verified
            // heading position, not from byte 0: an MDF/IDF/Other device
            // that is also actionable (as these fixtures deliberately
            // are, to exercise a real section) legitimately renders a
            // second time, earlier, inside the always-visible priority
            // panel (`<span class="priority-device">`) — an unanchored
            // strpos() would find that earlier, unrelated occurrence
            // instead of the one actually inside the section under test.
            $criticalPosition = strpos($html, 'compile-guard-critical.example.com', $markerPosition['MDF Servers']);
            $idfDevicePosition = strpos($html, 'compile-guard-idf.example.com', $markerPosition['IDF Locations']);
            $otherDevicePosition = strpos($html, 'compile-guard-other.example.com', $markerPosition['Other Locations']);

            $this->assertNotFalse($criticalPosition, "$viewLabel HTML must render the MDF-location fixture device.");
            $this->assertNotFalse($idfDevicePosition, "$viewLabel HTML must render the IDF-location fixture device.");
            $this->assertNotFalse($otherDevicePosition, "$viewLabel HTML must render the Other-location fixture device.");

            $this->assertGreaterThan($markerPosition['MDF Servers'], $criticalPosition, "$viewLabel HTML: the MDF-location fixture device must render after the MDF Servers heading.");
            $this->assertLessThan($markerPosition['MDF Power'], $criticalPosition, "$viewLabel HTML: the MDF-location fixture device must render inside the MDF Servers section, before MDF Power.");

            $this->assertGreaterThan($markerPosition['IDF Locations'], $idfDevicePosition, "$viewLabel HTML: the IDF-location fixture device must render after the IDF Locations heading.");
            $this->assertLessThan($markerPosition['Other Locations'], $idfDevicePosition, "$viewLabel HTML: the IDF-location fixture device must render inside the IDF Locations section, before Other Locations.");

            $this->assertGreaterThan($markerPosition['Other Locations'], $otherDevicePosition, "$viewLabel HTML: the Other-location fixture device must render after the Other Locations heading.");
        }
    }

    /**
     * Gate E regression matrix for Support\OperationalPolicy (Layer 3
     * fallback) — proves the core principle this redesign is built on:
     * "a technical failure is not automatically an operationally
     * critical incident". Every fixture here deliberately has NO
     * matching Alert Rule, so the observed severity comes only from
     * OperationalPolicy's own default policy, never from
     * Support\AlertRules — the moment an administrator configures a
     * real rule, that separate code path takes over entirely (already
     * covered by testAlertRuleInclusionDefaultsToEverythingAndRespects
     * AnExplicitAdministratorSelection and the Caso A-F test above).
     */
    public function testOperationalPolicyFallbackSeverityFollowsTheOperationalCriticalPolicyMatrix(): void
    {
        $location = Location::factory()->create(['location' => 'Policy Fallback Fixture']);
        $criticalGroup = DeviceGroup::factory()->create(['name' => 'Operational Critical']);

        $makeDevice = static fn (string $hostname, int $status = 1): Device => Device::factory()->create([
            'hostname' => $hostname,
            'location_id' => $location->id,
            'type' => 'server',
            'status' => $status,
            'disabled' => 0,
            'ignore' => 0,
        ]);

        // Caso 1: a kitchen-printer-style peripheral going DOWN is a
        // real technical failure and must stay visible, but is not
        // automatically an operationally critical incident — the
        // mission's own opening example.
        $peripheralDown = $makeDevice('kitchen-printer.example.com', 0);

        // Caso 2: the same raw condition (Device Down), but on a
        // device the administrator has placed in the Operational
        // Critical group — a production cluster being unreachable IS
        // Critical.
        $clusterDown = $makeDevice('production-cluster.example.com', 0);
        $criticalGroup->devices()->attach($clusterDown->device_id);

        // Caso 3: a critical server's own numeric sensor breaching a
        // Critical threshold, with no Alert Rule covering it yet — the
        // condition must not be silently hidden just because no rule
        // exists, and its severity follows the device's Operational
        // Critical membership.
        $criticalServerSensor = $makeDevice('critical-db-server.example.com', 1);
        $criticalGroup->devices()->attach($criticalServerSensor->device_id);
        Sensor::factory()->for($criticalServerSensor)->create([
            'sensor_class' => 'temperature',
            'sensor_descr' => 'Server Ambient',
            'sensor_current' => 95,
            'sensor_limit' => 80,
            'sensor_limit_warn' => 70,
            'sensor_alert' => 1,
            'lastupdate' => now(),
        ]);

        // Caso 4: "Cluster Power Supply 2 = Failed" — a state sensor
        // LibreNMS itself decodes as Critical (state_generic_value=2),
        // again with no Alert Rule attached, on an Operational Critical
        // device.
        $clusterStateSensorFailed = $makeDevice('cluster-psu.example.com', 1);
        $criticalGroup->devices()->attach($clusterStateSensorFailed->device_id);
        $psuSensor = Sensor::factory()->for($clusterStateSensorFailed)->create([
            'sensor_class' => 'state',
            'sensor_descr' => 'Power Supply 2',
            'sensor_current' => 2,
            'sensor_alert' => 1,
            'lastupdate' => now(),
        ]);
        $psuStateIndexId = DB::table('state_indexes')->insertGetId(['state_name' => 'policy-fallback-psu-state']);
        DB::table('sensors_to_state_indexes')->insert([
            'sensor_id' => $psuSensor->sensor_id,
            'state_index_id' => $psuStateIndexId,
        ]);
        DB::table('state_translations')->insert([
            'state_index_id' => $psuStateIndexId,
            'state_descr' => 'Failed',
            'state_value' => 2,
            'state_generic_value' => 2,
        ]);

        // Caso 5: service_status=1 is Warning for every device, critical
        // or not — the mission's policy matrix makes no distinction here.
        $serviceWarningStandard = $makeDevice('manageengine-agent.example.com', 1);
        Service::factory()->for($serviceWarningStandard)->create([
            'service_name' => 'ManageEngine Patch Status',
            'service_status' => 1,
            'service_message' => '3 pending patches',
        ]);

        // Caso 6: service_status=2 is Critical for every device by
        // default (unlike Device Down/sensor conditions) — a specific
        // failing service check is a stronger signal than the device's
        // own infrastructure tier. $serviceCriticalStandard is
        // deliberately NOT in the Operational Critical group.
        $serviceCriticalStandard = $makeDevice('sophos-agent.example.com', 1);
        Service::factory()->for($serviceCriticalStandard)->create([
            'service_name' => 'Sophos Tamper Protection',
            'service_status' => 2,
            'service_message' => 'Tamper protection disabled',
        ]);

        // Caso 7: service_status=3 (Unknown) must never become Critical
        // on its own — proven here on an Operational Critical device
        // specifically, the case most likely to accidentally leak into
        // Critical if this were implemented as "any nonzero status on a
        // critical device is Critical".
        $serviceUnknownOnCritical = $makeDevice('critical-unknown-service.example.com', 1);
        $criticalGroup->devices()->attach($serviceUnknownOnCritical->device_id);
        Service::factory()->for($serviceUnknownOnCritical)->create([
            'service_name' => 'Undetermined check',
            'service_status' => 3,
            'service_message' => 'Check could not determine state',
        ]);

        // Caso 8: a real Warning-severity Alert Rule on an Operational
        // Critical, currently-DOWN device must fully replace the
        // fallback, not merge/escalate with it — the device must show
        // exactly what the administrator's rule says (Warning), never
        // the Critical the Device Down fallback would otherwise produce.
        $alertOutranksFallback = $makeDevice('alert-covered-critical.example.com', 0);
        $criticalGroup->devices()->attach($alertOutranksFallback->device_id);
        $outrankingRule = AlertRule::factory()->create([
            'name' => 'Deliberately scoped down to Warning',
            'severity' => 'warning',
        ]);
        Alert::factory()->create([
            'device_id' => $alertOutranksFallback->device_id,
            'rule_id' => $outrankingRule->id,
        ]);

        // Caso 9: an active LibreNMS maintenance window suppresses the
        // fallback exactly as it already does for Alert Rule-sourced
        // issues — proven on an Operational Critical, currently-DOWN
        // device with zero Alert Rules at all, so only the fallback's
        // own maintenance gate (not an Alert Rule's own suppression)
        // could be responsible for the result.
        $maintenanceSuppressesFallback = $makeDevice('maintained-critical-cluster.example.com', 0);
        $criticalGroup->devices()->attach($maintenanceSuppressesFallback->device_id);
        $maintenanceSchedule = AlertSchedule::factory()->create([
            'title' => 'Policy fallback maintenance window',
            'start' => now()->subHour(),
            'end' => now()->addHour(),
            'behavior' => 1,
        ]);
        $maintenanceSchedule->devices()->attach($maintenanceSuppressesFallback->device_id);

        // Caso 10: an old Critical-looking event log entry must never
        // elevate a device's CURRENT severity — Priority Attention
        // ordering (Section 14) is explicit that EventLog is context
        // only. $eventLogDevice's only current condition is a Warning
        // service; if EventLog participated in severity at all, this
        // would incorrectly resolve to critical.
        $eventLogDevice = $makeDevice('old-critical-event.example.com', 1);
        Service::factory()->for($eventLogDevice)->create([
            'service_name' => 'Background Sync',
            'service_status' => 1,
            'service_message' => 'Retrying',
        ]);
        DB::table('eventlog')->insert([
            'device_id' => $eventLogDevice->device_id,
            'datetime' => now()->subHours(2),
            'message' => 'CRITICAL: historical event, must not affect current severity',
        ]);

        // Case A (Gate B): an unrelated Warning Alert Rule on an
        // Operational Critical device must NOT suppress a genuinely
        // uncovered Critical hardware condition on that same device —
        // this is the exact device-wide "any alert suppresses
        // everything" bug this redesign exists to fix.
        $unrelatedAlertUncoveredSensor = $makeDevice('unrelated-alert-uncovered-sensor.example.com', 1);
        $criticalGroup->devices()->attach($unrelatedAlertUncoveredSensor->device_id);
        $unrelatedRule = AlertRule::factory()->create([
            'name' => 'ManageEngine Pending Patches',
            'severity' => 'warning',
        ]);
        Alert::factory()->create([
            'device_id' => $unrelatedAlertUncoveredSensor->device_id,
            'rule_id' => $unrelatedRule->id,
        ]);
        $psuSensorA = Sensor::factory()->for($unrelatedAlertUncoveredSensor)->create([
            'sensor_class' => 'state',
            'sensor_descr' => 'Power Supply 1',
            'sensor_current' => 2,
            'sensor_alert' => 1,
            'lastupdate' => now(),
        ]);
        $psuStateIndexIdA = DB::table('state_indexes')->insertGetId(['state_name' => 'case-a-psu-state']);
        DB::table('sensors_to_state_indexes')->insert([
            'sensor_id' => $psuSensorA->sensor_id,
            'state_index_id' => $psuStateIndexIdA,
        ]);
        DB::table('state_translations')->insert([
            'state_index_id' => $psuStateIndexIdA,
            'state_descr' => 'Failed',
            'state_value' => 2,
            'state_generic_value' => 2,
        ]);

        // Case B/C (final hardening): tagging a rule "Sensors" in
        // Settings is administrative documentation ONLY — it must NEVER
        // suppress a sensor fallback, because no exact per-sensor
        // identity exists to confirm the rule actually covers a given
        // sensor. Two different sensors on the same device, both
        // "covered" only by category tag (never by exact identity):
        // BOTH fallbacks must remain. Safe visual duplicate over a
        // hidden incident.
        //
        // REGRESSION NOTE: the Power Supply and Fan sensors below are
        // deliberately different LibreNMS sensor_class values ('state'
        // vs 'fanspeed'), not two 'state'-class sensors. This is not
        // about OperationalPolicy's own correlation logic — it is
        // because Page::genericTelemetry()/sensorMetric() (pre-existing,
        // unrelated telemetry curation, documented at length in
        // buildTelemetry()) already summarizes ALL same-sensor_class
        // instances into a single "worst of N" telemetry entry per
        // device, by design (e.g. "Signal (worst of 82)") — a real,
        // separate behavior this correlation-hardening pass does not
        // touch. Two 'state'-class sensors here would collapse into one
        // telemetry metric before OperationalPolicy ever sees them,
        // which would test that pre-existing curation, not condition-
        // level correlation. Using two distinct sensor classes gives
        // each condition its own telemetry entry/sensor_id, which is
        // exactly what this test needs to isolate.
        $coveredSensorDevice = $makeDevice('covered-sensor.example.com', 1);
        $criticalGroup->devices()->attach($coveredSensorDevice->device_id);
        $sensorCoveringRule = AlertRule::factory()->create([
            'name' => 'Hardware Sensor Alert Rule',
            'severity' => 'critical',
        ]);
        Alert::factory()->create([
            'device_id' => $coveredSensorDevice->device_id,
            'rule_id' => $sensorCoveringRule->id,
        ]);
        $psuSensorB = Sensor::factory()->for($coveredSensorDevice)->create([
            'sensor_class' => 'state',
            'sensor_descr' => 'Power Supply 1',
            'sensor_current' => 2,
            'sensor_alert' => 1,
            'lastupdate' => now(),
        ]);
        $psuStateIndexIdB = DB::table('state_indexes')->insertGetId(['state_name' => 'case-b-psu-state']);
        DB::table('sensors_to_state_indexes')->insert([
            'sensor_id' => $psuSensorB->sensor_id,
            'state_index_id' => $psuStateIndexIdB,
        ]);
        DB::table('state_translations')->insert([
            'state_index_id' => $psuStateIndexIdB,
            'state_descr' => 'Failed',
            'state_value' => 2,
            'state_generic_value' => 2,
        ]);
        $fanSensorB = Sensor::factory()->for($coveredSensorDevice)->create([
            'sensor_class' => 'fanspeed',
            'sensor_descr' => 'Fan 1',
            'sensor_current' => 0,
            'sensor_limit_low' => 500,
            'sensor_limit_low_warn' => 1000,
            'sensor_alert' => 1,
            'lastupdate' => now(),
        ]);

        // Case C (Gate B): multiple genuinely uncovered conditions on
        // the same device must ALL surface — an unrelated rule must
        // not black out the rest, and "uncovered" must not collapse
        // to only one fallback firing. Same distinct-sensor_class
        // reasoning as Case B/C above.
        $multiUncoveredDevice = $makeDevice('multi-uncovered.example.com', 1);
        $criticalGroup->devices()->attach($multiUncoveredDevice->device_id);
        Alert::factory()->create([
            'device_id' => $multiUncoveredDevice->device_id,
            'rule_id' => $unrelatedRule->id,
        ]);
        $psuSensorC = Sensor::factory()->for($multiUncoveredDevice)->create([
            'sensor_class' => 'state',
            'sensor_descr' => 'Power Supply 1',
            'sensor_current' => 2,
            'sensor_alert' => 1,
            'lastupdate' => now(),
        ]);
        $psuStateIndexIdC = DB::table('state_indexes')->insertGetId(['state_name' => 'case-c-psu-state']);
        DB::table('sensors_to_state_indexes')->insert([
            'sensor_id' => $psuSensorC->sensor_id,
            'state_index_id' => $psuStateIndexIdC,
        ]);
        DB::table('state_translations')->insert([
            'state_index_id' => $psuStateIndexIdC,
            'state_descr' => 'Failed',
            'state_value' => 2,
            'state_generic_value' => 2,
        ]);
        $fanSensorC = Sensor::factory()->for($multiUncoveredDevice)->create([
            'sensor_class' => 'fanspeed',
            'sensor_descr' => 'Fan 1',
            'sensor_current' => 0,
            'sensor_limit_low' => 500,
            'sensor_limit_low_warn' => 1000,
            'sensor_alert' => 1,
            'lastupdate' => now(),
        ]);

        // Case D (final hardening): a rule tagged "Services" must never
        // hide a DIFFERENT failing service — same reasoning as sensors,
        // no exact per-service identity exists to correlate against.
        $serviceTagDevice = $makeDevice('service-tag-unrelated.example.com', 1);
        $serviceCoveringRule = AlertRule::factory()->create([
            'name' => 'Service Health Alert Rule',
            'severity' => 'critical',
        ]);
        Alert::factory()->create([
            'device_id' => $serviceTagDevice->device_id,
            'rule_id' => $serviceCoveringRule->id,
        ]);
        Service::factory()->for($serviceTagDevice)->create([
            'service_name' => 'Unrelated Failing Service',
            'service_status' => 2,
            'service_message' => 'Genuinely different service, not covered by the tagged rule',
        ]);

        // Case E (final hardening): a sensor/service-tagged Alert Rule
        // must never hide Device Down — only a rule tagged "Device
        // Down" itself may suppress that fallback.
        $deviceDownNotHidden = $makeDevice('device-down-not-hidden.example.com', 0);
        Alert::factory()->create([
            'device_id' => $deviceDownNotHidden->device_id,
            'rule_id' => $serviceCoveringRule->id,
        ]);

        $user = User::factory()->create(['enabled' => 1]);
        $user->assignRole('admin');
        $request = Request::create('/plugin/IdfDashboard');
        $request->setUserResolver(fn (): User => $user);
        $payload = (new Page())->data(
            [
                'operational_critical_group_name' => 'Operational Critical',
                AlertRules::CONDITION_SETTING_KEY => [
                    (string) $outrankingRule->id => ['device_down'],
                    (string) $sensorCoveringRule->id => ['sensor'],
                    (string) $serviceCoveringRule->id => ['service'],
                ],
            ],
            $request
        );
        $devices = collect($payload['otherLocations'])
            ->flatMap(fn (array $group): mixed => $group['devices'])
            ->keyBy('device_id');

        $this->assertSame('warning', $devices->get($peripheralDown->device_id)['health'], 'Caso 1: a non-critical device going down is Warning, not Critical.');
        $this->assertFalse($devices->get($peripheralDown->device_id)['operationally_critical']);
        $this->assertTrue($devices->get($peripheralDown->device_id)['issues']->contains(
            fn (array $issue): bool => $issue['source'] === 'device' && $issue['severity'] === 'warning'
        ));

        $this->assertSame('critical', $devices->get($clusterDown->device_id)['health'], 'Caso 2: an Operational Critical device going down is Critical.');
        $this->assertTrue($devices->get($clusterDown->device_id)['operationally_critical']);

        $this->assertSame('critical', $devices->get($criticalServerSensor->device_id)['health'], 'Caso 3: a Critical numeric sensor on a critical server, no Alert Rule yet, is Critical.');
        $this->assertTrue($devices->get($criticalServerSensor->device_id)['issues']->contains(
            fn (array $issue): bool => $issue['source'] === 'sensor'
                && $issue['severity'] === 'critical'
                && str_contains($issue['description'], 'no active Alert Rule covers this')
        ));

        $this->assertSame('critical', $devices->get($clusterStateSensorFailed->device_id)['health'], 'Caso 4: a Critical state sensor (Power Supply 2 = Failed) on a critical cluster is Critical.');

        $this->assertSame('warning', $devices->get($serviceWarningStandard->device_id)['health'], 'Caso 5: service_status=1 is Warning.');

        $this->assertSame('critical', $devices->get($serviceCriticalStandard->device_id)['health'], 'Caso 6: service_status=2 (Sophos Tamper Protection disabled) is Critical for every device by default, not just Operational Critical ones.');
        $this->assertFalse($devices->get($serviceCriticalStandard->device_id)['operationally_critical']);

        $this->assertSame('warning', $devices->get($serviceUnknownOnCritical->device_id)['health'], 'Caso 7: service_status=3 (Unknown) never becomes Critical, even on an Operational Critical device.');

        $this->assertSame('warning', $devices->get($alertOutranksFallback->device_id)['health'], 'Caso 8: a real Warning Alert Rule, tagged as covering Device Down, fully replaces the Critical the Device Down fallback would otherwise produce for that condition — it never merges/escalates.');
        $this->assertFalse($devices->get($alertOutranksFallback->device_id)['issues']->contains(
            fn (array $issue): bool => $issue['source'] === 'device',
        ), 'Caso 8: the fallback device-down issue must not exist once the covering Alert Rule issue exists for that same condition.');

        $this->assertSame('critical', $devices->get($unrelatedAlertUncoveredSensor->device_id)['health'], 'Case A: an unrelated Warning Alert Rule must not suppress an uncovered Critical hardware condition on the same device.');
        $this->assertTrue($devices->get($unrelatedAlertUncoveredSensor->device_id)['issues']->contains(
            fn (array $issue): bool => $issue['source'] === 'alert'
        ), 'Case A: the unrelated alert issue itself must remain.');
        $this->assertTrue($devices->get($unrelatedAlertUncoveredSensor->device_id)['issues']->contains(
            fn (array $issue): bool => $issue['source'] === 'sensor' && $issue['severity'] === 'critical'
        ), 'Case A: the uncovered PSU sensor fallback must coexist with the unrelated alert, not be hidden by it.');

        $coveredSensorIssues = $devices->get($coveredSensorDevice->device_id)['issues'];
        $this->assertTrue(
            $coveredSensorIssues->contains(fn (array $issue): bool => $issue['source'] === 'alert'),
            'Case B: the tagged Alert Rule issue remains present.'
        );
        $this->assertSame(
            2,
            $coveredSensorIssues->filter(fn (array $issue): bool => $issue['source'] === 'sensor' && $issue['severity'] === 'critical')->count(),
            'Case B/C: a "Sensors" category tag must NEVER suppress a sensor fallback — no exact per-sensor identity exists to confirm the rule actually covers either sensor, so BOTH the Power Supply and Fan fallbacks remain alongside the tagged Alert Rule. A safe visual duplicate is required over a hidden incident.'
        );

        $multiIssues = $devices->get($multiUncoveredDevice->device_id)['issues'];
        $this->assertTrue(
            $multiIssues->contains(fn (array $issue): bool => $issue['source'] === 'alert'),
            'Case C: the unrelated alert issue remains alongside both uncovered sensor fallbacks.'
        );
        $this->assertSame(
            2,
            $multiIssues->filter(fn (array $issue): bool => $issue['source'] === 'sensor' && $issue['severity'] === 'critical')->count(),
            'Case C: both uncovered sensor fallbacks (Power Supply and Fan) coexist — being uncovered never collapses to only one.'
        );

        $serviceTagIssues = $devices->get($serviceTagDevice->device_id)['issues'];
        $this->assertTrue(
            $serviceTagIssues->contains(fn (array $issue): bool => $issue['source'] === 'alert'),
            'Case D: the tagged Service Alert Rule issue remains present.'
        );
        $this->assertTrue(
            $serviceTagIssues->contains(fn (array $issue): bool => $issue['source'] === 'service' && $issue['severity'] === 'critical'),
            'Case D: a "Services" category tag must NEVER hide a DIFFERENT failing service — no exact per-service identity exists to confirm the rule actually covers this specific service.'
        );

        $deviceDownIssues = $devices->get($deviceDownNotHidden->device_id)['issues'];
        $this->assertTrue(
            $deviceDownIssues->contains(fn (array $issue): bool => $issue['source'] === 'device'),
            'Case E: a Service-tagged Alert Rule must never suppress the Device Down fallback — only a rule tagged "Device Down" itself may.'
        );

        $this->assertSame('maintenance', $devices->get($maintenanceSuppressesFallback->device_id)['health'], 'Caso 9: an active maintenance window suppresses the fallback exactly as it does Alert Rule-sourced issues.');
        $this->assertTrue($devices->get($maintenanceSuppressesFallback->device_id)['issues']->isEmpty());

        $this->assertSame('warning', $devices->get($eventLogDevice->device_id)['health'], 'Caso 10: an old Critical-looking EventLog entry must never elevate current severity above what the live service status warrants.');
        $this->assertGreaterThan(0, $devices->get($eventLogDevice->device_id)['recent_event_count'], 'Caso 10: the event is genuinely present as context, not silently dropped — it simply does not drive severity.');
    }

    /**
     * Gate C — the administrator-configurable operational severity
     * policy itself: severity overrides, per-category enable/disable,
     * the maintenance-suppression toggle, and fail-safe handling of an
     * invalid persisted value. Each scenario is intentionally isolated
     * to its own device so one setting change cannot be mistaken for
     * affecting another device's outcome.
     */
    public function testConfigurableOperationalSeverityPolicyOverridesAndFailSafeDefaults(): void
    {
        $location = Location::factory()->create(['location' => 'Policy Config Fixture']);
        $criticalGroup = DeviceGroup::factory()->create(['name' => 'Operational Critical']);

        $makeDevice = static fn (string $hostname, int $status = 1): Device => Device::factory()->create([
            'hostname' => $hostname,
            'location_id' => $location->id,
            'type' => 'server',
            'status' => $status,
            'disabled' => 0,
            'ignore' => 0,
        ]);

        // Case F (configurability demo): a normal device's Device Down
        // severity overridden to Disabled — a real, existing choice
        // (never UNKNOWN/"Informational", which does not exist as a
        // policy choice — see the dedicated UNKNOWN-semantics
        // assertions below) — without touching any source code.
        $configuredNormalDown = $makeDevice('configured-normal-down.example.com', 0);

        // Case G: a disabled numeric sensor fallback must never
        // generate an issue for an otherwise-Critical numeric sensor,
        // while the sensor's own telemetry stays fully visible.
        $disabledSensorFallback = $makeDevice('disabled-sensor-fallback.example.com', 1);
        $criticalGroup->devices()->attach($disabledSensorFallback->device_id);
        Sensor::factory()->for($disabledSensorFallback)->create([
            'sensor_class' => 'temperature',
            'sensor_descr' => 'Server Ambient',
            'sensor_current' => 95,
            'sensor_limit' => 80,
            'sensor_limit_warn' => 70,
            'sensor_alert' => 1,
            'lastupdate' => now(),
        ]);

        // Case I (configurability demo): an UNKNOWN (status=3) service
        // check overridden to Disabled instead of the default Warning.
        $configuredUnknownService = $makeDevice('configured-unknown-service.example.com', 1);
        Service::factory()->for($configuredUnknownService)->create([
            'service_name' => 'Undetermined check',
            'service_status' => 3,
            'service_message' => 'Check could not determine state',
        ]);

        // Case K: an invalid persisted severity choice ("banana") must
        // never reach OperationalPolicy — Support\Config::resolve()'s
        // own choice-type validation is the single authority here, so
        // this device must fall back to the documented safe default
        // (Warning) exactly as if nothing had been configured at all.
        // Uses a normal (non-Operational-Critical) device with a
        // Critical STATE sensor (fallback_state_sensor_normal_severity
        // is the one setting given the invalid value below) so this
        // case is unaffected by Case G's numeric-sensor-fallback
        // disable and by Case F's Device Down setting.
        $invalidConfigDevice = $makeDevice('invalid-config-device.example.com', 1);
        $invalidConfigPsu = Sensor::factory()->for($invalidConfigDevice)->create([
            'sensor_class' => 'state',
            'sensor_descr' => 'Power Supply 1',
            'sensor_current' => 2,
            'sensor_alert' => 1,
            'lastupdate' => now(),
        ]);
        $invalidConfigStateIndexId = DB::table('state_indexes')->insertGetId(['state_name' => 'case-k-psu-state']);
        DB::table('sensors_to_state_indexes')->insert([
            'sensor_id' => $invalidConfigPsu->sensor_id,
            'state_index_id' => $invalidConfigStateIndexId,
        ]);
        DB::table('state_translations')->insert([
            'state_index_id' => $invalidConfigStateIndexId,
            'state_descr' => 'Failed',
            'state_value' => 2,
            'state_generic_value' => 2,
        ]);

        // Case F (UNKNOWN semantics): a legacy-persisted 'unknown'
        // value (from before this hardening, when it was briefly
        // offered as a fake "Informational") must fail safely to the
        // documented default (Critical for an Operational Critical
        // device's state sensor) exactly like any other invalid value
        // — never treated as a real choice, since UNKNOWN is not (and
        // must never become) a policy option.
        $legacyUnknownConfigDevice = $makeDevice('legacy-unknown-config.example.com', 1);
        $criticalGroup->devices()->attach($legacyUnknownConfigDevice->device_id);
        $legacyUnknownPsu = Sensor::factory()->for($legacyUnknownConfigDevice)->create([
            'sensor_class' => 'state',
            'sensor_descr' => 'Power Supply 1',
            'sensor_current' => 2,
            'sensor_alert' => 1,
            'lastupdate' => now(),
        ]);
        $legacyUnknownStateIndexId = DB::table('state_indexes')->insertGetId(['state_name' => 'case-f-psu-state']);
        DB::table('sensors_to_state_indexes')->insert([
            'sensor_id' => $legacyUnknownPsu->sensor_id,
            'state_index_id' => $legacyUnknownStateIndexId,
        ]);
        DB::table('state_translations')->insert([
            'state_index_id' => $legacyUnknownStateIndexId,
            'state_descr' => 'Failed',
            'state_value' => 2,
            'state_generic_value' => 2,
        ]);

        // Case J: maintenance suppression itself is configurable —
        // with it turned off, an active maintenance window must no
        // longer blank out the fallback.
        //
        // REGRESSION NOTE: this device is deliberately placed in the
        // Operational Critical group so its Device Down severity
        // resolves via fallback_device_down_critical_group_severity
        // (left at its default 'critical' below), never via
        // fallback_device_down_normal_severity — which Case F above
        // deliberately sets to 'disabled' as part of a DIFFERENT
        // scenario sharing this same settings payload. A normal
        // (non-critical-group) device here would have its fallback
        // suppressed by Case F's unrelated severity override, not by
        // the maintenance-suppression setting this case actually
        // exists to test — a real prior test bug that produced the
        // exact "still suppressed" symptom of a genuine maintenance-
        // override defect without one actually being present.
        $maintenanceNotSuppressed = $makeDevice('maintenance-not-suppressed.example.com', 0);
        $criticalGroup->devices()->attach($maintenanceNotSuppressed->device_id);
        $maintenanceSchedule = AlertSchedule::factory()->create([
            'title' => 'Gate C maintenance window',
            'start' => now()->subHour(),
            'end' => now()->addHour(),
            'behavior' => 1,
        ]);
        $maintenanceSchedule->devices()->attach($maintenanceNotSuppressed->device_id);

        $user = User::factory()->create(['enabled' => 1]);
        $user->assignRole('admin');
        $request = Request::create('/plugin/IdfDashboard');
        $request->setUserResolver(fn (): User => $user);
        $payload = (new Page())->data(
            [
                'operational_critical_group_name' => 'Operational Critical',
                'fallback_device_down_normal_severity' => 'disabled',
                'fallback_numeric_sensor_enabled' => '0',
                'fallback_state_sensor_normal_severity' => 'banana',
                'fallback_state_sensor_critical_group_severity' => 'unknown',
                'fallback_service_unknown_severity' => 'disabled',
                'fallback_suppress_during_maintenance' => '0',
            ],
            $request
        );
        $devices = collect($payload['otherLocations'])
            ->flatMap(fn (array $group): mixed => $group['devices'])
            ->keyBy('device_id');

        $this->assertFalse(
            $devices->get($configuredNormalDown->device_id)['issues']->contains(fn (array $issue): bool => $issue['source'] === 'device'),
            'Case F: an administrator-configured Disabled Device Down severity generates no issue.'
        );

        $disabledSensorFallbackDevice = $devices->get($disabledSensorFallback->device_id);
        $this->assertFalse(
            $disabledSensorFallbackDevice['issues']->contains(fn (array $issue): bool => $issue['source'] === 'sensor'),
            'Case G: a disabled numeric sensor fallback must generate no issue at all.'
        );
        $this->assertTrue(
            $disabledSensorFallbackDevice['telemetry']->contains(fn (array $metric): bool => $metric['state'] === 'critical'),
            'Case G: the underlying sensor telemetry must remain visible even though no issue was generated for it.'
        );

        $this->assertFalse(
            $devices->get($configuredUnknownService->device_id)['issues']->contains(fn (array $issue): bool => $issue['source'] === 'service'),
            'Case I: an administrator-configured Disabled severity for service status UNKNOWN generates no issue.'
        );

        $this->assertSame('warning', $devices->get($invalidConfigDevice->device_id)['health'], 'Case K: an invalid persisted severity choice ("banana") safely falls back to the documented default (Warning), never an exception or a malformed severity.');

        // Case F (UNKNOWN semantics): Config::FIELDS must never offer
        // 'unknown' as a choice for any operational_severity_policy
        // field — UNKNOWN is a real technical state (Needs Review),
        // never a fake "Informational" policy severity.
        foreach (Config::FIELDS as $key => $field) {
            if (($field['group'] ?? null) !== 'operational_severity_policy' || $field['type'] !== 'choice') {
                continue;
            }

            $this->assertArrayNotHasKey(
                'unknown',
                $field['options'],
                "Case F: Settings must never offer 'unknown' as an Operational Severity Policy choice ($key) — no real Informational severity tier exists in this dashboard."
            );
        }

        // A legacy-persisted 'unknown' value (saved before this
        // hardening) must fail safely to the documented default
        // (Critical for an Operational Critical device's state sensor),
        // exactly like the "banana" case above — never treated as a
        // real choice.
        $this->assertSame(
            'critical',
            $devices->get($legacyUnknownConfigDevice->device_id)['health'],
            'Case F: a legacy-persisted "unknown" severity value safely falls back to the documented default (Critical), never a fake Informational state.'
        );

        $this->assertSame(
            'critical',
            $devices->get($maintenanceNotSuppressed->device_id)['health'],
            'Case J: with fallback_suppress_during_maintenance turned off, the Operational Critical Device Down fallback must be evaluated normally (Critical) despite the active maintenance window, not blanked out.'
        );
        $this->assertTrue(
            $devices->get($maintenanceNotSuppressed->device_id)['issues']->contains(fn (array $issue): bool => $issue['source'] === 'device'),
            'Case J: the Device Down fallback must be generated when maintenance suppression is disabled.'
        );
    }

    /**
     * Section 25's "Policy Health" panel. RefreshDatabase gives each
     * test its own isolated, rolled-back transaction, so — unlike
     * AlertRules::available()'s own docblock caveat about a shared
     * instance — this fixture can safely assert exact counts for
     * every one of PolicyHealth's six checks without any risk of
     * leftover state from another test method's own Alert Rule/
     * Device Group fixtures leaking in.
     */
    public function testPolicyHealthReflectsRealConfigurationAndIsAdminOnly(): void
    {
        $location = Location::factory()->create(['location' => 'Policy Health Fixture']);
        $criticalGroup = DeviceGroup::factory()->create(['name' => 'Operational Critical']);

        $makeDevice = static fn (string $hostname, int $status = 1): Device => Device::factory()->create([
            'hostname' => $hostname,
            'location_id' => $location->id,
            'type' => 'server',
            'status' => $status,
            'disabled' => 0,
            'ignore' => 0,
        ]);

        // Group check: OK, exactly one member.
        $groupMember = $makeDevice('policy-health-group-member.example.com', 1);
        $criticalGroup->devices()->attach($groupMember->device_id);

        // Rule-name checks (informational, name-substring heuristic
        // only): both a "down" rule and a "service" rule exist, so
        // both resolve OK — never parsed for whether they would
        // actually fire, only whether a rule by that kind of name is
        // present at all.
        $downRule = AlertRule::factory()->create(['name' => 'Device Down — Critical Infrastructure']);
        $serviceRule = AlertRule::factory()->create(['name' => 'Sophos Health Check Service']);

        // Fallback-coverage check: exactly one device currently has a
        // Critical/Warning condition with no Alert Rule attached at
        // all (Device Down, no Alert Rule of any kind on this
        // device_id) — must be counted, not silently absorbed.
        $fallbackDevice = $makeDevice('policy-health-fallback-device.example.com', 0);

        // Overlapping-alert check: exactly one device has two
        // distinct Alert Rules firing at the same time.
        $overlapDevice = $makeDevice('policy-health-overlap-device.example.com', 1);
        Alert::factory()->create(['device_id' => $overlapDevice->device_id, 'rule_id' => $downRule->id]);
        Alert::factory()->create(['device_id' => $overlapDevice->device_id, 'rule_id' => $serviceRule->id]);

        $admin = User::factory()->create(['enabled' => 1]);
        $admin->assignRole('admin');
        $adminRequest = Request::create('/plugin/IdfDashboard');
        $adminRequest->setUserResolver(fn (): User => $admin);
        $adminPayload = (new Page())->data(
            ['operational_critical_group_name' => 'Operational Critical'],
            $adminRequest
        );

        $this->assertIsArray($adminPayload['policyHealth'], 'An admin request must receive the Policy Health payload.');
        $this->assertCount(8, $adminPayload['policyHealth'], 'All eight PolicyHealth checks must be present, in a stable order (Gate D added condition-coverage and fallback-categories checks).');

        $checks = $adminPayload['policyHealth'];

        $this->assertSame('ok', $checks[0]['status']);
        $this->assertStringContainsString('Operational Critical', $checks[0]['label']);
        $this->assertStringContainsString('1 member', $checks[0]['label']);

        $this->assertSame('ok', $checks[1]['status']);
        $this->assertStringContainsString('2 of 2', $checks[1]['label'], 'Both rules are included by default — no explicit idf_included_alert_rule_ids setting was saved in this fixture.');

        $this->assertSame('info', $checks[2]['status']);
        $this->assertStringContainsString('has been tagged', $checks[2]['label'], 'Neither rule was tagged with a condition category in this fixture — informational, never hides the fallback.');

        $this->assertSame('ok', $checks[3]['status']);
        $this->assertStringContainsString('Device Down — Critical Infrastructure', $checks[3]['label']);

        $this->assertSame('ok', $checks[4]['status']);
        $this->assertStringContainsString('Sophos Health Check Service', $checks[4]['label']);

        $this->assertSame('ok', $checks[5]['status']);
        $this->assertStringContainsString('Every fallback category', $checks[5]['label'], 'No fallback_*_enabled setting was saved in this fixture, so every category defaults to enabled.');

        $this->assertSame('info', $checks[6]['status']);
        $this->assertStringContainsString('1 device', $checks[6]['label']);
        $this->assertStringContainsString('default fallback policy', $checks[6]['label']);

        $this->assertSame('warning', $checks[7]['status']);
        $this->assertStringContainsString('1 device', $checks[7]['label']);
        $this->assertStringContainsString('more than one active Alert Rule', $checks[7]['label']);

        $adminHtml = view()->file(
            app_path('Plugins/IdfDashboard/resources/views/page.blade.php'),
            $adminPayload
        )->render();
        // Checked by the exact opening HTML tag, not a bare
        // "infra-policy-health" class-name substring. Real CI (twice
        // now) proved a plain substring is not safe here: this
        // <style> block also unconditionally renders the CSS *rules*
        // that style .infra-policy-health (background/border color,
        // the summary color, and the body.tv-mode-active hide rule),
        // every one of which contains that same class name as a CSS
        // selector, for every visitor, admin or not — a class name
        // has no special immunity to this any more than the earlier
        // "Policy Health" text substring did. '<details
        // class="infra-policy-health">' is the literal opening tag
        // Blade only ever emits from inside the real
        // @if($policyHealth !== null) block (page.blade.php ~line
        // 2051) — it cannot appear in a CSS selector (those read
        // ".infra-policy-health { ... }", never "<details
        // class=..."), so this is the first check in this test that
        // is genuinely unambiguous regardless of what any nearby
        // comment or CSS rule happens to say.
        $this->assertStringContainsString('<details class="infra-policy-health">', $adminHtml, 'The panel must actually render for an admin, not just exist in the payload.');
        $this->assertStringContainsString('Sophos Health Check Service', $adminHtml);

        $viewer = User::factory()->create(['enabled' => 1]);
        $viewer->assignRole('global-read');
        $viewerRequest = Request::create('/plugin/IdfDashboard');
        $viewerRequest->setUserResolver(fn (): User => $viewer);
        $viewerPayload = (new Page())->data(
            ['operational_critical_group_name' => 'Operational Critical'],
            $viewerRequest
        );

        $this->assertNull($viewerPayload['policyHealth'], 'A non-admin request must never receive the Policy Health diagnostic payload.');

        $viewerHtml = view()->file(
            app_path('Plugins/IdfDashboard/resources/views/page.blade.php'),
            $viewerPayload
        )->render();
        // Same reasoning as the admin-positive check above, mirrored:
        // the CSS *rules* for .infra-policy-health are legitimately
        // present in this page's <style> block for every visitor
        // regardless of role (that is normal, harmless stylesheet
        // architecture, not an information leak — a CSS selector
        // existing does not disclose that any element using it is
        // actually on the page). What must genuinely be absent for a
        // non-admin is the opening <details> tag itself; checking for
        // the bare class name would incorrectly fail for every user,
        // not just a non-admin, since the stylesheet is role-agnostic
        // by design.
        $this->assertStringNotContainsString('<details class="infra-policy-health">', $viewerHtml, 'The panel must not exist in the rendered HTML at all for a non-admin — absent, not merely hidden by CSS.');
    }

    /**
     * Mission Section 30's two remaining "healthy — no false issue"
     * scenarios: a healthy UPS's live telemetry must stay visible
     * (not merely absent-of-an-issue, genuinely rendered), and a
     * healthy Sophos service check must never itself become an issue.
     * Both are proven true through the real Blade render, not just
     * through Page::data()'s array shape — a metric/service that is
     * correctly excluded from the *issue* list but never actually
     * reaches the compiled HTML at all would still fail the mission's
     * own "live telemetry remains visible" requirement.
     */
    public function testHealthyUpsTelemetryRemainsVisibleAndHealthySophosProducesNoIssue(): void
    {
        $location = Location::factory()->create(['location' => 'Healthy Telemetry Fixture']);

        $healthyUps = Device::factory()->create([
            'display' => 'Healthy UPS',
            'hostname' => 'policy-health-ups.example.com',
            'location_id' => $location->id,
            'type' => 'network',
            'status' => 1,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        // buildTelemetry()'s 'ups' role curates exactly two readings —
        // Input Voltage and Battery Charge (Page.php ~line 2017) — both
        // are required for a genuinely, fully "healthy UPS" fixture.
        // Real CI caught the gap this comment now documents: providing
        // only Battery Charge left Input Voltage resolving through
        // unavailableSensorMetric() to Severity::NO_SENSOR, and
        // buildDeviceIssues()'s own pre-existing (not this session's)
        // NO_SENSOR handling (~line 1624) correctly, intentionally
        // pushes one informational 'missing sensor' issue for that —
        // exactly the mission's own Section 6 "Missing required sensor
        // -> Needs Review" policy, not a defect in either this fixture's
        // battery reading or this session's OperationalPolicy/
        // PolicyHealth work. A safely-healthy Input Voltage sensor
        // (well below both threshold values, mirroring the explicit-
        // threshold pattern already proven correct by Caso 3's Critical
        // sensor fixture) closes that gap.
        Sensor::factory()->for($healthyUps)->create([
            'sensor_class' => 'voltage',
            'sensor_descr' => 'Input Voltage',
            'sensor_current' => 120,
            'sensor_limit' => 140,
            'sensor_limit_warn' => 130,
            'sensor_alert' => 1,
            'lastupdate' => now(),
        ]);
        Sensor::factory()->for($healthyUps)->create([
            'sensor_class' => 'charge',
            'sensor_descr' => 'Battery Charge',
            'sensor_current' => 95,
            'sensor_alert' => 1,
            'lastupdate' => now(),
        ]);

        $healthySophosDevice = Device::factory()->create([
            'display' => 'Healthy Sophos Endpoint',
            'hostname' => 'policy-health-sophos.example.com',
            'location_id' => $location->id,
            'type' => 'server',
            'status' => 1,
            'disabled' => 0,
            'ignore' => 0,
        ]);
        Service::factory()->for($healthySophosDevice)->create([
            'service_name' => 'Sophos Tamper Protection',
            'service_status' => 0,
            'service_message' => 'Protected',
        ]);

        $admin = User::factory()->create(['enabled' => 1]);
        $admin->assignRole('admin');
        $request = Request::create('/plugin/IdfDashboard');
        $request->setUserResolver(fn (): User => $admin);
        $payload = (new Page())->data([], $request);
        $devices = collect($payload['otherLocations'])
            ->flatMap(fn (array $group): mixed => $group['devices'])
            ->keyBy('device_id');

        $this->assertSame('healthy', $devices->get($healthyUps->device_id)['health'], 'A healthy UPS with no other condition must remain Healthy, not be turned into a false issue by its own telemetry.');
        $this->assertTrue($devices->get($healthyUps->device_id)['issues']->isEmpty(), 'A healthy battery-charge reading must not itself become an issue.');

        $this->assertSame('healthy', $devices->get($healthySophosDevice->device_id)['health'], 'A healthy Sophos service check must not make the device anything other than Healthy.');
        $this->assertFalse($devices->get($healthySophosDevice->device_id)['issues']->contains(
            fn (array $issue): bool => $issue['source'] === 'service'
        ), 'service_status=0 must never itself produce a service issue — Sophos healthy means no issue, not a hidden one.');

        $html = view()->file(
            app_path('Plugins/IdfDashboard/resources/views/page.blade.php'),
            $payload
        )->render();
        $this->assertStringContainsString('Battery Charge', $html, 'The healthy UPS\'s curated telemetry must genuinely render on the card, not just be absent from the issue list — Section 30\'s "live telemetry remains visible" requirement.');
        $this->assertStringNotContainsString('Sophos Tamper Protection', $html, 'A healthy service check must not appear anywhere in the rendered issue markup.');
    }

    private function writeVisualFixture(string $name, string $html): void
    {
        $directory = getenv('IDF_VISUAL_OUTPUT_DIR');

        if (is_string($directory) && is_dir($directory)) {
            file_put_contents($directory . DIRECTORY_SEPARATOR . $name, $html);
        }
    }
}
