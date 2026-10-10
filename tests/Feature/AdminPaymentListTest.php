<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceTransactions\Pages\ListInvoiceTransactions;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPaymentListTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_list_filters_status_and_date_and_sorts_amount_and_fee(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $invoice = Invoice::factory()->create(['user_id' => User::factory()->create()->id]);
        $small = $invoice->transactions()->create(['amount' => 10, 'fee' => 3, 'status' => InvoiceTransactionStatus::Failed]);
        $large = $invoice->transactions()->create(['amount' => 100, 'fee' => 1, 'status' => InvoiceTransactionStatus::Failed]);
        $processing = $invoice->transactions()->create(['amount' => 30, 'fee' => 2, 'status' => InvoiceTransactionStatus::Processing]);
        $old = $invoice->transactions()->create(['amount' => 20, 'status' => InvoiceTransactionStatus::Failed]);
        $old->forceFill(['created_at' => today()->subDay()])->save();
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(ListInvoiceTransactions::class)
            ->filterTable('status', InvoiceTransactionStatus::Failed->value)
            ->filterTable('created_at', ['from' => today()->toDateString(), 'until' => today()->toDateString()])
            ->sortTable('formattedAmount', 'asc')
            ->assertCanSeeTableRecords([$small, $large], inOrder: true)
            ->assertCanNotSeeTableRecords([$processing, $old])
            ->sortTable('formattedFee', 'asc')
            ->assertCanSeeTableRecords([$large, $small], inOrder: true);
    }

    public function test_payment_list_bulk_delete_keeps_successful_processing_and_applied_transactions(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $invoice = Invoice::factory()->create(['user_id' => User::factory()->create()->id]);
        $paid = $invoice->transactions()->create(['amount' => 10, 'status' => InvoiceTransactionStatus::Succeeded]);
        $processing = $invoice->transactions()->create(['amount' => 10, 'status' => InvoiceTransactionStatus::Processing]);
        $applied = $invoice->transactions()->create(['amount' => 10, 'status' => InvoiceTransactionStatus::Failed, 'applied_to_invoice' => true]);
        $failed = $invoice->transactions()->create(['amount' => 10, 'status' => InvoiceTransactionStatus::Failed]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(ListInvoiceTransactions::class)
            ->assertTableActionHidden('delete', $paid)
            ->assertTableActionHidden('delete', $processing)
            ->assertTableActionHidden('delete', $applied)
            ->assertTableActionVisible('delete', $failed)
            ->callTableBulkAction('delete', [$paid, $processing, $applied, $failed]);

        foreach ([$paid, $processing, $applied] as $transaction) {
            $this->assertDatabaseHas('invoice_transactions', ['id' => $transaction->id]);
        }
        $this->assertDatabaseMissing('invoice_transactions', ['id' => $failed->id]);
    }
}
