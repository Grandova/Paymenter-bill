<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_upgrades', function (Blueprint $table) {
            $table->boolean('stock_reserved')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('service_upgrades', function (Blueprint $table) {
            $table->dropColumn('stock_reserved');
        });
    }
};
