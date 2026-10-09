<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\TerminateJob;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Clicd\Clicd;
use Paymenter\Extensions\Servers\Clicd\Client\ClicdClient;
use Paymenter\Extensions\Servers\Clicd\Jobs\SyncExpiry;
use Paymenter\Extensions\Servers\Clicd\Livewire\Manage;
use Paymenter\Extensions\Servers\Clicd\Services\LifecycleService;
use Tests\TestCase;

class ClicdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new Clicd)->boot();
        Http::preventStrayRequests();
    }

    protected function service(bool $bound = true): Service
    {
        $server = Server::create(['name' => 'CLICD Test', 'type' => 'server', 'extension' => 'Clicd', 'enabled' => true]);
        foreach (['api_url' => 'https://clicd.test', 'api_key' => 'test-key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value, 'encrypted' => $key === 'api_key']);
        }
        $product = $this->createProduct(['server_id' => $server->id]);
        $service = Service::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => 'active']);
        if ($bound) {
            $service->properties()->create(['key' => 'clicd_instance_uuid', 'name' => 'UUID', 'value' => 'instance-uuid']);
        }

        return $service;
    }

    protected function containerData(): array
    {
        return ['uuid' => 'instance-uuid', 'id' => 9, 'name' => 'pm-9', 'status' => 'stopped', 'virtualization' => 'lxc',
            'template' => 'debian-bookworm', 'vcpu' => 1, 'ram_mb' => 512, 'disk_gb' => 10, 'ip' => '10.0.0.9', 'ipv6' => '',
            'ssh_port' => 22009, 'ssh_password' => 'never-expose-secret', 'network_down_mbps' => 100, 'network_up_mbps' => 50,
            'port_mapping_limit' => 2, 'port_mappings' => [], 'snapshot_limit' => 1];
    }

    public function test_reading_an_unbound_service_never_creates_or_guesses_an_instance(): void
    {
        $service = $this->service(false);
        $this->actingAs($service->user);
        Livewire::test(Manage::class, ['service' => $service])->call('refreshInstance')->assertSee('实例尚未开通或未绑定');
        Http::assertNothingSent();
    }

    public function test_client_cannot_read_another_users_instance_or_control_a_suspended_service(): void
    {
        $service = $this->service();
        $this->actingAs(User::factory()->create());
        Livewire::test(Manage::class, ['service' => $service])->assertForbidden();
        $this->actingAs($service->user);
        $component = Livewire::test(Manage::class, ['service' => $service]);
        $service->update(['status' => 'suspended']);
        $component->call('power', 'start')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_admin_view_permission_does_not_allow_mutations(): void
    {
        $service = $this->service();
        $role = Role::create(['name' => 'Instance viewer', 'permissions' => ['admin.services.view']]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]));
        Livewire::test(Manage::class, ['service' => $service, 'admin' => true])->call('power', 'stop')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_refresh_uses_real_runtime_state_and_never_exposes_node_credentials(): void
    {
        $service = $this->service();
        $this->actingAs($service->user);
        Http::fake([
            'clicd.test/api/v1/containers/instance-uuid' => Http::response(['success' => true, 'data' => $this->containerData()]),
            'clicd.test/api/v1/containers/instance-uuid/usage' => Http::response(['success' => true, 'data' => ['cpu_usage_pct' => 0]]),
            'clicd.test/api/v1/containers/instance-uuid/traffic' => Http::response(['success' => true, 'data' => ['rx_used_bytes' => 123, 'tx_used_bytes' => 456]]),
        ]);
        Livewire::test(Manage::class, ['service' => $service])->call('refreshInstance')->assertSet('instance.status', 'stopped')
            ->assertSee('已关机')->assertDontSee('never-expose-secret')->assertDontSee('test-key');
        $this->assertSame('active', $service->refresh()->status);
        $this->assertSame('stopped', $service->properties()->where('key', 'clicd_status')->value('value'));
        $this->assertFalse($service->properties()->where('value', 'never-expose-secret')->exists());
        Http::assertSent(fn ($request) => $request->hasHeader('X-API-Key', 'test-key'));
    }

    public function test_api_errors_are_shown_without_faking_a_running_instance(): void
    {
        $service = $this->service();
        $this->actingAs($service->user);
        Http::fake(['*' => Http::response('<html>Bad gateway</html>', 502)]);
        Livewire::test(Manage::class, ['service' => $service])->call('refreshInstance')->assertSet('instance', [])->assertSee('502');
        $this->assertFalse($service->properties()->where('key', 'clicd_status')->exists());
    }

    public function test_task_submission_is_not_treated_as_completion_and_uses_the_real_task_list_endpoint(): void
    {
        $service = $this->service();
        Http::fake([
            'clicd.test/api/v1/containers/instance-uuid/start' => Http::response(['success' => true, 'data' => ['task_id' => 'task-1']], 202),
            'clicd.test/api/v1/tasks' => Http::sequence()->push(['success' => true, 'data' => [['id' => 'task-1', 'type' => 'start', 'status' => 'pending']]])
                ->push(['success' => true, 'data' => [['id' => 'task-1', 'type' => 'start', 'status' => 'done']]]),
        ]);
        $lifecycle = new LifecycleService(new ClicdClient(['api_url' => 'https://clicd.test', 'api_key' => 'test-key']));
        $lifecycle->power($service, 'start', false);
        $this->assertSame('pending', $lifecycle->task($service)['status']);
        $this->assertNull($lifecycle->task($service));
        $this->assertFalse($service->properties()->where('key', 'clicd_task')->exists());
        $this->assertFalse($service->properties()->where('key', 'clicd_status')->exists());
    }

    public function test_uncertain_create_retry_does_not_provision_a_duplicate(): void
    {
        $service = $this->service(false);
        $service->properties()->create(['key' => 'clicd_instance_name', 'name' => 'Name', 'value' => 'pm-reserved']);
        $service->properties()->create(['key' => 'clicd_create_attempt', 'name' => 'Attempt', 'value' => now()->toIso8601String()]);
        Http::fake(['clicd.test/api/v1/containers/pm-reserved' => Http::response(['success' => true, 'data' => $this->containerData()])]);
        ExtensionHelper::createServer($service);
        $this->assertSame('instance-uuid', $service->properties()->where('key', 'clicd_instance_uuid')->value('value'));
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_console_reuses_a_scoped_native_account_without_storing_its_password(): void
    {
        $service = $this->service();
        $this->actingAs($service->user);
        Http::fake([
            'clicd.test/api/v1/containers/instance-uuid' => Http::response(['success' => true, 'data' => $this->containerData()]),
            'clicd.test/api/v1/sub-user/create' => Http::response(['success' => true, 'data' => [
                'container_uuids' => ['instance-uuid'], 'access_code' => 'test-code', 'password' => 'instance-login-password',
            ]]),
        ]);
        Livewire::test(Manage::class, ['service' => $service])->call('console')->assertReturned([
            'url' => 'https://clicd.test/login?code=test-code', 'password' => 'instance-login-password',
        ])->assertDontSee('instance-login-password')->assertDontSee('test-key');
        $this->assertFalse($service->properties()->where('value', 'instance-login-password')->exists());
        Http::assertSent(fn ($request) => $request->url() === 'https://clicd.test/api/v1/sub-user/create' && $request['container_name'] === 'pm-9');
    }

    public function test_console_never_reveals_an_account_that_also_controls_another_instance(): void
    {
        $service = $this->service();
        $this->actingAs($service->user);
        Http::fake([
            'clicd.test/api/v1/containers/instance-uuid' => Http::response(['success' => true, 'data' => $this->containerData()]),
            'clicd.test/api/v1/sub-user/create' => Http::response(['success' => true, 'data' => [
                'container_uuids' => ['instance-uuid', 'someone-else'], 'access_code' => 'unsafe-code', 'password' => 'unsafe-password',
            ]]),
        ]);
        Livewire::test(Manage::class, ['service' => $service])->call('console')->assertReturned(null)
            ->assertSee('并非仅绑定当前实例')->assertDontSee('unsafe-password')->assertDontSee('unsafe-code');
    }

    public function test_node_policy_block_is_respected_for_customer_power_requests(): void
    {
        $service = $this->service();
        $this->actingAs($service->user);
        Http::fake(['clicd.test/api/v1/containers/instance-uuid' => Http::response(['success' => true, 'data' => $this->containerData() + ['policy_blocked' => true]])]);
        Livewire::test(Manage::class, ['service' => $service])->call('power', 'start')->assertSee('已被节点策略暂停');
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_renewal_synchronizes_expiry_without_reopening_a_suspended_instance(): void
    {
        Queue::fake();
        $service = $this->service();
        $service->update(['expires_at' => now()->addMonths(2)]);
        Queue::assertPushed(SyncExpiry::class, fn ($job) => $job->service->is($service));
        $service->update(['status' => 'suspended']);
        Http::fake(['clicd.test/api/v1/containers/instance-uuid/expiry' => Http::response(['success' => true])]);
        (new SyncExpiry($service))->handle();
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && Carbon::parse($request['expires_at'])->isPast());
        Http::assertSentCount(1);
    }

    public function test_expired_clicd_services_are_suspended_and_deleted_after_three_days(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 00:00:00'));
        $service = $this->service();
        Queue::fake();
        $service->update(['expires_at' => '2026-10-07']);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_SUSPENDED, $service->refresh()->status);
        Queue::assertPushed(SuspendJob::class, fn ($job) => $job->service->is($service));
        Queue::assertNotPushed(TerminateJob::class);

        $this->travelTo(Carbon::parse('2026-10-11 00:00:00'));
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_SUSPENDED, $service->refresh()->status);
        Queue::assertPushed(TerminateJob::class, fn ($job) => $job->service->is($service));

        Http::fake([
            'clicd.test/api/v1/containers/instance-uuid/*' => Http::response(['success' => true, 'data' => ['task_id' => 'delete-1']], 202),
            'clicd.test/api/v1/tasks' => Http::response(['success' => true, 'data' => [['id' => 'delete-1', 'status' => 'done']]]),
        ]);
        (new TerminateJob($service))->handle();
        $this->assertSame(Service::STATUS_CANCELLED, $service->refresh()->status);
    }

    public function test_provisioning_uses_typed_product_configuration_and_records_the_real_uuid(): void
    {
        $service = $this->service(false);
        $name = null;
        Http::fake([
            'clicd.test/api/v1/images/enabled*' => Http::response(['success' => true, 'data' => [['id' => 'debian-bookworm', 'name' => 'Debian 12']]]),
            'clicd.test/api/v1/containers' => function ($request) use (&$name) {
                $name = $request['name'];
                $this->assertSame(1024, $request['ram_mb']);
                $this->assertSame(false, $request['assign_ipv6']);
                $this->assertSame('auto_password', $request['ssh_auth_mode']);

                return Http::response(['success' => true], 201);
            },
            'clicd.test/api/v1/containers/*' => function () use (&$name) {
                return Http::response(['success' => true, 'data' => array_replace($this->containerData(), ['name' => $name])]);
            },
        ]);
        $lifecycle = new LifecycleService(new ClicdClient(['api_url' => 'https://clicd.test', 'api_key' => 'test-key']));
        $lifecycle->createServer($service, ['template_id' => 'debian-bookworm', 'ram_mb' => '1024', 'assign_ipv6' => '0'], []);
        $this->assertSame('instance-uuid', $service->properties()->where('key', 'clicd_instance_uuid')->value('value'));
        $this->assertStringStartsWith('pm-' . $service->id . '-', $name);
        Http::assertSentCount(3);
    }
}
