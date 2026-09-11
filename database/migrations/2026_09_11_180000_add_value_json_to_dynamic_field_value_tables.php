<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DF-3-REST-C1: additives value_json für Select-/Multi-Select-Laufzeitwerte.
 *
 * Kein Backfill, keine Mutation bestehender Zeilen. Kanal-XOR wird im
 * Application-Layer (ChoiceFieldValueContract) erzwungen – SQLite kann auf
 * bestehenden Tabellen keinen symmetrischen ADD CHECK ohne Tabellen-Rebuild.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = [
        'calculation_field_values',
        'calculation_position_field_values',
        'dispo_order_field_values',
        'dispo_order_position_field_values',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            if (Schema::hasColumn($tableName, 'value_json')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->json('value_json')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            if (! Schema::hasColumn($tableName, 'value_json')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('value_json');
            });
        }
    }
};
