<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-P9-01c: Metadaten für dynamische Datei-Uploads an dispo_order_uploads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispo_order_uploads', function (Blueprint $table): void {
            $table->string('field_key', 64)->nullable()->after('category');
            $table->string('field_label_snapshot')->nullable()->after('field_key');
            $table->unsignedBigInteger('snapshot_field_definition_id')->nullable()->after('field_label_snapshot');
            $table->unsignedBigInteger('dispo_order_position_id')->nullable()->after('snapshot_field_definition_id');
            $table->string('position_label_snapshot')->nullable()->after('dispo_order_position_id');

            $table->foreign('snapshot_field_definition_id')
                ->references('id')
                ->on('snapshot_field_definitions')
                ->nullOnDelete();
            $table->foreign('dispo_order_position_id')
                ->references('id')
                ->on('dispo_order_positions')
                ->nullOnDelete();

            $table->index(['dispo_order_id', 'field_key', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::table('dispo_order_uploads', function (Blueprint $table): void {
            $table->dropForeign(['snapshot_field_definition_id']);
            $table->dropForeign(['dispo_order_position_id']);
            $table->dropIndex(['dispo_order_id', 'field_key', 'archived_at']);
            $table->dropColumn([
                'field_key',
                'field_label_snapshot',
                'snapshot_field_definition_id',
                'dispo_order_position_id',
                'position_label_snapshot',
            ]);
        });
    }
};
