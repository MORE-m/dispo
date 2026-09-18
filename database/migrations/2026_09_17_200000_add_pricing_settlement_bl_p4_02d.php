<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calculation_positions', function (Blueprint $table): void {
            $table->string('pricing_settlement_mode', 32)->default('normal')->after('nn_invest');
            $table->decimal('fixed_price_nn', 14, 2)->nullable()->after('pricing_settlement_mode');
            $table->decimal('effective_pay_factor_percent', 12, 4)->nullable()->after('fixed_price_nn');
            $table->decimal('effective_total_discount_percent', 12, 4)->nullable()->after('effective_pay_factor_percent');
        });

        Schema::table('dispo_order_positions', function (Blueprint $table): void {
            $table->string('pricing_settlement_mode', 32)->default('normal')->after('nn_invest');
            $table->decimal('fixed_price_nn', 14, 2)->nullable()->after('pricing_settlement_mode');
            $table->decimal('effective_pay_factor_percent', 12, 4)->nullable()->after('fixed_price_nn');
            $table->decimal('effective_total_discount_percent', 12, 4)->nullable()->after('effective_pay_factor_percent');
        });
    }

    public function down(): void
    {
        Schema::table('dispo_order_positions', function (Blueprint $table): void {
            $table->dropColumn([
                'pricing_settlement_mode',
                'fixed_price_nn',
                'effective_pay_factor_percent',
                'effective_total_discount_percent',
            ]);
        });

        Schema::table('calculation_positions', function (Blueprint $table): void {
            $table->dropColumn([
                'pricing_settlement_mode',
                'fixed_price_nn',
                'effective_pay_factor_percent',
                'effective_total_discount_percent',
            ]);
        });
    }
};
