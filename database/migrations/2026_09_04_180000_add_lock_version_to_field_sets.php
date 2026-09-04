<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DF-3.1: Optimistic Locking für Feldset-Admin (Activate/Draft).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_sets', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(1)->after('active_version_id');
        });
    }

    public function down(): void
    {
        Schema::table('field_sets', function (Blueprint $table) {
            $table->dropColumn('lock_version');
        });
    }
};
