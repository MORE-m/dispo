<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispo_orders', function (Blueprint $table) {
            // Unique index backs the FK (MySQL) and enforces at most one successor.
            $table->unsignedBigInteger('revises_dispo_order_id')
                ->nullable()
                ->after('calculation_id');

            $table->unique('revises_dispo_order_id');

            $table->foreign('revises_dispo_order_id')
                ->references('id')
                ->on('dispo_orders')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dispo_orders', function (Blueprint $table) {
            $table->dropForeign(['revises_dispo_order_id']);
            $table->dropUnique(['revises_dispo_order_id']);
            $table->dropColumn('revises_dispo_order_id');
        });
    }
};
