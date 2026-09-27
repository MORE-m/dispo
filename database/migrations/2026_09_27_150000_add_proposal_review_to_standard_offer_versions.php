<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-P4-03b / PO-BLP403B-1: Freitext-Prüfstufe und Quellkalkulations-Referenz
 * für Vorschlags-Drafts (keine Sync; nur Nachvollziehbarkeit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standard_offer_versions', function (Blueprint $table) {
            $table->json('proposal_review')->nullable()->after('draft_payload');
            $table->foreignId('source_calculation_id')
                ->nullable()
                ->after('proposal_review')
                ->constrained('calculations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('standard_offer_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_calculation_id');
            $table->dropColumn('proposal_review');
        });
    }
};
