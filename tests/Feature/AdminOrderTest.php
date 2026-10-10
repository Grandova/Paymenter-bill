<?php

namespace Tests\Feature;

use App\Admin\Resources\OrderResource\Pages\CreateOrder;
use App\Admin\Resources\OrderResource\Pages\EditOrder;
use App\Admin\Resources\OrderResource\Pages\ListOrders;
use App\Jobs\Server\CreateJob;
use App\Models\ConfigOption;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AdminOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_can_be_found_by_customer_email_product_service_status_and_date(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $customer = User::factory()->create(['email' => 'customer-search@example.test']);
        $product = $this->createProduct();
        $otherProduct = $this->createProduct();
        $orders = collect();
        foreach ([$product, $otherProduct] as $index => $item) {
            $order = Order::withoutEvents(fn () => Order::create([
                'user_id' => $index === 0 ? $customer->id : $admin->id,
                'currency_code' => 'CNY',
            ]));
            $order->forceFill(['created_at' => $index === 0 ? '2026-10-01 23:59:59' : '2026-10-02 00:00:00'])->saveQuietly();
            Service::factory()->create([
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'product_id' => $item->product->id,
                'plan_id' => $item->plan->id,
                'status' => $index === 0 ? Service::STATUS_ACTIVE : Service::STATUS_PENDING,
            ]);
            $orders->push($order);
        }
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        $page = Livewire::test(ListOrders::class)->assertCanSeeTableRecords($orders);
        $page->searchTable($customer->email)
            ->assertCanSeeTableRecords([$orders[0]])->assertCanNotSeeTableRecords([$orders[1]])
            ->searchTable('');
        $page->filterTable('product', $product->product->id)
            ->assertCanSeeTableRecords([$orders[0]])->assertCanNotSeeTableRecords([$orders[1]])
            ->resetTableFilters();
        $page->filterTable('service_status', Service::STATUS_PENDING)
            ->assertCanSeeTableRecords([$orders[1]])->assertCanNotSeeTableRecords([$orders[0]])
            ->resetTableFilters();
        $page->filterTable('created_at', ['from' => '2026-10-01', 'until' => '2026-10-01'])
            ->assertCanSeeTableRecords([$orders[0]])->assertCanNotSeeTableRecords([$orders[1]]);
    }

    public function test_admin_order_reserves_finite_product_stock(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $customer = User::factory()->create();
        $product = $this->createProduct();
        $product->product->update(['stock' => 5]);
        Queue::fake();

        $this->actingAs($admin)->withSession($this->loginUser($admin));
        Livewire::test(CreateOrder::class)
            ->fillForm([
                'user_id' => $customer->id,
                'currency_code' => 'CNY',
                'services' => [[
                    'user_id' => $customer->id,
                    'currency_code' => 'CNY',
                    'product_id' => $product->product->id,
                    'plan_id' => $product->plan->id,
                    'quantity' => 2,
                    'price' => 20,
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(3, $product->product->fresh()->stock);
        $this->assertSame(2, Service::sole()->quantity);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_admin_order_rolls_back_when_finite_product_stock_is_insufficient(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $customer = User::factory()->create();
        $product = $this->createProduct();
        $product->product->update(['stock' => 1]);

        $this->actingAs($admin)->withSession($this->loginUser($admin));
        Livewire::test(CreateOrder::class)
            ->fillForm([
                'user_id' => $customer->id,
                'currency_code' => 'CNY',
                'services' => [[
                    'user_id' => $customer->id,
                    'currency_code' => 'CNY',
                    'product_id' => $product->product->id,
                    'plan_id' => $product->plan->id,
                    'quantity' => 2,
                    'price' => 20,
                ]],
            ])
            ->call('create');

        $this->assertSame(1, $product->product->fresh()->stock);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_admin_order_activates_free_services_and_only_bills_paid_services(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $customer = User::factory()->create();
        $server = Server::create(['name' => 'Test server', 'type' => 'server', 'extension' => 'Pterodactyl', 'enabled' => true]);
        $freeProduct = $this->createProduct(['server_id' => $server->id]);
        $paidProduct = $this->createProduct();
        Queue::fake();

        $this->actingAs($admin)->withSession($this->loginUser($admin));
        Livewire::test(CreateOrder::class)
            ->fillForm([
                'user_id' => $customer->id,
                'currency_code' => 'CNY',
                'services' => [
                    ['user_id' => $customer->id, 'currency_code' => 'CNY', 'product_id' => $freeProduct->product->id, 'plan_id' => $freeProduct->plan->id, 'quantity' => 1, 'price' => 0],
                    ['user_id' => $customer->id, 'currency_code' => 'CNY', 'product_id' => $paidProduct->product->id, 'plan_id' => $paidProduct->plan->id, 'quantity' => 1, 'price' => 20],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $services = Service::where('user_id', $customer->id)->get()->keyBy('product_id');
        $this->assertSame(Service::STATUS_ACTIVE, $services[$freeProduct->product->id]->status);
        $this->assertSame(0, $services[$freeProduct->product->id]->renewal_count);
        $this->assertSame(Service::STATUS_PENDING, $services[$paidProduct->product->id]->status);
        Queue::assertPushed(CreateJob::class, fn ($job) => $job->service->is($services[$freeProduct->product->id]));

        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseHas('invoice_items', [
            'reference_id' => $services[$paidProduct->product->id]->id,
            'reference_type' => Service::class,
            'price' => 20,
        ]);
        $this->assertDatabaseMissing('invoice_items', [
            'reference_id' => $services[$freeProduct->product->id]->id,
            'reference_type' => Service::class,
        ]);
    }

    public function test_admin_order_with_only_free_services_does_not_create_a_zero_amount_invoice(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $customer = User::factory()->create();
        $product = $this->createProduct();
        Queue::fake();

        $this->actingAs($admin)->withSession($this->loginUser($admin));
        Livewire::test(CreateOrder::class)
            ->fillForm([
                'user_id' => $customer->id,
                'currency_code' => 'CNY',
                'services' => [[
                    'user_id' => $customer->id,
                    'currency_code' => 'CNY',
                    'product_id' => $product->product->id,
                    'plan_id' => $product->plan->id,
                    'quantity' => 1,
                    'price' => 0,
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(Service::STATUS_ACTIVE, Service::where('user_id', $customer->id)->sole()->status);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_admin_order_rejects_a_plan_from_another_product(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $customer = User::factory()->create();
        $product = $this->createProduct();
        $otherProduct = $this->createProduct();
        Queue::fake();

        $this->actingAs($admin)->withSession($this->loginUser($admin));
        Livewire::test(CreateOrder::class)
            ->fillForm([
                'user_id' => $customer->id,
                'currency_code' => 'CNY',
                'services' => [[
                    'user_id' => $customer->id,
                    'currency_code' => 'CNY',
                    'product_id' => $product->product->id,
                    'plan_id' => $otherProduct->plan->id,
                    'quantity' => 1,
                    'price' => 10,
                ]],
            ])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_admin_order_bills_setup_fee_only_on_the_initial_invoice(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $customer = User::factory()->create();
        $product = $this->createProduct();
        $product->plan->prices()->update(['setup_fee' => 5]);
        Queue::fake();

        $this->actingAs($admin)->withSession($this->loginUser($admin));
        Livewire::test(CreateOrder::class)
            ->fillForm([
                'user_id' => $customer->id,
                'currency_code' => 'CNY',
                'services' => [[
                    'user_id' => $customer->id,
                    'currency_code' => 'CNY',
                    'product_id' => $product->product->id,
                    'plan_id' => $product->plan->id,
                    'quantity' => 1,
                    'price' => 20,
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $service = Service::where('user_id', $customer->id)->sole();
        $this->assertSame(20.0, (float) $service->price);
        $this->assertDatabaseHas('invoice_items', [
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => 25,
        ]);
    }

    public function test_admin_order_saves_product_configuration_and_bills_its_setup_fee(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $customer = User::factory()->create();
        $product = $this->createProduct();
        $product->plan->prices()->update(['setup_fee' => 5]);
        $option = ConfigOption::create(['name' => 'Region', 'type' => 'select']);
        $option->products()->attach($product->product->id);
        $hostnameOption = ConfigOption::create(['name' => 'Hostname', 'type' => 'text', 'env_variable' => 'CUSTOM_HOST']);
        $hostnameOption->products()->attach($product->product->id);
        $cpuOption = ConfigOption::create(['name' => 'CPU cores', 'type' => 'number', 'env_variable' => 'CPU_COUNT']);
        $cpuOption->products()->attach($product->product->id);
        $value = ConfigOption::create(['name' => 'Tokyo', 'parent_id' => $option->id]);
        $valuePlan = Plan::factory()->create([
            'priceable_id' => $value->id,
            'priceable_type' => ConfigOption::class,
            'name' => 'Tokyo price',
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);
        Price::factory()->create([
            'plan_id' => $valuePlan->id,
            'price' => 3,
            'setup_fee' => 2,
            'currency_code' => 'CNY',
        ]);
        Queue::fake();

        $this->actingAs($admin)->withSession($this->loginUser($admin));
        Livewire::test(CreateOrder::class)
            ->fillForm([
                'user_id' => $customer->id,
                'currency_code' => 'CNY',
                'services' => [[
                    'user_id' => $customer->id,
                    'currency_code' => 'CNY',
                    'product_id' => $product->product->id,
                    'plan_id' => $product->plan->id,
                    'quantity' => 1,
                    'price' => 13,
                    'configs' => [[
                        'config_option_id' => $option->id,
                        'config_value_id' => $value->id,
                    ]],
                    'custom_configs' => [
                        ['option_id' => $hostnameOption->id, 'value' => 'Tokyo-1'],
                        ['option_id' => $cpuOption->id, 'value' => '4'],
                    ],
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $service = Service::where('user_id', $customer->id)->sole();
        $this->assertDatabaseHas('service_configs', [
            'configurable_id' => $service->id,
            'configurable_type' => Service::class,
            'config_option_id' => $option->id,
            'config_value_id' => $value->id,
        ]);
        $this->assertDatabaseHas('invoice_items', [
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => 20,
        ]);
        $this->assertDatabaseHas('properties', [
            'model_id' => $service->id,
            'model_type' => Service::class,
            'key' => 'CUSTOM_HOST',
            'name' => 'Hostname',
            'value' => 'Tokyo-1',
        ]);
        $this->assertDatabaseHas('properties', [
            'model_id' => $service->id,
            'model_type' => Service::class,
            'key' => 'CPU_COUNT',
            'name' => 'CPU cores',
            'value' => '4',
        ]);
    }

    public function test_order_with_services_cannot_be_deleted_from_record_or_bulk_actions(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $customer = User::factory()->create();
        $product = $this->createProduct();
        $order = Order::create(['user_id' => $customer->id, 'currency_code' => 'CNY']);
        $service = Service::factory()->create([
            'order_id' => $order->id,
            'user_id' => $customer->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
        ]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(EditOrder::class, ['record' => $order->id])->callAction('delete');
        Livewire::test(ListOrders::class)->callTableBulkAction('delete', [$order]);

        $this->assertNotNull($order->fresh());
        $this->assertNotNull($service->fresh());
    }

    public function test_order_details_with_services_cannot_be_changed_outside_the_service_and_invoice(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $customer = User::factory()->create();
        $otherCustomer = User::factory()->create();
        $product = $this->createProduct();
        $otherProduct = $this->createProduct();
        $order = Order::create(['user_id' => $customer->id, 'currency_code' => 'CNY']);
        $service = Service::factory()->create([
            'order_id' => $order->id,
            'user_id' => $customer->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'currency_code' => 'CNY',
        ]);
        $invoice = Invoice::create([
            'user_id' => $customer->id,
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
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(EditOrder::class, ['record' => $order->id])
            ->fillForm([
                'user_id' => $otherCustomer->id,
                'currency_code' => 'USD',
                'services' => [[
                    'user_id' => $otherCustomer->id,
                    'currency_code' => 'USD',
                    'product_id' => $otherProduct->product->id,
                    'plan_id' => $otherProduct->plan->id,
                    'quantity' => 1,
                    'price' => 99,
                ]],
            ])
            ->call('save');

        $this->assertSame($customer->id, $order->fresh()->user_id);
        $this->assertSame('CNY', $order->fresh()->currency_code);
        $this->assertSame(1, $order->services()->count());
        $this->assertSame($product->product->id, $service->fresh()->product_id);
        $this->assertSame($customer->id, $service->fresh()->user_id);
        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame($service->id, $invoice->items()->sole()->reference_id);
    }
}
