<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADV-001a: Oberkategorie-Datenbasis (AdvertisingCategory + category_id am Werbemittel).
 * Keine Assignments, keine Snapshot-Änderung, keine Admin-UI.
 *
 * Bewusste Duplizierung: Die Literale unten frieren den historischen Stand dieser
 * Migration ein. Der Runtime-Katalog
 * App\Support\Advertising\CanonicalAdvertisingCategories beschreibt die aktuelle
 * technische Schnittstelle und darf diese Migration nicht speisen.
 */
return new class extends Migration
{
    /**
     * Historischer Seed-Stand ADV-001a (unveränderlich in dieser Migration).
     *
     * @var list<array{key: string, name: string, sort: int}>
     */
    private const CATEGORY_DEFINITIONS = [
        ['key' => 'spots', 'name' => 'Spots', 'sort' => 10],
        ['key' => 'special_advertising_formats', 'name' => 'SWF / Sonderwerbeformen', 'sort' => 20],
        ['key' => 'online_audio', 'name' => 'Online Audio', 'sort' => 30],
        ['key' => 'social_online', 'name' => 'Social Media / Online', 'sort' => 40],
        ['key' => 'events_promotion', 'name' => 'Events / Promotion', 'sort' => 50],
        ['key' => 'barter', 'name' => 'Gegengeschäft', 'sort' => 60],
    ];

    /**
     * Explizite Bestands-Map zum Migrationszeitpunkt.
     * Nur Codes, die vor ADV-001a als Bestandsdaten vorkommen können
     * (Factory-Default / E2E-Seeder: spot_classic). spot_classic_alt entsteht
     * ausschließlich nachträglich in Tests und gehört nicht in diesen Backfill.
     *
     * @var array<string, string>
     */
    private const MEDIUM_CODE_TO_CATEGORY_KEY = [
        'spot_classic' => 'spots',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('advertising_categories')) {
            Schema::create('advertising_categories', function (Blueprint $table) {
                $table->id();
                $table->string('key', 64)->unique();
                $table->string('name');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort')->default(0);
                $table->timestamps();
            });
        }

        $this->seedCanonicalCategories();

        if (! Schema::hasColumn('advertising_media', 'category_id')) {
            Schema::disableForeignKeyConstraints();
            try {
                if (Schema::getConnection()->getDriverName() === 'sqlite') {
                    DB::statement('ALTER TABLE advertising_media ADD COLUMN category_id INTEGER NULL');
                } else {
                    Schema::table('advertising_media', function (Blueprint $table) {
                        $table->unsignedBigInteger('category_id')->nullable()->after('id');
                    });
                }
            } finally {
                Schema::enableForeignKeyConstraints();
            }
        }

        $this->backfillMediumCategories();
        $this->assertAllMediaMapped();
        $this->enforceCategoryIdNotNullAndForeignKey();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        try {
            if (Schema::hasColumn('advertising_media', 'category_id')) {
                if (Schema::getConnection()->getDriverName() === 'mysql') {
                    if ($this->foreignKeyExists('advertising_media', 'advertising_media_category_id_fk')) {
                        Schema::table('advertising_media', function (Blueprint $table) {
                            $table->dropForeign('advertising_media_category_id_fk');
                        });
                    }
                    Schema::table('advertising_media', function (Blueprint $table) {
                        $table->dropColumn('category_id');
                    });
                } else {
                    if ($this->sqliteForeignKeyExists('advertising_media', 'category_id')) {
                        Schema::table('advertising_media', function (Blueprint $table) {
                            $table->dropForeign(['category_id']);
                        });
                    }
                    Schema::table('advertising_media', function (Blueprint $table) {
                        $table->dropColumn('category_id');
                    });
                }
            }

            Schema::dropIfExists('advertising_categories');
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function seedCanonicalCategories(): void
    {
        $now = now();

        foreach (self::CATEGORY_DEFINITIONS as $definition) {
            $existing = DB::table('advertising_categories')->where('key', $definition['key'])->first();
            if ($existing !== null) {
                continue;
            }

            DB::table('advertising_categories')->insert([
                'key' => $definition['key'],
                'name' => $definition['name'],
                'is_active' => true,
                'sort' => $definition['sort'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function backfillMediumCategories(): void
    {
        $categoryKeys = array_column(self::CATEGORY_DEFINITIONS, 'key');

        /** @var array<string, int> $categoryIdsByKey */
        $categoryIdsByKey = DB::table('advertising_categories')
            ->whereIn('key', $categoryKeys)
            ->pluck('id', 'key')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach (self::MEDIUM_CODE_TO_CATEGORY_KEY as $mediumCode => $categoryKey) {
            $categoryId = $categoryIdsByKey[$categoryKey] ?? null;
            if ($categoryId === null) {
                throw new RuntimeException(
                    "ADV-001a Backfill: Kategorie „{$categoryKey}“ fehlt für Medium-Code „{$mediumCode}“.",
                );
            }

            DB::table('advertising_media')
                ->where('code', $mediumCode)
                ->whereNull('category_id')
                ->update(['category_id' => $categoryId]);
        }
    }

    private function assertAllMediaMapped(): void
    {
        $unmapped = DB::table('advertising_media')
            ->whereNull('category_id')
            ->orderBy('id')
            ->get(['id', 'code', 'name']);

        if ($unmapped->isEmpty()) {
            return;
        }

        $details = $unmapped
            ->map(fn ($row): string => sprintf('#%s code=%s name=%s', $row->id, $row->code, $row->name))
            ->implode('; ');

        throw new RuntimeException(
            'ADV-001a Backfill abgebrochen: Werbemittel ohne explizite Kategorie-Zuordnung: '.$details
            .'. Bekannte Codes: '
            .implode(', ', array_keys(self::MEDIUM_CODE_TO_CATEGORY_KEY))
            .'. Keine pauschale Default-Kategorie.',
        );
    }

    private function enforceCategoryIdNotNullAndForeignKey(): void
    {
        Schema::disableForeignKeyConstraints();
        try {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                if (! $this->sqliteColumnIsNotNull('advertising_media', 'category_id')) {
                    Schema::table('advertising_media', function (Blueprint $table) {
                        $table->unsignedBigInteger('category_id')->nullable(false)->change();
                    });
                }

                if (! $this->sqliteForeignKeyExists('advertising_media', 'category_id')) {
                    Schema::table('advertising_media', function (Blueprint $table) {
                        $table->foreign('category_id', 'advertising_media_category_id_fk')
                            ->references('id')
                            ->on('advertising_categories')
                            ->restrictOnDelete();
                    });
                }
            } else {
                if (! $this->foreignKeyExists('advertising_media', 'advertising_media_category_id_fk')) {
                    Schema::table('advertising_media', function (Blueprint $table) {
                        $table->foreign('category_id', 'advertising_media_category_id_fk')
                            ->references('id')
                            ->on('advertising_categories')
                            ->restrictOnDelete();
                    });
                }

                DB::statement('ALTER TABLE advertising_media MODIFY category_id BIGINT UNSIGNED NOT NULL');
            }
        } finally {
            Schema::enableForeignKeyConstraints();
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

    private function foreignKeyExists(string $table, string $name): bool
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return false;
        }

        $database = Schema::getConnection()->getDatabaseName();
        $row = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->first();

        return $row !== null;
    }
};
