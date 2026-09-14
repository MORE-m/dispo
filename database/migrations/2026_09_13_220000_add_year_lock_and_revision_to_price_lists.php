<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BL-P4-01a: Jahresbezug, lock_version, revisionsfeste Versionsidentität
 * und höchstens eine aktive Preisliste je Inventar/Jahr.
 *
 * Jahr-Backfill nur aus valid_from (eindeutig belegt). Kein Fallback auf 2026
 * oder das Ausführungsjahr. Historische version-Strings und IDs bleiben.
 *
 * Alle vorher prüfbaren Voraussetzungen laufen vor der ersten Schema- oder
 * Datenänderung. Ein nicht verlustfreier Rollback wird verweigert, ohne den
 * aktuellen Zustand zu beschädigen. MySQL-DDL gilt nicht als transaktional.
 *
 * Eindeutigkeit Active:
 * - SQLite: partieller Unique-Index WHERE status = 'active'
 * - MySQL: generierte Spalten (NULL wenn nicht active) + UNIQUE
 *   (MySQL erlaubt mehrere NULL in UNIQUE; Drafts/Archive bleiben mehrfach möglich)
 *
 * MySQL: neue Inventar-Indizes zuerst anlegen, danach den alten Unique
 * `(inventory_id, version)` droppen. Der alte Index stützt den FK inventory_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertDriverSupported();
        $this->assertBackfillPrecondition();

        Schema::table('price_lists', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->default(0);
            $table->unsignedInteger('revision_number')->default(0);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
        });

        $this->backfillYearFromValidFrom();
        $this->backfillRevisionNumbers();
        $this->assertBackfillComplete();

        Schema::table('price_lists', function (Blueprint $table) {
            $table->unique(['inventory_id', 'year', 'version'], 'price_lists_inventory_year_version_unique');
            $table->unique(['inventory_id', 'year', 'revision_number'], 'price_lists_inventory_year_revision_unique');
            $table->index(['inventory_id', 'year', 'status'], 'price_lists_inventory_year_status_idx');
        });

        Schema::table('price_lists', function (Blueprint $table) {
            $table->dropUnique(['inventory_id', 'version']);
        });

        $this->installActiveUniqueness();
    }

    public function down(): void
    {
        $this->assertLegacyUniquenessRestorable();
        $this->dropActiveUniqueness();

        Schema::table('price_lists', function (Blueprint $table) {
            $table->unique(['inventory_id', 'version']);
        });

        Schema::table('price_lists', function (Blueprint $table) {
            $table->dropUnique('price_lists_inventory_year_version_unique');
            $table->dropUnique('price_lists_inventory_year_revision_unique');
            $table->dropIndex('price_lists_inventory_year_status_idx');
            $table->dropColumn([
                'year',
                'revision_number',
                'lock_version',
                'published_at',
                'archived_at',
            ]);
        });
    }

    private function driver(): string
    {
        return Schema::getConnection()->getDriverName();
    }

    private function assertDriverSupported(): void
    {
        $driver = $this->driver();
        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new RuntimeException(
                'Nicht unterstützter Datenbanktreiber für Preislisten-Migration: '.$driver,
            );
        }
    }

    private function yearExpression(): string
    {
        return $this->driver() === 'sqlite'
            ? "CAST(strftime('%Y', valid_from) AS INTEGER)"
            : 'YEAR(valid_from)';
    }

    private function assertBackfillPrecondition(): void
    {
        $invalidValidFrom = $this->driver() === 'sqlite'
            ? (int) DB::table('price_lists')
                ->where(function ($query): void {
                    $query->whereNull('valid_from')
                        ->orWhereRaw("CAST(strftime('%Y', valid_from) AS INTEGER) IS NULL")
                        ->orWhereRaw("CAST(strftime('%Y', valid_from) AS INTEGER) < 1990")
                        ->orWhereRaw("CAST(strftime('%Y', valid_from) AS INTEGER) > 2100");
                })
                ->count()
            : (int) DB::table('price_lists')
                ->where(function ($query): void {
                    $query->whereNull('valid_from')
                        ->orWhereRaw('YEAR(valid_from) IS NULL')
                        ->orWhereRaw('YEAR(valid_from) < 1990')
                        ->orWhereRaw('YEAR(valid_from) > 2100');
                })
                ->count();
        if ($invalidValidFrom > 0) {
            throw new RuntimeException(
                'Jahr-Backfill nicht möglich: price_lists.valid_from fehlt, ist nicht eindeutig auswertbar oder ergibt ein Kalenderjahr außerhalb 1990–2100 ('
                .$invalidValidFrom
                .' Zeile(n)). Kein pauschales Jahr raten.',
            );
        }

        $collisions = $this->driver() === 'sqlite'
            ? DB::table('price_lists')
                ->select('inventory_id')
                ->selectRaw("CAST(strftime('%Y', valid_from) AS INTEGER) as year_value")
                ->selectRaw('COUNT(*) as n')
                ->where('status', 'active')
                ->groupBy('inventory_id')
                ->groupByRaw("CAST(strftime('%Y', valid_from) AS INTEGER)")
                ->having('n', '>', 1)
                ->get()
            : DB::table('price_lists')
                ->select('inventory_id')
                ->selectRaw('YEAR(valid_from) as year_value')
                ->selectRaw('COUNT(*) as n')
                ->where('status', 'active')
                ->groupBy('inventory_id')
                ->groupByRaw('YEAR(valid_from)')
                ->having('n', '>', 1)
                ->get();

        if ($collisions->isNotEmpty()) {
            $sample = $collisions->map(
                function (mixed $row): string {
                    $data = (array) $row;

                    return 'inventory_id='.($data['inventory_id'] ?? '')
                        .' year='.($data['year_value'] ?? '')
                        .' active='.($data['n'] ?? '');
                },
            )->implode('; ');

            throw new RuntimeException(
                'Jahr-Backfill nicht möglich: mehrere aktive Preislisten desselben Inventars und Jahres. '
                .$sample
                .'. Keine automatische Archivierung.',
            );
        }

        $versionCollisions = $this->driver() === 'sqlite'
            ? DB::table('price_lists')
                ->select('inventory_id', 'version')
                ->selectRaw("CAST(strftime('%Y', valid_from) AS INTEGER) as year_value")
                ->selectRaw('COUNT(*) as n')
                ->groupBy('inventory_id', 'version')
                ->groupByRaw("CAST(strftime('%Y', valid_from) AS INTEGER)")
                ->having('n', '>', 1)
                ->get()
            : DB::table('price_lists')
                ->select('inventory_id', 'version')
                ->selectRaw('YEAR(valid_from) as year_value')
                ->selectRaw('COUNT(*) as n')
                ->groupBy('inventory_id', 'version')
                ->groupByRaw('YEAR(valid_from)')
                ->having('n', '>', 1)
                ->get();

        if ($versionCollisions->isNotEmpty()) {
            $sample = $versionCollisions->map(
                function (mixed $row): string {
                    $data = (array) $row;

                    return 'inventory_id='.($data['inventory_id'] ?? '')
                        .' year='.($data['year_value'] ?? '')
                        .' version='.($data['version'] ?? '');
                },
            )->implode('; ');

            throw new RuntimeException(
                'Jahr-Backfill nicht möglich: UNIQUE(inventory_id, year, version) würde kollidieren. '
                .$sample
                .'. Keine automatische Umnummerierung.',
            );
        }
    }

    private function backfillYearFromValidFrom(): void
    {
        $yearExpr = $this->yearExpression();
        DB::statement('UPDATE price_lists SET year = '.$yearExpr.' WHERE valid_from IS NOT NULL');
    }

    private function backfillRevisionNumbers(): void
    {
        $rows = DB::table('price_lists')
            ->select('id', 'inventory_id', 'year')
            ->orderBy('inventory_id')
            ->orderBy('year')
            ->orderBy('id')
            ->get();

        $counters = [];
        foreach ($rows as $row) {
            $key = $row->inventory_id.'|'.$row->year;
            $counters[$key] = ($counters[$key] ?? 0) + 1;
            DB::table('price_lists')->where('id', $row->id)->update([
                'revision_number' => $counters[$key],
                'lock_version' => 1,
            ]);
        }
    }

    private function assertBackfillComplete(): void
    {
        $invalidYear = (int) DB::table('price_lists')
            ->where(function ($query): void {
                $query->where('year', '<', 1990)->orWhere('year', '>', 2100);
            })
            ->count();
        if ($invalidYear > 0) {
            throw new RuntimeException(
                'Jahr-Backfill unvollständig: '.$invalidYear.' Zeile(n) ohne belegbares Kalenderjahr 1990–2100.',
            );
        }

        $invalidRevision = (int) DB::table('price_lists')->where('revision_number', '<', 1)->count();
        if ($invalidRevision > 0) {
            throw new RuntimeException(
                'Revisions-Backfill unvollständig: '.$invalidRevision.' Zeile(n) ohne revision_number.',
            );
        }
    }

    private function assertLegacyUniquenessRestorable(): void
    {
        $collisions = DB::table('price_lists')
            ->select('inventory_id', 'version')
            ->selectRaw('COUNT(*) as n')
            ->groupBy('inventory_id', 'version')
            ->having('n', '>', 1)
            ->get();

        if ($collisions->isNotEmpty()) {
            $sample = $collisions->map(
                function (mixed $row): string {
                    $data = (array) $row;

                    return 'inventory_id='.($data['inventory_id'] ?? '')
                        .' version='.($data['version'] ?? '')
                        .' n='.($data['n'] ?? '');
                },
            )->implode('; ');

            throw new RuntimeException(
                'Rollback nicht möglich: UNIQUE(inventory_id, version) ist nicht wiederherstellbar. '
                .$sample
                .'. Schutzmechanismen und Spalten bleiben unverändert.',
            );
        }
    }

    private function installActiveUniqueness(): void
    {
        $driver = $this->driver();

        if ($driver === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX price_lists_one_active_per_inventory_year
                 ON price_lists (inventory_id, year)
                 WHERE status = 'active'",
            );

            return;
        }

        if ($driver === 'mysql') {
            DB::statement(
                "ALTER TABLE price_lists
                 ADD COLUMN active_inventory_id BIGINT UNSIGNED
                 GENERATED ALWAYS AS (IF(status = 'active', inventory_id, NULL)) STORED",
            );
            DB::statement(
                "ALTER TABLE price_lists
                 ADD COLUMN active_year SMALLINT UNSIGNED
                 GENERATED ALWAYS AS (IF(status = 'active', year, NULL)) STORED",
            );
            DB::statement(
                'CREATE UNIQUE INDEX price_lists_one_active_per_inventory_year
                 ON price_lists (active_inventory_id, active_year)',
            );

            return;
        }

        throw new RuntimeException('Nicht unterstützter Datenbanktreiber für Preislisten-Eindeutigkeit: '.$driver);
    }

    private function dropActiveUniqueness(): void
    {
        $driver = $this->driver();

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS price_lists_one_active_per_inventory_year');

            return;
        }

        if ($driver === 'mysql') {
            DB::statement('DROP INDEX price_lists_one_active_per_inventory_year ON price_lists');
            Schema::table('price_lists', function (Blueprint $table) {
                $table->dropColumn(['active_inventory_id', 'active_year']);
            });
        }
    }
};
