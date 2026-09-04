<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DF-1: geschützte Systemfelddefinitionen, Config-Snapshots, Kalkulationswerte.
 * DYN-001, VER-001, VER-002, VER-003, VER-007, DYN-004, DYN-006, PRI-003.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('field_type', 32);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_key_protected')->default(false);
            $table->string('scope', 16);
            $table->string('applies_to', 32);
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->timestamps();
        });

        Schema::create('field_definition_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_definition_id')->constrained('field_definitions')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('label');
            $table->text('help_text')->nullable();
            $table->json('validation_json')->nullable();
            $table->string('group_key', 64)->nullable();
            $table->unsignedInteger('sort_default')->default(0);
            $table->boolean('reportable')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['field_definition_id', 'revision'], 'field_def_rev_unique');
        });

        Schema::table('field_definitions', function (Blueprint $table) {
            $table->foreign('current_revision_id')
                ->references('id')
                ->on('field_definition_revisions')
                ->nullOnDelete();
        });

        Schema::create('field_sets', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->unsignedBigInteger('active_version_id')->nullable();
            $table->timestamps();
        });

        Schema::create('field_set_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_set_id')->constrained('field_sets')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['field_set_id', 'version'], 'field_set_version_unique');
        });

        Schema::table('field_sets', function (Blueprint $table) {
            $table->foreign('active_version_id')
                ->references('id')
                ->on('field_set_versions')
                ->nullOnDelete();
        });

        Schema::create('field_set_version_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_set_version_id')->constrained('field_set_versions')->cascadeOnDelete();
            $table->foreignId('field_definition_revision_id')->constrained('field_definition_revisions')->restrictOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('required_override')->nullable();
            $table->boolean('visible_override')->nullable();

            $table->unique(
                ['field_set_version_id', 'field_definition_revision_id'],
                'field_set_version_field_unique',
            );
        });

        Schema::create('field_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_set_version_id')->constrained('field_set_versions')->cascadeOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->json('condition_json');
            $table->json('action_json');
        });

        Schema::create('configuration_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_set_id')->constrained('field_sets')->restrictOnDelete();
            $table->foreignId('field_set_version_id')->constrained('field_set_versions')->restrictOnDelete();
            $table->string('source', 32);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('snapshot_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('configuration_snapshot_id')->constrained('configuration_snapshots')->cascadeOnDelete();
            $table->foreignId('field_definition_id')->constrained('field_definitions')->restrictOnDelete();
            $table->foreignId('field_definition_revision_id')->constrained('field_definition_revisions')->restrictOnDelete();
            $table->string('key', 64);
            $table->string('field_type', 32);
            $table->string('label');
            $table->text('help_text')->nullable();
            $table->string('scope', 16);
            $table->string('applies_to', 32);
            $table->unsignedInteger('sort')->default(0);
            $table->string('group_key', 64)->nullable();
            $table->boolean('reportable')->default(true);
            $table->json('validation_json')->nullable();

            $table->unique(['configuration_snapshot_id', 'key'], 'snapshot_field_def_key_unique');
        });

        Schema::create('snapshot_field_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('configuration_snapshot_id')->constrained('configuration_snapshots')->cascadeOnDelete();
            $table->unsignedBigInteger('source_field_rule_id')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->json('condition_json');
            $table->json('action_json');
        });

        Schema::table('calculations', function (Blueprint $table) {
            $table->foreignId('configuration_snapshot_id')
                ->nullable()
                ->after('lock_version')
                ->constrained('configuration_snapshots')
                ->restrictOnDelete();
        });

        Schema::create('calculation_field_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('calculation_id');
            $table->unsignedBigInteger('snapshot_field_definition_id');
            $table->string('value_string', 255)->nullable();
            $table->text('value_text')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->date('value_period_start')->nullable();
            $table->date('value_period_end')->nullable();
            $table->timestamps();

            $table->foreign('calculation_id', 'calc_field_val_calc_fk')
                ->references('id')->on('calculations')->cascadeOnDelete();
            $table->foreign('snapshot_field_definition_id', 'calc_field_val_snap_fk')
                ->references('id')->on('snapshot_field_definitions')->restrictOnDelete();
            $table->unique(
                ['calculation_id', 'snapshot_field_definition_id'],
                'calc_field_value_unique',
            );
        });

        Schema::create('calculation_position_field_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('calculation_position_id');
            $table->unsignedBigInteger('snapshot_field_definition_id');
            $table->string('value_string', 255)->nullable();
            $table->text('value_text')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->date('value_period_start')->nullable();
            $table->date('value_period_end')->nullable();
            $table->timestamps();

            $table->foreign('calculation_position_id', 'calc_pos_field_val_pos_fk')
                ->references('id')->on('calculation_positions')->cascadeOnDelete();
            $table->foreign('snapshot_field_definition_id', 'calc_pos_field_val_snap_fk')
                ->references('id')->on('snapshot_field_definitions')->restrictOnDelete();
            $table->unique(
                ['calculation_position_id', 'snapshot_field_definition_id'],
                'calc_pos_field_value_unique',
            );
        });

        $this->seedSystemCalculationCore();
        $this->backfillExistingCalculations();
    }

    public function down(): void
    {
        Schema::dropIfExists('calculation_position_field_values');
        Schema::dropIfExists('calculation_field_values');

        Schema::table('calculations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('configuration_snapshot_id');
        });

        Schema::dropIfExists('snapshot_field_rules');
        Schema::dropIfExists('snapshot_field_definitions');
        Schema::dropIfExists('configuration_snapshots');
        Schema::dropIfExists('field_rules');
        Schema::dropIfExists('field_set_version_fields');

        Schema::table('field_sets', function (Blueprint $table) {
            $table->dropForeign(['active_version_id']);
        });
        Schema::dropIfExists('field_set_versions');
        Schema::dropIfExists('field_sets');

        Schema::table('field_definitions', function (Blueprint $table) {
            $table->dropForeign(['current_revision_id']);
        });
        Schema::dropIfExists('field_definition_revisions');
        Schema::dropIfExists('field_definitions');
    }

    private function seedSystemCalculationCore(): void
    {
        $now = now();

        $fields = [
            [
                'key' => 'campaign_period',
                'field_type' => 'period',
                'scope' => 'header',
                'applies_to' => 'both',
                'label' => 'Kampagnenzeitraum',
                'help_text' => 'Optionaler Zeitraum der Gesamtkampagne. Die Kalkulation kann ohne Zeitraum gespeichert werden.',
                'sort' => 10,
                'group_key' => 'header',
            ],
            [
                'key' => 'period_open',
                'field_type' => 'boolean',
                'scope' => 'position',
                'applies_to' => 'both',
                'label' => 'Zeitraum offen',
                'help_text' => 'Wenn aktiv, ist kein konkreter Flugzeitraum der Position erforderlich.',
                'sort' => 20,
                'group_key' => 'position_period',
            ],
            [
                'key' => 'position_flight_period',
                'field_type' => 'period',
                'scope' => 'position',
                'applies_to' => 'both',
                'label' => 'Flugzeitraum',
                'help_text' => 'Buchungs- bzw. Ausstrahlungszeitraum der Position. Pflicht, wenn „Zeitraum offen“ deaktiviert ist.',
                'sort' => 30,
                'group_key' => 'position_period',
            ],
        ];

        $revisionIdsByKey = [];

        foreach ($fields as $field) {
            $definitionId = DB::table('field_definitions')->insertGetId([
                'key' => $field['key'],
                'field_type' => $field['field_type'],
                'is_system' => true,
                'is_key_protected' => true,
                'scope' => $field['scope'],
                'applies_to' => $field['applies_to'],
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
                'group_key' => $field['group_key'],
                'sort_default' => $field['sort'],
                'reportable' => true,
                'created_at' => $now,
            ]);

            DB::table('field_definitions')->where('id', $definitionId)->update([
                'current_revision_id' => $revisionId,
            ]);

            $revisionIdsByKey[$field['key']] = $revisionId;
        }

        $setId = DB::table('field_sets')->insertGetId([
            'key' => 'system_calculation_core',
            'name' => 'System – Kalkulationskern',
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

        $sort = 0;
        foreach ($revisionIdsByKey as $revisionId) {
            DB::table('field_set_version_fields')->insert([
                'field_set_version_id' => $versionId,
                'field_definition_revision_id' => $revisionId,
                'sort' => $sort,
                'required_override' => null,
                'visible_override' => null,
            ]);
            $sort += 10;
        }

        DB::table('field_rules')->insert([
            'field_set_version_id' => $versionId,
            'sort' => 0,
            'condition_json' => json_encode([
                'op' => 'field_equals',
                'field_key' => 'period_open',
                'value' => false,
            ], JSON_THROW_ON_ERROR),
            'action_json' => json_encode([
                'op' => 'require_field',
                'field_key' => 'position_flight_period',
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    private function backfillExistingCalculations(): void
    {
        $set = DB::table('field_sets')->where('key', 'system_calculation_core')->first();
        if ($set === null || $set->active_version_id === null) {
            throw new RuntimeException('Seed field set system_calculation_core fehlt.');
        }

        $snapshotId = $this->materializeSnapshot(
            (int) $set->id,
            (int) $set->active_version_id,
            'legacy_backfill',
        );

        DB::table('calculations')
            ->whereNull('configuration_snapshot_id')
            ->update(['configuration_snapshot_id' => $snapshotId]);

        $periodOpenDef = DB::table('snapshot_field_definitions')
            ->where('configuration_snapshot_id', $snapshotId)
            ->where('key', 'period_open')
            ->first();

        if ($periodOpenDef === null) {
            throw new RuntimeException('Snapshot-Feld period_open fehlt.');
        }

        $positionIds = DB::table('calculation_positions')->pluck('id');
        $now = now();

        foreach ($positionIds as $positionId) {
            $exists = DB::table('calculation_position_field_values')
                ->where('calculation_position_id', $positionId)
                ->where('snapshot_field_definition_id', $periodOpenDef->id)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('calculation_position_field_values')->insert([
                'calculation_position_id' => $positionId,
                'snapshot_field_definition_id' => $periodOpenDef->id,
                'value_boolean' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function materializeSnapshot(int $fieldSetId, int $fieldSetVersionId, string $source): int
    {
        $now = now();

        $snapshotId = DB::table('configuration_snapshots')->insertGetId([
            'field_set_id' => $fieldSetId,
            'field_set_version_id' => $fieldSetVersionId,
            'source' => $source,
            'created_at' => $now,
        ]);

        $memberships = DB::table('field_set_version_fields as m')
            ->join('field_definition_revisions as r', 'r.id', '=', 'm.field_definition_revision_id')
            ->join('field_definitions as d', 'd.id', '=', 'r.field_definition_id')
            ->where('m.field_set_version_id', $fieldSetVersionId)
            ->orderBy('m.sort')
            ->get([
                'm.sort',
                'r.id as revision_id',
                'r.label',
                'r.help_text',
                'r.validation_json',
                'r.group_key',
                'r.reportable',
                'd.id as definition_id',
                'd.key',
                'd.field_type',
                'd.scope',
                'd.applies_to',
            ]);

        foreach ($memberships as $row) {
            DB::table('snapshot_field_definitions')->insert([
                'configuration_snapshot_id' => $snapshotId,
                'field_definition_id' => $row->definition_id,
                'field_definition_revision_id' => $row->revision_id,
                'key' => $row->key,
                'field_type' => $row->field_type,
                'label' => $row->label,
                'help_text' => $row->help_text,
                'scope' => $row->scope,
                'applies_to' => $row->applies_to,
                'sort' => $row->sort,
                'group_key' => $row->group_key,
                'reportable' => (bool) $row->reportable,
                'validation_json' => $row->validation_json,
            ]);
        }

        $rules = DB::table('field_rules')
            ->where('field_set_version_id', $fieldSetVersionId)
            ->orderBy('sort')
            ->get();

        foreach ($rules as $rule) {
            DB::table('snapshot_field_rules')->insert([
                'configuration_snapshot_id' => $snapshotId,
                'source_field_rule_id' => $rule->id,
                'sort' => $rule->sort,
                'condition_json' => $rule->condition_json,
                'action_json' => $rule->action_json,
            ]);
        }

        return $snapshotId;
    }
};
