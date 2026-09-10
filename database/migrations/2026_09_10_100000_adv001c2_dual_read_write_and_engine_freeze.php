<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADV-001c2: Positions-Freeze, Spot-Katalog-Backfill, Dual-Read/Write-Vorbereitung,
 * advertising_media.kind nullable.
 *
 * Preflight vor irreversiblen/nicht-atomaren DDL-Schritten.
 * Keine Hard Deletes, keine stillen Kaskaden, keine Gen-3-Snapshot-Mutation.
 * Literale Backfill-Werte bewusst eingefroren (kein Runtime-Import).
 *
 * Ab ADV-001c3 (Katalog-Methoden-Admin) bzw. spätestens mit Entfernung der
 * Legacy-Felder (nach Dual-Write-Phase) ist ein vollständiger Schema-Rollback
 * dieser Migration erwartbar nicht mehr möglich / fachlich unsicher.
 *
 * Eigentumsnachweis: Auf dem Pre-c2-Schema (keine Freeze-Spalten) führt jede
 * vorbestehende Kategorie-/Medium-Zuordnung oder jeder Default zum Abbruch.
 * Partielle Wiederaufnahme ohne Schema-Metadaten ist ausdrücklich verweigert;
 * nur der vollständige Endzustand ist idempotent erneut ausführbar.
 */
return new class extends Migration
{
    private const SPOTS_CATEGORY_KEY = 'spots';

    /**
     * @var array{
     *     engine_profile_key: string,
     *     calculation_method_key: string,
     *     calculation_method_name: string,
     *     algorithm_version: string
     * }
     */
    private const LEGACY_SPOT_CLASSIC_AVERAGE = [
        'engine_profile_key' => 'spot_classic',
        'calculation_method_key' => 'average',
        'calculation_method_name' => 'Durchschnitt',
        'algorithm_version' => 'v1',
    ];

    /**
     * @var list<array{key: string, engine_profile_key: string, sort: int}>
     */
    private const SPOT_CATEGORY_METHODS = [
        ['key' => 'average', 'engine_profile_key' => 'spot_classic', 'sort' => 10],
        ['key' => 'calendar', 'engine_profile_key' => 'spot_classic', 'sort' => 20],
        ['key' => 'fixed_price', 'engine_profile_key' => 'spot_classic', 'sort' => 30],
    ];

    private const FREEZE_COLUMNS = [
        'engine_profile_key',
        'calculation_method_key',
        'calculation_method_name',
        'algorithm_version',
    ];

    public function up(): void
    {
        $this->assertPrerequisites();

        if ($this->isFullyAppliedComplete()) {
            // Idempotent: Endzustand bereits erreicht – Datenintegrität und Guards prüfen.
            $this->assertExistingFreezeRowsSafe('calculation_positions');
            $this->assertExistingFreezeRowsSafe('dispo_order_positions');
            $this->installFreezeGuards('calculation_positions');
            $this->installFreezeGuards('dispo_order_positions');
            $this->assertUpComplete();

            return;
        }

        if ($this->hasPartialOrInconsistentSchema()) {
            throw new RuntimeException(
                'ADV-001c2: partieller oder inkonsistenter Schema-Zustand erkannt. '
                .'Wiederaufnahme ohne eindeutigen Eigentumsnachweis ist verweigert – '
                .'bitte manuell bereinigen und Migration erneut ausführen.',
            );
        }

        // Erwartetes Pre-c2-Schema: keine Freeze-Spalten, leerer Katalog-Methodenbestand.
        $this->assertPreC2CatalogClean();
        $this->assertPositionBackfillSafe();

        $this->addFreezeColumns('calculation_positions');
        $this->addFreezeColumns('dispo_order_positions');
        $this->installFreezeGuards('calculation_positions');
        $this->installFreezeGuards('dispo_order_positions');

        $this->backfillPositions('calculation_positions');
        $this->backfillPositions('dispo_order_positions');

        $this->backfillSpotCategoryAssignments();
        $this->makeAdvertisingMediaKindNullable();
        // SQLite baut bei column change die Tabelle neu und verwirft Mode-Trigger.
        $this->reinstallCalculationMethodModeGuards();
        $this->assertUpComplete();
    }

    public function down(): void
    {
        $this->assertDownSafe();

        $this->rollbackSpotCategoryAssignments();
        $this->dropFreezeGuards('calculation_positions');
        $this->dropFreezeGuards('dispo_order_positions');
        $this->dropFreezeColumns('calculation_positions');
        $this->dropFreezeColumns('dispo_order_positions');
        $this->restoreAdvertisingMediaKindNotNull();
    }

    private function assertPrerequisites(): void
    {
        foreach ([
            'calculation_methods',
            'advertising_category_calculation_methods',
            'advertising_medium_calculation_methods',
            'advertising_categories',
            'advertising_media',
            'calculation_positions',
            'dispo_order_positions',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("ADV-001c2: Voraussetzung fehlt ({$table}).");
            }
        }

        if (! Schema::hasColumn('advertising_media', 'kind')) {
            throw new RuntimeException('ADV-001c2: advertising_media.kind fehlt.');
        }

        $spots = DB::table('advertising_categories')
            ->where('key', self::SPOTS_CATEGORY_KEY)
            ->first();
        if ($spots === null) {
            throw new RuntimeException('ADV-001c2: Oberkategorie „spots“ fehlt.');
        }

        foreach (['average', 'calendar', 'fixed_price'] as $key) {
            if (! DB::table('calculation_methods')->where('key', $key)->exists()) {
                throw new RuntimeException("ADV-001c2: calculation_methods.{$key} fehlt.");
            }
        }
    }

    private function assertPositionBackfillSafe(): void
    {
        foreach (['calculation_positions', 'dispo_order_positions'] as $table) {
            if ($this->hasAllFreezeColumns($table)) {
                $this->assertExistingFreezeRowsSafe($table);

                continue;
            }

            $rows = DB::table($table)->select(['id', 'kind', 'spot_method'])->orderBy('id')->get();
            foreach ($rows as $row) {
                $kind = $this->nullableString($row->kind ?? null);
                $method = $this->nullableString($row->spot_method ?? null);

                if ($kind === 'spot_classic' && $method === 'average') {
                    continue;
                }

                throw new RuntimeException(
                    "ADV-001c2: unbekannte Legacy-Kombination in {$table}#{$row->id} "
                    .'(kind='.($kind ?? 'NULL').', spot_method='.($method ?? 'NULL').'). '
                    .'Keine stille Abbildung – Abbruch ohne Datenmutation.',
                );
            }
        }
    }

    private function assertExistingFreezeRowsSafe(string $table): void
    {
        $rows = DB::table($table)->select(array_merge(['id', 'kind', 'spot_method'], self::FREEZE_COLUMNS))->orderBy('id')->get();
        $expected = self::LEGACY_SPOT_CLASSIC_AVERAGE;

        foreach ($rows as $row) {
            $freeze = $this->inspectFreezeFieldsStrict($table, (int) $row->id, $row);
            $nullCount = count(array_filter($freeze, fn ($v) => $v === null));
            if ($nullCount > 0 && $nullCount < 4) {
                throw new RuntimeException(
                    "ADV-001c2: partielle Freeze-Felder in {$table}#{$row->id} – Abbruch.",
                );
            }

            if ($nullCount === 4) {
                $kind = $this->nullableString($row->kind ?? null);
                $method = $this->nullableString($row->spot_method ?? null);
                if ($kind === 'spot_classic' && $method === 'average') {
                    continue;
                }
                throw new RuntimeException(
                    "ADV-001c2: unbekannte Legacy-Kombination in {$table}#{$row->id} – Abbruch.",
                );
            }

            foreach ($expected as $key => $value) {
                if ($freeze[$key] !== $value) {
                    throw new RuntimeException(
                        "ADV-001c2: widersprüchlicher Freeze in {$table}#{$row->id} "
                        ."({$key}={$freeze[$key]}, erwartet {$value}) – Abbruch.",
                    );
                }
            }

            if ($this->nullableString($row->kind ?? null) !== 'spot_classic'
                || $this->nullableString($row->spot_method ?? null) !== 'average') {
                throw new RuntimeException(
                    "ADV-001c2: Freeze/Legacy-Widerspruch in {$table}#{$row->id} – Abbruch.",
                );
            }
        }
    }

    /**
     * Liest Freeze-Rohwerte ohne Leerstring→NULL-Normalisierung.
     * Blank/Whitespace ist fail-closed mit Tabellenname und Datensatz-ID.
     *
     * @return array{
     *     engine_profile_key: string|null,
     *     calculation_method_key: string|null,
     *     calculation_method_name: string|null,
     *     algorithm_version: string|null
     * }
     */
    private function inspectFreezeFieldsStrict(string $table, int $id, object $row): array
    {
        $result = [];
        foreach (self::FREEZE_COLUMNS as $column) {
            $raw = $row->{$column} ?? null;
            if ($raw === null) {
                $result[$column] = null;

                continue;
            }

            $asString = (string) $raw;
            if (trim($asString) === '') {
                throw new RuntimeException(
                    "ADV-001c2: leerer oder Whitespace-Freeze-Wert in {$table}#{$id}.{$column} "
                    .'– Abbruch ohne Normalisierung auf NULL.',
                );
            }

            $result[$column] = trim($asString);
        }

        return $result;
    }

    /**
     * Pre-c2: keine Zuordnungen, keine Defaults – sonst kein Eigentumsnachweis möglich.
     */
    private function assertPreC2CatalogClean(): void
    {
        $categoryAssignments = (int) DB::table('advertising_category_calculation_methods')->count();
        if ($categoryAssignments > 0) {
            throw new RuntimeException(
                "ADV-001c2: {$categoryAssignments} vorbestehende Kategorie-Methodenzuordnung(en) – "
                .'Abbruch (kein Eigentumsnachweis, keine stille Übernahme).',
            );
        }

        $mediumAssignments = (int) DB::table('advertising_medium_calculation_methods')->count();
        if ($mediumAssignments > 0) {
            throw new RuntimeException(
                "ADV-001c2: {$mediumAssignments} vorbestehende Medium-Methodenzuordnung(en) – "
                .'Abbruch (kein Eigentumsnachweis, keine stille Übernahme).',
            );
        }

        $categoryDefaults = (int) DB::table('advertising_categories')
            ->whereNotNull('default_calculation_method_id')
            ->count();
        if ($categoryDefaults > 0) {
            throw new RuntimeException(
                "ADV-001c2: {$categoryDefaults} vorbestehende Kategorie-Defaultmethode(n) – "
                .'Abbruch (auch bei average kein Eigentumsnachweis).',
            );
        }

        $mediumDefaults = (int) DB::table('advertising_media')
            ->whereNotNull('default_calculation_method_id')
            ->count();
        if ($mediumDefaults > 0) {
            throw new RuntimeException(
                "ADV-001c2: {$mediumDefaults} vorbestehende Medium-Defaultmethode(n) – "
                .'Abbruch (kein Eigentumsnachweis).',
            );
        }
    }

    private function isFullyAppliedComplete(): bool
    {
        if (! $this->hasAllFreezeColumns('calculation_positions')
            || ! $this->hasAllFreezeColumns('dispo_order_positions')) {
            return false;
        }

        if ($this->hasPartialFreezeColumns('calculation_positions')
            || $this->hasPartialFreezeColumns('dispo_order_positions')) {
            return false;
        }

        if (! $this->isKindNullable()) {
            return false;
        }

        $spotsId = (int) DB::table('advertising_categories')
            ->where('key', self::SPOTS_CATEGORY_KEY)
            ->value('id');
        $methodIds = DB::table('calculation_methods')
            ->whereIn('key', ['average', 'calendar', 'fixed_price'])
            ->pluck('id', 'key');

        if ($methodIds->count() !== 3) {
            return false;
        }

        if (! $this->spotCatalogExactlyMatchesC2($spotsId, $methodIds)) {
            return false;
        }

        // Global exakt der von c2 erzeugte Katalogzustand – keine Extra-Zuordnungen/Defaults.
        if ((int) DB::table('advertising_category_calculation_methods')->count()
            !== count(self::SPOT_CATEGORY_METHODS)) {
            return false;
        }

        $categoryDefaults = DB::table('advertising_categories')
            ->whereNotNull('default_calculation_method_id')
            ->get(['id', 'default_calculation_method_id']);
        if ($categoryDefaults->count() !== 1) {
            return false;
        }
        $onlyDefault = $categoryDefaults->first();
        if ((int) $onlyDefault->id !== $spotsId
            || (int) $onlyDefault->default_calculation_method_id !== (int) $methodIds['average']) {
            return false;
        }

        if ((int) DB::table('advertising_medium_calculation_methods')->count() !== 0) {
            return false;
        }

        if ((int) DB::table('advertising_media')->whereNotNull('default_calculation_method_id')->count() !== 0) {
            return false;
        }

        return true;
    }

    private function hasPartialOrInconsistentSchema(): bool
    {
        if ($this->hasPartialFreezeColumns('calculation_positions')
            || $this->hasPartialFreezeColumns('dispo_order_positions')) {
            return true;
        }

        $calcHas = $this->hasAllFreezeColumns('calculation_positions');
        $dispoHas = $this->hasAllFreezeColumns('dispo_order_positions');
        if ($calcHas !== $dispoHas) {
            return true;
        }

        // Freeze-Spalten vorhanden, aber Endzustand unvollständig → partielle Wiederaufnahme.
        if ($calcHas && $dispoHas && ! $this->isFullyAppliedComplete()) {
            return true;
        }

        return false;
    }

    private function isKindNullable(): bool
    {
        $kindColumn = collect(Schema::getColumns('advertising_media'))->firstWhere('name', 'kind');

        return $kindColumn !== null && (bool) ($kindColumn['nullable'] ?? false);
    }

    /**
     * @param  Collection<string, mixed>  $methodIds
     */
    private function spotCatalogExactlyMatchesC2(int $spotsId, $methodIds): bool
    {
        $averageId = (int) $methodIds['average'];
        $defaultId = DB::table('advertising_categories')->where('id', $spotsId)->value('default_calculation_method_id');
        if ($defaultId === null || (int) $defaultId !== $averageId) {
            return false;
        }

        foreach (self::SPOT_CATEGORY_METHODS as $definition) {
            $methodId = (int) $methodIds[$definition['key']];
            $row = DB::table('advertising_category_calculation_methods')
                ->where('advertising_category_id', $spotsId)
                ->where('calculation_method_id', $methodId)
                ->first();
            if ($row === null) {
                return false;
            }
            if ($this->nullableString($row->engine_profile_key) !== $definition['engine_profile_key']
                || ! (bool) $row->is_active
                || (int) $row->sort !== $definition['sort']) {
                return false;
            }
        }

        $spotsCount = DB::table('advertising_category_calculation_methods')
            ->where('advertising_category_id', $spotsId)
            ->count();

        return $spotsCount === count(self::SPOT_CATEGORY_METHODS);
    }

    private function addFreezeColumns(string $table): void
    {
        if ($this->hasAllFreezeColumns($table)) {
            if ($this->hasPartialFreezeColumns($table)) {
                throw new RuntimeException("ADV-001c2: partielle Freeze-Spalten an {$table}.");
            }

            return;
        }

        if ($this->hasPartialFreezeColumns($table)) {
            throw new RuntimeException(
                "ADV-001c2: unvollständige Freeze-Spalten an {$table} – keine stille Teilmigration.",
            );
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->string('engine_profile_key', 64)->nullable()->after('spot_method');
            $blueprint->string('calculation_method_key', 64)->nullable()->after('engine_profile_key');
            $blueprint->string('calculation_method_name', 255)->nullable()->after('calculation_method_key');
            $blueprint->string('algorithm_version', 32)->nullable()->after('calculation_method_name');
        });
    }

    private function backfillPositions(string $table): void
    {
        $expected = self::LEGACY_SPOT_CLASSIC_AVERAGE;

        DB::table($table)
            ->where('kind', 'spot_classic')
            ->where('spot_method', 'average')
            ->whereNull('engine_profile_key')
            ->whereNull('calculation_method_key')
            ->whereNull('calculation_method_name')
            ->whereNull('algorithm_version')
            ->update([
                'engine_profile_key' => $expected['engine_profile_key'],
                'calculation_method_key' => $expected['calculation_method_key'],
                'calculation_method_name' => $expected['calculation_method_name'],
                'algorithm_version' => $expected['algorithm_version'],
            ]);

        $remainingEmpty = DB::table($table)
            ->whereNull('engine_profile_key')
            ->whereNull('calculation_method_key')
            ->whereNull('calculation_method_name')
            ->whereNull('algorithm_version')
            ->count();
        if ($remainingEmpty > 0) {
            throw new RuntimeException(
                "ADV-001c2: nach Backfill verbleiben leere Freeze-Zeilen in {$table}.",
            );
        }
    }

    private function backfillSpotCategoryAssignments(): void
    {
        $spotsId = (int) DB::table('advertising_categories')
            ->where('key', self::SPOTS_CATEGORY_KEY)
            ->value('id');
        $now = now();

        foreach (self::SPOT_CATEGORY_METHODS as $definition) {
            $methodId = (int) DB::table('calculation_methods')->where('key', $definition['key'])->value('id');
            $existing = DB::table('advertising_category_calculation_methods')
                ->where('advertising_category_id', $spotsId)
                ->where('calculation_method_id', $methodId)
                ->first();

            if ($existing !== null) {
                continue;
            }

            DB::table('advertising_category_calculation_methods')->insert([
                'advertising_category_id' => $spotsId,
                'calculation_method_id' => $methodId,
                'engine_profile_key' => $definition['engine_profile_key'],
                'is_active' => true,
                'sort' => $definition['sort'],
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $averageId = (int) DB::table('calculation_methods')->where('key', 'average')->value('id');
        DB::table('advertising_categories')
            ->where('id', $spotsId)
            ->whereNull('default_calculation_method_id')
            ->update(['default_calculation_method_id' => $averageId]);

        $defaultId = DB::table('advertising_categories')->where('id', $spotsId)->value('default_calculation_method_id');
        if ((int) $defaultId !== $averageId) {
            throw new RuntimeException('ADV-001c2: Spot-Default konnte nicht auf average gesetzt werden.');
        }

        $count = DB::table('advertising_category_calculation_methods')
            ->where('advertising_category_id', $spotsId)
            ->count();
        if ($count !== count(self::SPOT_CATEGORY_METHODS)) {
            throw new RuntimeException(
                'ADV-001c2: Spot-Kategorie muss genau drei Methodenzuordnungen haben.',
            );
        }
    }

    private function makeAdvertisingMediaKindNullable(): void
    {
        $column = collect(Schema::getColumns('advertising_media'))->firstWhere('name', 'kind');
        if ($column !== null && ($column['nullable'] ?? false) === true) {
            return;
        }

        Schema::table('advertising_media', function (Blueprint $table): void {
            $table->string('kind')->nullable()->change();
        });
    }

    private function reinstallCalculationMethodModeGuards(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            // MySQL CHECK überlebt column change; nur bei Fehlen nachziehen.
            if (! $this->mysqlCheckExists('advertising_media', 'advertising_media_calc_method_mode_chk')) {
                DB::statement(<<<'SQL'
ALTER TABLE advertising_media
ADD CONSTRAINT advertising_media_calc_method_mode_chk
CHECK (calculation_method_mode IN ('inherit', 'override'))
SQL);
            }

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS advertising_media_calc_method_mode_insert');
            DB::statement('DROP TRIGGER IF EXISTS advertising_media_calc_method_mode_update');

            $predicate = "NEW.calculation_method_mode IN ('inherit', 'override')";

            DB::statement(<<<SQL
CREATE TRIGGER advertising_media_calc_method_mode_insert
BEFORE INSERT ON advertising_media
FOR EACH ROW
BEGIN
    SELECT CASE
        WHEN NOT ({$predicate})
        THEN RAISE(ABORT, 'advertising_media_calc_method_mode')
    END;
END;
SQL);

            DB::statement(<<<SQL
CREATE TRIGGER advertising_media_calc_method_mode_update
BEFORE UPDATE ON advertising_media
FOR EACH ROW
BEGIN
    SELECT CASE
        WHEN NOT ({$predicate})
        THEN RAISE(ABORT, 'advertising_media_calc_method_mode')
    END;
END;
SQL);
        }
    }

    private function assertUpComplete(): void
    {
        foreach (['calculation_positions', 'dispo_order_positions'] as $table) {
            if (! $this->hasAllFreezeColumns($table)) {
                throw new RuntimeException("ADV-001c2: Freeze-Spalten an {$table} unvollständig.");
            }
        }

        $kindColumn = collect(Schema::getColumns('advertising_media'))->firstWhere('name', 'kind');
        if ($kindColumn === null || ! ($kindColumn['nullable'] ?? false)) {
            throw new RuntimeException('ADV-001c2: advertising_media.kind ist nicht nullable.');
        }

        $inheritMismatch = DB::table('advertising_media')
            ->where('kind', 'spot_classic')
            ->where('calculation_method_mode', '!=', 'inherit')
            ->count();
        // Nur diagnostisch: c2 erzeugt keine Overrides; abweichende Modes sind erlaubt,
        // solange keine Medium-Zuordnungen existieren (bereits in Preflight geprüft).
        unset($inheritMismatch);
    }

    private function assertDownSafe(): void
    {
        $nullKinds = DB::table('advertising_media')->whereNull('kind')->count();
        if ($nullKinds > 0) {
            throw new RuntimeException(
                "ADV-001c2 down(): {$nullKinds} Werbemittel mit kind=NULL – "
                .'Rollback verweigert (noch keine Mutation).',
            );
        }

        $spotsId = (int) DB::table('advertising_categories')
            ->where('key', self::SPOTS_CATEGORY_KEY)
            ->value('id');
        $averageId = (int) DB::table('calculation_methods')->where('key', 'average')->value('id');
        $defaultId = DB::table('advertising_categories')->where('id', $spotsId)->value('default_calculation_method_id');

        if ($defaultId === null) {
            throw new RuntimeException(
                'ADV-001c2 down(): Spot-Default fehlt (NULL) – Rollback verweigert (noch keine Mutation).',
            );
        }
        if ((int) $defaultId !== $averageId) {
            throw new RuntimeException(
                'ADV-001c2 down(): Spot-Default weicht von average ab – Rollback verweigert (noch keine Mutation).',
            );
        }

        $spotsAssignments = DB::table('advertising_category_calculation_methods')
            ->where('advertising_category_id', $spotsId)
            ->get();
        if ($spotsAssignments->count() !== count(self::SPOT_CATEGORY_METHODS)) {
            throw new RuntimeException(
                'ADV-001c2 down(): Spot-Kategorie hat nicht genau drei Methodenzuordnungen – '
                .'Rollback verweigert (noch keine Mutation).',
            );
        }

        foreach (self::SPOT_CATEGORY_METHODS as $definition) {
            $methodId = (int) DB::table('calculation_methods')->where('key', $definition['key'])->value('id');
            $row = $spotsAssignments->firstWhere('calculation_method_id', $methodId);
            if ($row === null) {
                throw new RuntimeException(
                    "ADV-001c2 down(): erwartete Spot-Zuordnung für {$definition['key']} fehlt – "
                    .'Rollback verweigert (noch keine Mutation).',
                );
            }
            if ($this->nullableString($row->engine_profile_key) !== $definition['engine_profile_key']
                || ! (bool) $row->is_active
                || (int) $row->sort !== $definition['sort']) {
                throw new RuntimeException(
                    "ADV-001c2 down(): Spot-Zuordnung für {$definition['key']} wurde nachträglich verändert – "
                    .'Rollback verweigert (noch keine Mutation).',
                );
            }
        }

        $globalCategoryAssignments = (int) DB::table('advertising_category_calculation_methods')->count();
        if ($globalCategoryAssignments !== count(self::SPOT_CATEGORY_METHODS)) {
            throw new RuntimeException(
                "ADV-001c2 down(): global {$globalCategoryAssignments} Kategorie-Methodenzuordnung(en), "
                .'erwartet genau '.count(self::SPOT_CATEGORY_METHODS).' (nur Spot) – '
                .'Rollback verweigert (noch keine Mutation).',
            );
        }

        $foreignCategoryAssignments = (int) DB::table('advertising_category_calculation_methods')
            ->where('advertising_category_id', '!=', $spotsId)
            ->count();
        if ($foreignCategoryAssignments > 0) {
            throw new RuntimeException(
                "ADV-001c2 down(): {$foreignCategoryAssignments} Kategorie-Methodenzuordnung(en) "
                .'außerhalb von Spots – Rollback verweigert (noch keine Mutation).',
            );
        }

        $categoryDefaults = (int) DB::table('advertising_categories')
            ->whereNotNull('default_calculation_method_id')
            ->count();
        if ($categoryDefaults !== 1) {
            throw new RuntimeException(
                "ADV-001c2 down(): global {$categoryDefaults} Kategorie-Default(s), erwartet genau 1 "
                .'(spots → average) – Rollback verweigert (noch keine Mutation).',
            );
        }

        $foreignCategoryDefaults = (int) DB::table('advertising_categories')
            ->where('id', '!=', $spotsId)
            ->whereNotNull('default_calculation_method_id')
            ->count();
        if ($foreignCategoryDefaults > 0) {
            throw new RuntimeException(
                "ADV-001c2 down(): {$foreignCategoryDefaults} Defaultmethode(n) außerhalb von Spots – "
                .'Rollback verweigert (noch keine Mutation).',
            );
        }

        $mediumAssignments = (int) DB::table('advertising_medium_calculation_methods')->count();
        if ($mediumAssignments > 0) {
            throw new RuntimeException(
                "ADV-001c2 down(): {$mediumAssignments} Medium-Methodenzuordnung(en) vorhanden – "
                .'Rollback verweigert (noch keine Mutation).',
            );
        }

        $mediumDefaults = (int) DB::table('advertising_media')
            ->whereNotNull('default_calculation_method_id')
            ->count();
        if ($mediumDefaults > 0) {
            throw new RuntimeException(
                "ADV-001c2 down(): {$mediumDefaults} Medium-Defaultmethode(n) vorhanden – "
                .'Rollback verweigert (noch keine Mutation).',
            );
        }
    }

    private function rollbackSpotCategoryAssignments(): void
    {
        $spotsId = (int) DB::table('advertising_categories')
            ->where('key', self::SPOTS_CATEGORY_KEY)
            ->value('id');
        $averageId = (int) DB::table('calculation_methods')->where('key', 'average')->value('id');

        DB::table('advertising_categories')
            ->where('id', $spotsId)
            ->where('default_calculation_method_id', $averageId)
            ->update(['default_calculation_method_id' => null]);

        foreach (self::SPOT_CATEGORY_METHODS as $definition) {
            $methodId = (int) DB::table('calculation_methods')->where('key', $definition['key'])->value('id');
            DB::table('advertising_category_calculation_methods')
                ->where('advertising_category_id', $spotsId)
                ->where('calculation_method_id', $methodId)
                ->where('engine_profile_key', $definition['engine_profile_key'])
                ->where('sort', $definition['sort'])
                ->delete();
        }
    }

    private function dropFreezeColumns(string $table): void
    {
        $present = array_values(array_filter(
            self::FREEZE_COLUMNS,
            fn (string $column): bool => Schema::hasColumn($table, $column),
        ));
        if ($present === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($present): void {
            $blueprint->dropColumn($present);
        });
    }

    private function restoreAdvertisingMediaKindNotNull(): void
    {
        $nullKinds = DB::table('advertising_media')->whereNull('kind')->count();
        if ($nullKinds > 0) {
            throw new RuntimeException(
                "ADV-001c2 down(): {$nullKinds} Werbemittel mit kind=NULL – kind bleibt nullable.",
            );
        }

        Schema::table('advertising_media', function (Blueprint $table): void {
            $table->string('kind')->nullable(false)->change();
        });
    }

    private function installFreezeGuards(string $table): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $predicate = $this->freezeCompletenessSql('NEW');

        if ($driver === 'mysql') {
            $constraint = $table.'_calc_method_freeze_chk';
            // Immer neu setzen, damit TRIM-Härtung auch nach älterer Definition greift.
            $this->dropMysqlCheckConstraint($table, $constraint);

            DB::statement(<<<SQL
ALTER TABLE {$table}
ADD CONSTRAINT {$constraint}
CHECK (
    (
        engine_profile_key IS NULL
        AND calculation_method_key IS NULL
        AND calculation_method_name IS NULL
        AND algorithm_version IS NULL
    )
    OR (
        engine_profile_key IS NOT NULL
        AND TRIM(engine_profile_key) <> ''
        AND calculation_method_key IS NOT NULL
        AND TRIM(calculation_method_key) <> ''
        AND calculation_method_name IS NOT NULL
        AND TRIM(calculation_method_name) <> ''
        AND algorithm_version IS NOT NULL
        AND TRIM(algorithm_version) <> ''
    )
)
SQL);

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_calc_method_freeze_insert");
            DB::statement("DROP TRIGGER IF EXISTS {$table}_calc_method_freeze_update");

            DB::statement(<<<SQL
CREATE TRIGGER {$table}_calc_method_freeze_insert
BEFORE INSERT ON {$table}
FOR EACH ROW
BEGIN
    SELECT CASE
        WHEN NOT ({$predicate})
        THEN RAISE(ABORT, '{$table}_calc_method_freeze')
    END;
END;
SQL);

            DB::statement(<<<SQL
CREATE TRIGGER {$table}_calc_method_freeze_update
BEFORE UPDATE ON {$table}
FOR EACH ROW
BEGIN
    SELECT CASE
        WHEN NOT ({$predicate})
        THEN RAISE(ABORT, '{$table}_calc_method_freeze')
    END;
END;
SQL);
        }
    }

    private function dropFreezeGuards(string $table): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $this->dropMysqlCheckConstraint($table, $table.'_calc_method_freeze_chk');

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_calc_method_freeze_insert");
            DB::statement("DROP TRIGGER IF EXISTS {$table}_calc_method_freeze_update");
        }
    }

    private function freezeCompletenessSql(string $alias): string
    {
        return '('
            ."{$alias}.engine_profile_key IS NULL "
            ."AND {$alias}.calculation_method_key IS NULL "
            ."AND {$alias}.calculation_method_name IS NULL "
            ."AND {$alias}.algorithm_version IS NULL"
            .') OR ('
            ."{$alias}.engine_profile_key IS NOT NULL AND TRIM({$alias}.engine_profile_key) <> '' "
            ."AND {$alias}.calculation_method_key IS NOT NULL AND TRIM({$alias}.calculation_method_key) <> '' "
            ."AND {$alias}.calculation_method_name IS NOT NULL AND TRIM({$alias}.calculation_method_name) <> '' "
            ."AND {$alias}.algorithm_version IS NOT NULL AND TRIM({$alias}.algorithm_version) <> ''"
            .')';
    }

    private function hasAllFreezeColumns(string $table): bool
    {
        foreach (self::FREEZE_COLUMNS as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function hasPartialFreezeColumns(string $table): bool
    {
        $present = 0;
        foreach (self::FREEZE_COLUMNS as $column) {
            if (Schema::hasColumn($table, $column)) {
                $present++;
            }
        }

        return $present > 0 && $present < count(self::FREEZE_COLUMNS);
    }

    private function mysqlCheckExists(string $table, string $constraint): bool
    {
        $schema = Schema::getConnection()->getDatabaseName();

        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $schema)
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->where('CONSTRAINT_TYPE', 'CHECK')
            ->exists();
    }

    private function dropMysqlCheckConstraint(string $table, string $constraint): void
    {
        if (! $this->mysqlCheckExists($table, $constraint)) {
            return;
        }

        $version = (string) (DB::selectOne('select version() as v')->v ?? '');
        $isMariaDb = str_contains(strtolower($version), 'mariadb');

        if ($isMariaDb) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
        } else {
            DB::statement("ALTER TABLE {$table} DROP CHECK {$constraint}");
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
};
