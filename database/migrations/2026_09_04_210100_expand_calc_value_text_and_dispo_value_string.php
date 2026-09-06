<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DF-3.2a: Calc value_text → MEDIUMTEXT (20000 utf8mb4); Dispo Header value_string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispo_order_field_values', function (Blueprint $table) {
            $table->string('value_string', 255)->nullable()->after('snapshot_field_definition_id');
        });

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE calculation_field_values MODIFY value_text MEDIUMTEXT NULL');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $tooLarge = (int) DB::table('calculation_field_values')
                ->whereNotNull('value_text')
                ->whereRaw('OCTET_LENGTH(value_text) > 65535')
                ->count();

            if ($tooLarge > 0) {
                throw new RuntimeException(
                    "Rollback von calculation_field_values.value_text auf TEXT abgebrochen: {$tooLarge} Zeile(n) überschreiten 65535 Bytes (utf8mb4).",
                );
            }

            DB::statement('ALTER TABLE calculation_field_values MODIFY value_text TEXT NULL');
        }

        Schema::table('dispo_order_field_values', function (Blueprint $table) {
            $table->dropColumn('value_string');
        });
    }
};
