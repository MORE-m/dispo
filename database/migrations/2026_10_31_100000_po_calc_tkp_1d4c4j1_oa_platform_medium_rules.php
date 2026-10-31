<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PO-CALC-TKP-1D4c-4j-1: Platform × OA-Werbeform-Zuordnungen (additiv).
 *
 * Noch kein operatives Enforcement im OA-Writer/Wizard (Folgeslices).
 * Soft-Deactivate über is_active; FKs restrictOnDelete (kein unkontrolliertes Cascade).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('oa_platform_medium_rules')) {
            return;
        }

        Schema::create('oa_platform_medium_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('oa_platform_id')
                ->constrained('oa_platforms')
                ->restrictOnDelete();
            $table->foreignId('advertising_medium_id')
                ->constrained('advertising_media')
                ->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['oa_platform_id', 'advertising_medium_id'],
                'oa_platform_medium_rules_unique',
            );
            $table->index(
                ['advertising_medium_id', 'is_active'],
                'oa_platform_medium_rules_medium_active_idx',
            );
            $table->index(
                ['oa_platform_id', 'is_active'],
                'oa_platform_medium_rules_platform_active_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oa_platform_medium_rules');
    }
};
