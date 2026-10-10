<?php

namespace Tests\Feature;

use App\Models\Credit;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_changes_create_a_signed_balance_history(): void
    {
        $user = User::factory()->create();
        $credit = $user->credits()->make(['currency_code' => 'CNY', 'amount' => '20.00']);
        $credit->recordAs('deposit', '账单充值')->save();

        $credit->amount = '12.50';
        $credit->recordAs('adjustment', '后台调账')->save();
        $credit->delete();

        $transactions = CreditTransaction::where('user_id', $user->id)->orderBy('id')->get();

        $this->assertCount(3, $transactions);
        $this->assertSame('20.00', $transactions[0]->amount);
        $this->assertSame('0.00', $transactions[0]->balance_before);
        $this->assertSame('20.00', $transactions[0]->balance_after);
        $this->assertSame('-7.50', $transactions[1]->amount);
        $this->assertSame('12.50', $transactions[1]->balance_after);
        $this->assertSame('-12.50', $transactions[2]->amount);
        $this->assertSame('0.00', $transactions[2]->balance_after);
        $this->assertSame('后台调账', $transactions[1]->description);
    }

    public function test_credit_spending_records_each_debit_against_the_invoice(): void
    {
        $user = User::factory()->create();
        $user->credits()->create(['currency_code' => 'CNY', 'amount' => '30.00']);

        $spent = Credit::spend($user, 'CNY', 10, type: 'auto_renewal');

        $this->assertSame(10.0, $spent);
        $transaction = $user->creditTransactions()->firstOrFail();
        $this->assertSame('-10.00', $transaction->amount);
        $this->assertSame('20.00', $transaction->balance_after);
        $this->assertSame('auto_renewal', $transaction->type);
    }
}
