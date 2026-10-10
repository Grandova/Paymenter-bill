<?php

namespace Tests\Feature;

use App\Jobs\Server\UpgradeJob;
use App\Livewire\Services\Upgrade;
use App\Models\ConfigOption;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ServiceUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_config_prices_use_the_services_currency_and_billing_cycle(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $option = ConfigOption::create(['name' => 'Region', 'type' => 'select', 'upgradable' => true]);
        $option->products()->attach($product->product->id);
        $value = ConfigOption::create(['name' => 'Tokyo', 'parent_id' => $option->id]);
        $valuePlan = Plan::factory()->create([
            'priceable_id' => $value->id,
            'priceable_type' => ConfigOption::class,
            'name' => 'Tokyo price',
            'billing_unit' => 'month',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);
        Price::factory()->create(['plan_id' => $valuePlan->id, 'price' => 3, 'currency_code' => 'CNY']);
        Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'suffix' => '', 'format' => '1,000.00']);
        Price::factory()->create(['plan_id' => $valuePlan->id, 'price' => 88, 'currency_code' => 'USD']);
        $yearlyPlan = Plan::factory()->create([
            'priceable_id' => $value->id,
            'priceable_type' => ConfigOption::class,
            'name' => 'Tokyo yearly price',
            'billing_unit' => 'year',
            'billing_period' => 1,
            'type' => 'recurring',
        ]);
        Price::factory()->create(['plan_id' => $yearlyPlan->id, 'price' => 20, 'currency_code' => 'CNY']);
        Price::factory()->create(['plan_id' => $yearlyPlan->id, 'price' => 1, 'currency_code' => 'USD']);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addDays(20),
            'currency_code' => 'CNY',
        ]);

        $this->actingAs($user)->withSession(['currency' => 'USD']);

        Livewire::test(Upgrade::class, ['service' => $service])
            ->assertSee('¥3.00')
            ->assertDontSee('$1.00');
    }

    public function test_prorated_upgrade_uses_the_paid_plan_when_the_product_also_has_a_free_plan(): void
    {
        $current = $this->createProduct();
        $target = $this->createProduct();
        $current->plan->prices()->update(['price' => 10]);
        $target->plan->prices()->update(['price' => 20]);
        $target->product->plans()->create([
            'name' => 'Free',
            'type' => 'free',
            'billing_period' => 1,
            'billing_unit' => 'month',
            'sort' => 10,
        ]);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $current->product->id,
            'plan_id' => $current->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addDays(15),
            'currency_code' => 'CNY',
        ]);
        $upgrade = new ServiceUpgrade(['service_id' => $service->id, 'product_id' => $target->product->id]);
        $upgrade->setRelation('service', $service);
        $upgrade->setRelation('product', $target->product);

        $this->assertEqualsWithDelta(5, $upgrade->calculatePrice()->price, 0.01);
    }

    public function test_upgrade_uses_the_service_currency_when_it_differs_from_the_session_currency(): void
    {
        Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'suffix' => '', 'format' => '1,000.00']);
        $user = User::factory()->create();
        $current = $this->createProduct();
        $target = $this->createProduct();
        $usdOnly = $this->createProduct();
        $target->plan->prices()->update(['price' => 20]);
        $usdOnly->plan->prices()->delete();
        Price::factory()->create(['plan_id' => $usdOnly->plan->id, 'price' => 50, 'currency_code' => 'USD']);
        $current->product->upgrades()->attach([$target->product->id, $usdOnly->product->id]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $current->product->id,
            'plan_id' => $current->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addDays(15),
            'currency_code' => 'CNY',
        ]);
        $this->actingAs($user)->withSession(['currency' => 'USD']);
        $this->assertTrue($service->productUpgrades()->contains('id', $target->product->id));
        $this->assertFalse($service->productUpgrades()->contains('id', $usdOnly->product->id));

        Livewire::test(Upgrade::class, ['service' => $service])
            ->set('upgrade', $target->product->id)
            ->call('doUpgrade')
            ->assertHasNoErrors();

        $invoice = Invoice::where('user_id', $user->id)->sole();
        $this->assertSame('CNY', $invoice->currency_code);
        $this->assertEquals(5, (float) $invoice->items()->sole()->price);
    }

    public function test_server_upgrade_job_is_marked_to_dispatch_after_commit(): void
    {
        $user = User::factory()->create();
        $current = $this->createProduct();
        $server = Server::create(['name' => 'Upgrade server', 'type' => 'server', 'extension' => 'Pterodactyl', 'enabled' => true]);
        $target = $this->createProduct(['server_id' => $server->id]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $current->product->id,
            'plan_id' => $current->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addDays(20),
            'currency_code' => 'CNY',
        ]);
        $upgrade = ServiceUpgrade::create([
            'service_id' => $service->id,
            'product_id' => $target->product->id,
            'plan_id' => $target->plan->id,
        ]);
        Queue::fake();

        (new ServiceUpgradeService)->handle($upgrade);

        Queue::assertPushed(UpgradeJob::class, fn ($job) => $job->service->id === $service->id && $job->afterCommit);
        $this->assertSame($target->product->id, $service->fresh()->product_id);
    }

    public function test_product_upgrade_reserves_stock_until_payment_and_does_not_decrement_twice(): void
    {
        $user = User::factory()->create();
        $current = $this->createProduct();
        $target = $this->createProduct(['stock' => 1]);
        $target->plan->prices()->update(['price' => 20]);
        $current->product->upgrades()->attach($target->product->id);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $current->product->id,
            'plan_id' => $current->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addDays(20),
            'currency_code' => 'CNY',
        ]);
        $this->actingAs($user);

        Livewire::test(Upgrade::class, ['service' => $service])
            ->set('upgrade', $target->product->id)
            ->call('doUpgrade')
            ->assertHasNoErrors();

        $upgrade = ServiceUpgrade::where('service_id', $service->id)->sole();
        $invoice = Invoice::where('user_id', $user->id)->sole();
        $this->assertSame(0, $target->product->fresh()->stock);
        $this->assertTrue($upgrade->stock_reserved);
        $this->assertSame($current->product->id, $service->fresh()->product_id);

        $invoice->update(['status' => Invoice::STATUS_PAID]);

        $this->assertSame($target->product->id, $service->fresh()->product_id);
        $this->assertSame(0, $target->product->fresh()->stock);
        $this->assertFalse($upgrade->fresh()->stock_reserved);
        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->fresh()->status);
    }

    public function test_cancelling_a_product_upgrade_invoice_releases_reserved_stock_once(): void
    {
        $user = User::factory()->create();
        $current = $this->createProduct();
        $target = $this->createProduct(['stock' => 1]);
        $target->plan->prices()->update(['price' => 20]);
        $current->product->upgrades()->attach($target->product->id);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $current->product->id,
            'plan_id' => $current->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addDays(20),
            'currency_code' => 'CNY',
        ]);
        $this->actingAs($user);

        Livewire::test(Upgrade::class, ['service' => $service])
            ->set('upgrade', $target->product->id)
            ->call('doUpgrade')
            ->assertHasNoErrors();

        $upgrade = ServiceUpgrade::where('service_id', $service->id)->sole();
        $invoice = Invoice::where('user_id', $user->id)->sole();
        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);
        $invoice->refresh();

        $this->assertSame(1, $target->product->fresh()->stock);
        $this->assertFalse($upgrade->fresh()->stock_reserved);
        $this->assertSame(ServiceUpgrade::STATUS_CANCELLED, $upgrade->fresh()->status);
    }

    public function test_expired_service_does_not_receive_a_prorated_downgrade_credit(): void
    {
        $current = $this->createProduct();
        $downgrade = $this->createProduct();
        $downgrade->plan->prices()->update(['price' => 5.00]);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $current->product->id,
            'plan_id' => $current->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->subDays(5),
            'currency_code' => 'CNY',
            'price' => 10.00,
        ]);
        $upgrade = new ServiceUpgrade(['service_id' => $service->id, 'product_id' => $downgrade->product->id]);
        $upgrade->setRelation('service', $service);
        $upgrade->setRelation('product', $downgrade->product);

        $this->assertSame(0.0, $upgrade->calculatePrice()->price);
    }

    public function test_future_service_keeps_its_prorated_downgrade_credit(): void
    {
        $current = $this->createProduct();
        $downgrade = $this->createProduct();
        $downgrade->plan->prices()->update(['price' => 5.00]);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $current->product->id,
            'plan_id' => $current->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addDays(10),
            'currency_code' => 'CNY',
            'price' => 10.00,
        ]);
        $upgrade = new ServiceUpgrade(['service_id' => $service->id, 'product_id' => $downgrade->product->id]);
        $upgrade->setRelation('service', $service);
        $upgrade->setRelation('product', $downgrade->product);

        $this->assertEqualsWithDelta(-5 / 3, $upgrade->calculatePrice()->price, 0.01);
    }

    public function test_hourly_service_upgrade_is_prorated_by_remaining_hours(): void
    {
        Carbon::setTestNow('2026-10-10 12:00:00');
        $current = $this->createProduct();
        $current->plan->update(['billing_unit' => 'hour', 'billing_period' => 6]);
        $current->plan->prices()->update(['price' => 12]);
        $target = $this->createProduct();
        $target->plan->update(['billing_unit' => 'hour', 'billing_period' => 6]);
        $target->plan->prices()->update(['price' => 24]);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $current->product->id,
            'plan_id' => $current->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addHours(3),
            'currency_code' => 'CNY',
        ]);
        $upgrade = new ServiceUpgrade(['service_id' => $service->id, 'product_id' => $target->product->id]);
        $upgrade->setRelation('service', $service);
        $upgrade->setRelation('product', $target->product);

        $this->assertEqualsWithDelta(6, $upgrade->calculatePrice()->price, 0.01);
        Carbon::setTestNow();
    }

    public function test_downgrade_does_not_create_account_credit_when_credits_are_disabled(): void
    {
        config(['settings.credits_enabled' => false, 'settings.credits_on_downgrade' => true]);
        $user = User::factory()->create();
        $current = $this->createProduct();
        $downgrade = $this->createProduct();
        $downgrade->plan->prices()->update(['price' => 5.00]);
        $current->product->upgrades()->attach($downgrade->product->id);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $current->product->id,
            'plan_id' => $current->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addDays(10),
            'currency_code' => 'CNY',
            'price' => 10.00,
        ]);

        $this->actingAs($user);
        Livewire::test(Upgrade::class, ['service' => $service])
            ->set('upgrade', $downgrade->product->id)
            ->call('doUpgrade')
            ->assertRedirect(route('services.show', $service));

        $this->assertSame($downgrade->product->id, $service->fresh()->product_id);
        $this->assertSame(0, $user->credits()->count());
        $this->assertDatabaseHas('service_upgrades', [
            'service_id' => $service->id,
            'status' => ServiceUpgrade::STATUS_COMPLETED,
        ]);
    }

    public function test_another_customer_cannot_open_the_service_upgrade_flow(): void
    {
        $owner = User::factory()->create();
        $current = $this->createProduct();
        $target = $this->createProduct();
        $current->product->upgrades()->attach($target->product->id);
        $service = Service::factory()->create([
            'user_id' => $owner->id,
            'product_id' => $current->product->id,
            'plan_id' => $current->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addDays(20),
            'currency_code' => 'CNY',
        ]);

        $this->actingAs(User::factory()->create());

        Livewire::test(Upgrade::class, ['service' => $service])->assertStatus(404);
    }

    public function test_service_with_a_cancellation_request_cannot_be_upgraded(): void
    {
        $user = User::factory()->create();
        $current = $this->createProduct();
        $target = $this->createProduct();
        $current->product->upgrades()->attach($target->product->id);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $current->product->id,
            'plan_id' => $current->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addDays(20),
            'currency_code' => 'CNY',
        ]);
        $this->actingAs($user);
        $component = Livewire::test(Upgrade::class, ['service' => $service]);
        ServiceCancellation::withoutEvents(fn () => ServiceCancellation::create([
            'service_id' => $service->id,
            'type' => 'end_of_period',
        ]));

        $component->call('doUpgrade')->assertRedirect(route('services.show', $service));

        $this->assertSame(0, ServiceUpgrade::where('service_id', $service->id)->count());
    }
}
