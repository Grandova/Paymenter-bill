<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\TerminateJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\ServiceUpgrade;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CronjobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic unit test example.
     */
    public function test_invoices_are_created_if_due_date_is_reached(): void
    {
        // Create a user
        $user = User::factory()->create();

        // Set config cronjob_invoice
        // This is the number of days before the due date to send an invoice
        config(['settings.cronjob_invoice' => 7]);

        $product = $this->createProduct();

        // Create a subscription for the user
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => now()->addDays(2)->addHour(-1), // Set expires_at to 6 days from now
            'currency_code' => 'CNY',
            'price' => 10.00, // Set a price for the service
        ]);

        // Run the cron job
        $this->artisan('app:cron-job')
            ->assertExitCode(0);

        // Check if an invoice was created
        $this->assertDatabaseHas('invoices', [
            'user_id' => $user->id,
            'status' => 'pending',
            'due_at' => $service->expires_at,
            'currency_code' => 'CNY',
        ]);

        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(1, $service->invoices()->where('status', 'pending')->count());
    }

    public function test_cron_does_not_reprice_a_pending_billing_cycle_renewal_invoice(): void
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
            'expires_at' => now()->addDays(5),
            'price' => 10,
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
            'price' => 100,
            'quantity' => 1,
            'description' => 'Yearly renewal',
        ]);
        ServiceUpgrade::create([
            'service_id' => $service->id,
            'product_id' => $product->product->id,
            'plan_id' => $yearlyPlan->id,
            'invoice_id' => $invoice->id,
            'type' => 'renewal_cycle',
        ]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(100.0, (float) $invoice->items()->sole()->price);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_scheduled_cancellation_skips_the_next_renewal_invoice(): void
    {
        config(['settings.cronjob_invoice' => 7]);
        $user = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addDays(2),
            'currency_code' => 'CNY',
            'price' => 10,
        ]);
        ServiceCancellation::withoutEvents(fn () => ServiceCancellation::create([
            'service_id' => $service->id,
            'type' => 'end_of_period',
        ]));

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(0, $service->invoices()->where('status', 'pending')->count());
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
    }

    public function test_invoices_are_paid_with_credits_if_available(): void
    {
        config(['settings.credits_enabled' => true]);
        $user = User::factory()->create();

        $user->credits()->create([
            'currency_code' => 'CNY',
            'amount' => 4.00,
        ]);
        $user->credits()->create([
            'currency_code' => 'CNY',
            'amount' => 6.00,
        ]);

        // Set config cronjob_invoice
        // This is the number of days before the due date to send an invoice
        config(['settings.cronjob_invoice' => 7]);

        $product = $this->createProduct();

        // Create a subscription for the user
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => now()->addDays(2)->addHour(-1), // Set expires_at to 6 days from now
            'currency_code' => 'CNY',
            'price' => 10.00, // Set a price for the service
            'auto_renew' => true,
        ]);

        // Run the cron job
        $this->artisan('app:cron-job')
            ->assertExitCode(0);

        // Check if an invoice was created
        $this->assertDatabaseHas('invoices', [
            'user_id' => $user->id,
            'status' => 'paid',
            'due_at' => $service->expires_at,
            'currency_code' => 'CNY',
        ]);
    }

    public function test_automatic_credit_payment_keeps_all_balances_when_total_is_insufficient(): void
    {
        config(['settings.cronjob_invoice' => 7]);
        $user = User::factory()->create();
        $firstCredit = $user->credits()->create(['currency_code' => 'CNY', 'amount' => 4]);
        $secondCredit = $user->credits()->create(['currency_code' => 'CNY', 'amount' => 5]);
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addDays(2),
            'currency_code' => 'CNY',
            'price' => 10,
            'auto_renew' => true,
        ]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $invoice = $service->invoices()->firstOrFail();
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->status);
        $this->assertSame(0, $invoice->transactions()->count());
        $this->assertEquals(4, (float) $firstCredit->fresh()->amount);
        $this->assertEquals(5, (float) $secondCredit->fresh()->amount);
    }

    public function test_automatic_credit_payment_retries_a_pending_renewal_after_the_balance_is_topped_up(): void
    {
        config(['settings.cronjob_invoice' => 7, 'settings.credits_enabled' => true, 'settings.credits_auto_use' => true]);
        $user = User::factory()->create();
        $credit = $user->credits()->create(['currency_code' => 'CNY', 'amount' => 4]);
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addDays(2),
            'currency_code' => 'CNY',
            'price' => 10,
            'auto_renew' => true,
        ]);

        $this->artisan('app:cron-job')->assertExitCode(0);
        $invoice = $service->invoices()->firstOrFail();
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->status);
        $this->assertEquals(4, (float) $credit->fresh()->amount);

        $credit->increment('amount', 6);
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(0.0, (float) $credit->fresh()->amount);
        $this->assertSame(1, $invoice->fresh()->transactions()->count());
    }

    public function test_automatic_credit_payment_retries_a_suspended_service_renewal(): void
    {
        config(['settings.cronjob_invoice' => 7, 'settings.credits_enabled' => true, 'settings.credits_auto_use' => true]);
        $user = User::factory()->create();
        $credit = $user->credits()->create(['currency_code' => 'CNY', 'amount' => 4]);
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => Service::STATUS_SUSPENDED,
            'expires_at' => now()->subDay(),
            'currency_code' => 'CNY',
            'price' => 10,
            'auto_renew' => true,
        ]);
        $invoice = Invoice::create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PENDING,
            'due_at' => $service->expires_at,
            'currency_code' => 'CNY',
        ]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => 10,
            'quantity' => 1,
        ]);

        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);

        $credit->increment('amount', 6);
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        $this->assertTrue($service->fresh()->expires_at->isFuture());
        $this->assertSame(0.0, (float) $credit->fresh()->amount);
        $this->assertSame(1, $invoice->fresh()->transactions()->count());
    }

    public function test_renewal_credits_are_not_used_without_service_opt_in(): void
    {
        config(['settings.cronjob_invoice' => 7]);
        $user = User::factory()->create();
        $credit = $user->credits()->create(['currency_code' => 'CNY', 'amount' => 20]);
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addDays(2),
            'currency_code' => 'CNY',
            'price' => 10,
            'auto_renew' => false,
        ]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $invoice = $service->invoices()->firstOrFail();
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->status);
        $this->assertEquals(20, (float) $credit->fresh()->amount);
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_renewal_credits_are_not_used_when_credits_are_disabled(): void
    {
        config(['settings.cronjob_invoice' => 7, 'settings.credits_enabled' => false, 'settings.credits_auto_use' => true]);
        $user = User::factory()->create();
        $credit = $user->credits()->create(['currency_code' => 'CNY', 'amount' => 20]);
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addDays(2),
            'currency_code' => 'CNY',
            'price' => 10,
            'auto_renew' => true,
        ]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $invoice = $service->invoices()->firstOrFail();
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->status);
        $this->assertSame(0, $invoice->transactions()->count());
        $this->assertSame(20.0, (float) $credit->fresh()->amount);
    }

    public function test_services_are_renewed_if_price_is_zero(): void
    {
        // Create a user
        $user = User::factory()->create();

        // Set config cronjob_invoice
        // This is the number of days before the due date to send an invoice
        config(['settings.cronjob_invoice' => 7]);

        $product = $this->createProduct();

        // Create a subscription for the user
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => now()->addDays(2)->addHour(-1), // Set expires_at to 6 days from now
            'currency_code' => 'CNY',
            'price' => 0.00, // Set a price for the service
        ]);

        // Run the cron job
        $this->artisan('app:cron-job')
            ->assertExitCode(0);

        // Check if the service was renewed
        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'status' => 'active',
            'expires_at' => $service->calculateNextDueDate(),
        ]);
    }

    public function test_services_are_cancelled_if_not_paid_within_configured_days(): void
    {
        // Create a user
        $user = User::factory()->create();

        // Set config cronjob_order_cancel
        config(['settings.cronjob_order_cancel' => 7]);

        $product = $this->createProduct();

        // Create a subscription for the user
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'pending',
            'currency_code' => 'CNY',
            'price' => 10.00,
            'created_at' => now()->subDays(8), // Set created_at to 8 days ago
        ]);

        // Run the cron job
        $this->artisan('app:cron-job')
            ->assertExitCode(0);

        // Check if the service was cancelled
        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_new_service_is_not_cancelled_while_its_invoice_has_a_successful_partial_payment(): void
    {
        config(['settings.cronjob_order_cancel' => 7]);
        $user = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => Service::STATUS_PENDING,
            'currency_code' => 'CNY',
            'created_at' => now()->subDays(8),
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PENDING,
            'currency_code' => 'CNY',
        ]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'description' => 'Partially paid service',
            'quantity' => 1,
            'price' => 10,
        ]);
        ExtensionHelper::addPayment($invoice, null, 5, transactionId: 'partial-order-payment');

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertDatabaseHas('invoice_transactions', [
            'invoice_id' => $invoice->id,
            'transaction_id' => 'partial-order-payment',
            'status' => InvoiceTransactionStatus::Succeeded->value,
            'applied_to_invoice' => true,
        ]);
    }

    public function test_services_are_suspended_if_due_date_has_passed(): void
    {
        // Create a user
        $user = User::factory()->create();

        $product = $this->createProduct();

        // Making sure the cronjob_order_suspend is set to 2 days
        config(['settings.cronjob_order_suspend' => 2]);

        // Create a subscription for the user
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => now()->subDays(3), // Set expires_at to 1 day ago
            'currency_code' => 'CNY',
            'price' => 10.00,
        ]);

        Queue::fake();

        // Run the cron job
        $this->artisan('app:cron-job')
            ->assertExitCode(0);

        // Check if an invoice was created
        $this->assertDatabaseHas('invoices', [
            'user_id' => $user->id,
            'status' => 'pending',
            'due_at' => $service->expires_at,
            'currency_code' => 'CNY',
        ]);

        Queue::assertPushed(SuspendJob::class, function ($job) use ($service) {
            return $job->service->id === $service->id;
        });

        // Check if the service was suspended
        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'status' => 'suspended',
        ]);
    }

    public function test_queued_server_state_jobs_skip_services_that_have_since_changed_state(): void
    {
        $user = User::factory()->create();
        $server = Server::create(['name' => 'Pterodactyl', 'type' => 'server', 'extension' => 'Pterodactyl', 'enabled' => true]);
        $server->settings()->create(['key' => 'host', 'value' => 'https://pterodactyl.test']);
        $server->settings()->create(['key' => 'api_key', 'value' => 'test-key']);
        $product = $this->createProduct(['server_id' => $server->id]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE,
        ]);
        Http::preventStrayRequests();

        (new SuspendJob($service, false))->handle();
        $service->update(['status' => Service::STATUS_SUSPENDED]);
        (new UnsuspendJob($service))->handle();

        Http::assertNothingSent();
    }

    public function test_suspend_job_defers_to_recent_pending_invoice_payment(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_SUSPENDED,
            'expires_at' => now()->subDays(3),
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PENDING,
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
        Http::preventStrayRequests();

        (new SuspendJob($service, false))->handle();

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_orders_are_terminated_if_due_date_is_overdue(): void
    {
        // Create a user
        $user = User::factory()->create();

        // Set config cronjob_order_terminate
        config(['settings.cronjob_order_terminate' => 14]);

        $product = $this->createProduct();

        // Create a subscription for the user
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => now(), // Set expires_at to 15 days ago
            'currency_code' => 'CNY',
            'price' => 10.00,
        ]);

        // Run the cron job
        $this->artisan('app:cron-job')
            ->assertExitCode(0);

        // Now it should have generated an invoice
        $this->assertDatabaseHas('invoices', [
            'user_id' => $user->id,
            'status' => 'pending',
            'due_at' => $service->expires_at,
            'currency_code' => 'CNY',
        ]);

        // Update due date to be overdue
        $service->expires_at = now()->subDays(15);
        $service->save();

        Queue::fake();

        // Run the cron job again
        $this->artisan('app:cron-job')
            ->assertExitCode(0);

        // The service stays provisioned until the server confirms termination.
        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'status' => Service::STATUS_SUSPENDED,
        ]);

        Queue::assertPushed(TerminateJob::class, function ($job) use ($service) {
            return $job->service->id === $service->id;
        });

        $this->assertDatabaseHas('invoices', [
            'user_id' => $user->id,
            'status' => 'pending',
            'currency_code' => 'CNY',
        ]);
        (new TerminateJob($service, false))->handle();

        $this->assertDatabaseHas('services', ['id' => $service->id, 'status' => Service::STATUS_CANCELLED]);
        $this->assertDatabaseHas('invoices', [
            'user_id' => $user->id,
            'status' => 'cancelled',
            'currency_code' => 'CNY',
        ]);
    }

    public function test_all_server_extensions_are_kept_for_three_days_before_termination(): void
    {
        config(['settings.cronjob_order_terminate' => 3]);
        $services = collect(['Clicd', 'Pterodactyl'])->map(function ($extension) {
            $server = Server::create(['name' => $extension, 'type' => 'server', 'extension' => $extension, 'enabled' => true]);
            $product = $this->createProduct(['server_id' => $server->id]);

            return Service::factory()->create([
                'product_id' => $product->product->id,
                'plan_id' => $product->plan->id,
                'status' => Service::STATUS_SUSPENDED,
                'expires_at' => now()->subDays(2),
                'user_id' => User::factory()->create()->id,
                'currency_code' => 'CNY',
                'price' => 10.00,
            ]);
        });
        Queue::fake();

        $this->artisan('app:cron-job')->assertExitCode(0);
        Queue::assertNotPushed(TerminateJob::class);
        foreach ($services as $service) {
            $this->assertDatabaseHas('services', ['id' => $service->id, 'status' => Service::STATUS_SUSPENDED]);
        }

        $services->each(fn (Service $service) => $service->update(['expires_at' => now()->subDays(4)]));
        $this->artisan('app:cron-job')->assertExitCode(0);

        Queue::assertPushed(TerminateJob::class, fn ($job) => in_array($job->service->id, $services->pluck('id')->all()));
        $this->assertCount(2, Queue::pushed(TerminateJob::class));
    }

    public function test_server_is_suspended_after_the_configured_overdue_period(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(12));
        $server = Server::create(['name' => 'Pterodactyl expiry test', 'type' => 'server', 'extension' => 'Pterodactyl', 'enabled' => true]);
        $product = $this->createProduct(['server_id' => $server->id]);
        $service = Service::factory()->create([
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => today(),
            'user_id' => User::factory()->create()->id,
            'currency_code' => 'CNY',
            'price' => 10.00,
        ]);
        Queue::fake();

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_ACTIVE, $service->refresh()->status);
        Queue::assertNotPushed(SuspendJob::class);

        $service->update(['expires_at' => today()->subDay()]);
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_ACTIVE, $service->refresh()->status);

        $service->update(['expires_at' => today()->subDays(3)]);
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_SUSPENDED, $service->refresh()->status);
        Queue::assertPushed(SuspendJob::class, fn ($job) => $job->service->id === $service->id);
    }

    public function test_suspend_hold_remains_active_through_the_selected_date(): void
    {
        config(['settings.cronjob_order_suspend' => 2]);
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->subDays(3),
            'suspend_hold_until' => today(),
            'user_id' => User::factory()->create()->id,
            'currency_code' => 'CNY',
            'price' => 10.00,
        ]);
        Queue::fake();

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_ACTIVE, $service->refresh()->status);
        Queue::assertNotPushed(SuspendJob::class);

        $service->update(['suspend_hold_until' => today()->subDay()]);
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_SUSPENDED, $service->refresh()->status);
        Queue::assertPushed(SuspendJob::class, fn ($job) => $job->service->id === $service->id);
    }

    public function test_renewal_recalculates_a_first_cycle_coupon_after_its_discount_ends(): void
    {
        config(['settings.cronjob_invoice' => 7]);
        $user = User::factory()->create();
        $product = $this->createProduct();
        $coupon = Coupon::create([
            'type' => 'percentage',
            'applies_to' => 'all',
            'code' => 'FIRST-CYCLE',
            'value' => 50,
            'recurring' => null,
        ]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'coupon_id' => $coupon->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addDays(2),
            'currency_code' => 'CNY',
            'price' => 5,
        ]);
        $paidInvoice = Invoice::create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PAID,
            'due_at' => now()->subMonth(),
            'currency_code' => 'CNY',
        ]);
        $paidInvoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => 5,
            'quantity' => 1,
        ]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertEquals(10, $service->fresh()->price);
        $this->assertEquals(10, $service->invoices()->where('status', Invoice::STATUS_PENDING)->firstOrFail()->items->first()->price);
    }

    public function test_zero_price_coupon_renewals_advance_the_coupon_cycle(): void
    {
        config(['settings.cronjob_invoice' => 60]);
        $user = User::factory()->create();
        $product = $this->createProduct();
        $coupon = Coupon::create([
            'type' => 'percentage',
            'applies_to' => 'all',
            'code' => 'TWO-ZERO-CYCLES',
            'value' => 100,
            'recurring' => 2,
        ]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'coupon_id' => $coupon->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addDays(2),
            'currency_code' => 'CNY',
            'price' => 0,
        ]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(1, $service->fresh()->renewal_count);
        $this->assertSame(0, $service->invoices()->count());

        $this->artisan('app:cron-job')->assertExitCode(0);

        $invoice = $service->invoices()->where('status', Invoice::STATUS_PENDING)->firstOrFail();
        $this->assertEquals(10, (float) $invoice->items()->firstOrFail()->price);
    }

    public function test_tickets_are_closed_if_no_response_for_x_days(): void
    {
        // Create a user
        $user = User::factory()->create();

        // Set config cronjob_ticket_close
        config(['settings.cronjob_close_ticket' => 7]);

        // Create a ticket for the user
        $ticket = Ticket::factory()->create([
            'user_id' => $user->id,
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'user_id' => $user->id,
            'status' => 'open',
        ]);

        $differentUser = User::factory()->create();

        // Add message
        TicketMessage::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $differentUser->id,
            'message' => 'This is a test message.',
            'created_at' => now()->subDays(8), // Set created_at to 8 days ago
        ]);

        // Run the cron job
        $this->artisan('app:cron-job')
            ->assertExitCode(0);

        // Check if the ticket was closed
        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'status' => 'closed',
        ]);
    }

    public function test_if_product_stock_is_incremented_on_termination(): void
    {
        $product = $this->createProduct(['stock' => 10]);
        $user = User::factory()->create();

        $service = Service::factory()->create([
            'product_id' => $product->product->id,
            'status' => 'suspended',
            'expires_at' => now()->subDays(15),
            'user_id' => $user->id,
            'currency_code' => 'CNY',
            'price' => 10.00,
            'quantity' => 2,
        ]);
        Queue::fake();

        // Run the cron job
        $this->artisan('app:cron-job')
            ->assertExitCode(0);

        // The service is cancelled and stock is released after the termination job succeeds.
        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'status' => Service::STATUS_SUSPENDED,
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->product->id,
            'stock' => 10,
        ]);
        (new TerminateJob($service, false))->handle();

        $this->assertDatabaseHas('services', ['id' => $service->id, 'status' => Service::STATUS_CANCELLED]);

        // Check if the product stock was incremented
        $this->assertDatabaseHas('products', [
            'id' => $product->product->id,
            'stock' => 10 + $service->quantity,
        ]);
    }

    public function test_queued_termination_skips_a_service_that_was_renewed_before_the_job_runs(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addMonth(),
            'user_id' => User::factory()->create()->id,
        ]);

        (new TerminateJob($service, false))->handle();

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
    }

    public function test_queued_termination_still_handles_an_immediate_cancellation(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addMonth(),
            'user_id' => User::factory()->create()->id,
        ]);
        ServiceCancellation::withoutEvents(fn () => ServiceCancellation::create([
            'service_id' => $service->id,
            'type' => 'immediate',
        ]));

        (new TerminateJob($service, false))->handle();

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
    }

    public function test_termination_only_removes_its_service_from_a_shared_pending_invoice(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_SUSPENDED,
            'expires_at' => now()->subDays(20),
        ]);
        $otherService = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE,
            'expires_at' => now()->addDays(10),
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

        (new TerminateJob($service, false))->handle();

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertSame([$otherService->id], $invoice->items()->pluck('reference_id')->all());
    }

    public function test_termination_waits_for_a_partially_paid_renewal_invoice(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_SUSPENDED,
            'expires_at' => now()->subDays(20),
        ]);
        $invoice = Invoice::create([
            'user_id' => $user->id,
            'currency_code' => 'CNY',
            'status' => Invoice::STATUS_PENDING,
            'due_at' => $service->expires_at,
        ]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => 100,
            'quantity' => 1,
        ]);
        ExtensionHelper::addPayment($invoice->id, null, 40, transactionId: 'partial-renewal-payment');

        (new TerminateJob($service, false))->handle();

        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertSame('40.00', $invoice->transactions()->firstOrFail()->amount);
    }

    public function test_if_stock_is_null_it_does_not_increment_stock()
    {
        $product = $this->createProduct(['stock' => null]);
        $user = User::factory()->create();

        $service = Service::factory()->create([
            'product_id' => $product->product->id,
            'status' => 'suspended',
            'expires_at' => now()->subDays(15),
            'user_id' => $user->id,
            'currency_code' => 'CNY',
            'price' => 10.00,
        ]);
        Queue::fake();

        // Run the cron job
        $this->artisan('app:cron-job')
            ->assertExitCode(0);

        // No stock is released while the server termination is pending.
        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'status' => Service::STATUS_SUSPENDED,
        ]);
        (new TerminateJob($service, false))->handle();
        $this->assertDatabaseHas('services', ['id' => $service->id, 'status' => Service::STATUS_CANCELLED]);
    }

    public function test_if_stock_is_zero_it_does_increment_stock()
    {
        $product = $this->createProduct(['stock' => 0]);
        $user = User::factory()->create();

        $service = Service::factory()->create([
            'product_id' => $product->product->id,
            'status' => 'suspended',
            'expires_at' => now()->subDays(15),
            'user_id' => $user->id,
            'currency_code' => 'CNY',
            'price' => 10.00,
        ]);
        Queue::fake();

        // Run the cron job
        $this->artisan('app:cron-job')
            ->assertExitCode(0);

        // The service remains available until termination succeeds.
        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'status' => Service::STATUS_SUSPENDED,
        ]);

        $this->assertDatabaseHas('products', ['id' => $product->product->id, 'stock' => 0]);
        (new TerminateJob($service, false))->handle();
        $this->assertDatabaseHas('services', ['id' => $service->id, 'status' => Service::STATUS_CANCELLED]);

        // Check if the product stock was incremented
        $this->assertDatabaseHas('products', [
            'id' => $product->product->id,
            'stock' => 1,
        ]);
    }

    public function test_termination_failure_keeps_service_and_stock_until_server_confirms_deletion(): void
    {
        $product = $this->createProduct(['stock' => 10]);
        $server = Server::create(['name' => 'Pterodactyl', 'type' => 'server', 'extension' => 'Pterodactyl', 'enabled' => true]);
        $server->settings()->create(['key' => 'host', 'value' => 'https://pterodactyl.test']);
        $server->settings()->create(['key' => 'api_key', 'value' => 'test-key']);
        $product->product->update(['server_id' => $server->id]);
        $service = Service::factory()->create([
            'product_id' => $product->product->id,
            'status' => Service::STATUS_SUSPENDED,
            'expires_at' => now()->subDays(15),
            'user_id' => User::factory()->create()->id,
            'currency_code' => 'CNY',
            'price' => 10.00,
        ]);
        Http::fake([
            'pterodactyl.test/api/application/servers/external/*' => Http::response(['errors' => [['detail' => 'Node unavailable']]], 503),
        ]);

        try {
            (new TerminateJob($service, false))->handle();
            $this->fail('The termination job should fail when the server API is unavailable.');
        } catch (\Exception $e) {
            $this->assertSame(__('Server not found'), $e->getMessage());
        }

        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);
        $this->assertSame(10, $product->product->fresh()->stock);
    }

    public function test_server_extension_returning_false_does_not_cancel_service_or_release_stock(): void
    {
        $product = $this->createProduct(['stock' => 10]);
        $server = Server::create(['name' => 'cPanel', 'type' => 'server', 'extension' => 'CPanel', 'enabled' => true]);
        foreach (['host' => 'https://cpanel.test:2087', 'username' => 'root', 'apikey' => 'test-key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $product->product->update(['server_id' => $server->id]);
        $service = Service::factory()->create([
            'product_id' => $product->product->id,
            'status' => Service::STATUS_SUSPENDED,
            'expires_at' => now()->subDays(15),
            'user_id' => User::factory()->create()->id,
            'currency_code' => 'CNY',
            'price' => 10.00,
        ]);
        $service->properties()->create(['key' => 'cpanel_username', 'value' => 'customer1']);
        Http::fake(fn () => Http::response(['metadata' => ['result' => 0]], 200));

        try {
            (new TerminateJob($service, false))->handle();
            $this->fail('The termination job should fail when the extension rejects deletion.');
        } catch (\Exception $e) {
            $this->assertSame(__('Failed to terminate server'), $e->getMessage());
        }

        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);
        $this->assertSame(10, $product->product->fresh()->stock);
    }
}
