<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PO-CALC-TKP-1D3d-2b-1: operativer verbindlicher Kampagnenzeitraum je Dispo-Position.
 * Getrennt von Calc-Origin-/Handoff-DF und Derived Campaign Period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispo_order_positions', function (Blueprint $table): void {
            $table->date('operational_flight_start')->nullable()->after('invoice_end_months');
            $table->date('operational_flight_end')->nullable()->after('operational_flight_start');
        });
    }

    public function down(): void
    {
        Schema::table('dispo_order_positions', function (Blueprint $table): void {
            $table->dropColumn(['operational_flight_start', 'operational_flight_end']);
        });
    }
};
