<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ServiceListTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_and_status_filters_only_show_the_current_users_services(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $product->product->update(['name' => 'Hong Kong Cloud']);
        $active = Service::factory()->create(['user_id' => $user->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => 'active', 'label' => 'Website server']);
        $pending = Service::factory()->create(['user_id' => $user->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => 'pending', 'label' => 'Database server']);
        Service::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => 'active', 'label' => 'Private server']);

        $this->actingAs($user)->withSession($this->loginUser($user));
        Livewire::test('services.index')
            ->assertSee('Website server')
            ->assertSee('Database server')
            ->assertDontSee('Private server')
            ->set('status', 'active')
            ->assertViewHas('services', fn ($services) => $services->pluck('id')->all() === [$active->id])
            ->set('search', 'Database')
            ->assertViewHas('services', fn ($services) => $services->isEmpty())
            ->set('status', '')
            ->assertViewHas('services', fn ($services) => $services->pluck('id')->all() === [$pending->id])
            ->set('search', 'Hong Kong')
            ->assertViewHas('services', fn ($services) => $services->total() === 2)
            ->call('gotoPage', 2)
            ->set('search', 'Website')
            ->assertSet('paginators.page', 1)
            ->assertSee('Website server');

        $this->get(route('dashboard'))->assertOk()->assertSee('Website server')->assertDontSee('Private server');
    }

    public function test_customer_can_create_one_invoice_for_selected_recurring_services(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $services = collect([Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])->map(fn ($status) => Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => $status,
            'price' => 10,
            'currency_code' => 'CNY',
        ]));
        $this->actingAs($user);

        Livewire::test('services.index')
            ->set('selectedServices', $services->pluck('id')->all())
            ->assertSee('批量续费（2）')
            ->call('renewSelected')
            ->assertRedirectContains('/invoices/');

        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame(2, $invoice->items()->count());
        $this->assertEquals(20.00, $invoice->total);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->status);

        $invoice->update(['status' => Invoice::STATUS_PAID]);
        foreach ($services as $service) {
            $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
            $this->assertNotNull($service->fresh()->expires_at);
        }
    }

    public function test_batch_renewal_renews_zero_price_services_without_adding_them_to_the_invoice(): void
    {
        $user = User::factory()->create();
        $freeProduct = $this->createProduct();
        $freeProduct->plan->prices()->update(['price' => 0]);
        $paidProduct = $this->createProduct();
        $freeService = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $freeProduct->product->id,
            'plan_id' => $freeProduct->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => today()->addDays(10),
            'price' => 0,
        ]);
        $paidService = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $paidProduct->product->id,
            'plan_id' => $paidProduct->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'price' => 10,
        ]);
        $this->actingAs($user);

        Livewire::test('services.index')
            ->set('selectedServices', [$freeService->id, $paidService->id])
            ->call('renewSelected')
            ->assertRedirectContains('/invoices/');

        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame($paidService->id, $invoice->items()->first()->reference_id);
        $this->assertTrue($freeService->fresh()->expires_at->gt(today()->addDays(10)));
    }

    public function test_batch_renewal_rejects_services_owned_by_another_customer(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $owner->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
        ]);
        $this->actingAs($otherUser);

        Livewire::test('services.index')
            ->set('selectedServices', [$service->id])
            ->call('renewSelected')
            ->assertDispatched('notify', fn ($name, $params) => str_contains($params[0]['message'], '所选服务无效'));

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_batch_renewal_does_not_duplicate_an_existing_renewal_invoice(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
        ]);
        $invoice = Invoice::create([
            'user_id' => $user->id,
            'currency_code' => 'CNY',
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now(),
        ]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => 10,
            'quantity' => 1,
            'description' => 'Test renewal',
        ]);
        $this->actingAs($user);

        Livewire::test('services.index')
            ->set('selectedServices', [$service->id])
            ->call('renewSelected')
            ->assertDispatched('notify', fn ($name, $params) => str_contains($params[0]['message'], '已有未支付账单'));

        $this->assertDatabaseCount('invoices', 1);
    }
}
