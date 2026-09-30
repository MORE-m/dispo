<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-P2-02a / MAT-CORE-1: Kombinationstabelle Buchungskennzeichen / Einplanung / Hinweis
 * plus Freeze-Spalten an Calc- und Dispo-Positionen.
 *
 * Additive, nullable Spalten – Legacy ohne Backfill aus Live-Katalog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_medium_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_medium_rules', 'booking_code')) {
                $table->string('booking_code', 32)->nullable()->after('is_active');
            }
            if (! Schema::hasColumn('inventory_medium_rules', 'planning_responsibility_key')) {
                $table->string('planning_responsibility_key', 64)->nullable()->after('booking_code');
            }
            if (! Schema::hasColumn('inventory_medium_rules', 'hint_text')) {
                $table->text('hint_text')->nullable()->after('planning_responsibility_key');
            }
            if (! Schema::hasColumn('inventory_medium_rules', 'sort')) {
                $table->unsignedInteger('sort')->default(0)->after('hint_text');
            }
            if (! Schema::hasColumn('inventory_medium_rules', 'lock_version')) {
                $table->unsignedInteger('lock_version')->default(1)->after('component_calculation_strategy');
            }
        });

        Schema::table('calculation_positions', function (Blueprint $table) {
            if (! Schema::hasColumn('calculation_positions', 'booking_code')) {
                $table->string('booking_code', 32)->nullable()->after('inventory_medium_rule_id');
            }
            if (! Schema::hasColumn('calculation_positions', 'planning_responsibility_key')) {
                $table->string('planning_responsibility_key', 64)->nullable()->after('booking_code');
            }
            if (! Schema::hasColumn('calculation_positions', 'planning_responsibility_label')) {
                $table->string('planning_responsibility_label', 255)->nullable()->after('planning_responsibility_key');
            }
            if (! Schema::hasColumn('calculation_positions', 'combination_hint_text')) {
                $table->text('combination_hint_text')->nullable()->after('planning_responsibility_label');
            }
        });

        Schema::table('dispo_order_positions', function (Blueprint $table) {
            if (! Schema::hasColumn('dispo_order_positions', 'booking_code')) {
                $table->string('booking_code', 32)->nullable()->after('advertising_medium_code');
            }
            if (! Schema::hasColumn('dispo_order_positions', 'planning_responsibility_key')) {
                $table->string('planning_responsibility_key', 64)->nullable()->after('booking_code');
            }
            if (! Schema::hasColumn('dispo_order_positions', 'planning_responsibility_label')) {
                $table->string('planning_responsibility_label', 255)->nullable()->after('planning_responsibility_key');
            }
            if (! Schema::hasColumn('dispo_order_positions', 'combination_hint_text')) {
                $table->text('combination_hint_text')->nullable()->after('planning_responsibility_label');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dispo_order_positions', function (Blueprint $table) {
            foreach (['combination_hint_text', 'planning_responsibility_label', 'planning_responsibility_key', 'booking_code'] as $column) {
                if (Schema::hasColumn('dispo_order_positions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('calculation_positions', function (Blueprint $table) {
            foreach (['combination_hint_text', 'planning_responsibility_label', 'planning_responsibility_key', 'booking_code'] as $column) {
                if (Schema::hasColumn('calculation_positions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('inventory_medium_rules', function (Blueprint $table) {
            foreach (['lock_version', 'sort', 'hint_text', 'planning_responsibility_key', 'booking_code'] as $column) {
                if (Schema::hasColumn('inventory_medium_rules', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
