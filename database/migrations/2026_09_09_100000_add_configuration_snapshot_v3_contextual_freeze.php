<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DF-3.3a2β / VER-003: Generation 3 – Parent, Positions-Effektiv-FK (Unique),
 * historischer Medium-/Kategorie-Kontext am Snapshot.
 *
 * Gen 1/2 bleiben unverändert; keine Inhaltsmigration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addParentAndContextColumns();
        $this->addCalculationPositionEffective();
        $this->addDispoOrderPositionEffective();
    }

    public function down(): void
    {
        if (Schema::hasColumn('dispo_order_positions', 'effective_configuration_snapshot_id')) {
            Schema::table('dispo_order_positions', function (Blueprint $table) {
                // MySQL: Unique-Index darf nicht vor dem FK fallen, der ihn nutzt.
                $this->dropForeign($table, 'dispo_pos_effective_cfg_snap_fk', 'effective_configuration_snapshot_id');
                $table->dropUnique('dispo_pos_effective_cfg_snap_uidx');
                $table->dropColumn([
                    'effective_configuration_snapshot_id',
                    'advertising_category_id',
                    'advertising_category_key',
                    'advertising_category_name',
                ]);
            });
        }

        if (Schema::hasColumn('calculation_positions', 'effective_configuration_snapshot_id')) {
            Schema::table('calculation_positions', function (Blueprint $table) {
                $this->dropForeign($table, 'calc_pos_effective_cfg_snap_fk', 'effective_configuration_snapshot_id');
                $table->dropUnique('calc_pos_effective_cfg_snap_uidx');
                $table->dropColumn([
                    'effective_configuration_snapshot_id',
                    'advertising_medium_name',
                    'advertising_medium_code',
                    'advertising_category_id',
                    'advertising_category_key',
                    'advertising_category_name',
                ]);
            });
        }

        if (Schema::hasColumn('configuration_snapshots', 'parent_configuration_snapshot_id')) {
            Schema::table('configuration_snapshots', function (Blueprint $table) {
                $this->dropForeign($table, 'cfg_snap_parent_cfg_snap_fk', 'parent_configuration_snapshot_id');
                $this->dropForeign($table, 'cfg_snap_ctx_medium_fk', 'context_advertising_medium_id');
                $this->dropForeign($table, 'cfg_snap_ctx_category_fk', 'context_advertising_category_id');
                $table->dropColumn([
                    'parent_configuration_snapshot_id',
                    'context_advertising_medium_id',
                    'context_advertising_medium_code',
                    'context_advertising_medium_name',
                    'context_advertising_category_id',
                    'context_advertising_category_key',
                    'context_advertising_category_name',
                ]);
            });
        }
    }

    /**
     * SQLite kennt keine benannten Constraints in ALTER TABLE und identifiziert
     * den Fremdschlüssel über die Spalte (siehe DF-3.3a2α-Migration).
     */
    private function dropForeign(Blueprint $table, string $name, string $column): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $table->dropForeign([$column]);

            return;
        }

        $table->dropForeign($name);
    }

    private function addParentAndContextColumns(): void
    {
        if (Schema::hasColumn('configuration_snapshots', 'parent_configuration_snapshot_id')) {
            return;
        }

        Schema::table('configuration_snapshots', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_configuration_snapshot_id')->nullable()->after('source_configuration_snapshot_id');
            $table->unsignedBigInteger('context_advertising_medium_id')->nullable()->after('schema_fingerprint');
            $table->string('context_advertising_medium_code', 64)->nullable()->after('context_advertising_medium_id');
            $table->string('context_advertising_medium_name', 255)->nullable()->after('context_advertising_medium_code');
            $table->unsignedBigInteger('context_advertising_category_id')->nullable()->after('context_advertising_medium_name');
            $table->string('context_advertising_category_key', 64)->nullable()->after('context_advertising_category_id');
            $table->string('context_advertising_category_name', 255)->nullable()->after('context_advertising_category_key');

            $table->foreign('parent_configuration_snapshot_id', 'cfg_snap_parent_cfg_snap_fk')
                ->references('id')
                ->on('configuration_snapshots')
                ->restrictOnDelete();

            $table->foreign('context_advertising_medium_id', 'cfg_snap_ctx_medium_fk')
                ->references('id')
                ->on('advertising_media')
                ->restrictOnDelete();

            $table->foreign('context_advertising_category_id', 'cfg_snap_ctx_category_fk')
                ->references('id')
                ->on('advertising_categories')
                ->restrictOnDelete();
        });
    }

    private function addCalculationPositionEffective(): void
    {
        if (Schema::hasColumn('calculation_positions', 'effective_configuration_snapshot_id')) {
            return;
        }

        Schema::table('calculation_positions', function (Blueprint $table) {
            $table->string('advertising_medium_name', 255)->nullable()->after('advertising_medium_id');
            $table->string('advertising_medium_code', 64)->nullable()->after('advertising_medium_name');
            $table->unsignedBigInteger('advertising_category_id')->nullable()->after('advertising_medium_code');
            $table->string('advertising_category_key', 64)->nullable()->after('advertising_category_id');
            $table->string('advertising_category_name', 255)->nullable()->after('advertising_category_key');
            $table->unsignedBigInteger('effective_configuration_snapshot_id')->nullable()->after('advertising_category_name');

            $table->foreign('effective_configuration_snapshot_id', 'calc_pos_effective_cfg_snap_fk')
                ->references('id')
                ->on('configuration_snapshots')
                ->restrictOnDelete();

            $table->unique('effective_configuration_snapshot_id', 'calc_pos_effective_cfg_snap_uidx');
        });
    }

    private function addDispoOrderPositionEffective(): void
    {
        if (Schema::hasColumn('dispo_order_positions', 'effective_configuration_snapshot_id')) {
            return;
        }

        Schema::table('dispo_order_positions', function (Blueprint $table) {
            $table->unsignedBigInteger('effective_configuration_snapshot_id')->nullable()->after('advertising_medium_code');
            $table->unsignedBigInteger('advertising_category_id')->nullable()->after('effective_configuration_snapshot_id');
            $table->string('advertising_category_key', 64)->nullable()->after('advertising_category_id');
            $table->string('advertising_category_name', 255)->nullable()->after('advertising_category_key');

            $table->foreign('effective_configuration_snapshot_id', 'dispo_pos_effective_cfg_snap_fk')
                ->references('id')
                ->on('configuration_snapshots')
                ->restrictOnDelete();

            $table->unique('effective_configuration_snapshot_id', 'dispo_pos_effective_cfg_snap_uidx');
        });
    }
};
