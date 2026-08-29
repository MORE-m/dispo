<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code', 64);
            $table->string('type', 32);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->string('logo_path')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('advertising_media', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 64)->unique();
            $table->string('kind', 32);
            $table->unsignedInteger('default_length_seconds')->default(30);
            $table->boolean('is_discountable')->default(true);
            $table->boolean('is_ae_eligible')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_medium_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained()->restrictOnDelete();
            $table->foreignId('advertising_medium_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('default_length_seconds')->nullable();
            $table->decimal('surcharge_percent', 7, 4)->default(0);
            $table->boolean('is_discountable')->default(true);
            $table->boolean('is_ae_eligible')->default(true);
            $table->timestamps();

            $table->unique(['inventory_id', 'advertising_medium_id'], 'inventory_medium_unique');
        });

        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('version', 64);
            $table->string('status', 32);
            $table->date('valid_from')->nullable();
            $table->timestamps();

            $table->unique(['inventory_id', 'version']);
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('hour');
            $table->string('day_group', 16);
            $table->decimal('second_price', 16, 4);
            $table->timestamps();

            $table->unique(['price_list_id', 'hour', 'day_group'], 'price_list_item_unique');
        });

        Schema::create('calculations', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->unsignedSmallInteger('number_year');
            $table->unsignedInteger('number_seq');
            $table->string('status', 32)->default('draft');
            $table->string('planning_mode', 32)->default('manual');
            $table->foreignId('advisor_id')->constrained('users')->restrictOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('agency_name')->nullable();
            $table->string('campaign')->nullable();
            $table->string('product_title')->nullable();
            $table->text('briefing')->nullable();
            $table->decimal('order_discount_percent', 7, 4)->default(0);
            $table->decimal('target_budget_nn', 14, 2)->nullable();
            $table->string('budget_strategy', 32)->nullable();
            $table->decimal('media_gross', 14, 2)->default(0);
            $table->decimal('position_discount_total', 14, 2)->default(0);
            $table->decimal('order_discount_total', 14, 2)->default(0);
            $table->decimal('ae_total', 14, 2)->default(0);
            $table->decimal('nn_invest', 14, 2)->default(0);
            $table->boolean('requires_special_approval')->default(false);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['number_year', 'number_seq']);
        });

        Schema::create('calculation_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calculation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_id')->constrained()->restrictOnDelete();
            $table->foreignId('advertising_medium_id')->constrained()->restrictOnDelete();
            $table->foreignId('price_list_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 32);
            $table->unsignedInteger('length_seconds');
            $table->decimal('surcharge_percent', 7, 4)->default(0);
            $table->decimal('position_discount_percent', 7, 4)->default(0);
            $table->decimal('ae_percent', 7, 4)->default(15);
            $table->boolean('is_discountable')->default(true);
            $table->boolean('is_ae_eligible')->default(true);
            $table->string('price_list_version', 64)->nullable();
            $table->decimal('media_gross', 14, 2)->default(0);
            $table->decimal('position_discount_amount', 14, 2)->default(0);
            $table->decimal('order_discount_amount', 14, 2)->default(0);
            $table->decimal('ae_amount', 14, 2)->default(0);
            $table->decimal('nn_invest', 14, 2)->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('spot_classic_plan_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calculation_position_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('hour');
            $table->string('day_group', 16);
            $table->unsignedInteger('spot_count');
            $table->decimal('second_price', 16, 4);
            $table->decimal('line_gross', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['calculation_position_id', 'hour', 'day_group'], 'spot_classic_row_unique');
        });

        Schema::create('budget_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calculation_id')->constrained()->cascadeOnDelete();
            $table->string('strategy', 32);
            $table->decimal('target_budget_nn', 14, 2);
            $table->json('payload');
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->string('action', 64);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('budget_proposals');
        Schema::dropIfExists('spot_classic_plan_rows');
        Schema::dropIfExists('calculation_positions');
        Schema::dropIfExists('calculations');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('inventory_medium_rules');
        Schema::dropIfExists('advertising_media');
        Schema::dropIfExists('inventories');
        Schema::dropIfExists('organizations');
    }
};
