<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_transactions', function (Blueprint $table) {
            $table->decimal('credited_amount', 17, 2)->default(0)->after('credited_to_balance');
        });

        DB::table('invoice_transactions')->where('credited_to_balance', true)->update([
            'credited_amount' => DB::raw('amount'),
        ]);
    }

    public function down(): void
    {
        Schema::table('invoice_transactions', function (Blueprint $table) {
            $table->dropColumn('credited_amount');
        });
    }
};
