<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-P9-01a / PO-BLP901A-1: privates Upload-Fundament + Kundenbestätigungs-Snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispo_order_uploads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dispo_order_id')->constrained('dispo_orders')->cascadeOnDelete();
            $table->string('category', 64);
            $table->string('original_filename');
            $table->string('storage_path');
            $table->string('mime_type', 255)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->foreignId('uploaded_by_user_id')->constrained('users');
            $table->string('uploaded_by_name_snapshot');
            $table->timestamp('uploaded_at');
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('archived_by_name_snapshot')->nullable();
            $table->timestamps();

            $table->index(['dispo_order_id', 'category', 'archived_at']);
            $table->index(['dispo_order_id', 'uploaded_at']);
        });

        Schema::table('dispo_order_approval_requests', function (Blueprint $table): void {
            $table->string('customer_confirmation_mode', 32)->nullable()->after('special_approval_reasons');
            $table->unsignedBigInteger('customer_confirmation_upload_id')->nullable()->after('customer_confirmation_mode');
            $table->string('customer_confirmation_upload_category', 64)->nullable();
            $table->string('customer_confirmation_upload_original_filename')->nullable();
            $table->string('customer_confirmation_upload_mime_type', 255)->nullable();
            $table->unsignedBigInteger('customer_confirmation_upload_size_bytes')->nullable();
            $table->string('customer_confirmation_upload_sha256', 64)->nullable();
            $table->timestamp('customer_confirmation_upload_uploaded_at')->nullable();
            $table->unsignedBigInteger('customer_confirmation_upload_uploaded_by_id')->nullable();
            $table->string('customer_confirmation_upload_uploaded_by_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dispo_order_approval_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'customer_confirmation_mode',
                'customer_confirmation_upload_id',
                'customer_confirmation_upload_category',
                'customer_confirmation_upload_original_filename',
                'customer_confirmation_upload_mime_type',
                'customer_confirmation_upload_size_bytes',
                'customer_confirmation_upload_sha256',
                'customer_confirmation_upload_uploaded_at',
                'customer_confirmation_upload_uploaded_by_id',
                'customer_confirmation_upload_uploaded_by_name',
            ]);
        });

        Schema::dropIfExists('dispo_order_uploads');
    }
};
