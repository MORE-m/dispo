<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-P4-03a / STD-001–STD-009 / VER-004: Standardangebote inkl. Versions- und
 * Übernahme-Herkunft an Kalkulationen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standard_offer_number_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_seq')->default(0);
            $table->timestamps();
        });

        Schema::create('standard_offers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->unsignedSmallInteger('number_year');
            $table->unsignedInteger('number_seq');
            $table->string('title');
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['number_year', 'number_seq']);
        });

        Schema::create('standard_offer_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('standard_offer_id')->constrained('standard_offers')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 32);
            $table->string('title');
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->json('draft_payload')->nullable();
            $table->json('frozen_materialization')->nullable();
            $table->foreignId('configuration_snapshot_id')->nullable()->constrained('configuration_snapshots')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['standard_offer_id', 'version_number']);
            $table->index(['standard_offer_id', 'status']);
        });

        Schema::table('calculations', function (Blueprint $table) {
            $table->foreignId('origin_standard_offer_version_id')
                ->nullable()
                ->after('configuration_snapshot_id')
                ->constrained('standard_offer_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('calculations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origin_standard_offer_version_id');
        });
        Schema::dropIfExists('standard_offer_versions');
        Schema::dropIfExists('standard_offers');
        Schema::dropIfExists('standard_offer_number_sequences');
    }
};
