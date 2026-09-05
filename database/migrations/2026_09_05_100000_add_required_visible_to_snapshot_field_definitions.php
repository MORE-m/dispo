<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DF-3.2a: Pinnt Membership-Overrides required/visible in Snapshot-Definitionen.
 * Bestehende Snapshots: required=false, visible=true (entspricht null-Overrides).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snapshot_field_definitions', function (Blueprint $table) {
            $table->boolean('required')->default(false)->after('reportable');
            $table->boolean('visible')->default(true)->after('required');
        });
    }

    public function down(): void
    {
        Schema::table('snapshot_field_definitions', function (Blueprint $table) {
            $table->dropColumn(['required', 'visible']);
        });
    }
};
