<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DF-3-REST-A: versionierte Auswahloptionen an FieldDefinitionRevision
 * sowie additives options_json-Freeze in Gen2/Gen3-Source- und Effektfeldern.
 *
 * Kein Gen4. Keine Rückmutation bestehender Snapshots. Kein Backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('field_definition_revision_options')) {
            Schema::create('field_definition_revision_options', function (Blueprint $table) {
                $table->id();
                $table->foreignId('field_definition_revision_id')
                    ->constrained('field_definition_revisions')
                    ->restrictOnDelete();
                $table->string('key', 64);
                $table->string('label', 255);
                $table->unsignedInteger('sort')->default(0);
                $table->boolean('is_active')->default(true);

                $table->unique(
                    ['field_definition_revision_id', 'key'],
                    'field_def_rev_opt_key_unique',
                );
                $table->index(
                    ['field_definition_revision_id', 'sort'],
                    'field_def_rev_opt_sort_index',
                );
            });
        }

        if (! Schema::hasColumn('configuration_snapshot_source_fields', 'options_json')) {
            Schema::table('configuration_snapshot_source_fields', function (Blueprint $table) {
                $table->json('options_json')->nullable();
            });
        }

        if (! Schema::hasColumn('snapshot_field_definitions', 'options_json')) {
            Schema::table('snapshot_field_definitions', function (Blueprint $table) {
                $table->json('options_json')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('snapshot_field_definitions', 'options_json')) {
            Schema::table('snapshot_field_definitions', function (Blueprint $table) {
                $table->dropColumn('options_json');
            });
        }

        if (Schema::hasColumn('configuration_snapshot_source_fields', 'options_json')) {
            Schema::table('configuration_snapshot_source_fields', function (Blueprint $table) {
                $table->dropColumn('options_json');
            });
        }

        Schema::dropIfExists('field_definition_revision_options');
    }
};
