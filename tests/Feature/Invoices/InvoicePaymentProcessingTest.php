<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceTransactionStatus;
use App\Events\InvoiceTransaction\Created;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\CreateJob;
use App\Listeners\InvoiceTransactionCreatedListener;
use App\Livewire\Invoices\Index;
use App\Livewire\Invoices\Show;
use App\Models\BillingAgreement;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\ProcessPaidInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class InvoicePaymentProcessingTest extends TestCase
{
    use RefreshDatabase;

    private function createInvoiceWithItem($total = 100.00, ?User $user = null)
    {
        $user ??= User::factory()->create();
        $invoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => 'pending']);

        $invoice->items()->create([
            'description' => 'Test Item',
            'quantity' => 1,
            'price' => $total,
        ]);

        return $invoice->fresh();
    }

    public function test_invoice_starts_with_draft_status()
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['user_id' => $user->id]);

        $invoice->items()->create([
            'description' => 'Test Item',
            'quantity' => 1,
            'price' => 100.00,
        ]);

        $invoice = $invoice->fresh();

        $this->assertEquals('draft', $invoice->status);
        $this->assertGreaterThan(0, $invoice->total);
    }

    public function test_gateway_transaction_cannot_be_applied_to_two_invoices(): void
    {
        $gateway = Gateway::create(['name' => 'Test gateway', 'extension' => 'TestGateway', 'type' => 'gateway']);
        $firstInvoice = $this->createInvoiceWithItem();
        $secondInvoice = $this->createInvoiceWithItem(user: $firstInvoice->user);

        ExtensionHelper::addPayment($firstInvoice, 'TestGateway', 100, transactionId: 'gateway-transaction-1');

        try {
            ExtensionHelper::addPayment($secondInvoice, 'TestGateway', 100, transactionId: 'gateway-transaction-1');
            $this->fail('A gateway transaction cannot be applied to another invoice.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame(__('invoices.transaction_already_used'), $exception->getMessage());
        }

        $this->assertSame(1, InvoiceTransaction::where('gateway_id', $gateway->id)->count());
        $this->assertSame(Invoice::STATUS_PAID, $firstInvoice->fresh()->status);
        $this->assertSame(Invoice::STATUS_PENDING, $secondInvoice->fresh()->status);
        $this->assertSame(0, $secondInvoice->fresh()->transactions()->count());
    }

    public function test_customer_can_cancel_an_unpaid_invoice(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createInvoiceWithItem(user: $user);

        $this->actingAs($user);
        Livewire::test(Show::class, ['invoice' => $invoice->fresh()])
            ->call('cancelInvoice');

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'status' => Invoice::STATUS_CANCELLED,
            'cancellation_reason' => __('Unpaid invoice cancelled by customer'),
        ]);
    }

    public function test_customer_cannot_pay_with_credits_when_credits_are_disabled(): void
    {
        config(['settings.credits_enabled' => false]);
        $user = User::factory()->create();
        $user->credits()->create(['currency_code' => 'CNY', 'amount' => 20]);
        $invoice = $this->createInvoiceWithItem(user: $user);

        $this->actingAs($user);
        Livewire::test(Show::class, ['invoice' => $invoice])
            ->set('showPayModal', true)
            ->assertDontSee(__('invoices.pay_with_credits'))
            ->set('selectedMethod', 'credit')
            ->call('processPayment')
            ->assertDispatched('notify', fn ($name, $params) => $params[0]['message'] === __('This payment method cannot be used for this invoice.'));

        $this->assertSame(20.0, (float) $user->credits()->where('currency_code', 'CNY')->value('amount'));
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_late_payment_for_cancelled_invoice_is_credited_once(): void
    {
        config(['settings.credits_enabled' => true]);
        $user = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_PENDING,
        ]);
        $invoice = $this->createInvoiceWithItem(user: $user);
        $invoice->items()->update([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
        ]);
        Queue::fake();

        $this->actingAs($user);
        Livewire::test(Show::class, ['invoice' => $invoice])->call('cancelInvoice');

        config(['settings.credits_enabled' => true]);
        ExtensionHelper::addPayment($invoice->id, null, 100, transactionId: 'late-payment-1');
        ExtensionHelper::addPayment($invoice->id, null, 100, transactionId: 'late-payment-1');

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        $this->assertSame(100.0, (float) $user->credits()->where('currency_code', $invoice->currency_code)->value('amount'));
        $this->assertDatabaseHas('invoice_transactions', [
            'invoice_id' => $invoice->id,
            'transaction_id' => 'late-payment-1',
            'status' => InvoiceTransactionStatus::Succeeded->value,
            'credited_to_balance' => true,
        ]);
        Queue::assertNotPushed(CreateJob::class);
    }

    public function test_partially_refunded_late_payment_credits_only_the_unreturned_amount_once(): void
    {
        config(['settings.credits_enabled' => true]);
        $user = User::factory()->create();
        $invoice = $this->createInvoiceWithItem(user: $user);
        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);

        $transaction = $invoice->transactions()->create([
            'amount' => 100,
            'refunded_amount' => 40,
            'status' => InvoiceTransactionStatus::Succeeded,
        ]);

        $this->assertSame(60.0, (float) $user->credits()->where('currency_code', $invoice->currency_code)->value('amount'));
        $this->assertSame('60.00', $transaction->fresh()->credited_amount);
        $this->assertFalse($transaction->fresh()->credited_to_balance);

        app(InvoiceTransactionCreatedListener::class)->handle(new Created($transaction->fresh()));

        $this->assertSame(60.0, (float) $user->credits()->where('currency_code', $invoice->currency_code)->value('amount'));
    }

    public function test_an_extra_payment_after_settlement_is_credited_once(): void
    {
        config(['settings.credits_enabled' => true]);
        $user = User::factory()->create();
        $invoice = $this->createInvoiceWithItem(user: $user);

        ExtensionHelper::addPayment($invoice->id, null, 100, transactionId: 'settlement-payment-1');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertDatabaseHas('invoice_transactions', [
            'invoice_id' => $invoice->id,
            'transaction_id' => 'settlement-payment-1',
            'applied_to_invoice' => true,
        ]);

        ExtensionHelper::addPayment($invoice->id, null, 100, transactionId: 'settlement-payment-2');
        ExtensionHelper::addPayment($invoice->id, null, 100, transactionId: 'settlement-payment-2');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(100.0, (float) $user->credits()->where('currency_code', $invoice->currency_code)->value('amount'));
        $this->assertDatabaseHas('invoice_transactions', [
            'invoice_id' => $invoice->id,
            'transaction_id' => 'settlement-payment-2',
            'credited_to_balance' => true,
            'applied_to_invoice' => false,
        ]);
    }

    public function test_late_failed_callback_does_not_downgrade_a_successful_payment(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createInvoiceWithItem(user: $user);

        ExtensionHelper::addPayment($invoice->id, null, 100, transactionId: 'out-of-order-payment-1');
        ExtensionHelper::addFailedPayment($invoice->id, null, 100, transactionId: 'out-of-order-payment-1');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(0.0, $invoice->fresh()->remaining);
        $this->assertDatabaseHas('invoice_transactions', [
            'invoice_id' => $invoice->id,
            'transaction_id' => 'out-of-order-payment-1',
            'status' => InvoiceTransactionStatus::Succeeded->value,
        ]);
    }

    public function test_extra_payment_after_settlement_is_visible_when_credits_are_disabled(): void
    {
        config(['settings.credits_enabled' => false]);
        $user = User::factory()->create();
        $invoice = $this->createInvoiceWithItem(user: $user);
        ExtensionHelper::addPayment($invoice->id, null, 100, transactionId: 'settlement-payment-disabled-1');
        ExtensionHelper::addPayment($invoice->id, null, 100, transactionId: 'settlement-payment-disabled-2');

        $this->assertSame(0, $user->credits()->count());
        $this->actingAs($user);
        Livewire::test(Show::class, ['invoice' => $invoice->fresh()])
            ->assertSee(__('invoices.late_payment_notice'));
    }

    public function test_late_payment_does_not_create_unusable_credit_when_credits_are_disabled(): void
    {
        config(['settings.credits_enabled' => false]);
        $user = User::factory()->create();
        $invoice = $this->createInvoiceWithItem(user: $user);

        $this->actingAs($user);
        Livewire::test(Show::class, ['invoice' => $invoice])->call('cancelInvoice');
        ExtensionHelper::addPayment($invoice->id, null, 100, transactionId: 'late-payment-disabled-1');

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame(0, $user->credits()->count());
        $this->assertDatabaseHas('invoice_transactions', [
            'invoice_id' => $invoice->id,
            'transaction_id' => 'late-payment-disabled-1',
            'status' => InvoiceTransactionStatus::Succeeded->value,
            'credited_to_balance' => false,
        ]);
    }

    public function test_invoice_list_shows_customer_friendly_status_and_date(): void
    {
        $user = User::factory()->create();
        $this->createInvoiceWithItem(user: $user);
        $paidInvoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => Invoice::STATUS_PAID]);
        $paidInvoice->items()->create([
            'description' => 'Paid Item',
            'quantity' => 1,
            'price' => 100.00,
        ]);

        $this->actingAs($user);
        Livewire::test(Index::class)
            ->assertSee(__('invoices.payment_pending'))
            ->assertSee(__('invoices.invoice_date'))
            ->assertSee('Test Item')
            ->set('status', Invoice::STATUS_PAID)
            ->assertSee(__('invoices.paid'))
            ->assertSee('Paid Item')
            ->assertDontSee('Test Item');
    }

    public function test_paid_invoice_does_not_reactivate_a_cancelled_service(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_CANCELLED,
        ]);
        $invoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => Invoice::STATUS_PENDING]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => 10,
            'quantity' => 1,
        ]);
        Queue::fake();

        app(ProcessPaidInvoiceService::class)->handle($invoice->fresh());

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        Queue::assertNotPushed(CreateJob::class);
    }

    public function test_cancelling_an_unpaid_order_releases_only_pending_services_and_stock(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct(['stock' => 10]);
        $pendingService = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'quantity' => 2,
            'status' => Service::STATUS_PENDING,
        ]);
        $activeService = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'quantity' => 1,
            'status' => Service::STATUS_ACTIVE,
        ]);
        $invoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => Invoice::STATUS_PENDING]);
        foreach ([$pendingService, $activeService] as $service) {
            $invoice->items()->create([
                'reference_id' => $service->id,
                'reference_type' => Service::class,
                'price' => 10,
                'quantity' => $service->quantity,
            ]);
        }

        $this->actingAs($user);
        Livewire::test(Show::class, ['invoice' => $invoice])->call('cancelInvoice');

        $this->assertSame(Service::STATUS_CANCELLED, $pendingService->fresh()->status);
        $this->assertSame(Service::STATUS_ACTIVE, $activeService->fresh()->status);
        $this->assertSame(12, $product->product->fresh()->stock);
    }

    public function test_invalid_saved_payment_method_cannot_become_the_default(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createInvoiceWithItem(user: $user);

        $this->actingAs($user);
        Livewire::test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'invalid-method')
            ->set('setAsDefault', true)
            ->call('processPayment');

        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_deleted_gateway_payment_method_is_not_saved_as_a_service_default(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'quantity' => 1,
            'status' => Service::STATUS_ACTIVE,
        ]);
        $invoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => Invoice::STATUS_PENDING]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => 10,
            'quantity' => 1,
        ]);
        $gateway = Gateway::create(['name' => 'Removed gateway', 'extension' => 'RemovedGateway', 'type' => 'gateway', 'enabled' => true]);
        $agreement = BillingAgreement::create([
            'user_id' => $user->id,
            'gateway_id' => $gateway->id,
            'name' => 'Old payment method',
            'external_reference' => 'old-method-reference',
        ]);
        $gateway->delete();

        $this->actingAs($user);
        Livewire::test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', $agreement->ulid)
            ->set('setAsDefault', true)
            ->call('processPayment');

        $this->assertNull($service->fresh()->billing_agreement_id);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_customer_cannot_open_another_users_invoice(): void
    {
        $invoice = $this->createInvoiceWithItem();
        $this->actingAs(User::factory()->create());

        Livewire::test(Show::class, ['invoice' => $invoice])->assertStatus(404);
    }

    public function test_invoice_with_a_processing_payment_cannot_be_cancelled(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createInvoiceWithItem(user: $user);
        $invoice->transactions()->create([
            'amount' => 100.00,
            'status' => InvoiceTransactionStatus::Processing,
        ]);

        $this->actingAs($user);
        Livewire::test(Show::class, ['invoice' => $invoice])
            ->call('cancelInvoice');

        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_invoice_with_a_processing_payment_cannot_start_another_payment(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createInvoiceWithItem(user: $user);
        $invoice->transactions()->create([
            'amount' => 100.00,
            'status' => InvoiceTransactionStatus::Processing,
        ]);

        $this->actingAs($user);
        Livewire::test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'credit')
            ->call('processPayment');

        $this->assertSame(1, $invoice->fresh()->transactions()->count());
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_stale_invoice_page_cannot_charge_credits_again_after_invoice_is_paid(): void
    {
        Setting::create(['key' => 'credits_enabled', 'value' => '1', 'type' => 'boolean']);
        Cache::forget('settings');
        $user = User::factory()->create();
        $invoice = $this->createInvoiceWithItem(user: $user);
        $credit = Credit::create(['user_id' => $user->id, 'currency_code' => $invoice->currency_code, 'amount' => 200]);
        $this->actingAs($user);

        $firstPage = Livewire::test(Show::class, ['invoice' => $invoice])->set('selectedMethod', 'credit');
        $stalePage = Livewire::test(Show::class, ['invoice' => $invoice])->set('selectedMethod', 'credit');

        $firstPage->call('processPayment');
        $stalePage->call('processPayment');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(1, $invoice->fresh()->transactions()->count());
        $this->assertEquals(100.00, $credit->fresh()->amount);
        Cache::forget('settings');
    }

    public function test_invoice_can_be_paid_from_multiple_credit_records_in_the_same_currency(): void
    {
        Setting::create(['key' => 'credits_enabled', 'value' => '1', 'type' => 'boolean']);
        Cache::forget('settings');
        $user = User::factory()->create();
        $invoice = $this->createInvoiceWithItem(user: $user);
        $firstCredit = Credit::create(['user_id' => $user->id, 'currency_code' => $invoice->currency_code, 'amount' => 40]);
        $secondCredit = Credit::create(['user_id' => $user->id, 'currency_code' => $invoice->currency_code, 'amount' => 60]);
        $this->actingAs($user);
        Livewire::test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'credit')
            ->call('processPayment');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertEquals(0, (float) $firstCredit->fresh()->amount);
        $this->assertEquals(0, (float) $secondCredit->fresh()->amount);
        Cache::forget('settings');
    }

    public function test_partial_invoice_payment_uses_available_credit_across_records(): void
    {
        Setting::create(['key' => 'credits_enabled', 'value' => '1', 'type' => 'boolean']);
        Cache::forget('settings');
        $user = User::factory()->create();
        $invoice = $this->createInvoiceWithItem(user: $user);
        $firstCredit = Credit::create(['user_id' => $user->id, 'currency_code' => $invoice->currency_code, 'amount' => 30]);
        $secondCredit = Credit::create(['user_id' => $user->id, 'currency_code' => $invoice->currency_code, 'amount' => 20]);
        $this->actingAs($user);

        Livewire::test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'credit')
            ->call('processPayment');

        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertEquals(50, (float) $invoice->fresh()->remaining);
        $this->assertEquals(0, (float) $firstCredit->fresh()->amount);
        $this->assertEquals(0, (float) $secondCredit->fresh()->amount);
        Cache::forget('settings');
    }

    public function test_partially_paid_invoice_cannot_be_cancelled(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createInvoiceWithItem(user: $user);
        $invoice->transactions()->create([
            'amount' => 25.00,
            'status' => InvoiceTransactionStatus::Succeeded,
        ]);

        $this->actingAs($user);
        Livewire::test(Show::class, ['invoice' => $invoice])
            ->call('cancelInvoice');

        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_pending_invoice_is_snapshotted_when_created()
    {
        config(['settings.invoice_snapshot' => true]);

        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PENDING,
        ]);

        $this->assertSame($user->name, $invoice->fresh()->snapshot->name);
    }

    public function test_invoice_calculates_remaining_amount_correctly()
    {
        $invoice = $this->createInvoiceWithItem(100.00);

        // No payments yet
        $this->assertEquals(100.00, $invoice->remaining);

        // Add partial payment
        $invoice->transactions()->create([
            'amount' => 30.00,
            'status' => InvoiceTransactionStatus::Succeeded,
        ]);

        $this->assertEquals(70.00, $invoice->fresh()->remaining);
    }

    public function test_invoice_totals_are_calculated_without_floating_point_errors()
    {
        $invoice = $this->createInvoiceWithItem(0.10);
        $invoice->items()->create([
            'description' => 'Second Test Item',
            'quantity' => 2,
            'price' => 0.10,
        ]);

        $invoice->refresh();

        $this->assertSame(0.30, $invoice->total);

        $invoice->transactions()->create([
            'amount' => 0.10,
            'status' => InvoiceTransactionStatus::Succeeded,
        ]);

        $this->assertSame(0.20, $invoice->fresh()->remaining);
        $this->assertSame($invoice->fresh()->remaining, $invoice->fresh()->currentBalance);
    }

    public function test_decimal_invoice_is_marked_paid_when_the_full_amount_is_paid()
    {
        $invoice = $this->createInvoiceWithItem(179.99);
        $invoice->items()->create([
            'description' => 'Second Test Item',
            'quantity' => 1,
            'price' => 69.99,
        ]);

        ExtensionHelper::addPayment($invoice->id, 'TestGateway', 249.98);

        $invoice->refresh();

        $this->assertSame('paid', $invoice->status);
        $this->assertSame(0.0, $invoice->remaining);
    }

    public function test_successful_payment_marks_invoice_as_paid()
    {
        $invoice = $this->createInvoiceWithItem(100.00);

        // Process full payment
        ExtensionHelper::addPayment(
            $invoice->id,
            'TestGateway',
            100.00,
            fee: 2.50,
            transactionId: 'test_txn_123'
        );

        $invoice->refresh();

        $this->assertEquals('paid', $invoice->status);
        $this->assertEquals(0, $invoice->remaining);
        $this->assertEquals(1, $invoice->transactions()->count());

        $transaction = $invoice->transactions()->first();
        $this->assertEquals(100.00, $transaction->amount);
        $this->assertEquals(2.50, $transaction->fee);
        $this->assertEquals('test_txn_123', $transaction->transaction_id);
    }

    public function test_partial_payment_keeps_invoice_pending()
    {
        $invoice = $this->createInvoiceWithItem(100.00);

        // Process partial payment
        ExtensionHelper::addPayment($invoice->id, 'TestGateway', 60.00);

        $invoice->refresh();

        $this->assertEquals('pending', $invoice->status);
        $this->assertEquals(40.00, $invoice->remaining);
    }

    public function test_overpayment_marks_invoice_as_paid()
    {
        config(['settings.credits_enabled' => true]);
        $invoice = $this->createInvoiceWithItem(100.00);

        // Process overpayment
        ExtensionHelper::addPayment($invoice->id, 'TestGateway', 150.00);

        $invoice->refresh();
        $transaction = $invoice->transactions()->firstOrFail();

        $this->assertEquals('paid', $invoice->status);
        $this->assertSame(0.0, $invoice->remaining);
        $this->assertSame('50.00', $transaction->credited_amount);
        $this->assertSame(100.0, $transaction->refundable_amount);
        $this->assertSame(50.0, (float) Credit::where('user_id', $invoice->user_id)->firstOrFail()->amount);

        $this->actingAs($invoice->user);
        Livewire::test(Show::class, ['invoice' => $invoice])
            ->assertSee(__('invoices.credited_amount'))
            ->assertSee('50.00');
    }

    public function test_service_is_activated_when_invoice_is_paid()
    {
        $user = User::factory()->create();
        $product = $this->createProduct();

        $service = Service::factory()->create(['status' => 'pending', 'user_id' => $user->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id]);
        $invoice = Invoice::factory()->create([
            'user_id' => $service->user_id,
            'status' => 'pending',
            'due_at' => now()->addDays(7),
            'currency_code' => $service->currency_code,
        ]);

        // Link service to invoice
        $invoice->items()->create([
            'reference_type' => Service::class,
            'reference_id' => $service->id,
            'price' => 100.00,
            'quantity' => 1,
            'description' => 'blah',
        ]);

        // Pay the invoice
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00);

        $service->refresh();

        $this->assertEquals('active', $service->status);
        $this->assertNotNull($service->expires_at);
    }

    public function test_invoice_handles_multiple_partial_payments()
    {
        $invoice = $this->createInvoiceWithItem(100.00);

        // First payment
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 30.00);

        // Second payment
        ExtensionHelper::addPayment($invoice->id, 'PayPal', 40.00);

        // Third payment completes it
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 30.00);

        $invoice->refresh();

        $this->assertEquals('paid', $invoice->status);
        $this->assertEquals(0, $invoice->remaining);
        $this->assertEquals(3, $invoice->transactions()->count());
    }

    public function test_payment_fee_is_recorded_correctly()
    {
        $invoice = $this->createInvoiceWithItem(100.00);

        ExtensionHelper::addPayment(
            $invoice->id,
            'Stripe',
            100.00,
            fee: 3.20,
            transactionId: 'pi_123'
        );

        $transaction = $invoice->transactions()->first();

        $this->assertEquals(3.20, $transaction->fee);
    }

    public function test_fee_can_be_updated_after_payment()
    {
        $invoice = $this->createInvoiceWithItem(100.00);

        // Initial payment without fee
        ExtensionHelper::addPayment(
            $invoice->id,
            'Stripe',
            100.00,
            transactionId: 'pi_123'
        );

        $transaction = $invoice->transactions()->first();
        $this->assertEquals(0, $transaction->fee);

        // Update fee (from Stripe webhook)
        ExtensionHelper::addPaymentFee('pi_123', 2.90);

        $transaction = $invoice->transactions()->first();
        $this->assertEquals(2.90, $transaction->fee);
    }
}
