<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispo_orders', function (Blueprint $table) {
            $table->json('source_calculation_totals_snapshot')->nullable()->after('order_discounts_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('dispo_orders', function (Blueprint $table) {
            $table->dropColumn('source_calculation_totals_snapshot');
        });
    }
};
