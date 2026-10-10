<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->string('currency_code', 3);
            $table->decimal('amount', 17, 2);
            $table->decimal('balance_before', 17, 2);
            $table->decimal('balance_after', 17, 2);
            $table->string('type');
            $table->string('description')->nullable();
            $table->nullableMorphs('reference');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'currency_code', 'created_at']);
        });

        DB::table('credits')
            ->select('user_id', 'currency_code', DB::raw('SUM(amount) as balance'))
            ->groupBy('user_id', 'currency_code')
            ->orderBy('user_id')
            ->get()
            ->each(function ($credit): void {
                DB::table('credit_transactions')->insert([
                    'user_id' => $credit->user_id,
                    'currency_code' => $credit->currency_code,
                    'amount' => $credit->balance,
                    'balance_before' => '0.00',
                    'balance_after' => $credit->balance,
                    'type' => 'opening_balance',
                    'description' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
