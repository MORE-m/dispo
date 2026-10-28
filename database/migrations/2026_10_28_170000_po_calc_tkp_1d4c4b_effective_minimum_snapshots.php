<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PO-CALC-TKP-1D4c-4b: Snapshot-Trennung Grundminimum vs. effektives Mindest-TKP
 * sowie Mindest-Aufschlag je Targeting-Selection.
 *
 * Historische Zeilen bleiben unmigriert:
 * - fehlendes price_list_minimum_tkp_snapshot → Legacy-Assessor (basis vs. minimum_tkp_snapshot)
 * - fehlendes minimum_tkp_surcharge_snapshot → 0 beim Lesen neuer Engine-Pfade
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('calculation_positions')
            && ! Schema::hasColumn('calculation_positions', 'price_list_minimum_tkp_snapshot')
        ) {
            Schema::table('calculation_positions', function (Blueprint $table): void {
                $table->decimal('price_list_minimum_tkp_snapshot', 16, 4)
                    ->nullable()
                    ->after('minimum_tkp_snapshot');
            });
        }

        if (Schema::hasTable('calculation_position_oa_targeting_selections')
            && ! Schema::hasColumn('calculation_position_oa_targeting_selections', 'minimum_tkp_surcharge_snapshot')
        ) {
            Schema::table('calculation_position_oa_targeting_selections', function (Blueprint $table): void {
                $table->decimal('minimum_tkp_surcharge_snapshot', 16, 4)
                    ->nullable()
                    ->after('tkp_surcharge_snapshot');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('calculation_position_oa_targeting_selections')
            && Schema::hasColumn('calculation_position_oa_targeting_selections', 'minimum_tkp_surcharge_snapshot')
        ) {
            Schema::table('calculation_position_oa_targeting_selections', function (Blueprint $table): void {
                $table->dropColumn('minimum_tkp_surcharge_snapshot');
            });
        }

        if (Schema::hasTable('calculation_positions')
            && Schema::hasColumn('calculation_positions', 'price_list_minimum_tkp_snapshot')
        ) {
            Schema::table('calculation_positions', function (Blueprint $table): void {
                $table->dropColumn('price_list_minimum_tkp_snapshot');
            });
        }
    }
};
