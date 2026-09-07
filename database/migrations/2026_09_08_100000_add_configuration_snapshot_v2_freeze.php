<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DF-3.3a2α / VER-002: format_version, v2-Quellengraph, Property-Provenance.
 *
 * Generation 1 = Legacy/Core; Generation 2 = Core+global Freeze.
 * Keine Kat-/Mediumquellen, kein VER-003.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addFormatVersion();
        $this->addSchemaFingerprint();
        $this->createSourceTables();
        $this->addProvenanceColumns();
        $this->addCompositeProvenanceForeignKeys();
    }

    public function down(): void
    {
        $this->dropCompositeProvenanceForeignKeys();

        Schema::table('snapshot_field_rules', function (Blueprint $table) {
            if (Schema::hasColumn('snapshot_field_rules', 'provenance_source_id')) {
                $table->dropColumn(['provenance_source_id', 'dedupe_key']);
            }
        });

        Schema::table('snapshot_field_definitions', function (Blueprint $table) {
            foreach ([
                'provenance_definition_source_id',
                'provenance_revision_source_id',
                'provenance_required_source_id',
                'provenance_visible_source_id',
                'provenance_sort_source_id',
                'provenance_group_source_id',
            ] as $column) {
                if (Schema::hasColumn('snapshot_field_definitions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('configuration_snapshot_source_rules');
        Schema::dropIfExists('configuration_snapshot_source_fields');
        Schema::dropIfExists('configuration_snapshot_sources');

        if (Schema::hasColumn('configuration_snapshots', 'schema_fingerprint')) {
            Schema::table('configuration_snapshots', function (Blueprint $table) {
                $table->dropColumn('schema_fingerprint');
            });
        }

        if (Schema::hasColumn('configuration_snapshots', 'format_version')) {
            Schema::table('configuration_snapshots', function (Blueprint $table) {
                $table->dropColumn('format_version');
            });
        }
    }

    private function addFormatVersion(): void
    {
        if (! Schema::hasColumn('configuration_snapshots', 'format_version')) {
            Schema::table('configuration_snapshots', function (Blueprint $table) {
                $table->unsignedSmallInteger('format_version')->nullable();
            });
        }

        DB::table('configuration_snapshots')
            ->whereNull('format_version')
            ->update(['format_version' => 1]);

        $nulls = DB::table('configuration_snapshots')->whereNull('format_version')->count();
        if ($nulls > 0) {
            throw new RuntimeException(
                "DF-3.3a2α Migration: {$nulls} configuration_snapshots ohne format_version nach Backfill.",
            );
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            Schema::disableForeignKeyConstraints();
            try {
                if (! $this->sqliteColumnIsNotNull('configuration_snapshots', 'format_version')) {
                    Schema::table('configuration_snapshots', function (Blueprint $table) {
                        $table->unsignedSmallInteger('format_version')->nullable(false)->change();
                    });
                }
            } finally {
                Schema::enableForeignKeyConstraints();
            }

            if (! $this->sqliteColumnIsNotNull('configuration_snapshots', 'format_version')) {
                throw new RuntimeException(
                    'DF-3.3a2α Migration: format_version ist unter SQLite nicht NOT NULL.',
                );
            }

            return;
        }

        // MySQL: NOT NULL ohne DEFAULT – Inserts müssen die Version explizit setzen.
        DB::statement('ALTER TABLE configuration_snapshots MODIFY format_version SMALLINT UNSIGNED NOT NULL');
    }

    private function addSchemaFingerprint(): void
    {
        if (Schema::hasColumn('configuration_snapshots', 'schema_fingerprint')) {
            return;
        }

        Schema::table('configuration_snapshots', function (Blueprint $table) {
            $table->string('schema_fingerprint', 64)->nullable();
        });
    }

    private function createSourceTables(): void
    {
        if (! Schema::hasTable('configuration_snapshot_sources')) {
            Schema::create('configuration_snapshot_sources', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('configuration_snapshot_id');
                $table->unsignedInteger('merge_order');
                $table->string('layer', 32);
                $table->string('role', 16);
                $table->unsignedBigInteger('field_set_id');
                $table->string('field_set_key', 64);
                $table->string('field_set_name');
                $table->unsignedBigInteger('field_set_version_id');
                $table->unsignedInteger('field_set_version_number');
                $table->unsignedBigInteger('field_set_assignment_id')->nullable();
                $table->unsignedInteger('assignment_lock_version')->nullable();
                $table->string('assignment_applies_to_process', 32)->nullable();
                $table->unsignedInteger('assignment_sort')->nullable();
                $table->boolean('assignment_is_active')->nullable();
                $table->string('target_layer', 32);
                $table->string('target_identity', 64);
                $table->unsignedBigInteger('target_id')->nullable();
                $table->string('target_key', 64)->nullable();
                $table->string('target_name')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(
                    ['configuration_snapshot_id', 'merge_order'],
                    'cfg_snap_source_merge_order_unique',
                );
                $table->unique(
                    ['configuration_snapshot_id', 'id'],
                    'cfg_snap_source_snapshot_id_unique',
                );

                $table->foreign('configuration_snapshot_id', 'cfg_snap_src_snap_fk')
                    ->references('id')->on('configuration_snapshots')->cascadeOnDelete();
                $table->foreign('field_set_id', 'cfg_snap_src_fs_fk')
                    ->references('id')->on('field_sets')->restrictOnDelete();
                $table->foreign('field_set_version_id', 'cfg_snap_src_fsv_fk')
                    ->references('id')->on('field_set_versions')->restrictOnDelete();
                $table->foreign('field_set_assignment_id', 'cfg_snap_src_asg_fk')
                    ->references('id')->on('field_set_assignments')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('configuration_snapshot_source_fields')) {
            Schema::create('configuration_snapshot_source_fields', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('configuration_snapshot_source_id');
                $table->unsignedBigInteger('field_definition_id');
                $table->unsignedBigInteger('field_definition_revision_id');
                $table->string('field_key', 64);
                $table->string('field_type', 32);
                $table->string('scope', 16);
                $table->string('applies_to', 32);
                $table->string('label');
                $table->text('help_text')->nullable();
                $table->string('group_key', 64)->nullable();
                $table->unsignedInteger('membership_sort')->default(0);
                $table->boolean('required_override')->nullable();
                $table->boolean('visible_override')->nullable();
                $table->json('validation_json')->nullable();
                $table->boolean('reportable')->default(true);
                $table->boolean('definition_is_system')->default(false);
                $table->boolean('definition_is_active')->default(true);

                $table->unique(
                    ['configuration_snapshot_source_id', 'field_definition_id'],
                    'cfg_snap_src_fld_def_unique',
                );

                $table->foreign('configuration_snapshot_source_id', 'cfg_snap_src_fld_src_fk')
                    ->references('id')->on('configuration_snapshot_sources')->cascadeOnDelete();
                $table->foreign('field_definition_id', 'cfg_snap_src_fld_def_fk')
                    ->references('id')->on('field_definitions')->restrictOnDelete();
                $table->foreign('field_definition_revision_id', 'cfg_snap_src_fld_rev_fk')
                    ->references('id')->on('field_definition_revisions')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('configuration_snapshot_source_rules')) {
            Schema::create('configuration_snapshot_source_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('configuration_snapshot_source_id');
                $table->unsignedBigInteger('source_field_rule_id')->nullable();
                $table->unsignedInteger('sort')->default(0);
                $table->json('condition_json');
                $table->json('action_json');
                $table->string('dedupe_key', 64);

                $table->foreign('configuration_snapshot_source_id', 'cfg_snap_src_rule_src_fk')
                    ->references('id')->on('configuration_snapshot_sources')->cascadeOnDelete();
            });
        }
    }

    private function addProvenanceColumns(): void
    {
        Schema::table('snapshot_field_definitions', function (Blueprint $table) {
            if (! Schema::hasColumn('snapshot_field_definitions', 'provenance_definition_source_id')) {
                $table->unsignedBigInteger('provenance_definition_source_id')->nullable();
            }
            if (! Schema::hasColumn('snapshot_field_definitions', 'provenance_revision_source_id')) {
                $table->unsignedBigInteger('provenance_revision_source_id')->nullable();
            }
            if (! Schema::hasColumn('snapshot_field_definitions', 'provenance_required_source_id')) {
                $table->unsignedBigInteger('provenance_required_source_id')->nullable();
            }
            if (! Schema::hasColumn('snapshot_field_definitions', 'provenance_visible_source_id')) {
                $table->unsignedBigInteger('provenance_visible_source_id')->nullable();
            }
            if (! Schema::hasColumn('snapshot_field_definitions', 'provenance_sort_source_id')) {
                $table->unsignedBigInteger('provenance_sort_source_id')->nullable();
            }
            if (! Schema::hasColumn('snapshot_field_definitions', 'provenance_group_source_id')) {
                $table->unsignedBigInteger('provenance_group_source_id')->nullable();
            }
        });

        Schema::table('snapshot_field_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('snapshot_field_rules', 'provenance_source_id')) {
                $table->unsignedBigInteger('provenance_source_id')->nullable();
            }
            if (! Schema::hasColumn('snapshot_field_rules', 'dedupe_key')) {
                $table->string('dedupe_key', 64)->nullable();
            }
        });
    }

    /**
     * @return list<array{table: string, columns: list<string>, name: string}>
     */
    private function compositeProvenanceForeignKeys(): array
    {
        $definitions = [];

        foreach ([
            'provenance_definition_source_id',
            'provenance_revision_source_id',
            'provenance_required_source_id',
            'provenance_visible_source_id',
            'provenance_sort_source_id',
            'provenance_group_source_id',
        ] as $column) {
            $definitions[] = [
                'table' => 'snapshot_field_definitions',
                'columns' => ['configuration_snapshot_id', $column],
                'name' => 'sfd_'.str_replace('provenance_', 'p_', str_replace('_source_id', '', $column)).'_fk',
            ];
        }

        $definitions[] = [
            'table' => 'snapshot_field_rules',
            'columns' => ['configuration_snapshot_id', 'provenance_source_id'],
            'name' => 'sfr_provenance_source_fk',
        ];

        return $definitions;
    }

    private function addCompositeProvenanceForeignKeys(): void
    {
        foreach ($this->compositeProvenanceForeignKeys() as $definition) {
            $this->addCompositeFk(
                $definition['table'],
                $definition['columns'],
                'configuration_snapshot_sources',
                ['configuration_snapshot_id', 'id'],
                $definition['name'],
            );
        }
    }

    /**
     * @param  list<string>  $localColumns
     * @param  list<string>  $foreignColumns
     */
    private function addCompositeFk(
        string $table,
        array $localColumns,
        string $foreignTable,
        array $foreignColumns,
        string $name,
    ): void {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            if ($this->sqliteForeignKeyExists($table, $localColumns)) {
                return;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($localColumns, $foreignTable, $foreignColumns, $name): void {
                $blueprint->foreign($localColumns, $name)
                    ->references($foreignColumns)
                    ->on($foreignTable)
                    ->restrictOnDelete();
            });

            return;
        }

        if ($this->foreignKeyExists($table, $name)) {
            return;
        }

        $local = implode(', ', $localColumns);
        $foreign = implode(', ', $foreignColumns);
        DB::statement(
            "ALTER TABLE {$table} ADD CONSTRAINT {$name} FOREIGN KEY ({$local}) ".
            "REFERENCES {$foreignTable} ({$foreign}) ON DELETE RESTRICT",
        );
    }

    private function dropCompositeProvenanceForeignKeys(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        foreach ($this->compositeProvenanceForeignKeys() as $definition) {
            $table = $definition['table'];
            $columns = $definition['columns'];

            if (! Schema::hasTable($table)) {
                continue;
            }

            // SQLite kennt keine benannten Constraints in ALTER TABLE; dort wird
            // über die Spaltenliste identifiziert und die Tabelle neu aufgebaut.
            if ($driver === 'sqlite') {
                if (! $this->sqliteForeignKeyExists($table, $columns)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                    $blueprint->dropForeign($columns);
                });

                continue;
            }

            if (! $this->foreignKeyExists($table, $definition['name'])) {
                continue;
            }

            DB::statement("ALTER TABLE {$table} DROP FOREIGN KEY {$definition['name']}");
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function sqliteForeignKeyExists(string $table, array $columns): bool
    {
        $grouped = [];
        foreach (DB::select("PRAGMA foreign_key_list({$table})") as $row) {
            $grouped[(int) ($row->id ?? 0)][] = (string) ($row->from ?? '');
        }

        foreach ($grouped as $from) {
            sort($from);
            $expected = $columns;
            sort($expected);
            if ($from === $expected) {
                return true;
            }
        }

        return false;
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

    private function foreignKeyExists(string $table, string $name): bool
    {
        $database = Schema::getConnection()->getDatabaseName();
        $count = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->count();

        return $count > 0;
    }
};
