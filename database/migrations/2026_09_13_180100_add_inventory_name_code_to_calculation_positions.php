<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BL-P2-01a: historischer Inventarname/-code an Kalkulationspositionen.
 * Backfill aus dem aktuell referenzierten Inventar; keine ID-Änderung,
 * keine Dispo-Snapshot-Mutation, keine erfundenen Werte.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('calculation_positions')) {
            return;
        }

        if (! Schema::hasColumn('calculation_positions', 'inventory_name')) {
            Schema::table('calculation_positions', function (Blueprint $table): void {
                $table->string('inventory_name')->nullable()->after('inventory_id');
            });
        }

        if (! Schema::hasColumn('calculation_positions', 'inventory_code')) {
            Schema::table('calculation_positions', function (Blueprint $table): void {
                $table->string('inventory_code', 64)->nullable()->after('inventory_name');
            });
        }

        if (! Schema::hasTable('inventories')) {
            return;
        }

        $positions = DB::table('calculation_positions')
            ->select('id', 'inventory_id', 'inventory_name', 'inventory_code')
            ->get();

        foreach ($positions as $position) {
            $needsName = $position->inventory_name === null || $position->inventory_name === '';
            $needsCode = $position->inventory_code === null || $position->inventory_code === '';
            if (! $needsName && ! $needsCode) {
                continue;
            }

            $inventory = DB::table('inventories')
                ->select('name', 'code')
                ->where('id', $position->inventory_id)
                ->first();

            if ($inventory === null) {
                continue;
            }

            $payload = [];
            if ($needsName) {
                $payload['inventory_name'] = $inventory->name;
            }
            if ($needsCode) {
                $payload['inventory_code'] = $inventory->code;
            }

            DB::table('calculation_positions')
                ->where('id', $position->id)
                ->update($payload);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('calculation_positions')) {
            return;
        }

        Schema::table('calculation_positions', function (Blueprint $table): void {
            if (Schema::hasColumn('calculation_positions', 'inventory_code')) {
                $table->dropColumn('inventory_code');
            }
            if (Schema::hasColumn('calculation_positions', 'inventory_name')) {
                $table->dropColumn('inventory_name');
            }
        });
    }
};
