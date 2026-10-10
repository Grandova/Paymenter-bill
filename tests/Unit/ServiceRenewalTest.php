<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ServiceRenewalTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_duedate_is_set(): void
    {
        // Create a user
        $user = User::factory()->create();
        $product = $this->createProduct();

        // Create a subscription for the user
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'pending',
            'currency_code' => 'CNY',
            'price' => 10.00, // Set a price for the service
        ]);

        // Create an invoice for the service renewal
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'currency_code' => 'CNY',
        ]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'description' => 'Service Renewal',
            'quantity' => 1,
            'price' => 10.00,
        ]);

        // Process the paid invoice
        $invoice->transactions()->create([
            'amount' => 10.00,
        ]);

        $invoice->refresh();
        $service->refresh();

        $this->assertEquals('paid', $invoice->status);
        $this->assertEquals('active', $service->status);
        $this->assertNotNull($service->expires_at);
    }

    public function test_service_duedate_is_extended_when_active(): void
    {
        // Create a user
        $user = User::factory()->create();
        $product = $this->createProduct();

        // Create a subscription for the user
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => now()->subDays(10),
            'currency_code' => 'CNY',
            'price' => 10.00, // Set a price for the service
        ]);

        // Create an invoice for the service renewal
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'currency_code' => 'CNY',
        ]);

        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'description' => 'Service Renewal',
            'quantity' => 1,
            'price' => 10.00,
        ]);

        // Process the paid invoice
        $invoice->transactions()->create([
            'amount' => 10.00,
        ]);

        $invoice->refresh();
        $service->refresh();

        $this->assertEquals('paid', $invoice->status);

        $this->assertEquals('active', $service->status);
        $this->assertNotNull($service->expires_at);
        $this->assertTrue($service->expires_at <= now()->addDays(21));
    }

    public function test_service_duedate_is_set_from_now_when_suspended(): void
    {
        // Create a user
        $user = User::factory()->create();
        $product = $this->createProduct();

        // Create a subscription for the user
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'suspended',
            'expires_at' => now()->subDays(10),
            'currency_code' => 'CNY',
            'price' => 10.00, // Set a price for the service
        ]);

        // Create an invoice for the service renewal
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'currency_code' => 'CNY',
        ]);

        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'description' => 'Service Renewal',
            'quantity' => 1,
            'price' => 10.00,
        ]);

        // Process the paid invoice
        $invoice->transactions()->create([
            'amount' => 10.00,
        ]);

        $invoice->refresh();
        $service->refresh();

        $this->assertEquals('paid', $invoice->status);

        $this->assertEquals('active', $service->status);
        $this->assertNotNull($service->expires_at);

        $this->assertTrue($service->expires_at >= now()->addMonth()->addDay(-1)); // 30 days from now minus a few seconds for processing time
    }

    public function test_monthly_service_renewal_does_not_roll_past_the_next_month(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => '2025-01-31',
            'currency_code' => 'CNY',
        ]);

        $this->assertSame('2025-02-28', $service->calculateNextDueDate()->toDateString());
        $this->assertStringContainsString(
            Carbon::parse('2025-02-28')->translatedFormat(__('general.date_format')),
            $service->description
        );
    }

    public function test_hourly_service_renewal_advances_by_the_configured_hours(): void
    {
        $product = $this->createProduct();
        $product->plan->update(['billing_unit' => 'hour', 'billing_period' => 6]);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => '2025-01-31 12:00:00',
            'currency_code' => 'CNY',
        ]);

        $this->assertSame('2025-01-31 18:00:00', $service->calculateNextDueDate()->format('Y-m-d H:i:s'));
    }
}
