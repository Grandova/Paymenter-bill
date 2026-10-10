<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')
            ->where('key', 'cronjob_order_terminate')
            ->where('value', '14')
            ->update(['value' => '3']);
    }

    public function down(): void {}
};
