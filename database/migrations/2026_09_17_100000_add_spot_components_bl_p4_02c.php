<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-P4-02c / AT-04: Spot-Komponenten (Hauptspot + Allonge) + Strategie je Regel/Position.
 * Additiv; Legacy-Positionen ohne Komponenten bleiben unverändert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_medium_rules', function (Blueprint $table) {
            $table->string('component_calculation_strategy', 32)
                ->default('shared_total_length')
                ->after('is_ae_eligible');
        });

        Schema::table('calculation_positions', function (Blueprint $table) {
            $table->string('component_calculation_strategy', 32)
                ->nullable()
                ->after('length_seconds');
        });

        Schema::create('calculation_position_components', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('calculation_position_id');
            $table->string('role', 32);
            $table->string('label');
            $table->unsignedInteger('length_seconds');
            $table->unsignedInteger('sort')->default(0);
            $table->unsignedInteger('length_index')->nullable();
            $table->decimal('media_gross', 14, 2)->nullable();
            $table->timestamps();

            $table->index('calculation_position_id', 'calc_pos_components_position_idx');
            $table->foreign('calculation_position_id', 'calc_pos_components_fk')
                ->references('id')
                ->on('calculation_positions')
                ->cascadeOnDelete();
        });

        Schema::table('dispo_order_positions', function (Blueprint $table) {
            $table->string('component_calculation_strategy', 32)
                ->nullable()
                ->after('length_seconds');
            $table->json('components_snapshot')
                ->nullable()
                ->after('planner_entries_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('dispo_order_positions', function (Blueprint $table) {
            $table->dropColumn(['components_snapshot', 'component_calculation_strategy']);
        });

        Schema::dropIfExists('calculation_position_components');

        Schema::table('calculation_positions', function (Blueprint $table) {
            $table->dropColumn('component_calculation_strategy');
        });

        Schema::table('inventory_medium_rules', function (Blueprint $table) {
            $table->dropColumn('component_calculation_strategy');
        });
    }
};
