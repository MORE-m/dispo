<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-P9-02a / AUTH-007: ausdrücklich erteilbares Extra-Recht für Dispo-Ansicht
 * (typisch Produktmanagement). Öffnet keinen Kalkulationszugang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_view_dispo_orders')
                ->default(false)
                ->after('can_special_approve');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_view_dispo_orders');
        });
    }
};
