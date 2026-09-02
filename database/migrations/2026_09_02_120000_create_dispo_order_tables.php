<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispo_order_number_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_seq')->default(0);
            $table->timestamps();
        });

        Schema::create('dispo_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calculation_id')->constrained()->restrictOnDelete();
            $table->string('number', 32)->unique();
            $table->unsignedSmallInteger('number_year');
            $table->unsignedInteger('number_org_seq');
            $table->unsignedTinyInteger('number_calc_seq');
            $table->string('status', 32)->default('draft');
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->string('source_calculation_number', 32);
            $table->string('customer_name')->nullable();
            $table->string('agency_name')->nullable();
            $table->string('campaign')->nullable();
            $table->string('product_title')->nullable();
            $table->text('briefing')->nullable();
            $table->foreignId('advisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('advisor_name')->nullable();
            $table->decimal('order_discount_percent', 7, 4)->default(0);
            $table->boolean('ae_enabled')->default(false);
            $table->decimal('target_budget_nn', 14, 2)->nullable();
            $table->decimal('media_gross', 14, 2)->default(0);
            $table->decimal('position_discount_total', 14, 2)->default(0);
            $table->decimal('order_discount_total', 14, 2)->default(0);
            $table->decimal('ae_total', 14, 2)->default(0);
            $table->decimal('nn_invest', 14, 2)->default(0);
            $table->boolean('requires_special_approval')->default(false);
            $table->json('order_discounts_snapshot')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['number_year', 'number_org_seq']);
            $table->unique(['calculation_id', 'number_calc_seq']);
            $table->index('status');
            $table->index('created_at');
        });

        Schema::create('dispo_order_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispo_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calculation_position_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->unsignedBigInteger('inventory_id')->nullable();
            $table->string('inventory_name');
            $table->string('inventory_code', 64)->nullable();
            $table->unsignedBigInteger('advertising_medium_id')->nullable();
            $table->string('advertising_medium_name');
            $table->string('advertising_medium_code', 64)->nullable();
            $table->string('kind', 32);
            $table->string('spot_method', 32);
            $table->unsignedInteger('length_seconds');
            $table->unsignedInteger('total_spot_count')->default(0);
            $table->boolean('needs_spot_redistribution')->default(false);
            $table->unsignedBigInteger('price_list_id')->nullable();
            $table->string('price_list_version', 64)->nullable();
            $table->string('price_list_name')->nullable();
            $table->decimal('average_second_price', 16, 4)->nullable();
            $table->unsignedInteger('length_index')->nullable();
            $table->decimal('surcharge_percent', 7, 4)->default(0);
            $table->decimal('position_discount_percent', 7, 4)->default(0);
            $table->decimal('ae_percent', 7, 4)->default(15);
            $table->boolean('is_discountable')->default(true);
            $table->boolean('is_ae_eligible')->default(true);
            $table->decimal('media_gross', 14, 2)->default(0);
            $table->decimal('position_discount_amount', 14, 2)->default(0);
            $table->decimal('order_discount_amount', 14, 2)->default(0);
            $table->decimal('ae_amount', 14, 2)->default(0);
            $table->decimal('nn_invest', 14, 2)->default(0);
            $table->json('plan_rows_snapshot')->nullable();
            $table->json('time_ranges_snapshot')->nullable();
            $table->json('position_discounts_snapshot')->nullable();
            $table->timestamps();

            $table->index('calculation_position_id');
            $table->index(['dispo_order_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispo_order_positions');
        Schema::dropIfExists('dispo_orders');
        Schema::dropIfExists('dispo_order_number_sequences');
    }
};
