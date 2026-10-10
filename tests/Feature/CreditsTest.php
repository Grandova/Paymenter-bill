<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Livewire\Client\Credits as CreditsPage;
use App\Models\Credit;
use App\Models\CreditTransaction;
use App\Models\Gateway;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CreditsTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_page_through_their_credit_history(): void
    {
        config(['settings.pagination' => 10, 'settings.credits_enabled' => true]);
        $user = User::factory()->create();
        foreach (range(1, 25) as $index) {
            CreditTransaction::create([
                'user_id' => $user->id,
                'currency_code' => 'CNY',
                'amount' => 1,
                'balance_before' => $index - 1,
                'balance_after' => $index,
                'type' => 'deposit',
                'description' => '充值记录 ' . $index,
                'created_at' => now()->addSeconds($index),
                'updated_at' => now()->addSeconds($index),
            ]);
        }

        $this->actingAs($user)->withSession($this->loginUser($user));

        Livewire::test(CreditsPage::class)
            ->assertSee('充值记录 25')
            ->assertDontSee('充值记录 15')
            ->assertSee('gotoPage(3)')
            ->call('gotoPage', 2)
            ->assertSee('充值记录 15')
            ->assertDontSee('充值记录 25');
    }

    public function test_deposit_rejects_amounts_with_more_than_two_decimal_places(): void
    {
        config([
            'settings.credits_enabled' => true,
            'settings.credits_minimum_deposit' => 1,
            'settings.credits_maximum_deposit' => 100,
            'settings.credits_maximum_credit' => 100,
        ]);

        $user = User::factory()->create();
        $gateway = Gateway::create(['name' => 'Test gateway', 'extension' => 'PayPal', 'type' => 'gateway']);
        $this->actingAs($user);

        $component = app(CreditsPage::class);
        $component->mount();
        $component->currency = 'CNY';
        $component->amount = '1.001';
        $component->gateway = $gateway->id;

        try {
            $component->addCredit();
            $this->fail('A deposit cannot use more than two decimal places.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_rejected_deposit_does_not_leave_a_database_transaction_open(): void
    {
        config([
            'settings.credits_enabled' => true,
            'settings.credits_minimum_deposit' => 1,
            'settings.credits_maximum_deposit' => 100,
            'settings.credits_maximum_credit' => 50,
        ]);

        $user = User::factory()->create();
        Credit::create(['user_id' => $user->id, 'currency_code' => 'CNY', 'amount' => 45]);
        Gateway::create(['name' => 'Test gateway', 'extension' => 'PayPal', 'type' => 'gateway']);
        $this->actingAs($user);
        $transactionLevel = DB::transactionLevel();

        $component = app(CreditsPage::class);
        $component->mount();
        $component->currency = 'CNY';
        $component->amount = 10;

        try {
            $component->addCredit();
            $this->fail('The deposit should be rejected when it exceeds the account credit limit.');
        } catch (DisplayException) {
        }

        $this->assertSame($transactionLevel, DB::transactionLevel());
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_first_deposit_cannot_exceed_the_account_credit_limit(): void
    {
        config([
            'settings.credits_enabled' => true,
            'settings.credits_minimum_deposit' => 1,
            'settings.credits_maximum_deposit' => 100,
            'settings.credits_maximum_credit' => 50,
        ]);

        $user = User::factory()->create();
        Gateway::create(['name' => 'Test gateway', 'extension' => 'PayPal', 'type' => 'gateway']);
        $this->actingAs($user);

        $component = app(CreditsPage::class);
        $component->mount();
        $component->currency = 'CNY';
        $component->amount = 60;

        try {
            $component->addCredit();
            $this->fail('The first deposit should not exceed the account credit limit.');
        } catch (DisplayException) {
        }

        $this->assertDatabaseCount('credits', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_gateway_failure_does_not_roll_back_or_leave_the_deposit_transaction_open(): void
    {
        config([
            'settings.credits_enabled' => true,
            'settings.credits_minimum_deposit' => 1,
            'settings.credits_maximum_deposit' => 100,
            'settings.credits_maximum_credit' => 100,
        ]);

        $user = User::factory()->create();
        $gateway = Gateway::create(['name' => 'Test gateway', 'extension' => 'MissingGateway', 'type' => 'gateway']);
        $this->actingAs($user);

        $component = app(CreditsPage::class);
        $component->gateways = [$gateway];
        $component->gateway = $gateway->id;
        $component->currency = 'CNY';
        $component->amount = 10;
        $transactionLevel = DB::transactionLevel();

        try {
            $component->addCredit();
            $this->fail('The unavailable gateway should fail after the deposit invoice is created.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('MissingGateway', $e->getMessage());
        }

        $this->assertSame($transactionLevel, DB::transactionLevel());
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseHas('invoices', ['user_id' => $user->id, 'status' => 'pending']);
    }
}
