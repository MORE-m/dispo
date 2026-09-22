<?php

use App\Enums\DerivedCampaignPeriodStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DSP-DCP-001: additiv eingefrorener abgeleiteter Kampagnenzeitraum am Dispoauftrag.
 * Bestehende Aufträge: Status legacy, keine fachliche Rückbefüllung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispo_orders', function (Blueprint $table): void {
            $table->date('derived_campaign_period_start')->nullable()->after('configuration_snapshot_id');
            $table->date('derived_campaign_period_end')->nullable()->after('derived_campaign_period_start');
            $table->string('derived_campaign_period_status', 32)
                ->default(DerivedCampaignPeriodStatus::Legacy->value)
                ->after('derived_campaign_period_end');
            $table->timestamp('derived_campaign_period_at')->nullable()->after('derived_campaign_period_status');
            $table->json('derived_campaign_period_snapshot')->nullable()->after('derived_campaign_period_at');
        });

        // Explizit: bestehende Zeilen bleiben legacy ohne Datumswerte.
        DB::table('dispo_orders')->update([
            'derived_campaign_period_status' => DerivedCampaignPeriodStatus::Legacy->value,
            'derived_campaign_period_start' => null,
            'derived_campaign_period_end' => null,
            'derived_campaign_period_at' => null,
            'derived_campaign_period_snapshot' => null,
        ]);
    }

    public function down(): void
    {
        Schema::table('dispo_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'derived_campaign_period_start',
                'derived_campaign_period_end',
                'derived_campaign_period_status',
                'derived_campaign_period_at',
                'derived_campaign_period_snapshot',
            ]);
        });
    }
};
