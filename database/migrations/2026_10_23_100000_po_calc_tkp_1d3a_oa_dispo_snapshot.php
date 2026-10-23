<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PO-CALC-TKP-1D3a: additive OA-TKP-Snapshot-Felder auf dispo_order_positions.
 *
 * Spot-Spalten unverändert. Bestehende Dispoaufträge bleiben lesbar.
 * Kein Registry-Release / kein HTTP-Store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispo_order_positions', function (Blueprint $table): void {
            if (! Schema::hasColumn('dispo_order_positions', 'impressions')) {
                $table->unsignedBigInteger('impressions')->nullable()->after('total_spot_count');
            }
            if (! Schema::hasColumn('dispo_order_positions', 'basis_tkp')) {
                $table->decimal('basis_tkp', 16, 4)->nullable()->after('impressions');
            }
            if (! Schema::hasColumn('dispo_order_positions', 'list_tkp_snapshot')) {
                $table->decimal('list_tkp_snapshot', 16, 4)->nullable()->after('basis_tkp');
            }
            if (! Schema::hasColumn('dispo_order_positions', 'minimum_tkp_snapshot')) {
                $table->decimal('minimum_tkp_snapshot', 16, 4)->nullable()->after('list_tkp_snapshot');
            }
            if (! Schema::hasColumn('dispo_order_positions', 'final_tkp')) {
                $table->decimal('final_tkp', 16, 4)->nullable()->after('minimum_tkp_snapshot');
            }
            if (! Schema::hasColumn('dispo_order_positions', 'targeting_surcharge_total')) {
                $table->decimal('targeting_surcharge_total', 16, 4)->nullable()->after('final_tkp');
            }
            if (! Schema::hasColumn('dispo_order_positions', 'below_minimum_tkp')) {
                $table->boolean('below_minimum_tkp')->nullable()->after('targeting_surcharge_total');
            }
            if (! Schema::hasColumn('dispo_order_positions', 'oa_tkp_price_list_id')) {
                $table->unsignedBigInteger('oa_tkp_price_list_id')->nullable()->after('price_list_id');
            }
            if (! Schema::hasColumn('dispo_order_positions', 'oa_tkp_price_list_version')) {
                $table->string('oa_tkp_price_list_version', 64)->nullable()->after('oa_tkp_price_list_id');
            }
            if (! Schema::hasColumn('dispo_order_positions', 'oa_tkp_price_list_year')) {
                $table->unsignedSmallInteger('oa_tkp_price_list_year')->nullable()->after('oa_tkp_price_list_version');
            }
            if (! Schema::hasColumn('dispo_order_positions', 'oa_tkp_price_list_revision')) {
                $table->unsignedInteger('oa_tkp_price_list_revision')->nullable()->after('oa_tkp_price_list_year');
            }
            if (! Schema::hasColumn('dispo_order_positions', 'oa_platform_allocations_snapshot')) {
                $table->json('oa_platform_allocations_snapshot')->nullable()->after('position_discounts_snapshot');
            }
            if (! Schema::hasColumn('dispo_order_positions', 'oa_targeting_selections_snapshot')) {
                $table->json('oa_targeting_selections_snapshot')->nullable()->after('oa_platform_allocations_snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dispo_order_positions', function (Blueprint $table): void {
            foreach ([
                'impressions',
                'basis_tkp',
                'list_tkp_snapshot',
                'minimum_tkp_snapshot',
                'final_tkp',
                'targeting_surcharge_total',
                'below_minimum_tkp',
                'oa_tkp_price_list_id',
                'oa_tkp_price_list_version',
                'oa_tkp_price_list_year',
                'oa_tkp_price_list_revision',
                'oa_platform_allocations_snapshot',
                'oa_targeting_selections_snapshot',
            ] as $column) {
                if (Schema::hasColumn('dispo_order_positions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
