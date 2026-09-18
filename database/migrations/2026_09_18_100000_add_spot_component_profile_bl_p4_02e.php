<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-P4-02e / SPT-012: Komponentenprofil am Werbemittel + Freeze an Calc-/Dispo-Position.
 * Additiv; bestehende Medien ohne Profil behalten optionales Hauptspot/Allonge-Verhalten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advertising_media', function (Blueprint $table) {
            $table->string('component_profile', 32)
                ->nullable()
                ->after('kind');
        });

        Schema::table('calculation_positions', function (Blueprint $table) {
            $table->string('component_profile', 32)
                ->nullable()
                ->after('component_calculation_strategy');
        });

        Schema::table('dispo_order_positions', function (Blueprint $table) {
            $table->string('component_profile', 32)
                ->nullable()
                ->after('component_calculation_strategy');
            $table->unsignedInteger('derived_component_airings')
                ->nullable()
                ->after('component_profile');
        });
    }

    public function down(): void
    {
        Schema::table('dispo_order_positions', function (Blueprint $table) {
            $table->dropColumn(['component_profile', 'derived_component_airings']);
        });

        Schema::table('calculation_positions', function (Blueprint $table) {
            $table->dropColumn('component_profile');
        });

        Schema::table('advertising_media', function (Blueprint $table) {
            $table->dropColumn('component_profile');
        });
    }
};
