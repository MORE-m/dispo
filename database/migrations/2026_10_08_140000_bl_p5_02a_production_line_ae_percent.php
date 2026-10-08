<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-P5-02a Review-Nachzug: tatsächlich angewendeten Produktions-AE-Satz einfrieren.
 *
 * Rücksetzplan (down): Spalte ae_percent entfernen; eingefrorene Sätze gehen verloren.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calculation_position_production_lines', function (Blueprint $table): void {
            $table->decimal('ae_percent', 8, 4)->default(0)->after('is_ae_eligible');
        });
    }

    public function down(): void
    {
        Schema::table('calculation_position_production_lines', function (Blueprint $table): void {
            $table->dropColumn('ae_percent');
        });
    }
};
