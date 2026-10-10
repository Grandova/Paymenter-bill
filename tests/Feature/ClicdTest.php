<?php

namespace Tests\Feature;

use App\Events\ServiceCancellation\Created;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\CreateJob;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\TerminateJob;
use App\Listeners\CancellationCreatedListener;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\User;
use App\Services\Service\RenewServiceService;
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

    public function test_customer_can_manually_add_a_nat_port_mapping(): void
    {
        $service = $this->service();
        $this->actingAs($service->user);
        $instance = array_replace($this->containerData(), ['port_mapping_limit' => 3]);
        $instance['port_mappings'] = [
            ['container_port' => 22, 'host_port' => 22009, 'protocol' => 'tcp', 'description' => 'SSH'],
        ];
        Http::fake([
            'clicd.test/api/v1/containers/instance-uuid' => Http::response(['success' => true, 'data' => array_replace($instance, ['port_mappings' => [
                ['container_port' => 22, 'host_port' => 22009, 'protocol' => 'tcp', 'description' => 'SSH'],
                ['container_port' => 8080, 'host_port' => 23000, 'protocol' => 'tcp', 'description' => '网站 HTTP'],
            ]])]),
            'clicd.test/api/v1/containers/instance-uuid/usage' => Http::response(['success' => true, 'data' => []]),
            'clicd.test/api/v1/containers/instance-uuid/traffic' => Http::response(['success' => true, 'data' => []]),
            'clicd.test/api/v1/containers/instance-uuid/port-mappings' => Http::response(['success' => true, 'data' => []]),
        ]);

        Livewire::test(Manage::class, ['service' => $service])->call('refreshInstance')
            ->set('mappingName', '网站 HTTP')
            ->set('mappingProtocol', 'tcp')
            ->set('mappingHostPort', 23000)
            ->set('mappingContainerPort', 8080)
            ->call('addPortMapping')
            ->assertSee('网站 HTTP')->assertSet('notice', '端口映射已添加。');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://clicd.test/api/v1/containers/instance-uuid/port-mappings'
            && $request['host_port'] === 23000
            && $request['container_port'] === 8080
            && $request['protocol'] === 'tcp'
            && $request['description'] === '网站 HTTP');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'random-port'));
    }

    public function test_customer_cannot_delete_the_default_ssh_port_mapping(): void
    {
        $service = $this->service();
        $this->actingAs($service->user);
        $instance = array_replace($this->containerData(), ['port_mappings' => [
            ['container_port' => 22, 'host_port' => 22009, 'protocol' => 'tcp', 'description' => 'SSH'],
        ]]);
        Http::fake([
            'clicd.test/api/v1/containers/instance-uuid' => Http::response(['success' => true, 'data' => $instance]),
            'clicd.test/api/v1/containers/instance-uuid/usage' => Http::response(['success' => true, 'data' => []]),
            'clicd.test/api/v1/containers/instance-uuid/traffic' => Http::response(['success' => true, 'data' => []]),
        ]);

        Livewire::test(Manage::class, ['service' => $service])->call('refreshInstance')
            ->call('deletePortMapping', 0)->assertSet('error', '默认 SSH 映射不能删除。');

        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }

    public function test_customer_can_delete_an_added_nat_mapping(): void
    {
        $service = $this->service();
        $this->actingAs($service->user);
        $mappings = [
            ['container_port' => 22, 'host_port' => 22009, 'protocol' => 'tcp', 'description' => 'SSH'],
            ['container_port' => 8080, 'host_port' => 23000, 'protocol' => 'tcp', 'description' => '网站 HTTP'],
        ];
        $before = array_replace($this->containerData(), ['port_mappings' => $mappings]);
        $after = array_replace($this->containerData(), ['port_mappings' => [$mappings[0]]]);
        Http::fake([
            'clicd.test/api/v1/containers/instance-uuid' => Http::sequence()
                ->push(['success' => true, 'data' => $before])
                ->push(['success' => true, 'data' => $before])
                ->push(['success' => true, 'data' => $before])
                ->push(['success' => true, 'data' => $after]),
            'clicd.test/api/v1/containers/instance-uuid/usage' => Http::response(['success' => true, 'data' => []]),
            'clicd.test/api/v1/containers/instance-uuid/traffic' => Http::response(['success' => true, 'data' => []]),
            'clicd.test/api/v1/containers/instance-uuid/port-mappings/1' => Http::response(['success' => true, 'data' => []]),
        ]);

        Livewire::test(Manage::class, ['service' => $service])->call('refreshInstance')
            ->call('deletePortMapping', 1)
            ->assertSet('instance.port_mappings', [$mappings[0]])
            ->assertSet('notice', '端口映射已删除。');

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://clicd.test/api/v1/containers/instance-uuid/port-mappings/1');
    }

    public function test_nat_errors_are_translated_without_showing_other_instance_details(): void
    {
        $service = $this->service();
        $this->actingAs($service->user);
        Http::fake([
            'clicd.test/api/v1/containers/instance-uuid' => Http::response(['success' => true, 'data' => $this->containerData()]),
            'clicd.test/api/v1/containers/instance-uuid/port-mappings' => Http::sequence()
                ->push(['success' => false, 'message' => 'host port 22000/all already used on the same IPv4 by container TS (ID: 1)'], 422)
                ->push(['success' => false, 'message' => 'host port must be within configured NAT4 range 20000-65535'], 422),
        ]);

        $component = Livewire::test(Manage::class, ['service' => $service])
            ->set('mappingName', 'SSH')
            ->set('mappingProtocol', 'tcp')
            ->set('mappingHostPort', 22000)
            ->set('mappingContainerPort', 22)
            ->call('addPortMapping')
            ->assertSee('该公网端口已被占用，请更换端口。')
            ->assertDontSee('container TS')
            ->assertDontSee('ID: 1');

        $component->set('mappingHostPort', 19999)->call('addPortMapping')
            ->assertSee('公网端口不在节点配置的 NAT4 端口范围内，请更换端口。');
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
        $service->product->update(['stock' => 5]);
        $service->update(['quantity' => 2]);
        Queue::fake();
        $service->update(['expires_at' => '2026-10-07']);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_SUSPENDED, $service->refresh()->status);
        Queue::assertPushed(SuspendJob::class, fn ($job) => $job->service->is($service));
        Queue::assertNotPushed(TerminateJob::class);

        $this->travelTo(Carbon::parse('2026-10-10 00:00:01'));
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_SUSPENDED, $service->refresh()->status);
        Queue::assertPushed(TerminateJob::class, fn ($job) => $job->service->is($service));
        $this->artisan('app:cron-job')->assertExitCode(0);
        Queue::assertPushed(TerminateJob::class, 1);
        $this->assertSame(5, $service->product->fresh()->stock);

        Http::fake([
            'clicd.test/api/v1/containers/instance-uuid/*' => Http::response(['success' => true, 'data' => ['task_id' => 'delete-1']], 202),
            'clicd.test/api/v1/tasks' => Http::response(['success' => true, 'data' => [['id' => 'delete-1', 'status' => 'done']]]),
        ]);
        (new TerminateJob($service))->handle();
        $this->assertSame(Service::STATUS_CANCELLED, $service->refresh()->status);
        $this->assertSame(7, $service->product->fresh()->stock);

        (new TerminateJob($service))->handle();
        $this->assertSame(7, $service->product->fresh()->stock);
        Http::assertSentCount(2);
    }

    public function test_immediate_clicd_cancellation_waits_for_instance_deletion_before_releasing_stock(): void
    {
        $service = $this->service();
        $service->product->update(['stock' => 0]);
        $service->update(['quantity' => 2]);
        $cancellation = ServiceCancellation::withoutEvents(fn () => ServiceCancellation::create([
            'service_id' => $service->id,
            'type' => 'immediate',
        ]));
        Queue::fake();

        (new CancellationCreatedListener)->handle(new Created($cancellation));

        $this->assertSame(Service::STATUS_ACTIVE, $service->refresh()->status);
        $this->assertSame(0, $service->product->fresh()->stock);
        Queue::assertPushed(TerminateJob::class, fn ($job) => $job->service->is($service));

        Http::fake([
            'clicd.test/api/v1/containers/instance-uuid/*' => Http::response(['success' => true, 'data' => ['task_id' => 'delete-1']], 202),
            'clicd.test/api/v1/tasks' => Http::response(['success' => true, 'data' => [['id' => 'delete-1', 'status' => 'done']]]),
        ]);
        (new TerminateJob($service))->handle();

        $this->assertSame(Service::STATUS_CANCELLED, $service->refresh()->status);
        $this->assertSame(2, $service->product->fresh()->stock);
    }

    public function test_immediate_cancellation_restores_stock_when_current_stock_is_zero(): void
    {
        $product = $this->createProduct(['stock' => 0]);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'quantity' => 2,
            'status' => Service::STATUS_ACTIVE,
        ]);
        $cancellation = new ServiceCancellation(['service_id' => $service->id, 'type' => 'immediate']);
        Queue::fake();

        (new CancellationCreatedListener)->handle(new Created($cancellation));

        $this->assertSame(Service::STATUS_CANCELLED, $service->refresh()->status);
        $this->assertSame(2, $product->product->fresh()->stock);
    }

    public function test_cancelled_service_cannot_be_provisioned_or_renewed(): void
    {
        $service = $this->service();
        $service->update(['status' => Service::STATUS_CANCELLED]);
        Queue::fake();

        (new CreateJob($service))->handle();
        (new RenewServiceService)->handle($service);

        $this->assertSame(Service::STATUS_CANCELLED, $service->refresh()->status);
        Queue::assertNotPushed(CreateJob::class);
        Http::assertNothingSent();
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
