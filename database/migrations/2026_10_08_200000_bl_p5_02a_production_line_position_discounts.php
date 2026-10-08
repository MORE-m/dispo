<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-P5-02a Review-Nachzug P2: angewendete Produktions-Positionsrabatte einfrieren.
 *
 * - position_discount_percent: effektiver gestapelter Positionsrabatt der Zeile (0 wenn nicht rabattfähig)
 * - position_discounts_snapshot: Staffel (type/custom_label/percent), leer wenn nicht rabattfähig
 *
 * Rücksetzplan (down): Spalten entfernen; eingefrorene Produktions-Rabattkonditionen gehen verloren.
 * Dispo-Snapshots bleiben unverändert (eigene Kopie zum Übernahmezeitpunkt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calculation_position_production_lines', function (Blueprint $table): void {
            $table->decimal('position_discount_percent', 8, 4)->default(0)->after('ae_percent');
            $table->json('position_discounts_snapshot')->nullable()->after('position_discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('calculation_position_production_lines', function (Blueprint $table): void {
            $table->dropColumn(['position_discount_percent', 'position_discounts_snapshot']);
        });
    }
};
