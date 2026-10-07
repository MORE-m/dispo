<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BL-P5-01a / SWF-001–SWF-005 / PO-BLP501A-1 (A1 + B1): SWF Trailer × Durchschnitt.
 *
 * 1. inventory_medium_rules.surcharge_percent wird NULLABLE ohne Default:
 *    NULL = nicht konfiguriert (fail-closed für swf_trailer), 0 = ausdrücklich 0 %.
 * 2. Medium trailer_station_voice (nur dieses) erhält kind=swf_trailer, sofern bisher kind=NULL
 *    und Oberkategorie special_advertising_formats. Andere SWF-Medien bleiben kind=NULL.
 * 3. Trailer-Regeln: nur unbestätigte Altdefaults (Länge = Medium-Default oder NULL, Aufschlag 0)
 *    werden auf NULL/NULL gesetzt. Individuelle Werte werden nicht still gelöscht und nicht
 *    automatisch als fachlich bestätigt freigeschaltet: bei kind=NULL und Nicht-Pristine
 *    bricht up() vor Datenänderungen an Regeln/kind ab. Ist kind bereits swf_trailer
 *    (erneutes up nach Admin-Pflege), bleiben Länge/Aufschlag unberührt.
 * 4. Kategorie special_advertising_formats: Zuordnung average → Engine-Profil swf_trailer,
 *    Standardmethode average (nur falls noch keine Standardmethode gesetzt ist) –
 *    ausschließlich in Datenbanken, in denen das Trailer-Medium bereits existiert (Initialkatalog
 *    geseedet). Auf leerem Katalog bleibt der ADV-001c2-Spot-Katalogzustand unverändert; frische
 *    Umgebungen erhalten die Zuordnung idempotent über den InitialCatalogBootstrapper.
 *
 * Rücksetzplan (down): nur wenn keine Positionen mit kind=swf_trailer existieren.
 * Setzt Trailer-Medium-kind zurück auf NULL, entfernt nur die migrationseigene Kategorie-
 * Zuordnung (engine_profile_key=swf_trailer). default_calculation_method_id bleibt unberührt
 * (kann vor der Migration bereits average gewesen sein). surcharge_percent wieder NOT NULL
 * (NULL → 0). Gelöschte Längen/Aufschläge der Trailer-Regeln werden NICHT rekonstruiert.
 */
return new class extends Migration
{
    private const TRAILER_MEDIUM_CODE = 'trailer_station_voice';

    private const SWF_CATEGORY_KEY = 'special_advertising_formats';

    private const ENGINE_PROFILE = 'swf_trailer';

    private const KIND = 'swf_trailer';

    public function up(): void
    {
        $this->assertPrerequisites();

        $this->makeSurchargeNullable();

        $kindWasAlreadyActive = $this->trailerKindAlreadyActive();
        $this->assertTrailerRuleCleanupSafe($kindWasAlreadyActive);
        $this->activateTrailerMedium();
        // Nur bei Erstaktivierung: Altdefaults nullen. Erneutes up() nach kind=swf_trailer
        // (inkl. ausdrücklicher 0 %-Aufschläge) lässt Regeln unverändert.
        $this->nullTrailerRuleConfiguration($kindWasAlreadyActive);
        $this->assignCategoryAverage();
    }

    public function down(): void
    {
        $this->assertDownSafe();

        $this->removeCategoryAverage();
        DB::table('advertising_media')
            ->where('code', self::TRAILER_MEDIUM_CODE)
            ->where('kind', self::KIND)
            ->update(['kind' => null]);

        DB::table('inventory_medium_rules')->whereNull('surcharge_percent')->update(['surcharge_percent' => 0]);

        Schema::table('inventory_medium_rules', function (Blueprint $table): void {
            $table->decimal('surcharge_percent', 7, 4)->default(0)->nullable(false)->change();
        });
    }

    private function assertPrerequisites(): void
    {
        foreach ([
            'inventory_medium_rules',
            'advertising_media',
            'advertising_categories',
            'calculation_methods',
            'advertising_category_calculation_methods',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("BL-P5-01a: Voraussetzung fehlt ({$table}).");
            }
        }

        if (! DB::table('calculation_methods')->where('key', 'average')->exists()) {
            throw new RuntimeException('BL-P5-01a: calculation_methods.average fehlt.');
        }
    }

    private function makeSurchargeNullable(): void
    {
        $column = collect(Schema::getColumns('inventory_medium_rules'))->firstWhere('name', 'surcharge_percent');
        if ($column !== null && ($column['nullable'] ?? false) === true && ($column['default'] ?? null) === null) {
            return;
        }

        Schema::table('inventory_medium_rules', function (Blueprint $table): void {
            $table->decimal('surcharge_percent', 7, 4)->nullable()->default(null)->change();
        });
    }

    private function swfCategoryId(): ?int
    {
        $id = DB::table('advertising_categories')->where('key', self::SWF_CATEGORY_KEY)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @return list<object{id: int|string, kind: mixed, default_length_seconds: mixed}>
     */
    private function trailerMediaRows(): array
    {
        return DB::table('advertising_media')
            ->where('code', self::TRAILER_MEDIUM_CODE)
            ->get(['id', 'kind', 'default_length_seconds'])
            ->all();
    }

    private function trailerKindAlreadyActive(): bool
    {
        return collect($this->trailerMediaRows())->contains(
            fn (object $row): bool => (string) ($row->kind ?? '') === self::KIND,
        );
    }

    /**
     * Unbestätigter Altdefault: Aufschlag ausdrücklich 0 und Länge NULL oder Medium-Default.
     * Bereits NULL/NULL zählt hier nicht als „zu nullen“, sondern als unkonfiguriert.
     */
    private function isPristineAltDefault(object $rule, ?int $mediumDefaultLength): bool
    {
        $surcharge = $rule->surcharge_percent;
        if ($surcharge === null || (float) $surcharge != 0.0) {
            return false;
        }

        $length = $rule->default_length_seconds;

        return $length === null
            || ($mediumDefaultLength !== null && (int) $length === $mediumDefaultLength);
    }

    private function isFullyUnconfigured(object $rule): bool
    {
        return $rule->surcharge_percent === null && $rule->default_length_seconds === null;
    }

    /**
     * Verhindert stilles Löschen individueller Trailer-Werte und automatische Freischaltung
     * unbestätigter Altwerte: bei kind=NULL und Nicht-Pristine → Abbruch vor kind/Regel-Mutation.
     */
    private function assertTrailerRuleCleanupSafe(bool $kindWasAlreadyActive): void
    {
        if ($kindWasAlreadyActive) {
            return;
        }

        $media = $this->trailerMediaRows();
        if ($media === []) {
            return;
        }

        $blocking = [];
        foreach ($media as $medium) {
            $mediumId = (int) $medium->id;
            $mediumDefault = $medium->default_length_seconds === null
                ? null
                : (int) $medium->default_length_seconds;

            $rules = DB::table('inventory_medium_rules')
                ->where('advertising_medium_id', $mediumId)
                ->get(['id', 'inventory_id', 'default_length_seconds', 'surcharge_percent']);

            foreach ($rules as $rule) {
                if ($this->isFullyUnconfigured($rule) || $this->isPristineAltDefault($rule, $mediumDefault)) {
                    continue;
                }
                $blocking[] = sprintf(
                    'rule#%s inventar#%s length=%s surcharge=%s',
                    $rule->id,
                    $rule->inventory_id,
                    $rule->default_length_seconds === null ? 'NULL' : (string) $rule->default_length_seconds,
                    $rule->surcharge_percent === null ? 'NULL' : (string) $rule->surcharge_percent,
                );
            }
        }

        if ($blocking === []) {
            return;
        }

        throw new RuntimeException(
            'BL-P5-01a: Trailer-Regeln mit individuellen Werten bei kind=NULL – '
            .'Herkunft/Bestätigung nicht unterscheidbar. Keine automatische Löschung und '
            .'keine automatische Freischaltung. Entweder Altdefaults auf Medium-Default/0 '
            .'zurücksetzen (dann bereinigt up() auf NULL/NULL), oder nach fachlicher Prüfung '
            .'kind=swf_trailer am Medium setzen und up() erneut ausführen (Werte bleiben). '
            .'Betroffen: '.implode('; ', $blocking),
        );
    }

    private function activateTrailerMedium(): void
    {
        $categoryId = $this->swfCategoryId();
        if ($categoryId === null) {
            return;
        }

        DB::table('advertising_media')
            ->where('code', self::TRAILER_MEDIUM_CODE)
            ->where('category_id', $categoryId)
            ->whereNull('kind')
            ->update(['kind' => self::KIND]);
    }

    private function nullTrailerRuleConfiguration(bool $kindWasAlreadyActive): void
    {
        if ($kindWasAlreadyActive) {
            return;
        }

        $media = $this->trailerMediaRows();
        if ($media === []) {
            return;
        }

        foreach ($media as $medium) {
            $mediumId = (int) $medium->id;
            $mediumDefault = $medium->default_length_seconds === null
                ? null
                : (int) $medium->default_length_seconds;

            $rules = DB::table('inventory_medium_rules')
                ->where('advertising_medium_id', $mediumId)
                ->get(['id', 'default_length_seconds', 'surcharge_percent']);

            foreach ($rules as $rule) {
                if (! $this->isPristineAltDefault($rule, $mediumDefault)) {
                    continue;
                }

                DB::table('inventory_medium_rules')
                    ->where('id', $rule->id)
                    ->update([
                        'surcharge_percent' => null,
                        'default_length_seconds' => null,
                    ]);
            }
        }
    }

    private function assignCategoryAverage(): void
    {
        $categoryId = $this->swfCategoryId();
        if ($categoryId === null) {
            return;
        }

        $trailerExists = DB::table('advertising_media')
            ->where('code', self::TRAILER_MEDIUM_CODE)
            ->where('category_id', $categoryId)
            ->exists();
        if (! $trailerExists) {
            return;
        }

        $averageId = (int) DB::table('calculation_methods')->where('key', 'average')->value('id');
        $now = now();

        $existing = DB::table('advertising_category_calculation_methods')
            ->where('advertising_category_id', $categoryId)
            ->where('calculation_method_id', $averageId)
            ->first();

        if ($existing === null) {
            DB::table('advertising_category_calculation_methods')->insert([
                'advertising_category_id' => $categoryId,
                'calculation_method_id' => $averageId,
                'engine_profile_key' => self::ENGINE_PROFILE,
                'is_active' => true,
                'sort' => 10,
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('advertising_categories')
            ->where('id', $categoryId)
            ->whereNull('default_calculation_method_id')
            ->update(['default_calculation_method_id' => $averageId]);
    }

    private function assertDownSafe(): void
    {
        foreach (['calculation_positions', 'dispo_order_positions'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'kind')) {
                continue;
            }
            if (DB::table($table)->where('kind', self::KIND)->exists()) {
                throw new RuntimeException(
                    "BL-P5-01a down(): Positionen mit kind=swf_trailer in {$table} vorhanden – Rollback verweigert.",
                );
            }
        }
    }

    private function removeCategoryAverage(): void
    {
        $categoryId = $this->swfCategoryId();
        if ($categoryId === null) {
            return;
        }

        // Nur migrationseigene Zuordnung (engine_profile_key=swf_trailer) entfernen.
        // Andere average-Zuordnungen und default_calculation_method_id bleiben erhalten
        // (Default kann vor der Migration bereits gesetzt gewesen sein).
        $averageId = (int) DB::table('calculation_methods')->where('key', 'average')->value('id');

        DB::table('advertising_category_calculation_methods')
            ->where('advertising_category_id', $categoryId)
            ->where('calculation_method_id', $averageId)
            ->where('engine_profile_key', self::ENGINE_PROFILE)
            ->delete();
    }
};
