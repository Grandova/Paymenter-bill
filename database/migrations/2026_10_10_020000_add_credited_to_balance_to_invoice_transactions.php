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
            $table->boolean('credited_to_balance')->default(false)->after('is_credit_transaction');
            $table->boolean('applied_to_invoice')->default(false)->after('credited_to_balance');
        });

        DB::table('invoice_transactions')
            ->where('status', 'succeeded')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('invoices')
                    ->whereColumn('invoices.id', 'invoice_transactions.invoice_id')
                    ->whereIn('invoices.status', ['pending', 'paid']);
            })
            ->update(['applied_to_invoice' => true]);
    }

    public function down(): void
    {
        Schema::table('invoice_transactions', function (Blueprint $table) {
            $table->dropColumn(['credited_to_balance', 'applied_to_invoice']);
        });
    }
};
