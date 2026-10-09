<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('currencies')->insertOrIgnore([
            'code' => 'CNY',
            'name' => '人民币',
            'prefix' => '¥',
            'suffix' => '',
            'format' => '1,000.00',
        ]);

        // Only replace the old installation default; financial records retain their original currency.
        DB::table('settings')->whereNull('settingable_type')->where('key', 'default_currency')
            ->where('value', 'USD')->update(['value' => 'CNY']);
        Cache::forget('settings');
    }

    public function down(): void
    {
        // CNY may now be referenced by paid invoices and balances, so keep the currency on rollback.
    }
};
