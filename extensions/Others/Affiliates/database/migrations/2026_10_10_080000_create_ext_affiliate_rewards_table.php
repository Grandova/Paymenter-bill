<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Paymenter\Extensions\Others\Affiliates\Models\Affiliate;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ext_affiliate_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Affiliate::class)->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('amount', 17, 2);
            $table->string('currency_code', 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_affiliate_rewards');
    }
};
