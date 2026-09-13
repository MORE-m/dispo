<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BL-P2-01a: optimistic locking für Inventar-Admin.
 * Keine Katalogdaten, keine IDs, keine Memberships.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventories') || Schema::hasColumn('inventories', 'lock_version')) {
            return;
        }

        Schema::table('inventories', function (Blueprint $table): void {
            $table->unsignedInteger('lock_version')->default(1)->after('sort');
        });

        DB::table('inventories')->whereNull('lock_version')->update(['lock_version' => 1]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('inventories') || ! Schema::hasColumn('inventories', 'lock_version')) {
            return;
        }

        Schema::table('inventories', function (Blueprint $table): void {
            $table->dropColumn('lock_version');
        });
    }
};
