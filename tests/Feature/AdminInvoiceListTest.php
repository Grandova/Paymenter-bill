<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminInvoiceListTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_filter_overdue_invoices_and_draft_status(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->first()->id]);
        $customer = User::factory()->create();
        $overdue = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => Invoice::STATUS_PENDING,
            'due_at' => today()->subDay(),
        ]);
        $draft = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => Invoice::STATUS_DRAFT,
            'due_at' => today()->subDay(),
        ]);
        $future = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => Invoice::STATUS_PENDING,
            'due_at' => today()->addDay(),
        ]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(ListInvoices::class)
            ->filterTable('overdue', true)
            ->assertCanSeeTableRecords([$overdue])
            ->assertCanNotSeeTableRecords([$draft, $future])
            ->resetTableFilters()
            ->filterTable('status', Invoice::STATUS_DRAFT)
            ->assertCanSeeTableRecords([$draft])
            ->assertCanNotSeeTableRecords([$overdue, $future]);
    }
}
