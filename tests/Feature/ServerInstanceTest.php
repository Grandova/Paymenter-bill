<?php

namespace Tests\Feature;

use App\Admin\Resources\ProductResource;
use App\Admin\Resources\ProductResource\Pages\EditProduct;
use App\Admin\Resources\ServerInstanceResource;
use App\Admin\Resources\ServerInstanceResource\Pages\ListServerInstances;
use App\Admin\Resources\ServerResource\Pages\CreateServer;
use App\Admin\Resources\ServerResource\Pages\EditServer;
use App\Admin\Resources\ServerResource\Pages\ListServers;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Enums\InvoiceTransactionStatus;
use App\Models\ConfigOption;
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

class ServerInstanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_server_creation_persists_extension_setting_metadata(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(CreateServer::class)
            ->fillForm([
                'name' => 'Pterodactyl',
                'extension' => 'Pterodactyl',
                'settings' => [
                    'host' => 'https://panel.example.test',
                    'api_key' => 'secret-key',
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $server = Server::where('name', 'Pterodactyl')->sole();
        $this->assertDatabaseHas('settings', [
            'settingable_id' => $server->id,
            'settingable_type' => $server->getMorphClass(),
            'key' => 'host',
            'value' => 'https://panel.example.test',
            'type' => 'string',
            'encrypted' => false,
        ]);
        $this->assertSame('secret-key', $server->settings()->where('key', 'api_key')->first()->value);
        $this->assertDatabaseHas('settings', [
            'settingable_id' => $server->id,
            'settingable_type' => $server->getMorphClass(),
            'key' => 'api_key',
            'type' => 'string',
            'encrypted' => true,
        ]);
    }

    public function test_server_overview_filters_real_services_without_contacting_providers(): void
    {
        Http::preventStrayRequests();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $this->actingAs($user)->withSession($this->loginUser($user));

        $servers = [];
        $services = [];
        foreach (['Hong Kong', 'Tokyo'] as $name) {
            $servers[] = $server = Server::create(['name' => $name, 'type' => 'server', 'extension' => 'Pterodactyl', 'enabled' => true]);
            $product = $this->createProduct(['server_id' => $server->id]);
            $services[] = Service::factory()->create(['user_id' => $user->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => 'active', 'label' => $name . ' instance']);
        }
        $product = $this->createProduct();
        $unassigned = Service::factory()->create(['user_id' => $user->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => 'active']);

        $this->get(ServerInstanceResource::getUrl())->assertOk();
        Livewire::test(ListServerInstances::class)
            ->assertCanSeeTableRecords($services)
            ->assertCanNotSeeTableRecords([$unassigned])
            ->assertSee(__('Assigned server'))
            ->assertSee(__('Platform'))
            ->assertDontSee('运行状态')
            ->assertSee(ServiceResource::getUrl('edit', ['record' => $services[0]]))
            ->filterTable('server', $servers[1]->id)
            ->assertCanSeeTableRecords([$services[1]])
            ->assertCanNotSeeTableRecords([$services[0], $unassigned])
            ->searchTable('Hong Kong')
            ->assertCanNotSeeTableRecords($services)
            ->resetTableFilters()
            ->assertCanSeeTableRecords([$services[0]])
            ->assertCanNotSeeTableRecords([$services[1], $unassigned]);
        Http::assertNothingSent();
    }

    public function test_overview_requires_service_management_permission(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $role = Role::create(['name' => 'No services', 'permissions' => ['admin.products.viewAny']]);
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user)->withSession($this->loginUser($user))
            ->get(ServerInstanceResource::getUrl())->assertForbidden();
    }

    public function test_product_server_assignment_warns_that_existing_services_are_affected(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $this->actingAs($user)->withSession($this->loginUser($user));

        $product = $this->createProduct()->product;

        $this->get(ProductResource::getUrl('edit', ['record' => $product]))
            ->assertOk()
            ->assertSee('更换服务器会影响绑定到此商品的现有服务');
    }

    public function test_product_server_cannot_be_changed_after_services_exist(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $this->actingAs($user)->withSession($this->loginUser($user));
        $currentServer = Server::create(['name' => 'Current server', 'type' => 'server', 'extension' => 'Clicd', 'enabled' => true]);
        $newServer = Server::create(['name' => 'New server', 'type' => 'server', 'extension' => 'Clicd', 'enabled' => true]);
        $product = $this->createProduct(['server_id' => $currentServer->id]);
        Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
        ]);

        Livewire::test(EditProduct::class, ['record' => $product->product->id])
            ->fillForm(['server_id' => $newServer->id])
            ->call('save')
            ->assertHasErrors();

        $this->assertSame($currentServer->id, $product->product->fresh()->server_id);
    }

    public function test_repeated_product_duplication_creates_unique_slugs(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $this->actingAs($user)->withSession($this->loginUser($user));
        $product = $this->createProduct()->product;
        $option = ConfigOption::create(['name' => 'Region', 'type' => 'select']);
        $hiddenOption = ConfigOption::create(['name' => 'Hidden option', 'type' => 'text', 'hidden' => true]);
        $option->products()->attach($product->id);
        $hiddenOption->products()->attach($product->id);

        Livewire::test(EditProduct::class, ['record' => $product->id])->callAction('duplicate');
        Livewire::test(EditProduct::class, ['record' => $product->id])->callAction('duplicate');

        $this->assertDatabaseHas('products', ['slug' => 'test-product']);
        $this->assertDatabaseHas('products', ['slug' => 'test-product-2']);
        $this->assertSame(3, $product->category->products()->count());
        foreach ($product->category->products()->whereKeyNot($product->id)->get() as $duplicate) {
            $this->assertTrue($duplicate->hidden);
            $this->assertSame(1, $duplicate->plans()->count());
            $this->assertDatabaseHas('prices', ['plan_id' => $duplicate->plans()->sole()->id, 'price' => 10, 'currency_code' => 'CNY']);
            $this->assertDatabaseHas('config_option_products', ['product_id' => $duplicate->id, 'config_option_id' => $option->id]);
            $this->assertDatabaseHas('config_option_products', ['product_id' => $duplicate->id, 'config_option_id' => $hiddenOption->id]);
        }
    }

    public function test_server_cannot_be_deleted_while_products_are_assigned_to_it(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $this->actingAs($user)->withSession($this->loginUser($user));

        $server = Server::create(['name' => 'Assigned server', 'type' => 'server', 'extension' => 'Clicd', 'enabled' => true]);
        $product = $this->createProduct(['server_id' => $server->id])->product;

        Livewire::test(EditServer::class, ['record' => $server->id])->callAction('delete');

        $this->assertNotNull(Server::find($server->id));
        $this->assertSame($server->id, $product->fresh()->server_id);
    }

    public function test_bulk_delete_cannot_remove_a_server_assigned_to_a_product(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $this->actingAs($user)->withSession($this->loginUser($user));

        $unassigned = Server::create(['name' => 'Unassigned server', 'type' => 'server', 'extension' => 'Clicd', 'enabled' => true]);
        $server = Server::create(['name' => 'Assigned server', 'type' => 'server', 'extension' => 'Clicd', 'enabled' => true]);
        $product = $this->createProduct(['server_id' => $server->id])->product;

        Livewire::test(ListServers::class)->callTableBulkAction('delete', [$unassigned, $server]);

        $this->assertNotNull(Server::find($unassigned->id));
        $this->assertNotNull(Server::find($server->id));
        $this->assertSame($server->id, $product->fresh()->server_id);
    }

    public function test_service_is_preserved_when_remote_instance_deletion_fails(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $this->actingAs($user)->withSession($this->loginUser($user));
        $server = Server::create(['name' => 'cPanel', 'type' => 'server', 'extension' => 'CPanel', 'enabled' => true]);
        $product = $this->createProduct(['server_id' => $server->id]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
        ]);

        Livewire::test(EditService::class, ['record' => $service->id])
            ->callAction('delete', data: ['deleteExtensionServer' => true]);

        $this->assertNotNull($service->fresh());
    }

    public function test_admin_deleting_a_service_releases_stock_and_cancels_its_pending_invoice(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $product = $this->createProduct(['stock' => 0]);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'quantity' => 2,
            'status' => Service::STATUS_PENDING,
        ]);
        $invoice = Invoice::create([
            'user_id' => $service->user_id,
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now()->addDays(7),
            'currency_code' => 'CNY',
        ]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => 10,
            'quantity' => 2,
        ]);
        $this->actingAs($user)->withSession($this->loginUser($user));

        Livewire::test(EditService::class, ['record' => $service->id])->callAction('delete');

        $this->assertNull($service->fresh());
        $this->assertSame(2, $product->product->fresh()->stock);
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
    }

    public function test_admin_deleting_one_service_keeps_other_services_on_a_shared_invoice(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $product = $this->createProduct(['stock' => 0]);
        $first = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_PENDING,
        ]);
        $second = Service::factory()->create([
            'user_id' => $first->user_id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_PENDING,
        ]);
        $invoice = Invoice::create([
            'user_id' => $first->user_id,
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now()->addDays(7),
            'currency_code' => 'CNY',
        ]);
        foreach ([$first, $second] as $service) {
            $invoice->items()->create([
                'reference_id' => $service->id,
                'reference_type' => Service::class,
                'price' => 10,
                'quantity' => 1,
            ]);
        }
        $this->actingAs($user)->withSession($this->loginUser($user));

        Livewire::test(EditService::class, ['record' => $first->id])->callAction('delete');

        $this->assertNull($first->fresh());
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoice->id,
            'reference_id' => $second->id,
            'reference_type' => Service::class,
        ]);
        $this->assertSame(1, $product->product->fresh()->stock);
    }

    public function test_admin_cannot_delete_a_service_while_invoice_payment_is_processing(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $product = $this->createProduct(['stock' => 0]);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_PENDING,
        ]);
        $invoice = Invoice::create([
            'user_id' => $service->user_id,
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now()->addDays(7),
            'currency_code' => 'CNY',
        ]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => 10,
            'quantity' => 1,
        ]);
        $invoice->transactions()->create([
            'amount' => 10,
            'status' => InvoiceTransactionStatus::Processing,
        ]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(EditService::class, ['record' => $service->id])->callAction('delete');

        $this->assertNotNull($service->fresh());
        $this->assertSame(0, $product->product->fresh()->stock);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_editing_product_removes_settings_no_longer_declared_by_its_server(): void
    {
        $server = Server::create(['name' => 'CLICD', 'type' => 'server', 'extension' => 'Clicd', 'enabled' => true]);
        $server->settings()->create(['key' => 'api_url', 'value' => 'https://clicd.test']);
        $server->settings()->create(['key' => 'api_key', 'value' => 'test-key', 'encrypted' => true]);
        $product = $this->createProduct(['server_id' => $server->id])->product;
        $product->settings()->create(['key' => 'removed_extension_setting', 'value' => 'stale']);

        $page = new class extends EditProduct
        {
            public function saveProduct($record, array $data)
            {
                return $this->handleRecordUpdate($record, $data);
            }
        };

        $page->saveProduct($product, [
            'server_id' => $server->id,
            'settings' => ['virtualization' => 'lxc', 'template_id' => 'debian-bookworm'],
        ]);

        $this->assertDatabaseMissing('settings', [
            'settingable_id' => $product->id,
            'settingable_type' => $product->getMorphClass(),
            'key' => 'removed_extension_setting',
        ]);
        $this->assertDatabaseHas('settings', [
            'settingable_id' => $product->id,
            'settingable_type' => $product->getMorphClass(),
            'key' => 'template_id',
            'value' => 'debian-bookworm',
        ]);

        $page->saveProduct($product, ['server_id' => null]);
        $this->assertDatabaseMissing('settings', [
            'settingable_id' => $product->id,
            'settingable_type' => $product->getMorphClass(),
        ]);
    }
}
