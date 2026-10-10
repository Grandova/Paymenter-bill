<?php

namespace Tests\Feature;

use App\Admin\Widgets\Overview;
use App\Admin\Widgets\Revenue;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRevenueTest extends TestCase
{
    use RefreshDatabase;

    public function test_revenue_chart_excludes_refunded_and_credited_amounts(): void
    {
        $invoice = Invoice::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => Invoice::STATUS_PAID,
        ]);
        $invoice->transactions()->create([
            'amount' => 100,
            'refunded_amount' => 20,
            'credited_amount' => 30,
            'fee' => 3,
            'status' => InvoiceTransactionStatus::Succeeded,
            'is_credit_transaction' => false,
            'credited_to_balance' => false,
            'applied_to_invoice' => true,
        ]);

        $widget = new class extends Revenue
        {
            public function data(): array
            {
                return $this->getData();
            }
        };

        $datasets = $widget->data()['datasets'];

        $this->assertEquals(50, array_sum($datasets[0]['data']));
        $this->assertEquals(47, array_sum($datasets[1]['data']));
    }

    public function test_revenue_chart_does_not_add_different_currencies_together(): void
    {
        Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'suffix' => '', 'format' => '1,000.00']);
        $invoice = Invoice::factory()->create([
            'user_id' => User::factory()->create()->id,
            'currency_code' => 'USD',
            'status' => Invoice::STATUS_PAID,
        ]);
        $invoice->transactions()->create([
            'amount' => 100,
            'status' => InvoiceTransactionStatus::Succeeded,
            'is_credit_transaction' => false,
            'credited_to_balance' => false,
            'applied_to_invoice' => true,
        ]);

        $widget = new class extends Revenue
        {
            public function data(): array
            {
                return $this->getData();
            }
        };

        $data = $widget->data();

        $this->assertSame(__('Revenue') . ' (CNY)', $data['datasets'][0]['label']);
        $this->assertEquals(0, array_sum($data['datasets'][0]['data']));

        $overview = new class extends Overview
        {
            public function stats(): array
            {
                return $this->getStats();
            }
        };

        $stat = $overview->stats()[0];
        $this->assertSame(__('Revenue') . ' (CNY)', $stat->getLabel());
        $this->assertEquals(0, $stat->getValue());
    }
}
