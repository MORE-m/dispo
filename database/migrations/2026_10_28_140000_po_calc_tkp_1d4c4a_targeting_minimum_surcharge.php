<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PO-CALC-TKP-1D4c-4a: Mindest-TKP-Aufschlag an Targeting-Stammdaten.
 *
 * tkp_surcharge bleibt Listen-Aufschlag. Engine/Under-Min unverändert (D4c-4b).
 * Selection-Snapshots werden in D4c-4b ergänzt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('oa_targeting_definitions')) {
            return;
        }

        if (! Schema::hasColumn('oa_targeting_definitions', 'minimum_tkp_surcharge')) {
            Schema::table('oa_targeting_definitions', function (Blueprint $table): void {
                $table->decimal('minimum_tkp_surcharge', 16, 4)
                    ->default(0)
                    ->after('tkp_surcharge');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('oa_targeting_definitions')) {
            return;
        }

        if (Schema::hasColumn('oa_targeting_definitions', 'minimum_tkp_surcharge')) {
            Schema::table('oa_targeting_definitions', function (Blueprint $table): void {
                $table->dropColumn('minimum_tkp_surcharge');
            });
        }
    }
};
