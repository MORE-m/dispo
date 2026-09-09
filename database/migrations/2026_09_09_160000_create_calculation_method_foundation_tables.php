<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADV-001c1 / CALC-KIND-FOUNDATION: Schema-Fundament Katalog ↔ Methoden ↔ Engine-Profile.
 *
 * - calculation_methods (systemseitig literal geseedet)
 * - advertising_category_calculation_methods / advertising_medium_calculation_methods
 * - calculation_method_mode + default_calculation_method_id
 *
 * Kein Zuordnungs-Backfill, keine Runtime-Anbindung, kind unverändert NOT NULL.
 * Literale Method-Seeds sind bewusst in dieser Migration eingefroren (kein Runtime-Import).
 *
 * Fail-closed: unvollständige Schema-Zwischenstände werden nicht still akzeptiert.
 * Vollständiger Zustand ist idempotent (erneutes up() ohne Mutation).
 */
return new class extends Migration
{
    /**
     * @var list<array{key: string, name: string, sort: int}>
     */
    private const METHOD_DEFINITIONS = [
        ['key' => 'average', 'name' => 'Durchschnitt', 'sort' => 10],
        ['key' => 'calendar', 'name' => 'Kalenderplaner', 'sort' => 20],
        ['key' => 'fixed_price', 'name' => 'Festpreis', 'sort' => 30],
        ['key' => 'tkp', 'name' => 'TKP', 'sort' => 40],
        ['key' => 'free_position', 'name' => 'Freie Preisposition', 'sort' => 50],
    ];

    public function up(): void
    {
        $this->assertPrerequisites();

        $state = $this->schemaState();
        if ($state === 'partial') {
            throw new RuntimeException(
                'ADV-001c1: unvollständiger Schema-Zustand erkannt '
                .'(calculation_methods / Zuordnungstabellen / Mode / Defaults). '
                .'Keine stille Teilmigration – bitte manuell bereinigen und Migration erneut ausführen.',
            );
        }

        if ($state === 'absent') {
            $this->createFoundationSchema();
        }

        $this->seedCalculationMethods();
        $this->assertFoundationComplete();
        $this->installCalculationMethodModeGuards();
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::disableForeignKeyConstraints();
        try {
            // Zuordnungstabellen zuerst entfernen, bevor advertising_media auf SQLite
            // per ALTER neu aufgebaut wird (sonst FK-Verletzung beim DROP TABLE).
            Schema::dropIfExists('advertising_medium_calculation_methods');
            Schema::dropIfExists('advertising_category_calculation_methods');

            if (Schema::hasTable('advertising_media')) {
                if (Schema::hasColumn('advertising_media', 'default_calculation_method_id')) {
                    Schema::table('advertising_media', function (Blueprint $table) use ($driver) {
                        if ($driver === 'mysql') {
                            $table->dropForeign('adv_med_default_calc_m_fk');
                        } else {
                            $table->dropForeign(['default_calculation_method_id']);
                        }
                        $table->dropColumn('default_calculation_method_id');
                    });
                }

                if (Schema::hasColumn('advertising_media', 'calculation_method_mode')) {
                    if ($driver === 'sqlite') {
                        DB::statement('DROP TRIGGER IF EXISTS advertising_media_calc_method_mode_insert');
                        DB::statement('DROP TRIGGER IF EXISTS advertising_media_calc_method_mode_update');
                    } elseif ($driver === 'mysql') {
                        $this->dropMysqlCheckConstraint('advertising_media', 'advertising_media_calc_method_mode_chk');
                    }

                    Schema::table('advertising_media', function (Blueprint $table) {
                        $table->dropColumn('calculation_method_mode');
                    });
                }
            }

            if (Schema::hasTable('advertising_categories')
                && Schema::hasColumn('advertising_categories', 'default_calculation_method_id')) {
                Schema::table('advertising_categories', function (Blueprint $table) use ($driver) {
                    if ($driver === 'mysql') {
                        $table->dropForeign('adv_cat_default_calc_m_fk');
                    } else {
                        $table->dropForeign(['default_calculation_method_id']);
                    }
                    $table->dropColumn('default_calculation_method_id');
                });
            }

            Schema::dropIfExists('calculation_methods');
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function assertPrerequisites(): void
    {
        if (! Schema::hasTable('advertising_categories') || ! Schema::hasTable('advertising_media')) {
            throw new RuntimeException(
                'ADV-001c1: Voraussetzungen fehlen (advertising_categories / advertising_media).',
            );
        }

        if (! Schema::hasColumn('advertising_media', 'kind')) {
            throw new RuntimeException(
                'ADV-001c1: advertising_media.kind fehlt – Abbruch.',
            );
        }
    }

    /**
     * @return 'absent'|'complete'|'partial'
     */
    private function schemaState(): string
    {
        $flags = [
            Schema::hasTable('calculation_methods'),
            Schema::hasTable('advertising_category_calculation_methods'),
            Schema::hasTable('advertising_medium_calculation_methods'),
            Schema::hasColumn('advertising_categories', 'default_calculation_method_id'),
            Schema::hasColumn('advertising_media', 'calculation_method_mode'),
            Schema::hasColumn('advertising_media', 'default_calculation_method_id'),
        ];

        $present = count(array_filter($flags));
        if ($present === 0) {
            return 'absent';
        }
        if ($present === count($flags)) {
            return 'complete';
        }

        return 'partial';
    }

    private function createFoundationSchema(): void
    {
        Schema::create('calculation_methods', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->text('help_text')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });

        Schema::create('advertising_category_calculation_methods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('advertising_category_id');
            $table->unsignedBigInteger('calculation_method_id');
            $table->string('engine_profile_key', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->foreign('advertising_category_id', 'adv_cat_calc_m_cat_fk')
                ->references('id')
                ->on('advertising_categories')
                ->restrictOnDelete();
            $table->foreign('calculation_method_id', 'adv_cat_calc_m_method_fk')
                ->references('id')
                ->on('calculation_methods')
                ->restrictOnDelete();
            $table->unique(
                ['advertising_category_id', 'calculation_method_id'],
                'adv_cat_calc_method_unique',
            );
        });

        Schema::create('advertising_medium_calculation_methods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('advertising_medium_id');
            $table->unsignedBigInteger('calculation_method_id');
            $table->string('engine_profile_key', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->foreign('advertising_medium_id', 'adv_med_calc_m_med_fk')
                ->references('id')
                ->on('advertising_media')
                ->restrictOnDelete();
            $table->foreign('calculation_method_id', 'adv_med_calc_m_method_fk')
                ->references('id')
                ->on('calculation_methods')
                ->restrictOnDelete();
            $table->unique(
                ['advertising_medium_id', 'calculation_method_id'],
                'adv_med_calc_method_unique',
            );
        });

        Schema::table('advertising_categories', function (Blueprint $table) {
            $table->unsignedBigInteger('default_calculation_method_id')->nullable()->after('lock_version');
            $table->foreign('default_calculation_method_id', 'adv_cat_default_calc_m_fk')
                ->references('id')
                ->on('calculation_methods')
                ->restrictOnDelete();
        });

        Schema::table('advertising_media', function (Blueprint $table) {
            $table->string('calculation_method_mode', 16)
                ->default('inherit')
                ->after('kind');
        });
        DB::table('advertising_media')->update(['calculation_method_mode' => 'inherit']);

        Schema::table('advertising_media', function (Blueprint $table) {
            $table->unsignedBigInteger('default_calculation_method_id')
                ->nullable()
                ->after('calculation_method_mode');
            $table->foreign('default_calculation_method_id', 'adv_med_default_calc_m_fk')
                ->references('id')
                ->on('calculation_methods')
                ->restrictOnDelete();
        });
    }

    private function seedCalculationMethods(): void
    {
        $now = now();
        $expectedKeys = array_column(self::METHOD_DEFINITIONS, 'key');

        foreach (self::METHOD_DEFINITIONS as $definition) {
            $existing = DB::table('calculation_methods')->where('key', $definition['key'])->first();
            if ($existing !== null) {
                continue;
            }

            DB::table('calculation_methods')->insert([
                'key' => $definition['key'],
                'name' => $definition['name'],
                'help_text' => null,
                'sort' => $definition['sort'],
                'is_active' => true,
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $actualKeys = DB::table('calculation_methods')->orderBy('sort')->pluck('key')->all();
        if ($actualKeys !== $expectedKeys) {
            throw new RuntimeException(
                'ADV-001c1: calculation_methods-Keys weichen vom literalen Vertrag ab. '
                .'Erwartet: '.implode(',', $expectedKeys).' – Ist: '.implode(',', $actualKeys),
            );
        }
    }

    private function assertFoundationComplete(): void
    {
        if ($this->schemaState() !== 'complete') {
            throw new RuntimeException(
                'ADV-001c1: Foundation-Schema nach up() unvollständig.',
            );
        }

        $invalidMode = DB::table('advertising_media')
            ->where(function ($query): void {
                $query->whereNull('calculation_method_mode')
                    ->orWhereNotIn('calculation_method_mode', ['inherit', 'override']);
            })
            ->count();
        if ($invalidMode > 0) {
            throw new RuntimeException(
                'ADV-001c1: advertising_media.calculation_method_mode enthält ungültige Werte.',
            );
        }
    }

    private function installCalculationMethodModeGuards(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $schema = Schema::getConnection()->getDatabaseName();
            $exists = DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', $schema)
                ->where('TABLE_NAME', 'advertising_media')
                ->where('CONSTRAINT_NAME', 'advertising_media_calc_method_mode_chk')
                ->where('CONSTRAINT_TYPE', 'CHECK')
                ->exists();

            if (! $exists) {
                DB::statement(<<<'SQL'
ALTER TABLE advertising_media
ADD CONSTRAINT advertising_media_calc_method_mode_chk
CHECK (calculation_method_mode IN ('inherit', 'override'))
SQL);
            }

            return;
        }

        if ($driver === 'sqlite') {
            // SQLite: ALTER TABLE mit FK baut die Tabelle neu und verwirft Trigger –
            // Mode-Guards daher immer nach Schemaänderungen (re)installieren.
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

            $installed = DB::select(
                "SELECT name FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'advertising_media_calc_method_mode_%'",
            );
            if (count($installed) < 2) {
                throw new RuntimeException(
                    'ADV-001c1: SQLite-Trigger für calculation_method_mode wurden nicht installiert.',
                );
            }
        }
    }

    private function dropMysqlCheckConstraint(string $table, string $constraint): void
    {
        $schema = Schema::getConnection()->getDatabaseName();
        $exists = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $schema)
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->where('CONSTRAINT_TYPE', 'CHECK')
            ->exists();

        if (! $exists) {
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
};
