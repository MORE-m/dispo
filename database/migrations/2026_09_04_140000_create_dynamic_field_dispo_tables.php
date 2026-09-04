<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DF-2: Dispo-Config-Snapshot-Compose, Dispo-Wertetabellen, system_dispo_order_core.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('configuration_snapshots', 'source_configuration_snapshot_id')) {
            Schema::table('configuration_snapshots', function (Blueprint $table) {
                $table->unsignedBigInteger('source_configuration_snapshot_id')->nullable()->after('source');
                $table->index('source_configuration_snapshot_id', 'cfg_snap_source_cfg_snap_idx');
            });
        }
        if (! $this->foreignKeyExists('configuration_snapshots', 'cfg_snap_source_cfg_snap_fk')) {
            Schema::table('configuration_snapshots', function (Blueprint $table) {
                $table->foreign('source_configuration_snapshot_id', 'cfg_snap_source_cfg_snap_fk')
                    ->references('id')
                    ->on('configuration_snapshots')
                    ->restrictOnDelete();
            });
        }

        Schema::disableForeignKeyConstraints();
        if (! Schema::hasColumn('dispo_orders', 'configuration_snapshot_id')) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                DB::statement('ALTER TABLE dispo_orders ADD COLUMN configuration_snapshot_id INTEGER NULL');
            } else {
                Schema::table('dispo_orders', function (Blueprint $table) {
                    $table->unsignedBigInteger('configuration_snapshot_id')->nullable()->after('lock_version');
                });
            }
        }
        Schema::enableForeignKeyConstraints();

        if (! Schema::hasTable('dispo_order_field_values')) {
            Schema::create('dispo_order_field_values', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('dispo_order_id');
                $table->unsignedBigInteger('snapshot_field_definition_id');
                $table->mediumText('value_text')->nullable();
                $table->date('value_period_start')->nullable();
                $table->date('value_period_end')->nullable();
                $table->timestamps();

                $table->foreign('dispo_order_id', 'dispo_ord_field_val_ord_fk')
                    ->references('id')->on('dispo_orders')->cascadeOnDelete();
                $table->foreign('snapshot_field_definition_id', 'dispo_ord_field_val_snap_fk')
                    ->references('id')->on('snapshot_field_definitions')->restrictOnDelete();
                $table->unique(
                    ['dispo_order_id', 'snapshot_field_definition_id'],
                    'dispo_ord_field_value_unique',
                );
            });
        }

        if (! Schema::hasTable('dispo_order_position_field_values')) {
            Schema::create('dispo_order_position_field_values', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('dispo_order_position_id');
                $table->unsignedBigInteger('snapshot_field_definition_id');
                $table->boolean('value_boolean')->nullable();
                $table->date('value_period_start')->nullable();
                $table->date('value_period_end')->nullable();
                $table->timestamps();

                $table->foreign('dispo_order_position_id', 'dispo_pos_field_val_pos_fk')
                    ->references('id')->on('dispo_order_positions')->cascadeOnDelete();
                $table->foreign('snapshot_field_definition_id', 'dispo_pos_field_val_snap_fk')
                    ->references('id')->on('snapshot_field_definitions')->restrictOnDelete();
                $table->unique(
                    ['dispo_order_position_id', 'snapshot_field_definition_id'],
                    'dispo_pos_field_value_unique',
                );
            });
        }

        if (! DB::table('field_sets')->where('key', 'system_dispo_order_core')->exists()) {
            $this->seedSystemDispoOrderCore();
        }
        $this->backfillExistingDispoOrders();

        if (DB::table('dispo_orders')->whereNull('configuration_snapshot_id')->exists()) {
            throw new RuntimeException('DF-2 Backfill: dispo_orders.configuration_snapshot_id bleibt NULL.');
        }

        $this->enforceConfigurationSnapshotNotNull();
    }

    private function enforceConfigurationSnapshotNotNull(): void
    {
        Schema::disableForeignKeyConstraints();

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            if (! $this->sqliteColumnIsNotNull('dispo_orders', 'configuration_snapshot_id')) {
                Schema::table('dispo_orders', function (Blueprint $table) {
                    $table->unsignedBigInteger('configuration_snapshot_id')->nullable(false)->change();
                });
            }

            DB::statement('CREATE INDEX IF NOT EXISTS dispo_orders_configuration_snapshot_id_index ON dispo_orders (configuration_snapshot_id)');

            if (! $this->sqliteForeignKeyExists('dispo_orders', 'configuration_snapshot_id')) {
                Schema::table('dispo_orders', function (Blueprint $table) {
                    $table->foreign('configuration_snapshot_id', 'dispo_orders_cfg_snap_fk')
                        ->references('id')
                        ->on('configuration_snapshots')
                        ->restrictOnDelete();
                });
            }
        } else {
            if (! $this->foreignKeyExists('dispo_orders', 'dispo_orders_cfg_snap_fk')) {
                Schema::table('dispo_orders', function (Blueprint $table) {
                    $table->foreign('configuration_snapshot_id', 'dispo_orders_cfg_snap_fk')
                        ->references('id')
                        ->on('configuration_snapshots')
                        ->restrictOnDelete();
                    $table->index('configuration_snapshot_id', 'dispo_orders_cfg_snap_idx');
                });
            }

            DB::statement('ALTER TABLE dispo_orders MODIFY configuration_snapshot_id BIGINT UNSIGNED NOT NULL');
        }

        Schema::enableForeignKeyConstraints();
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

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('dispo_orders', function (Blueprint $table) {
                $table->dropForeign('dispo_orders_cfg_snap_fk');
            });
        }

        Schema::dropIfExists('dispo_order_position_field_values');
        Schema::dropIfExists('dispo_order_field_values');

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('dispo_orders', function (Blueprint $table) {
                $table->dropColumn('configuration_snapshot_id');
            });

            Schema::table('configuration_snapshots', function (Blueprint $table) {
                $table->dropForeign('cfg_snap_source_cfg_snap_fk');
                $table->dropIndex('cfg_snap_source_cfg_snap_idx');
                $table->dropColumn('source_configuration_snapshot_id');
            });
        } elseif (Schema::hasColumn('dispo_orders', 'configuration_snapshot_id')) {
            // SQLite: Spalte bleibt für Idempotenz; wieder nullable für Legacy-Inserts vor Up.
            Schema::table('dispo_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('configuration_snapshot_id')->nullable()->change();
            });
        }
        // SQLite: Spalten bleiben stehen; Up ist idempotent über hasColumn/hasTable.
        $set = DB::table('field_sets')->where('key', 'system_dispo_order_core')->first();
        if ($set !== null) {
            $versionIds = DB::table('field_set_versions')
                ->where('field_set_id', $set->id)
                ->pluck('id');
            DB::table('field_rules')->whereIn('field_set_version_id', $versionIds)->delete();
            DB::table('field_set_version_fields')->whereIn('field_set_version_id', $versionIds)->delete();
            DB::table('field_sets')->where('id', $set->id)->update(['active_version_id' => null]);
            DB::table('field_set_versions')->where('field_set_id', $set->id)->delete();
            DB::table('field_sets')->where('id', $set->id)->delete();
        }

        foreach (['billing_special_features', 'disposition_notes'] as $key) {
            $def = DB::table('field_definitions')->where('key', $key)->first();
            if ($def === null) {
                continue;
            }
            DB::table('field_definitions')->where('id', $def->id)->update(['current_revision_id' => null]);
            DB::table('field_definition_revisions')->where('field_definition_id', $def->id)->delete();
            DB::table('field_definitions')->where('id', $def->id)->delete();
        }

        Schema::enableForeignKeyConstraints();
    }

    private function seedSystemDispoOrderCore(): void
    {
        $now = now();

        if (DB::table('field_sets')->where('key', 'system_dispo_order_core')->exists()) {
            throw new RuntimeException('Seed field set system_dispo_order_core existiert bereits inkonsistent.');
        }

        foreach (['billing_special_features', 'disposition_notes'] as $key) {
            if (DB::table('field_definitions')->where('key', $key)->exists()) {
                throw new RuntimeException("Seed-Definition {$key} existiert bereits inkonsistent.");
            }
        }

        $fields = [
            [
                'key' => 'billing_special_features',
                'label' => 'Besonderheiten zur Rechnungsstellung',
                'help_text' => 'Freitext für Hinweise zur Rechnungsstellung im Dispoauftrag.',
                'sort' => 10,
            ],
            [
                'key' => 'disposition_notes',
                'label' => 'Wichtige Informationen an die Disposition',
                'help_text' => 'Freitext für operative Hinweise an die Disposition.',
                'sort' => 20,
            ],
        ];

        $membershipByKey = [];

        foreach ($fields as $field) {
            $definitionId = DB::table('field_definitions')->insertGetId([
                'key' => $field['key'],
                'field_type' => 'long_text',
                'is_system' => true,
                'is_key_protected' => true,
                'scope' => 'header',
                'applies_to' => 'dispo_order',
                'current_revision_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $revisionId = DB::table('field_definition_revisions')->insertGetId([
                'field_definition_id' => $definitionId,
                'revision' => 1,
                'label' => $field['label'],
                'help_text' => $field['help_text'],
                'validation_json' => null,
                'group_key' => 'dispo_notes',
                'sort_default' => $field['sort'],
                'reportable' => true,
                'created_at' => $now,
            ]);

            DB::table('field_definitions')->where('id', $definitionId)->update([
                'current_revision_id' => $revisionId,
            ]);

            $membershipByKey[$field['key']] = [
                'definition_id' => $definitionId,
                'revision_id' => $revisionId,
                'sort' => $field['sort'],
            ];
        }

        $setId = DB::table('field_sets')->insertGetId([
            'key' => 'system_dispo_order_core',
            'name' => 'System – Dispoauftrag-Kern',
            'active_version_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $versionId = DB::table('field_set_versions')->insertGetId([
            'field_set_id' => $setId,
            'version' => 1,
            'status' => 'active',
            'created_at' => $now,
        ]);

        DB::table('field_sets')->where('id', $setId)->update([
            'active_version_id' => $versionId,
        ]);

        foreach ($membershipByKey as $membership) {
            DB::table('field_set_version_fields')->insert([
                'field_set_version_id' => $versionId,
                'field_definition_id' => $membership['definition_id'],
                'field_definition_revision_id' => $membership['revision_id'],
                'sort' => $membership['sort'],
                'required_override' => null,
                'visible_override' => null,
            ]);
        }
    }

    private function backfillExistingDispoOrders(): void
    {
        $dispoSet = DB::table('field_sets')->where('key', 'system_dispo_order_core')->first();
        if ($dispoSet === null || $dispoSet->active_version_id === null) {
            throw new RuntimeException('Seed field set system_dispo_order_core fehlt.');
        }

        $orders = DB::table('dispo_orders')
            ->whereNull('configuration_snapshot_id')
            ->orderBy('id')
            ->get(['id', 'calculation_id']);

        foreach ($orders as $order) {
            $calc = DB::table('calculations')->where('id', $order->calculation_id)->first();
            if ($calc === null) {
                throw new RuntimeException(
                    "DF-2 Backfill: Dispoauftrag {$order->id} ohne Kalkulation.",
                );
            }
            if ($calc->configuration_snapshot_id === null) {
                throw new RuntimeException(
                    "DF-2 Backfill: Kalkulation {$calc->id} ohne configuration_snapshot_id.",
                );
            }

            $snapshotId = $this->composeSnapshot(
                (int) $dispoSet->id,
                (int) $dispoSet->active_version_id,
                (int) $calc->configuration_snapshot_id,
                'dispo_order_legacy_backfill',
            );

            DB::table('dispo_orders')->where('id', $order->id)->update([
                'configuration_snapshot_id' => $snapshotId,
            ]);
        }
    }

    /**
     * @return int Snapshot-ID
     */
    private function composeSnapshot(
        int $dispoFieldSetId,
        int $dispoFieldSetVersionId,
        int $sourceCalcSnapshotId,
        string $source,
    ): int {
        $now = now();

        $calcKeys = ['campaign_period', 'period_open', 'position_flight_period'];
        $calcDefs = DB::table('snapshot_field_definitions')
            ->where('configuration_snapshot_id', $sourceCalcSnapshotId)
            ->whereIn('key', $calcKeys)
            ->get()
            ->keyBy('key');

        foreach ($calcKeys as $key) {
            if (! $calcDefs->has($key)) {
                throw new RuntimeException(
                    "DF-2 Compose: Calc-Snapshot {$sourceCalcSnapshotId} fehlt Definition {$key}.",
                );
            }
        }

        $memberships = DB::table('field_set_version_fields as m')
            ->join('field_definitions as d', 'd.id', '=', 'm.field_definition_id')
            ->join('field_definition_revisions as r', 'r.id', '=', 'm.field_definition_revision_id')
            ->where('m.field_set_version_id', $dispoFieldSetVersionId)
            ->orderBy('m.sort')
            ->get([
                'm.sort',
                'd.id as definition_id',
                'd.key',
                'd.field_type',
                'd.scope',
                'd.applies_to',
                'r.id as revision_id',
                'r.label',
                'r.help_text',
                'r.validation_json',
                'r.group_key',
                'r.reportable',
            ]);

        if ($memberships->count() !== 2) {
            throw new RuntimeException('DF-2 Compose: system_dispo_order_core muss genau zwei Memberships haben.');
        }

        $seenKeys = [];
        foreach ($calcDefs as $def) {
            $seenKeys[$def->key] = true;
        }
        foreach ($memberships as $membership) {
            if (isset($seenKeys[$membership->key])) {
                throw new RuntimeException(
                    "DF-2 Compose: doppelter Schlüssel {$membership->key}.",
                );
            }
            $seenKeys[$membership->key] = true;
        }

        $snapshotId = DB::table('configuration_snapshots')->insertGetId([
            'field_set_id' => $dispoFieldSetId,
            'field_set_version_id' => $dispoFieldSetVersionId,
            'source' => $source,
            'source_configuration_snapshot_id' => $sourceCalcSnapshotId,
            'created_at' => $now,
        ]);

        foreach ($calcKeys as $key) {
            $def = $calcDefs->get($key);
            DB::table('snapshot_field_definitions')->insert([
                'configuration_snapshot_id' => $snapshotId,
                'field_definition_id' => $def->field_definition_id,
                'field_definition_revision_id' => $def->field_definition_revision_id,
                'key' => $def->key,
                'field_type' => $def->field_type,
                'label' => $def->label,
                'help_text' => $def->help_text,
                'scope' => $def->scope,
                'applies_to' => $def->applies_to,
                'sort' => $def->sort,
                'group_key' => $def->group_key,
                'reportable' => $def->reportable,
                'validation_json' => $def->validation_json,
            ]);
        }

        $calcRules = DB::table('snapshot_field_rules')
            ->where('configuration_snapshot_id', $sourceCalcSnapshotId)
            ->orderBy('sort')
            ->get();

        foreach ($calcRules as $rule) {
            $condition = json_decode((string) $rule->condition_json, true, 512, JSON_THROW_ON_ERROR);
            $action = json_decode((string) $rule->action_json, true, 512, JSON_THROW_ON_ERROR);
            $conditionKey = (string) ($condition['field_key'] ?? '');
            $actionKey = (string) ($action['field_key'] ?? '');
            if (! isset($seenKeys[$conditionKey]) && $conditionKey !== '') {
                continue;
            }
            if ($actionKey !== '' && ! isset($seenKeys[$actionKey])) {
                continue;
            }

            DB::table('snapshot_field_rules')->insert([
                'configuration_snapshot_id' => $snapshotId,
                'source_field_rule_id' => $rule->source_field_rule_id,
                'sort' => $rule->sort,
                'condition_json' => $rule->condition_json,
                'action_json' => $rule->action_json,
            ]);
        }

        foreach ($memberships as $membership) {
            DB::table('snapshot_field_definitions')->insert([
                'configuration_snapshot_id' => $snapshotId,
                'field_definition_id' => $membership->definition_id,
                'field_definition_revision_id' => $membership->revision_id,
                'key' => $membership->key,
                'field_type' => $membership->field_type,
                'label' => $membership->label,
                'help_text' => $membership->help_text,
                'scope' => $membership->scope,
                'applies_to' => $membership->applies_to,
                'sort' => $membership->sort,
                'group_key' => $membership->group_key,
                'reportable' => (bool) $membership->reportable,
                'validation_json' => $membership->validation_json,
            ]);
        }

        return $snapshotId;
    }
};
