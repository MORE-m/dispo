<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DF-3.2b: Dispo-Position value_string/value_text; Calc-Position value_text → MEDIUMTEXT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispo_order_position_field_values', function (Blueprint $table) {
            $table->string('value_string', 255)->nullable()->after('snapshot_field_definition_id');
            $table->mediumText('value_text')->nullable()->after('value_string');
        });

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE calculation_position_field_values MODIFY value_text MEDIUMTEXT NULL');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $tooLarge = (int) DB::table('calculation_position_field_values')
                ->whereNotNull('value_text')
                ->whereRaw('OCTET_LENGTH(value_text) > 65535')
                ->count();

            if ($tooLarge > 0) {
                throw new RuntimeException(
                    "Rollback von calculation_position_field_values.value_text auf TEXT abgebrochen: {$tooLarge} Zeile(n) überschreiten 65535 Bytes (utf8mb4).",
                );
            }

            DB::statement('ALTER TABLE calculation_position_field_values MODIFY value_text TEXT NULL');
        }

        Schema::table('dispo_order_position_field_values', function (Blueprint $table) {
            $table->dropColumn(['value_string', 'value_text']);
        });
    }
};
