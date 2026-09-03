<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispo_orders', function (Blueprint $table) {
            $table->foreignId('revises_dispo_order_id')
                ->nullable()
                ->after('calculation_id')
                ->constrained('dispo_orders')
                ->restrictOnDelete();

            $table->unique('revises_dispo_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('dispo_orders', function (Blueprint $table) {
            $table->dropUnique(['revises_dispo_order_id']);
            $table->dropConstrainedForeignId('revises_dispo_order_id');
        });
    }
};
