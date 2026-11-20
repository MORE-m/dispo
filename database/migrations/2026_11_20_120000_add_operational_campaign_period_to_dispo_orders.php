<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PO-CALC-DISPO-UX-1 / PR5: operativer Kampagnenzeitraum am Dispo-Kopf.
 * Additiv, nullable, kein Backfill (PO-PR5-03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispo_orders', function (Blueprint $table): void {
            $table->date('operational_campaign_starts_on')
                ->nullable()
                ->after('briefing');
            $table->date('operational_campaign_ends_on')
                ->nullable()
                ->after('operational_campaign_starts_on');
        });
    }

    public function down(): void
    {
        Schema::table('dispo_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'operational_campaign_starts_on',
                'operational_campaign_ends_on',
            ]);
        });
    }
};
