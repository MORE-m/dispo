<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DF-3.3-fs: Container-Metadaten für Core- und freie Feldsets.
 *
 * Bewusste Duplizierung: Die Core-Keys und Backfill-Werte unten frieren den
 * historischen Stand dieser Migration ein. Runtime-Kataloge
 * (AdminFieldSetCatalog) dürfen diese Migration nicht speisen.
 */
return new class extends Migration
{
    private const CALCULATION_CORE_KEY = 'system_calculation_core';

    private const DISPO_ORDER_CORE_KEY = 'system_dispo_order_core';

    public function up(): void
    {
        if (! Schema::hasColumn('field_sets', 'is_system')) {
            Schema::table('field_sets', function (Blueprint $table) {
                $table->boolean('is_system')->nullable()->after('name');
                $table->string('applies_to', 32)->nullable()->after('is_system');
                $table->boolean('is_assignable')->nullable()->after('applies_to');
            });
        }

        $this->backfillCoreMetadataFailClosed();
        $this->enforceNotNull();
    }

    public function down(): void
    {
        if (! Schema::hasColumn('field_sets', 'is_system')) {
            return;
        }

        Schema::disableForeignKeyConstraints();
        try {
            Schema::table('field_sets', function (Blueprint $table) {
                $table->dropColumn(['is_system', 'applies_to', 'is_assignable']);
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function backfillCoreMetadataFailClosed(): void
    {
        $rows = DB::table('field_sets')->orderBy('id')->get(['id', 'key', 'is_system', 'applies_to', 'is_assignable']);

        $byKey = [];
        foreach ($rows as $row) {
            if (isset($byKey[$row->key])) {
                throw new RuntimeException(
                    "DF-3.3-fs Migration: doppelter Feldset-Key „{$row->key}“ (IDs {$byKey[$row->key]} und {$row->id}).",
                );
            }
            $byKey[$row->key] = $row->id;
        }

        if (! isset($byKey[self::CALCULATION_CORE_KEY])) {
            throw new RuntimeException(
                'DF-3.3-fs Migration: erwartetes Core-Feldset „'.self::CALCULATION_CORE_KEY.'“ fehlt.',
            );
        }
        if (! isset($byKey[self::DISPO_ORDER_CORE_KEY])) {
            throw new RuntimeException(
                'DF-3.3-fs Migration: erwartetes Core-Feldset „'.self::DISPO_ORDER_CORE_KEY.'“ fehlt.',
            );
        }

        foreach ($rows as $row) {
            if ($row->key === self::CALCULATION_CORE_KEY) {
                DB::table('field_sets')->where('id', $row->id)->update([
                    'is_system' => true,
                    'applies_to' => 'calculation',
                    'is_assignable' => false,
                ]);

                continue;
            }

            if ($row->key === self::DISPO_ORDER_CORE_KEY) {
                DB::table('field_sets')->where('id', $row->id)->update([
                    'is_system' => true,
                    'applies_to' => 'dispo_order',
                    'is_assignable' => false,
                ]);

                continue;
            }

            throw new RuntimeException(
                "DF-3.3-fs Migration: unbekanntes Feldset id={$row->id} key=\"{$row->key}\" – ".
                'kein stiller Backfill als freies/System-Feldset. Bitte Datenstand klären.',
            );
        }
    }

    private function enforceNotNull(): void
    {
        $nulls = DB::table('field_sets')
            ->where(function ($query): void {
                $query->whereNull('is_system')
                    ->orWhereNull('applies_to')
                    ->orWhereNull('is_assignable');
            })
            ->count();

        if ($nulls > 0) {
            throw new RuntimeException(
                'DF-3.3-fs Migration: Backfill hinterließ NULL-Werte in field_sets.',
            );
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->enforceSqliteNotNullWithoutDefaults();

            return;
        }

        // Keine DEFAULT-Werte: Inserts müssen is_system/applies_to/is_assignable explizit setzen.
        DB::statement('ALTER TABLE field_sets MODIFY is_system TINYINT(1) NOT NULL');
        DB::statement('ALTER TABLE field_sets MODIFY applies_to VARCHAR(32) NOT NULL');
        DB::statement('ALTER TABLE field_sets MODIFY is_assignable TINYINT(1) NOT NULL');
    }

    /**
     * SQLite: NOT NULL per change() (wie ADV-001a), ohne Defaults und ohne FK-Verlust.
     */
    private function enforceSqliteNotNullWithoutDefaults(): void
    {
        Schema::disableForeignKeyConstraints();
        try {
            if (! $this->sqliteColumnIsNotNull('field_sets', 'is_system')) {
                Schema::table('field_sets', function (Blueprint $table) {
                    $table->boolean('is_system')->nullable(false)->change();
                });
            }
            if (! $this->sqliteColumnIsNotNull('field_sets', 'applies_to')) {
                Schema::table('field_sets', function (Blueprint $table) {
                    $table->string('applies_to', 32)->nullable(false)->change();
                });
            }
            if (! $this->sqliteColumnIsNotNull('field_sets', 'is_assignable')) {
                Schema::table('field_sets', function (Blueprint $table) {
                    $table->boolean('is_assignable')->nullable(false)->change();
                });
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        foreach (['is_system', 'applies_to', 'is_assignable'] as $column) {
            if (! $this->sqliteColumnIsNotNull('field_sets', $column)) {
                throw new RuntimeException(
                    "DF-3.3-fs Migration: Spalte field_sets.{$column} ist unter SQLite nicht NOT NULL.",
                );
            }
        }

        if (! $this->sqliteForeignKeyExists('field_sets', 'active_version_id')) {
            throw new RuntimeException(
                'DF-3.3-fs Migration: FK field_sets.active_version_id fehlt nach SQLite-NOT-NULL-Änderung.',
            );
        }
    }

    private function sqliteColumnIsNotNull(string $table, string $column): bool
    {
        foreach (DB::select("PRAGMA table_info({$table})") as $row) {
            if (($row->name ?? null) === $column) {
                return (int) ($row->notnull ?? 0) === 1;
            }
        }

        return false;
    }

    private function sqliteForeignKeyExists(string $table, string $column): bool
    {
        foreach (DB::select("PRAGMA foreign_key_list({$table})") as $row) {
            if (($row->from ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
};
