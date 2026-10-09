<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CnyDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_installation_uses_renminbi(): void
    {
        $this->assertSame('CNY', config('settings.default_currency'));
        $this->assertSame('人民币', Currency::findOrFail('CNY')->name);
        $product = $this->createProduct();
        $this->get(route('products.checkout', [$product->product->category->slug, $product->product->slug]))
            ->assertOk()->assertSee('¥10.00');
    }

    public function test_upgrade_changes_default_without_relabelling_existing_money(): void
    {
        Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'suffix' => '', 'format' => '1,000.00']);
        Setting::whereNull('settingable_type')->where('key', 'default_currency')->update(['value' => 'USD']);
        $invoice = Invoice::factory()->create(['currency_code' => 'USD', 'user_id' => User::factory()->create()->id]);
        $invoice->items()->create(['description' => 'Existing service', 'price' => 19.90, 'quantity' => 1]);
        $credit = $invoice->user->credits()->create(['currency_code' => 'USD', 'amount' => 30]);
        $product = $this->createProduct();
        $price = $product->plan->prices()->create(['currency_code' => 'USD', 'price' => 10]);

        $migration = require database_path('migrations/2026_10_09_000000_set_cny_default_currency.php');
        $migration->up();
        $migration->up();

        $this->assertSame('CNY', Setting::whereNull('settingable_type')->where('key', 'default_currency')->value('value'));
        $this->assertSame('USD', $invoice->fresh()->currency_code);
        $this->assertEquals(19.90, $invoice->fresh()->total);
        $this->assertSame('USD', $credit->fresh()->currency_code);
        $this->assertEquals(30, $credit->fresh()->amount);
        $this->assertSame('USD', $price->fresh()->currency_code);
        $this->assertEquals(10, $price->fresh()->price);
    }

    public function test_upgrade_keeps_a_deliberately_configured_other_currency(): void
    {
        Currency::create(['code' => 'EUR', 'name' => 'Euro', 'prefix' => '€', 'suffix' => '', 'format' => '1,000.00']);
        Setting::whereNull('settingable_type')->where('key', 'default_currency')->update(['value' => 'EUR']);
        $migration = require database_path('migrations/2026_10_09_000000_set_cny_default_currency.php');
        $migration->up();
        $this->assertSame('EUR', Setting::whereNull('settingable_type')->where('key', 'default_currency')->value('value'));
    }
}
