<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PO-SWF-CONTEST-1: SWF-Medium Gewinnspiel/Pay-Off × Durchschnitt.
 *
 * Atomar:
 * 1. advertising_media.kind = swf_contest für contest_payoff
 *    (nur wenn kind bisher NULL und Kategorie special_advertising_formats;
 *    bereits swf_contest = idempotent).
 * 2. Medium-Override: Average mit Profil swf_contest (wie Promo-Muster).
 * 3. inventory_medium_rules: default_length_seconds = 25 und surcharge_percent = 50
 *    für alle bestehenden Regeln dieses Mediums (Import-30/0 und NULL werden ersetzt).
 *    Erzeugt keine neuen Regeln – nur die vier Matrix-Kombinationen bleiben.
 *
 * Kein Confirm-Feld. Calendar/Settlement/Produktion/Komponenten OUT (Registry/Gates).
 *
 * down: kind/Override zurück nur ohne Calc-/Dispo-Positionen mit kind=swf_contest;
 * Länge und Aufschlag bleiben (kein Blind-Reset auf 30/0).
 */
return new class extends Migration
{
    private const MEDIUM_CODE = 'contest_payoff';

    private const SWF_CATEGORY_KEY = 'special_advertising_formats';

    private const ENGINE_PROFILE = 'swf_contest';

    private const KIND = 'swf_contest';

    private const LENGTH = 25;

    private const SURCHARGE = '50';

    public function up(): void
    {
        $this->assertPrerequisites();

        DB::transaction(function (): void {
            $this->activateContestMedium();
            $this->ensureContestOverride();
            $this->setInitialRuleValues();
            $this->assertActiveRulesReady();
        });
    }

    public function down(): void
    {
        $this->assertDownSafe();
        $this->removeContestOverride();

        DB::table('advertising_media')
            ->where('code', self::MEDIUM_CODE)
            ->where('kind', self::KIND)
            ->update([
                'kind' => null,
                'calculation_method_mode' => 'inherit',
                'default_calculation_method_id' => null,
            ]);
    }

    private function assertPrerequisites(): void
    {
        foreach ([
            'inventory_medium_rules',
            'advertising_media',
            'advertising_categories',
            'calculation_methods',
            'advertising_medium_calculation_methods',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("PO-SWF-CONTEST-1: Voraussetzung fehlt ({$table}).");
            }
        }
    }

    private function swfCategoryId(): ?int
    {
        $id = DB::table('advertising_categories')->where('key', self::SWF_CATEGORY_KEY)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function activateContestMedium(): void
    {
        $categoryId = $this->swfCategoryId();
        if ($categoryId === null) {
            return;
        }

        $medium = DB::table('advertising_media')->where('code', self::MEDIUM_CODE)->first();
        if ($medium === null) {
            return;
        }

        $kind = $medium->kind === null ? null : (string) $medium->kind;
        if ($kind === self::KIND) {
            return;
        }

        if ($kind !== null || (int) $medium->category_id !== $categoryId) {
            throw new RuntimeException(
                'PO-SWF-CONTEST-1: Medium contest_payoff hat eine unerwartete Berechnungsart oder Kategorie. up() ändert es nicht.',
            );
        }

        DB::table('advertising_media')
            ->where('id', $medium->id)
            ->update(['kind' => self::KIND]);
    }

    private function ensureContestOverride(): void
    {
        $medium = DB::table('advertising_media')->where('code', self::MEDIUM_CODE)->first();
        if ($medium === null || (string) $medium->kind !== self::KIND) {
            return;
        }

        $averageId = DB::table('calculation_methods')->where('key', 'average')->value('id');
        if ($averageId === null) {
            throw new RuntimeException('PO-SWF-CONTEST-1: calculation_methods.average fehlt.');
        }
        $averageId = (int) $averageId;

        $assignments = DB::table('advertising_medium_calculation_methods')
            ->where('advertising_medium_id', $medium->id)
            ->get();

        $already = (string) $medium->calculation_method_mode === 'override'
            && (int) $medium->default_calculation_method_id === $averageId
            && $assignments->count() === 1
            && (int) $assignments[0]->calculation_method_id === $averageId
            && (string) $assignments[0]->engine_profile_key === self::ENGINE_PROFILE
            && (bool) $assignments[0]->is_active;

        if ($already) {
            return;
        }

        $canInstall = (string) $medium->calculation_method_mode === 'inherit'
            && $medium->default_calculation_method_id === null
            && $assignments->isEmpty();

        if (! $canInstall) {
            throw new RuntimeException(
                'PO-SWF-CONTEST-1: Contest-Methodenvertrag weicht ab. up() überschreibt ihn nicht.',
            );
        }

        DB::table('advertising_media')
            ->where('id', $medium->id)
            ->update([
                'calculation_method_mode' => 'override',
                'default_calculation_method_id' => $averageId,
            ]);

        $now = now();
        DB::table('advertising_medium_calculation_methods')->insert([
            'advertising_medium_id' => $medium->id,
            'calculation_method_id' => $averageId,
            'engine_profile_key' => self::ENGINE_PROFILE,
            'is_active' => true,
            'sort' => 10,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function setInitialRuleValues(): void
    {
        $mediumId = DB::table('advertising_media')->where('code', self::MEDIUM_CODE)->value('id');
        if ($mediumId === null) {
            return;
        }

        DB::table('inventory_medium_rules')
            ->where('advertising_medium_id', (int) $mediumId)
            ->update([
                'default_length_seconds' => self::LENGTH,
                'surcharge_percent' => self::SURCHARGE,
            ]);
    }

    private function assertActiveRulesReady(): void
    {
        $mediumId = DB::table('advertising_media')->where('code', self::MEDIUM_CODE)->value('id');
        if ($mediumId === null) {
            return;
        }

        $bad = DB::table('inventory_medium_rules')
            ->where('advertising_medium_id', (int) $mediumId)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('default_length_seconds')
                    ->orWhere('default_length_seconds', '<=', 0)
                    ->orWhereNull('surcharge_percent')
                    ->orWhere('surcharge_percent', '<=', 0);
            })
            ->count();

        if ($bad > 0) {
            throw new RuntimeException(
                'PO-SWF-CONTEST-1: Nach up() existieren noch aktive Contest-Regeln ohne gültige Länge/Aufschlag.',
            );
        }
    }

    private function assertDownSafe(): void
    {
        foreach (['calculation_positions', 'dispo_order_positions'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'kind')) {
                continue;
            }
            if (DB::table($table)->where('kind', self::KIND)->exists()) {
                throw new RuntimeException(
                    "PO-SWF-CONTEST-1 down(): Positionen mit kind=swf_contest in {$table} vorhanden – Rollback verweigert.",
                );
            }
        }
    }

    private function removeContestOverride(): void
    {
        $mediumId = DB::table('advertising_media')->where('code', self::MEDIUM_CODE)->value('id');
        if ($mediumId === null) {
            return;
        }

        DB::table('advertising_medium_calculation_methods')
            ->where('advertising_medium_id', $mediumId)
            ->where('engine_profile_key', self::ENGINE_PROFILE)
            ->delete();
    }
};
