<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PO-CALC-TKP-1A: OA/TKP-Stammdatenfundament (ohne Engine/Release).
 *
 * - oa_platforms
 * - oa_tkp_price_lists + oa_tkp_price_list_items (Jahr/Revision × Medium × Inventar)
 * - oa_targeting_definitions (absolute TKP-Aufschläge)
 * - oa_adserver_surcharges (konfigurierbar, ohne operative Fake-Werte)
 *
 * Keine Plattformdimension im Preis-Key (FD-OA-PLAT offen).
 * Keine Position-/Allocation-Tabellen (Phase B).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oa_platforms', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_adserver_relevant')->default(false);
            $table->timestamps();

            $table->unique('code', 'oa_platforms_code_unique');
            $table->index(['is_active', 'sort'], 'oa_platforms_active_sort_idx');
        });

        Schema::create('oa_tkp_price_lists', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->string('name');
            $table->string('version', 32);
            $table->unsignedInteger('revision_number');
            $table->unsignedInteger('lock_version')->default(1);
            $table->string('status', 16)->default('draft');
            $table->date('valid_from')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['year', 'revision_number'], 'oa_tkp_price_lists_year_revision_unique');
            $table->index(['year', 'status'], 'oa_tkp_price_lists_year_status_idx');
        });

        Schema::create('oa_tkp_price_list_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('oa_tkp_price_list_id')
                ->constrained('oa_tkp_price_lists')
                ->cascadeOnDelete();
            $table->foreignId('advertising_medium_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_id')->constrained()->restrictOnDelete();
            $table->decimal('list_tkp', 16, 4);
            $table->decimal('minimum_tkp', 16, 4);
            $table->timestamps();

            $table->unique(
                ['oa_tkp_price_list_id', 'advertising_medium_id', 'inventory_id'],
                'oa_tkp_price_list_items_unique',
            );
            $table->index(
                ['advertising_medium_id', 'inventory_id'],
                'oa_tkp_price_list_items_lookup_idx',
            );
        });

        Schema::create('oa_targeting_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64);
            $table->string('name');
            $table->string('category', 32);
            $table->decimal('tkp_surcharge', 16, 4);
            $table->unsignedInteger('revision_number')->default(1);
            $table->boolean('is_active')->default(true);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->unique(['code', 'revision_number'], 'oa_targeting_definitions_code_revision_unique');
            $table->index(['code', 'is_active'], 'oa_targeting_definitions_code_active_idx');
            $table->index(['category', 'is_active'], 'oa_targeting_definitions_category_active_idx');
        });

        Schema::create('oa_adserver_surcharges', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64);
            $table->string('name');
            $table->decimal('tkp_amount', 16, 4);
            $table->unsignedInteger('revision_number')->default(1);
            $table->boolean('is_active')->default(true);
            $table->json('scope_json')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->unique(['code', 'revision_number'], 'oa_adserver_surcharges_code_revision_unique');
            $table->index(['code', 'is_active'], 'oa_adserver_surcharges_code_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oa_adserver_surcharges');
        Schema::dropIfExists('oa_targeting_definitions');
        Schema::dropIfExists('oa_tkp_price_list_items');
        Schema::dropIfExists('oa_tkp_price_lists');
        Schema::dropIfExists('oa_platforms');
    }
};
