<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-P8-02c / PO-BLP802C-1: Kundenbestätigung Ausnahmeweg ohne Upload.
 * Additiv, kein fachlicher Backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispo_orders', function (Blueprint $table) {
            $table->boolean('customer_confirmation_without_upload')->default(false)
                ->after('lock_version');
            $table->text('customer_confirmation_exception_reason')->nullable()
                ->after('customer_confirmation_without_upload');
            $table->foreignId('customer_confirmation_exception_set_by_id')->nullable()
                ->after('customer_confirmation_exception_reason')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('customer_confirmation_exception_set_by_name')->nullable()
                ->after('customer_confirmation_exception_set_by_id');
            $table->timestamp('customer_confirmation_exception_set_at')->nullable()
                ->after('customer_confirmation_exception_set_by_name');
        });

        Schema::table('dispo_order_approval_requests', function (Blueprint $table) {
            $table->boolean('customer_confirmation_without_upload')->default(false)
                ->after('special_approval_reasons');
            $table->text('customer_confirmation_exception_reason')->nullable()
                ->after('customer_confirmation_without_upload');
            $table->unsignedBigInteger('customer_confirmation_exception_set_by_id')->nullable()
                ->after('customer_confirmation_exception_reason');
            $table->string('customer_confirmation_exception_set_by_name')->nullable()
                ->after('customer_confirmation_exception_set_by_id');
            $table->timestamp('customer_confirmation_exception_set_at')->nullable()
                ->after('customer_confirmation_exception_set_by_name');
            $table->boolean('customer_confirmation_exception_acknowledged')->default(false)
                ->after('decision_note');
            $table->foreignId('customer_confirmation_exception_acknowledged_by_id')->nullable()
                ->after('customer_confirmation_exception_acknowledged')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('customer_confirmation_exception_acknowledged_by_name')->nullable()
                ->after('customer_confirmation_exception_acknowledged_by_id');
            $table->timestamp('customer_confirmation_exception_acknowledged_at')->nullable()
                ->after('customer_confirmation_exception_acknowledged_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('dispo_order_approval_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_confirmation_exception_acknowledged_by_id');
            $table->dropColumn([
                'customer_confirmation_without_upload',
                'customer_confirmation_exception_reason',
                'customer_confirmation_exception_set_by_id',
                'customer_confirmation_exception_set_by_name',
                'customer_confirmation_exception_set_at',
                'customer_confirmation_exception_acknowledged',
                'customer_confirmation_exception_acknowledged_by_name',
                'customer_confirmation_exception_acknowledged_at',
            ]);
        });

        Schema::table('dispo_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_confirmation_exception_set_by_id');
            $table->dropColumn([
                'customer_confirmation_without_upload',
                'customer_confirmation_exception_reason',
                'customer_confirmation_exception_set_by_name',
                'customer_confirmation_exception_set_at',
            ]);
        });
    }
};
