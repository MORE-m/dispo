<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-P4-01b: Auditierbarer Preislisten-Importlauf (Datei privat, Report JSON).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_list_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('status', 32);
            $table->string('original_filename', 255);
            $table->string('stored_path', 512);
            $table->string('checksum_sha256', 64);
            $table->string('mime_type', 127)->nullable();
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('sheet_count')->default(0);
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('valid_row_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->json('report')->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->json('created_price_list_ids')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'year']);
            $table->index('checksum_sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_imports');
    }
};
