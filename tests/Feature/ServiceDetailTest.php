<?php

namespace Tests\Feature;

use App\Livewire\Services\Cancel;
use App\Livewire\Services\Show;
use App\Models\BillingAgreement;
use App\Models\Coupon;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\ServiceUpgrade;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ServiceDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_coupon_does_not_discount_renewals_for_other_products(): void
    {
        $user = User::factory()->create();
        $eligibleProduct = $this->createProduct();
        $otherProduct = $this->createProduct();
        $coupon = Coupon::create([
            'type' => 'percentage',
            'applies_to' => 'price',
            'code' => 'PRODUCT-ONLY',
            'value' => 20,
            'recurring' => 0,
        ]);
        $coupon->products()->attach($eligibleProduct->product->id);
        $eligibleService = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $eligibleProduct->product->id,
            'plan_id' => $eligibleProduct->plan->id,
            'coupon_id' => $coupon->id,
            'currency_code' => 'CNY',
        ]);
        $otherService = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $otherProduct->product->id,
            'plan_id' => $otherProduct->plan->id,
            'coupon_id' => $coupon->id,
            'currency_code' => 'CNY',
        ]);

        $this->assertSame('8.00', $eligibleService->calculatePrice());
        $this->assertSame('10.00', $otherService->calculatePrice());
    }

    public function test_immediate_cancellation_removes_only_that_service_from_a_shared_invoice(): void
    {
        $user = User::factory()->create();
        $cancelledProduct = $this->createProduct(['stock' => 0]);
        $remainingProduct = $this->createProduct();
        $cancelledService = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $cancelledProduct->product->id,
            'plan_id' => $cancelledProduct->plan->id,
            'status' => Service::STATUS_PENDING,
        ]);
        $remainingService = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $remainingProduct->product->id,
            'plan_id' => $remainingProduct->plan->id,
            'status' => Service::STATUS_PENDING,
        ]);
        $invoice = Invoice::create([
            'user_id' => $user->id,
            'currency_code' => 'CNY',
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now()->addDays(7),
        ]);
        foreach ([$cancelledService, $remainingService] as $service) {
            $invoice->items()->create([
                'reference_id' => $service->id,
                'reference_type' => Service::class,
                'price' => 10,
                'quantity' => 1,
                'description' => $service->description,
            ]);
        }
        $cancellation = ServiceCancellation::create([
            'service_id' => $cancelledService->id,
            'type' => 'immediate',
        ]);

        $this->assertSame(Service::STATUS_CANCELLED, $cancelledService->fresh()->status);
        $this->assertSame(Service::STATUS_PENDING, $remainingService->fresh()->status);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertSame([$remainingService->id], $invoice->items()->pluck('reference_id')->all());
        $this->assertSame(1, $cancelledProduct->product->fresh()->stock);
    }

    public function test_invalid_billing_method_does_not_break_service_page(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
        ]);

        $this->actingAs($user);

        Livewire::test(Show::class, ['service' => $service])
            ->set('selectedMethod', 'invalid-method')
            ->call('updateBillingAgreement');

        $this->assertNull($service->fresh()->billing_agreement_id);
    }

    public function test_service_auto_pay_rejects_gateways_without_billing_agreement_support(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
        ]);
        $gateway = Gateway::create(['name' => 'Epay', 'extension' => 'Epay', 'type' => 'gateway']);
        $agreement = BillingAgreement::create([
            'user_id' => $user->id,
            'gateway_id' => $gateway->id,
            'name' => 'Unsupported payment method',
            'external_reference' => 'epay-account',
            'type' => 'account',
        ]);
        $this->actingAs($user);

        Livewire::test(Show::class, ['service' => $service])
            ->assertDontSee('Unsupported payment method')
            ->set('selectedMethod', $agreement->ulid)
            ->call('updateBillingAgreement')
            ->assertDispatched('notify');

        $this->assertNull($service->fresh()->billing_agreement_id);
    }

    public function test_service_page_hides_another_users_service(): void
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

        Livewire::test(Show::class, ['service' => $service])->assertStatus(404);
    }

    public function test_admin_with_view_permission_cannot_change_service_label(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = User::factory()->create();
        $role = Role::create(['name' => 'Service viewer', 'permissions' => ['admin.services.view']]);
        $viewer = User::factory()->create(['role_id' => $role->id]);
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $owner->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
        ]);

        $this->actingAs($viewer);

        Livewire::test(Show::class, ['service' => $service])
            ->set('label', 'Changed by viewer')
            ->call('updateLabel')
            ->assertStatus(404);

        $this->assertNotSame('Changed by viewer', $service->fresh()->label);
    }

    public function test_repeated_service_cancellation_requests_create_only_one_request(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
        ]);
        $this->actingAs($user);
        Queue::fake();
        $component = Livewire::test(Cancel::class, ['service' => $service])
            ->set('type', 'end_of_period')
            ->set('reason', '准备迁移');

        $component->call('cancelService');
        $component->call('cancelService')->assertDispatched('notify');

        $this->assertSame(1, ServiceCancellation::where('service_id', $service->id)->count());
    }

    public function test_cancelling_one_service_preserves_other_items_on_a_shared_renewal_invoice(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
        ]);
        $otherService = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
        ]);
        $invoice = Invoice::create([
            'user_id' => $user->id,
            'currency_code' => 'CNY',
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now()->addDays(7),
        ]);
        foreach ([$service, $otherService] as $itemService) {
            $invoice->items()->create([
                'reference_id' => $itemService->id,
                'reference_type' => Service::class,
                'price' => 10,
                'quantity' => 1,
            ]);
        }
        $this->actingAs($user);

        Livewire::test(Cancel::class, ['service' => $service])
            ->set('type', 'end_of_period')
            ->set('reason', '不再续费')
            ->call('cancelService')
            ->assertRedirect(route('services.show', $service));

        $this->assertSame(1, ServiceCancellation::where('service_id', $service->id)->count());
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertSame([$otherService->id], $invoice->items()->pluck('reference_id')->all());
    }

    public function test_cancelling_a_service_cancels_its_only_pending_renewal_invoice(): void
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
            'due_at' => now()->addDays(7),
        ]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => 10,
            'quantity' => 1,
        ]);
        $this->actingAs($user);

        Livewire::test(Cancel::class, ['service' => $service])
            ->set('type', 'end_of_period')
            ->set('reason', '不再续费')
            ->call('cancelService');

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame(0, $invoice->items()->count());
    }

    public function test_cancellation_does_not_change_an_invoice_with_payment_in_progress(): void
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
            'due_at' => now()->addDays(7),
        ]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => 10,
            'quantity' => 1,
        ]);
        $invoice->transactions()->create(['amount' => 10, 'status' => 'processing']);
        $this->actingAs($user);

        Livewire::test(Cancel::class, ['service' => $service])
            ->set('type', 'end_of_period')
            ->set('reason', '不再续费')
            ->call('cancelService');

        $this->assertSame(0, ServiceCancellation::where('service_id', $service->id)->count());
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertSame(1, $invoice->items()->count());
    }

    public function test_customer_can_create_an_immediate_renewal_invoice(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addDays(10),
        ]);
        $this->actingAs($user);

        Livewire::test(Show::class, ['service' => $service])
            ->call('renewNow')
            ->assertRedirect(route('invoices.show', $service->invoices()->first()) . '?pay=1');

        $invoice = $service->invoices()->firstOrFail();
        $this->assertSame('pending', $invoice->status);
        $this->assertTrue($invoice->due_at->isToday());
        $this->assertSame(10.0, (float) $invoice->items()->firstOrFail()->price);
    }

    public function test_customer_can_choose_a_billing_cycle_for_renewal_and_it_changes_after_payment(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $yearlyPlan = $product->product->plans()->create([
            'name' => 'Yearly',
            'billing_unit' => 'year',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);
        $yearlyPlan->prices()->create(['price' => 100, 'currency_code' => 'CNY']);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => today()->addDays(10),
            'price' => 10,
        ]);
        $this->actingAs($user);

        Livewire::test(Show::class, ['service' => $service])
            ->set('showRenewal', true)
            ->assertSeeText('每年')
            ->assertSeeText('¥100.00')
            ->set('renewalPlanId', $yearlyPlan->id)
            ->call('renewNow')
            ->assertRedirectContains('/invoices/');

        $invoice = $service->invoices()->where('status', Invoice::STATUS_PENDING)->sole();
        $upgrade = ServiceUpgrade::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('renewal_cycle', $upgrade->type);
        $this->assertSame($service->plan_id, $service->fresh()->plan_id);
        $this->assertSame(100.0, (float) $invoice->items()->sole()->price);
        $this->assertSame(Service::class, $invoice->items()->sole()->reference_type);

        $invoice->update(['status' => Invoice::STATUS_PAID]);

        $this->assertSame($yearlyPlan->id, $service->fresh()->plan_id);
        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->fresh()->status);
        $this->assertTrue($service->fresh()->expires_at->equalTo(today()->addDays(10)->addYear()));
    }

    public function test_existing_renewal_invoice_prevents_changing_the_selected_billing_cycle(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $yearlyPlan = $product->product->plans()->create([
            'name' => 'Yearly',
            'billing_unit' => 'year',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);
        $yearlyPlan->prices()->create(['price' => 100, 'currency_code' => 'CNY']);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
        ]);
        $invoice = $service->invoices()->create([
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
            'description' => 'Monthly renewal',
        ]);
        $this->actingAs($user);

        Livewire::test(Show::class, ['service' => $service])
            ->set('renewalPlanId', $yearlyPlan->id)
            ->call('renewNow')
            ->assertDispatched('notify', fn ($name, $params) => str_contains($params[0]['message'], '未支付账单'));

        $this->assertSame(1, $service->invoices()->where('status', Invoice::STATUS_PENDING)->count());
        $this->assertDatabaseMissing('service_upgrades', ['service_id' => $service->id, 'type' => 'renewal_cycle']);
    }

    public function test_cancelling_the_renewal_invoice_cancels_the_pending_cycle_change(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
        ]);
        $invoice = $service->invoices()->create([
            'user_id' => $user->id,
            'currency_code' => 'CNY',
            'status' => Invoice::STATUS_PENDING,
            'due_at' => now(),
        ]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => 100,
            'quantity' => 1,
            'description' => 'Yearly renewal',
        ]);
        $upgrade = ServiceUpgrade::create([
            'service_id' => $service->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'invoice_id' => $invoice->id,
            'type' => 'renewal_cycle',
        ]);

        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);

        $this->assertSame(ServiceUpgrade::STATUS_CANCELLED, $upgrade->fresh()->status);
    }

    public function test_immediate_renewal_renews_a_zero_price_service_without_an_invoice(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $product->plan->prices()->update(['price' => 0]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => today()->addDays(10),
        ]);
        $this->actingAs($user);

        Livewire::test(Show::class, ['service' => $service])
            ->call('renewNow')
            ->assertRedirect(route('services.show', $service));

        $this->assertSame(0, $service->invoices()->count());
        $this->assertSame(1, $service->fresh()->renewal_count);
        $this->assertTrue($service->fresh()->expires_at->gt(today()->addDays(10)));
    }

    public function test_immediate_renewal_reuses_an_existing_pending_invoice(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
        ]);
        $invoice = $service->invoices()->create([
            'user_id' => $user->id,
            'currency_code' => 'CNY',
            'status' => 'pending',
            'due_at' => now(),
        ]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => 10,
            'quantity' => 1,
            'description' => 'Test Product',
        ]);
        $this->actingAs($user);

        Livewire::test(Show::class, ['service' => $service])
            ->call('renewNow')
            ->assertRedirect(route('invoices.show', $invoice) . '?pay=1');

        $this->assertSame(1, $service->invoices()->where('status', 'pending')->count());
    }

    public function test_immediate_renewal_is_unavailable_for_one_time_services(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $product->plan->update(['type' => 'one-time']);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
        ]);
        $this->actingAs($user);

        Livewire::test(Show::class, ['service' => $service])
            ->call('renewNow')
            ->assertDispatched('notify');

        $this->assertSame(0, $service->invoices()->count());
    }

    public function test_customer_can_enable_and_disable_service_balance_auto_renewal(): void
    {
        config(['settings.credits_enabled' => true]);
        $user = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'auto_renew' => false,
        ]);
        $this->actingAs($user);

        Livewire::test(Show::class, ['service' => $service])
            ->call('toggleAutoRenew')
            ->assertSet('autoRenew', true)
            ->call('toggleAutoRenew')
            ->assertSet('autoRenew', false);

        $this->assertFalse((bool) $service->fresh()->auto_renew);
    }

    public function test_customer_cannot_enable_balance_auto_renewal_when_credits_are_disabled(): void
    {
        config(['settings.credits_enabled' => false]);
        $user = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'auto_renew' => false,
        ]);
        $this->actingAs($user);

        Livewire::test(Show::class, ['service' => $service])
            ->assertDontSee(__('services.enable_auto_renew'))
            ->call('toggleAutoRenew')
            ->assertForbidden();

        $this->assertFalse((bool) $service->fresh()->auto_renew);
    }
}
