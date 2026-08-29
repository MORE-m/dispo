<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('calculation_positions')) {
            return;
        }

        DB::table('calculation_positions')
            ->select('id')
            ->whereNull('length_index')
            ->where('length_seconds', '>', 0)
            ->orderBy('id')
            ->chunkById(500, function ($positions): void {
                $ids = $positions->pluck('id')->all();

                if ($ids === []) {
                    return;
                }

                DB::table('calculation_positions')
                    ->whereIn('id', $ids)
                    ->whereNull('length_index')
                    ->update([
                        'length_index' => DB::raw(
                            'CASE
                                WHEN length_seconds BETWEEN 1 AND 15 THEN 110
                                WHEN length_seconds BETWEEN 16 AND 24 THEN 105
                                WHEN length_seconds BETWEEN 25 AND 34 THEN 100
                                WHEN length_seconds >= 35 THEN 95
                                ELSE NULL
                            END',
                        ),
                    ]);
            });
    }

    public function down(): void
    {
        // Daten-Backfill; kein Schema-Rollback nötig.
    }
};
