<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DF-3.2a: is_active + lock_version für Custom-/System-Definitionen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_definitions', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('applies_to');
            $table->unsignedInteger('lock_version')->default(1)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('field_definitions', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'lock_version']);
        });
    }
};
