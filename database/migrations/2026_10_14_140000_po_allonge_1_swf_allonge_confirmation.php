<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PO-ALLONGE-1: SWF-Medium Allonge × Durchschnitt.
 *
 * 1. inventory_medium_rules.swf_allonge_confirmed_at nullable, ohne Backfill.
 *    Bestehende Länge und bestehender Aufschlag bleiben, auch 30 s / 0 %.
 *    NULL = unbestätigt und nicht kalkulierbar.
 * 2. Medium allonge (nur dieses), sofern kind NULL und Oberkategorie
 *    special_advertising_formats: kind=swf_allonge, Methodenmodus override,
 *    genau eine Average-Zuordnung mit Profil swf_allonge.
 *    Kategorie special_advertising_formats und Trailer-Profil swf_trailer bleiben.
 * 3. Import, Bootstrap-Altwerte und kind allein setzen den Zeitpunkt nicht.
 *
 * Rücksetzplan (down): nur wenn keine Positionen mit kind=swf_allonge existieren.
 * Entfernt die Medium-Zuordnung swf_allonge, setzt kind/Modus des Allonge-Mediums
 * zurück und entfernt die Spalte. Länge und Aufschlag der Regeln bleiben.
 * Ein erneutes up() legt die Spalte wieder leer an und schaltet damit keine
 * zuvor bestätigte Zeile frei.
 */
return new class extends Migration
{
    private const MEDIUM_CODE = 'allonge';

    private const SWF_CATEGORY_KEY = 'special_advertising_formats';

    private const ENGINE_PROFILE = 'swf_allonge';

    private const KIND = 'swf_allonge';

    public function up(): void
    {
        $this->assertPrerequisites();
        $this->addConfirmationColumn();
        $this->activateAllongeMedium();
        $this->ensureAllongeOverride();
    }

    public function down(): void
    {
        $this->assertDownSafe();
        $this->removeAllongeOverride();

        DB::table('advertising_media')
            ->where('code', self::MEDIUM_CODE)
            ->where('kind', self::KIND)
            ->update([
                'kind' => null,
                'calculation_method_mode' => 'inherit',
                'default_calculation_method_id' => null,
            ]);

        if (Schema::hasColumn('inventory_medium_rules', 'swf_allonge_confirmed_at')) {
            Schema::table('inventory_medium_rules', function (Blueprint $table): void {
                $table->dropColumn('swf_allonge_confirmed_at');
            });
        }
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
                throw new RuntimeException("PO-ALLONGE-1: Voraussetzung fehlt ({$table}).");
            }
        }
    }

    private function addConfirmationColumn(): void
    {
        if (Schema::hasColumn('inventory_medium_rules', 'swf_allonge_confirmed_at')) {
            return;
        }

        Schema::table('inventory_medium_rules', function (Blueprint $table): void {
            $table->timestamp('swf_allonge_confirmed_at')->nullable();
        });
    }

    private function swfCategoryId(): ?int
    {
        $id = DB::table('advertising_categories')->where('key', self::SWF_CATEGORY_KEY)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function activateAllongeMedium(): void
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
                'PO-ALLONGE-1: Medium allonge hat eine unerwartete Berechnungsart oder Kategorie. up() ändert es nicht.',
            );
        }

        DB::table('advertising_media')
            ->where('id', $medium->id)
            ->update(['kind' => self::KIND]);
    }

    private function ensureAllongeOverride(): void
    {
        $medium = DB::table('advertising_media')->where('code', self::MEDIUM_CODE)->first();
        if ($medium === null || (string) $medium->kind !== self::KIND) {
            return;
        }

        $averageId = DB::table('calculation_methods')->where('key', 'average')->value('id');
        if ($averageId === null) {
            throw new RuntimeException('PO-ALLONGE-1: calculation_methods.average fehlt.');
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
                'PO-ALLONGE-1: Allonge-Methodenvertrag weicht ab. up() überschreibt ihn nicht.',
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

    private function assertDownSafe(): void
    {
        foreach (['calculation_positions', 'dispo_order_positions'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'kind')) {
                continue;
            }
            if (DB::table($table)->where('kind', self::KIND)->exists()) {
                throw new RuntimeException(
                    "PO-ALLONGE-1 down(): Positionen mit kind=swf_allonge in {$table} vorhanden – Rollback verweigert.",
                );
            }
        }
    }

    private function removeAllongeOverride(): void
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
