<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mehrere Dispoaufträge derselben Kalkulation teilen Jahr + organisationsweite
 * Stammsequenz und unterscheiden sich nur im Suffix. Der bisherige Unique-Index
 * auf (number_year, number_org_seq) verhinderte genau das.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispo_orders', function (Blueprint $table) {
            $table->dropUnique(['number_year', 'number_org_seq']);
            $table->index(['number_year', 'number_org_seq']);
        });
    }

    public function down(): void
    {
        Schema::table('dispo_orders', function (Blueprint $table) {
            $table->dropIndex(['number_year', 'number_org_seq']);
        });

        // Unique auf (number_year, number_org_seq) wird bewusst nicht wieder
        // hergestellt: Familien teilen die Stammnummer; ein Restore würde bei
        // vorhandenem Suffix > 1 und bei DatabaseMigrations-Teardown scheitern.
    }
};
