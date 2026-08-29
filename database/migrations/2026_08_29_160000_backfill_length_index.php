<?php

use App\Services\Calculation\SpotLengthIndex;
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

        $positions = DB::table('calculation_positions')
            ->select('id', 'length_seconds', 'length_index')
            ->whereNull('length_index')
            ->get();

        foreach ($positions as $position) {
            $seconds = (int) $position->length_seconds;
            if ($seconds <= 0) {
                continue;
            }

            DB::table('calculation_positions')
                ->where('id', $position->id)
                ->update(['length_index' => SpotLengthIndex::forSeconds($seconds)]);
        }
    }

    public function down(): void
    {
        // Daten-Backfill; kein Schema-Rollback nötig.
    }
};
