<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-P5-02a / PO-BLP502-1: Spotproduktion als eigene Produktionszeile.
 *
 * - production_price_lists: inventarspezifische Produktionspreise (ein unit_price je Liste).
 * - calculation_position_production_lines: eingefrorene Produktionszeilen je Kalkulationsposition.
 * - calculation_positions: production_gross / production_nn_invest (media_gross bleibt medien-only).
 * - dispo_order_positions: line_role + production_*-Snapshot-Felder.
 *
 * Rücksetzplan (down): entfernt die Spalten und Tabellen; Produktionsdaten gehen verloren.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_price_lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_id')->constrained()->restrictOnDelete();
            $table->string('production_type', 64)->default('spot_production');
            $table->unsignedSmallInteger('year');
            $table->string('name');
            $table->string('version', 32);
            $table->unsignedInteger('revision_number');
            $table->unsignedInteger('lock_version')->default(1);
            $table->string('status', 16)->default('draft');
            $table->decimal('unit_price', 14, 2);
            $table->boolean('is_discountable')->default(false);
            $table->boolean('is_ae_eligible')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['inventory_id', 'production_type', 'year', 'revision_number'],
                'prod_price_lists_identity_unique',
            );
            $table->index(
                ['inventory_id', 'production_type', 'year', 'status'],
                'prod_price_lists_active_lookup_idx',
            );
        });

        Schema::create('calculation_position_production_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('calculation_position_id');
            $table->string('client_key')->nullable();
            $table->string('production_type', 64);
            $table->string('label');
            $table->decimal('quantity', 14, 4)->default(0);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_gross', 14, 2);
            $table->text('remark')->nullable();
            $table->unsignedBigInteger('production_price_list_id')->nullable();
            $table->string('production_price_list_version', 32);
            $table->boolean('is_discountable');
            $table->boolean('is_ae_eligible');
            $table->decimal('position_discount_amount', 14, 2)->default(0);
            $table->decimal('order_discount_amount', 14, 2)->default(0);
            $table->decimal('ae_amount', 14, 2)->default(0);
            $table->decimal('nn_invest', 14, 2)->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index('calculation_position_id', 'calc_pos_prod_lines_pos_idx');
            $table->foreign('calculation_position_id', 'calc_pos_prod_lines_pos_fk')
                ->references('id')
                ->on('calculation_positions')
                ->cascadeOnDelete();
            $table->foreign('production_price_list_id', 'calc_pos_prod_lines_list_fk')
                ->references('id')
                ->on('production_price_lists')
                ->restrictOnDelete();
        });

        Schema::table('calculation_positions', function (Blueprint $table): void {
            $table->decimal('production_gross', 14, 2)->default(0);
            $table->decimal('production_nn_invest', 14, 2)->default(0);
        });

        Schema::table('dispo_order_positions', function (Blueprint $table): void {
            $table->string('line_role', 16)->nullable()->default('media');
            $table->string('production_type', 64)->nullable();
            $table->string('production_label')->nullable();
            $table->decimal('production_quantity', 14, 4)->nullable();
            $table->decimal('production_unit_price', 14, 2)->nullable();
            $table->text('production_remark')->nullable();
            $table->unsignedBigInteger('production_price_list_id')->nullable();
            $table->string('production_price_list_version', 32)->nullable();
            $table->decimal('production_line_gross', 14, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dispo_order_positions', function (Blueprint $table): void {
            $table->dropColumn([
                'line_role',
                'production_type',
                'production_label',
                'production_quantity',
                'production_unit_price',
                'production_remark',
                'production_price_list_id',
                'production_price_list_version',
                'production_line_gross',
            ]);
        });

        Schema::table('calculation_positions', function (Blueprint $table): void {
            $table->dropColumn(['production_gross', 'production_nn_invest']);
        });

        Schema::dropIfExists('calculation_position_production_lines');
        Schema::dropIfExists('production_price_lists');
    }
};
