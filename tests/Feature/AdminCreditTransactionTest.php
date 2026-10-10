<?php

namespace Tests\Feature;

use App\Admin\Resources\CreditTransactionResource\Pages\ListCreditTransactions;
use App\Models\CreditTransaction;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminCreditTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_filter_credit_transactions_by_type_and_date(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $role = Role::where('name', 'admin')->first();
        $role->update(['permissions' => ['admin.credits.viewAny']]);
        $admin = User::factory()->create(['role_id' => $role->id]);
        $user = User::factory()->create();
        $matching = CreditTransaction::create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'amount' => 10,
            'balance_before' => 0,
            'balance_after' => 10,
            'type' => 'deposit',
            'created_at' => today(),
        ]);
        $old = CreditTransaction::create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'amount' => 10,
            'balance_before' => 0,
            'balance_after' => 10,
            'type' => 'deposit',
            'created_at' => today()->subDay(),
        ]);
        $otherType = CreditTransaction::create([
            'user_id' => $user->id,
            'currency_code' => 'USD',
            'amount' => -10,
            'balance_before' => 10,
            'balance_after' => 0,
            'type' => 'invoice_payment',
            'created_at' => today(),
        ]);
        $this->actingAs($admin)->withSession($this->loginUser($admin));

        Livewire::test(ListCreditTransactions::class)
            ->filterTable('type', 'deposit')
            ->filterTable('created_at', ['from' => today()->toDateString(), 'until' => today()->toDateString()])
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$old, $otherType]);
    }

    public function test_user_without_credit_view_permission_cannot_open_credit_transactions(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $role = Role::where('name', 'admin')->first();
        $role->update(['permissions' => []]);
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user)->withSession($this->loginUser($user));

        Livewire::test(ListCreditTransactions::class)->assertForbidden();
    }
}
