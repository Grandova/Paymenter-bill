<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Admin\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\CreateJob;
use App\Models\ApiKey;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AdminInvoiceTransactionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_payment_cannot_be_deleted_individually_or_in_bulk(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $this->actingAs($user)->withSession($this->loginUser($user));
        $invoice = Invoice::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => Invoice::STATUS_PAID,
        ]);
        $transaction = $invoice->transactions()->create([
            'amount' => 10,
            'status' => InvoiceTransactionStatus::Succeeded,
        ]);

        $manager = Livewire::test(TransactionsRelationManager::class, [
            'ownerRecord' => $invoice,
            'pageClass' => EditInvoice::class,
        ]);

        $manager->assertTableActionHidden('delete', $transaction)
            ->assertTableBulkActionHidden('delete');

        $this->assertDatabaseHas('invoice_transactions', ['id' => $transaction->id]);
    }

    public function test_paid_or_in_flight_payment_invoices_are_read_only(): void
    {
        config(['settings.immutable_invoices_enabled' => false]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $this->actingAs($user)->withSession($this->loginUser($user));

        $paidInvoice = Invoice::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => Invoice::STATUS_PAID,
        ]);
        $transactionInvoice = Invoice::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => Invoice::STATUS_PENDING,
        ]);
        $transactionInvoice->transactions()->create([
            'amount' => 10,
            'status' => InvoiceTransactionStatus::Succeeded,
        ]);
        $processingInvoice = Invoice::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => Invoice::STATUS_PENDING,
        ]);
        $processingInvoice->transactions()->create([
            'amount' => 10,
            'status' => InvoiceTransactionStatus::Processing,
        ]);
        $editableInvoice = Invoice::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => Invoice::STATUS_PENDING,
        ]);

        Livewire::test(EditInvoice::class, ['record' => $paidInvoice->id])
            ->assertRedirect(InvoiceResource::getUrl('view', ['record' => $paidInvoice->id]));
        Livewire::test(EditInvoice::class, ['record' => $transactionInvoice->id])
            ->assertRedirect(InvoiceResource::getUrl('view', ['record' => $transactionInvoice->id]));
        Livewire::test(EditInvoice::class, ['record' => $processingInvoice->id])
            ->assertRedirect(InvoiceResource::getUrl('view', ['record' => $processingInvoice->id]));
        Livewire::test(TransactionsRelationManager::class, [
            'ownerRecord' => $paidInvoice,
            'pageClass' => ViewInvoice::class,
        ])->assertTableActionHidden('create');
        Livewire::test(TransactionsRelationManager::class, [
            'ownerRecord' => $processingInvoice,
            'pageClass' => ViewInvoice::class,
        ])->assertTableActionHidden('create');

        $this->get(InvoiceResource::getUrl('view', ['record' => $paidInvoice->id]))->assertOk();
        $this->get(InvoiceResource::getUrl('edit', ['record' => $editableInvoice->id]))->assertOk();

        $this->assertDatabaseHas('invoices', ['id' => $paidInvoice->id]);
        $this->assertDatabaseHas('invoices', ['id' => $transactionInvoice->id]);
        $this->assertDatabaseHas('invoices', ['id' => $processingInvoice->id]);
    }

    public function test_invoice_with_a_successful_partial_payment_cannot_be_cancelled(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));
        $invoice = Invoice::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => Invoice::STATUS_PENDING,
        ]);
        $invoice->items()->create(['description' => 'Server renewal', 'price' => 100, 'quantity' => 1]);
        $invoice->transactions()->create([
            'amount' => 40,
            'status' => InvoiceTransactionStatus::Succeeded,
        ]);

        Livewire::test(ListInvoices::class)->assertTableActionHidden('cancel', $invoice);
        Livewire::test(ViewInvoice::class, ['record' => $invoice->id])->assertActionHidden('cancel');

        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertSame(60.0, $invoice->fresh()->remaining);
        $this->assertDatabaseCount('adjustment_notes', 0);
    }

    public function test_admin_cancelling_an_invoice_cancels_pending_services_and_releases_stock(): void
    {
        config(['settings.immutable_invoices_enabled' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $user = User::factory()->create();
        $product = $this->createProduct(['stock' => 0]);
        $service = Service::factory()->create([
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
        $invoice = $this->invoiceForService($user, $service);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(ListInvoices::class)
            ->callTableAction('cancel', $invoice, data: ['cancellation_reason' => 'Order cancelled']);

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        $this->assertSame(Service::STATUS_ACTIVE, $activeService->fresh()->status);
        $this->assertSame(2, $product->product->fresh()->stock);
    }

    public function test_admin_cancelling_an_invoice_from_the_detail_page_cancels_pending_service(): void
    {
        config(['settings.immutable_invoices_enabled' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $user = User::factory()->create();
        $product = $this->createProduct(['stock' => 0]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'quantity' => 1,
            'status' => Service::STATUS_PENDING,
        ]);
        $invoice = $this->invoiceForService($user, $service);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
            ->callAction('cancel', data: ['cancellation_reason' => 'Order cancelled']);

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        $this->assertSame(1, $product->product->fresh()->stock);
    }

    public function test_admin_api_cancelling_an_invoice_cancels_pending_service_and_blocks_in_flight_payment(): void
    {
        ApiKey::create([
            'name' => 'Invoice API test',
            'token' => hash('sha256', 'invoice-api-test-token'),
            'type' => 'admin',
            'permissions' => ['admin.invoices.update'],
        ]);
        $user = User::factory()->create();
        $product = $this->createProduct(['stock' => 0]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'quantity' => 2,
            'status' => Service::STATUS_PENDING,
        ]);
        $invoice = $this->invoiceForService($user, $service);

        $this->withToken('invoice-api-test-token')
            ->patchJson('/api/v1/admin/invoices/' . $invoice->id, ['status' => Invoice::STATUS_CANCELLED])
            ->assertOk();

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        $this->assertSame(2, $product->product->fresh()->stock);

        $inFlightService = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'quantity' => 1,
            'status' => Service::STATUS_PENDING,
        ]);
        $inFlightInvoice = $this->invoiceForService($user, $inFlightService);
        $inFlightInvoice->transactions()->create([
            'amount' => 10,
            'status' => InvoiceTransactionStatus::Processing,
        ]);

        $this->withToken('invoice-api-test-token')
            ->patchJson('/api/v1/admin/invoices/' . $inFlightInvoice->id, ['status' => Invoice::STATUS_CANCELLED])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame(Invoice::STATUS_PENDING, $inFlightInvoice->fresh()->status);
        $this->assertSame(Service::STATUS_PENDING, $inFlightService->fresh()->status);
        $this->assertSame(2, $product->product->fresh()->stock);
    }

    public function test_admin_api_cannot_delete_invoices_with_successful_or_processing_payments(): void
    {
        ApiKey::create([
            'name' => 'Invoice delete API test',
            'token' => hash('sha256', 'invoice-delete-api-test-token'),
            'type' => 'admin',
            'permissions' => ['admin.invoices.delete'],
        ]);
        $user = User::factory()->create();
        $paidInvoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PAID,
        ]);
        $processingInvoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PENDING,
        ]);
        $processingInvoice->transactions()->create([
            'amount' => 10,
            'status' => InvoiceTransactionStatus::Processing,
        ]);
        $draftInvoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_DRAFT,
        ]);

        $this->withToken('invoice-delete-api-test-token')
            ->deleteJson('/api/v1/admin/invoices/' . $paidInvoice->id)
            ->assertForbidden();
        $this->withToken('invoice-delete-api-test-token')
            ->deleteJson('/api/v1/admin/invoices/' . $processingInvoice->id)
            ->assertForbidden();
        $this->withToken('invoice-delete-api-test-token')
            ->deleteJson('/api/v1/admin/invoices/' . $draftInvoice->id)
            ->assertNoContent();

        $this->assertDatabaseHas('invoices', ['id' => $paidInvoice->id]);
        $this->assertDatabaseHas('invoices', ['id' => $processingInvoice->id]);
        $this->assertDatabaseMissing('invoices', ['id' => $draftInvoice->id]);
    }

    public function test_admin_api_cannot_edit_invoices_with_successful_or_processing_payments(): void
    {
        config(['settings.immutable_invoices_enabled' => false]);
        ApiKey::create([
            'name' => 'Invoice edit API test',
            'token' => hash('sha256', 'invoice-edit-api-test-token'),
            'type' => 'admin',
            'permissions' => ['admin.invoices.update'],
        ]);
        $user = User::factory()->create();
        $paidInvoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PAID,
        ]);
        $processingInvoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PENDING,
        ]);
        $processingInvoice->transactions()->create([
            'amount' => 10,
            'status' => InvoiceTransactionStatus::Processing,
        ]);
        $editableInvoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => Invoice::STATUS_PENDING,
        ]);

        $this->withToken('invoice-edit-api-test-token')
            ->patchJson('/api/v1/admin/invoices/' . $paidInvoice->id, ['due_at' => '2026-11-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invoice');
        $this->withToken('invoice-edit-api-test-token')
            ->patchJson('/api/v1/admin/invoices/' . $processingInvoice->id, ['due_at' => '2026-11-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invoice');
        $this->withToken('invoice-edit-api-test-token')
            ->patchJson('/api/v1/admin/invoices/' . $editableInvoice->id, ['due_at' => '2026-11-01'])
            ->assertOk();

        $this->assertDatabaseHas('invoices', [
            'id' => $editableInvoice->id,
            'due_at' => '2026-11-01 00:00:00',
        ]);
    }

    public function test_immutable_issued_invoice_cannot_be_edited_or_deleted_through_the_admin_api(): void
    {
        config(['settings.immutable_invoices_enabled' => true]);
        ApiKey::create([
            'name' => 'Immutable invoice API test',
            'token' => hash('sha256', 'immutable-invoice-api-test-token'),
            'type' => 'admin',
            'permissions' => ['admin.invoices.update', 'admin.invoices.delete'],
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => Invoice::STATUS_PENDING,
        ]);

        $this->withToken('immutable-invoice-api-test-token')
            ->patchJson('/api/v1/admin/invoices/' . $invoice->id, ['due_at' => '2026-11-01'])
            ->assertForbidden();
        $this->withToken('immutable-invoice-api-test-token')
            ->deleteJson('/api/v1/admin/invoices/' . $invoice->id)
            ->assertForbidden();

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => Invoice::STATUS_PENDING]);
    }

    private function invoiceForService(User $user, Service $service): Invoice
    {
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'currency_code' => 'CNY',
            'status' => Invoice::STATUS_PENDING,
        ]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'description' => $service->description,
            'price' => 10,
            'quantity' => $service->quantity,
        ]);

        return $invoice;
    }

    public function test_invoice_cannot_be_cancelled_if_a_payment_starts_after_the_cancel_page_loads(): void
    {
        config(['settings.immutable_invoices_enabled' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));
        $invoice = Invoice::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => Invoice::STATUS_PENDING,
        ]);
        $component = Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
            ->assertActionVisible('cancel');

        $invoice->transactions()->create([
            'amount' => 100,
            'status' => InvoiceTransactionStatus::Processing,
        ]);

        $component->callAction('cancel', data: ['cancellation_reason' => 'Payment is processing']);

        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertDatabaseCount('adjustment_notes', 0);
    }

    public function test_refunding_a_credit_deposit_deducts_the_same_amount_from_account_balance(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'currency_code' => 'CNY',
            'status' => Invoice::STATUS_PAID,
        ]);
        $invoice->items()->create([
            'description' => 'Account credit deposit',
            'price' => 50,
            'quantity' => 1,
            'reference_type' => Credit::class,
        ]);
        $transaction = $invoice->transactions()->create([
            'amount' => 50,
            'status' => InvoiceTransactionStatus::Succeeded,
            'transaction_id' => 'deposit-1',
        ]);
        $firstCredit = $user->credits()->create(['currency_code' => 'CNY', 'amount' => 10]);
        $secondCredit = $user->credits()->create(['currency_code' => 'CNY', 'amount' => 40]);

        ExtensionHelper::refundLocally($transaction, 15.25);

        $this->assertEquals(0, (float) $firstCredit->fresh()->amount);
        $this->assertEquals(34.75, (float) $secondCredit->fresh()->amount);
        $this->assertSame('15.25', $transaction->fresh()->refunded_amount);
        $this->assertDatabaseHas('adjustment_notes', [
            'invoice_id' => $invoice->id,
            'amount' => -15.25,
        ]);
    }

    public function test_credit_deposit_cannot_be_refunded_after_its_balance_has_been_spent(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'currency_code' => 'CNY',
            'status' => Invoice::STATUS_PAID,
        ]);
        $invoice->items()->create([
            'description' => 'Account credit deposit',
            'price' => 50,
            'quantity' => 1,
            'reference_type' => Credit::class,
        ]);
        $transaction = $invoice->transactions()->create([
            'amount' => 50,
            'status' => InvoiceTransactionStatus::Succeeded,
            'transaction_id' => 'deposit-2',
        ]);
        $credit = $user->credits()->create(['currency_code' => 'CNY', 'amount' => 5]);

        try {
            ExtensionHelper::refundLocally($transaction, 10);
            $this->fail('A credit deposit refund must not exceed the account balance.');
        } catch (\RuntimeException $e) {
            $this->assertSame(__('invoices.refund_credit_balance_insufficient'), $e->getMessage());
        }

        $this->assertEquals(5, (float) $credit->fresh()->amount);
        $this->assertSame('0.00', $transaction->fresh()->refunded_amount);
        $this->assertDatabaseCount('adjustment_notes', 0);
    }

    public function test_failed_or_processing_transactions_cannot_be_refunded(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));
        $invoice = Invoice::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => Invoice::STATUS_PENDING,
        ]);
        $failed = $invoice->transactions()->create([
            'amount' => 10,
            'transaction_id' => 'failed-payment',
            'status' => InvoiceTransactionStatus::Failed,
        ]);
        $processing = $invoice->transactions()->create([
            'amount' => 10,
            'transaction_id' => 'processing-payment',
            'status' => InvoiceTransactionStatus::Processing,
        ]);

        foreach ([$failed, $processing] as $transaction) {
            try {
                ExtensionHelper::refundLocally($transaction, 5);
                $this->fail('Only successful payments can be refunded.');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame(__('invoices.refund_successful_only'), $e->getMessage());
            }
        }

        Livewire::test(TransactionsRelationManager::class, [
            'ownerRecord' => $invoice,
            'pageClass' => EditInvoice::class,
        ])->assertTableActionHidden('refund', $failed)
            ->assertTableActionHidden('refund', $processing);

        $this->assertSame('0.00', $failed->fresh()->refunded_amount);
        $this->assertSame('0.00', $processing->fresh()->refunded_amount);
        $this->assertDatabaseCount('adjustment_notes', 0);
    }

    public function test_admin_can_locally_refund_a_manual_successful_payment_without_a_gateway_transaction_id(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $invoice = Invoice::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => Invoice::STATUS_PENDING,
        ]);
        $invoice->items()->create(['description' => 'Manual payment', 'price' => 10, 'quantity' => 1]);
        $transaction = $invoice->transactions()->create([
            'amount' => 10,
            'status' => InvoiceTransactionStatus::Succeeded,
        ]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(TransactionsRelationManager::class, [
            'ownerRecord' => $invoice,
            'pageClass' => ViewInvoice::class,
        ])
            ->assertTableActionVisible('refund', $transaction)
            ->callTableAction('refund', $transaction, data: ['amount' => 5]);

        $this->assertSame('5.00', $transaction->fresh()->refunded_amount);
        $this->assertDatabaseHas('adjustment_notes', [
            'invoice_id' => $invoice->id,
            'amount' => -5,
        ]);
    }

    public function test_admin_recording_a_manual_payment_pays_the_invoice_and_queues_service_creation(): void
    {
        config(['settings.immutable_invoices_enabled' => false]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $user = User::factory()->create();
        $server = Server::create(['name' => 'Test server', 'type' => 'server', 'extension' => 'Pterodactyl', 'enabled' => true]);
        $product = $this->createProduct(['server_id' => $server->id]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => Service::STATUS_PENDING,
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'currency_code' => 'CNY',
            'status' => Invoice::STATUS_PENDING,
        ]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'description' => $service->description,
            'price' => 10,
            'quantity' => 1,
        ]);
        Queue::fake();
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(TransactionsRelationManager::class, [
            'ownerRecord' => $invoice,
            'pageClass' => EditInvoice::class,
        ])->callTableAction('create', data: [
            'amount' => 10,
            'transaction_id' => 'manual-payment-1',
        ]);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertDatabaseHas('invoice_transactions', [
            'invoice_id' => $invoice->id,
            'transaction_id' => 'manual-payment-1',
            'status' => InvoiceTransactionStatus::Succeeded->value,
            'applied_to_invoice' => true,
        ]);
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        Queue::assertPushed(CreateJob::class, fn ($job) => $job->service->is($service));
    }
}
