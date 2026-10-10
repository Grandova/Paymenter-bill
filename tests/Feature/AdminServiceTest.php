<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\CreateService;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Admin\Resources\ServiceResource\Pages\ListService;
use App\Classes\Extension\Server as ServerExtension;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AdminServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_services_cannot_be_deleted_through_the_bulk_action(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $product = $this->createProduct();
        Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
        ]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(ListService::class)
            ->assertTableBulkActionDoesNotExist('delete');
    }

    public function test_admin_can_find_services_by_balance_auto_renewal_setting(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $customer = User::factory()->create();
        $product = $this->createProduct();
        $renewing = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'auto_renew' => true,
        ]);
        $manual = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'auto_renew' => false,
        ]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(ListService::class)
            ->filterTable('auto_renew', 1)
            ->assertCanSeeTableRecords([$renewing])
            ->assertCanNotSeeTableRecords([$manual]);
    }

    public function test_server_actions_keep_the_service_status_and_stock_in_sync(): void
    {
        $extensionName = 'Paymenter\\Extensions\\Servers\\ServiceLifecycleTest\\ServiceLifecycleTest';
        if (!class_exists($extensionName)) {
            $extension = new class extends ServerExtension
            {
                public function suspendServer(Service $service, $settings, $properties): bool
                {
                    return true;
                }

                public function unsuspendServer(Service $service, $settings, $properties): bool
                {
                    return true;
                }

                public function terminateServer(Service $service, $settings, $properties): bool
                {
                    return true;
                }
            };
            class_alias($extension::class, $extensionName);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $customer = User::factory()->create();
        $server = Server::create(['name' => 'Lifecycle server', 'type' => 'server', 'extension' => 'ServiceLifecycleTest', 'enabled' => true]);
        $product = $this->createProduct(['server_id' => $server->id, 'stock' => 3]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'quantity' => 2,
        ]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));
        $page = Livewire::test(EditService::class, ['record' => $service->getRouteKey()]);

        $page->callAction('changeStatus', data: ['action' => 'suspend', 'sendNotification' => false]);
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);

        $page->callAction('changeStatus', data: ['action' => 'unsuspend', 'sendNotification' => false]);
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);

        $invoice = $service->invoices()->create([
            'user_id' => $customer->id,
            'currency_code' => 'CNY',
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now(),
        ]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => 10,
            'quantity' => 2,
        ]);

        $page->callAction('changeStatus', data: ['action' => 'terminate', 'sendNotification' => false]);

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        $this->assertSame(5, (int) $product->product->fresh()->stock);
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
    }

    public function test_admin_cannot_create_a_service_with_a_non_positive_quantity(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $customer = User::factory()->create();
        $product = $this->createProduct();
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(CreateService::class)
            ->fillForm([
                'product_id' => $product->product->id,
                'plan_id' => $product->plan->id,
                'user_id' => $customer->id,
                'status' => 'active',
                'quantity' => 0,
                'expires_at' => now()->addMonth()->toDateString(),
                'currency_code' => 'CNY',
                'price' => 10,
            ])
            ->call('create')
            ->assertHasFormErrors(['quantity']);
    }

    public function test_failed_and_unsupported_instance_actions_do_not_change_service_status(): void
    {
        $extensionName = 'Paymenter\\Extensions\\Servers\\FailedLifecycleTest\\FailedLifecycleTest';
        if (!class_exists($extensionName)) {
            $extension = new class extends ServerExtension
            {
                public function suspendServer(Service $service, $settings, $properties): bool
                {
                    return false;
                }

                public function unsuspendServer(Service $service, $settings, $properties): bool
                {
                    return false;
                }
            };
            class_alias($extension::class, $extensionName);
        }

        config(['app.debug' => false]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $server = Server::create(['name' => 'Failed lifecycle', 'type' => 'server', 'extension' => 'FailedLifecycleTest', 'enabled' => true]);
        $product = $this->createProduct(['server_id' => $server->id]);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
        ]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('changeStatus', data: ['action' => 'suspend', 'sendNotification' => false])
            ->assertNotified(__('Error occured while triggering the action:'));
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);

        $service->update(['status' => Service::STATUS_SUSPENDED]);
        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('changeStatus', data: ['action' => 'unsuspend', 'sendNotification' => false])
            ->assertNotified(__('Error occured while triggering the action:'));
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('changeStatus', data: ['action' => 'terminate', 'sendNotification' => false])
            ->assertHasActionErrors(['action']);
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);
    }

    public function test_admin_cannot_create_a_service_with_a_plan_from_another_product(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $customer = User::factory()->create();
        $product = $this->createProduct();
        $otherProduct = $this->createProduct();
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(CreateService::class)
            ->fillForm([
                'product_id' => $product->product->id,
                'plan_id' => $otherProduct->plan->id,
                'user_id' => $customer->id,
                'status' => 'pending',
                'quantity' => 1,
                'currency_code' => 'CNY',
                'price' => 10,
            ])
            ->call('create')
            ->assertHasFormErrors(['plan_id']);

        $this->assertDatabaseCount('services', 0);
    }

    public function test_service_editor_without_delete_permission_cannot_terminate_an_instance(): void
    {
        Http::preventStrayRequests();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $role = Role::where('name', 'admin')->first();
        $role->update(['permissions' => ['admin.services.viewAny', 'admin.services.update']]);
        $admin = User::factory()->create(['role_id' => $role->id]);
        $server = Server::create(['name' => 'Restricted interface', 'type' => 'server', 'extension' => 'Pterodactyl', 'enabled' => true]);
        $product = $this->createProduct(['server_id' => $server->id]);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
        ]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('changeStatus', data: ['action' => 'terminate', 'sendNotification' => false])
            ->assertHasActionErrors(['action']);

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        Http::assertNothingSent();
    }
}
