<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Extension;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Paymenter\Extensions\Others\Affiliates\Listeners\RewardAffiliate;
use Paymenter\Extensions\Others\Affiliates\Models\Affiliate;
use Paymenter\Extensions\Others\Affiliates\Models\AffiliateOrder;
use Tests\TestCase;

class AffiliateRewardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'extensions/Others/Affiliates/database/migrations', '--force' => true]);
    }

    public function test_inviter_receives_the_custom_percentage_as_account_credit(): void
    {
        $inviter = User::factory()->create();
        $invitee = User::factory()->create();
        $affiliate = Affiliate::create([
            'user_id' => $inviter->id,
            'code' => 'CUSTOM12',
            'reward' => 12,
        ]);
        $extension = Extension::create([
            'name' => 'Affiliates',
            'extension' => 'Affiliates',
            'type' => 'other',
            'enabled' => true,
        ]);
        $extension->settings()->create(['key' => 'default_reward', 'value' => '5']);
        $order = Order::create(['user_id' => $invitee->id, 'currency_code' => 'CNY']);
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $invitee->id,
            'order_id' => $order->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
        ]);
        $invoice = Invoice::create([
            'user_id' => $invitee->id,
            'currency_code' => 'CNY',
            'status' => Invoice::STATUS_PAID,
            'due_at' => now(),
        ]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => 200,
            'quantity' => 1,
        ]);
        AffiliateOrder::create(['order_id' => $order->id, 'affiliate_id' => $affiliate->id]);

        (new RewardAffiliate)->handle((object) ['invoice' => $invoice]);
        (new RewardAffiliate)->handle((object) ['invoice' => $invoice]);

        $this->assertDatabaseHas('credits', [
            'user_id' => $inviter->id,
            'currency_code' => 'CNY',
            'amount' => 24,
        ]);
        $this->assertDatabaseCount('ext_affiliate_rewards', 1);

        $currencyName = Currency::where('code', 'CNY')->value('name');
        $affiliateOrder = AffiliateOrder::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(24.0, $affiliateOrder->earnings[$currencyName]);

        $affiliate->update(['reward' => 30]);
        $this->assertSame(24.0, $affiliateOrder->fresh()->earnings[$currencyName]);
    }
}
