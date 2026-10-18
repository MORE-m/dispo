<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PO-SPOT-SURCHARGE-1: Erst-/Letztplatzierung auf dem Spot-Classic-Pfad.
 *
 * Atomar je Medium:
 * 1. advertising_media.kind = spot_classic für spot_first / spot_last
 *    (nur wenn kind bisher NULL und Kategorie spots; bereits spot_classic = idempotent).
 * 2. inventory_medium_rules.surcharge_percent = 30 für alle Regeln dieser Medien
 *    (Import-0 und NULL werden ersetzt; andere Medien unverändert).
 *
 * Kein neues Engine-Profil. Historische Entscheidung PO-MAT-CORE-CATALOG-1 bleibt;
 * dieser Slice ist der dokumentierte Katalog-Nachzug.
 *
 * down: kind zurück auf NULL nur ohne Calc-/Dispo-Positionen mit diesen Medium-IDs;
 * Aufschläge bleiben (kein Blind-Reset auf 0).
 */
return new class extends Migration
{
    private const KIND = 'spot_classic';

    private const SPOTS_CATEGORY_KEY = 'spots';

    /** @var list<string> */
    private const MEDIUM_CODES = ['spot_first', 'spot_last'];

    private const SURCHARGE = '30';

    public function up(): void
    {
        $this->assertPrerequisites();

        DB::transaction(function (): void {
            foreach (self::MEDIUM_CODES as $code) {
                $this->activateMedium($code);
                $this->setSurchargeForMedium($code);
            }

            $this->assertNoActiveZeroSurcharge();
        });
    }

    public function down(): void
    {
        $this->assertDownSafe();

        DB::table('advertising_media')
            ->whereIn('code', self::MEDIUM_CODES)
            ->where('kind', self::KIND)
            ->update(['kind' => null]);
    }

    private function assertPrerequisites(): void
    {
        foreach (['advertising_media', 'advertising_categories', 'inventory_medium_rules'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("PO-SPOT-SURCHARGE-1: Voraussetzung fehlt ({$table}).");
            }
        }
    }

    private function spotsCategoryId(): ?int
    {
        $id = DB::table('advertising_categories')->where('key', self::SPOTS_CATEGORY_KEY)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function activateMedium(string $code): void
    {
        $categoryId = $this->spotsCategoryId();
        if ($categoryId === null) {
            return;
        }

        $medium = DB::table('advertising_media')->where('code', $code)->first();
        if ($medium === null) {
            return;
        }

        $kind = $medium->kind === null ? null : (string) $medium->kind;
        if ($kind === self::KIND) {
            return;
        }

        if ($kind !== null || (int) $medium->category_id !== $categoryId) {
            throw new RuntimeException(
                "PO-SPOT-SURCHARGE-1: Medium {$code} hat eine unerwartete Berechnungsart oder Kategorie. up() ändert es nicht.",
            );
        }

        DB::table('advertising_media')
            ->where('id', $medium->id)
            ->update(['kind' => self::KIND]);
    }

    private function setSurchargeForMedium(string $code): void
    {
        $mediumId = DB::table('advertising_media')->where('code', $code)->value('id');
        if ($mediumId === null) {
            return;
        }

        DB::table('inventory_medium_rules')
            ->where('advertising_medium_id', (int) $mediumId)
            ->update(['surcharge_percent' => self::SURCHARGE]);
    }

    private function assertNoActiveZeroSurcharge(): void
    {
        $mediumIds = DB::table('advertising_media')
            ->whereIn('code', self::MEDIUM_CODES)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($mediumIds === []) {
            return;
        }

        $bad = DB::table('inventory_medium_rules')
            ->whereIn('advertising_medium_id', $mediumIds)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('surcharge_percent')
                    ->orWhere('surcharge_percent', '<=', 0);
            })
            ->count();

        if ($bad > 0) {
            throw new RuntimeException(
                'PO-SPOT-SURCHARGE-1: Nach up() existieren noch aktive Erst-/Letzt-Regeln ohne positiven Aufschlag.',
            );
        }
    }

    private function assertDownSafe(): void
    {
        $mediumIds = DB::table('advertising_media')
            ->whereIn('code', self::MEDIUM_CODES)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($mediumIds === []) {
            return;
        }

        if (Schema::hasTable('calculation_positions')) {
            $calc = DB::table('calculation_positions')
                ->whereIn('advertising_medium_id', $mediumIds)
                ->count();
            if ($calc > 0) {
                throw new RuntimeException(
                    'PO-SPOT-SURCHARGE-1: down() abgebrochen – es existieren Kalkulationspositionen mit Erst-/Letzt-Medien.',
                );
            }
        }

        if (Schema::hasTable('dispo_order_positions')) {
            $dispo = DB::table('dispo_order_positions')
                ->whereIn('advertising_medium_id', $mediumIds)
                ->count();
            if ($dispo > 0) {
                throw new RuntimeException(
                    'PO-SPOT-SURCHARGE-1: down() abgebrochen – es existieren Dispo-Positionen mit Erst-/Letzt-Medien.',
                );
            }
        }
    }
};
