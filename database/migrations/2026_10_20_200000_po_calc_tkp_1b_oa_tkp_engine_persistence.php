<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PO-CALC-TKP-1B: Persistenz für Normal-TKP (Impressions, Preis-Snapshot, Allocations, Targetings).
 *
 * Kein Registry-Release. Kein Adserver-Runtime. Keine Plattform-Preisdimension.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calculation_positions', function (Blueprint $table): void {
            if (! Schema::hasColumn('calculation_positions', 'impressions')) {
                $table->unsignedBigInteger('impressions')->nullable()->after('total_spot_count');
            }
            if (! Schema::hasColumn('calculation_positions', 'basis_tkp')) {
                $table->decimal('basis_tkp', 16, 4)->nullable()->after('impressions');
            }
            if (! Schema::hasColumn('calculation_positions', 'list_tkp_snapshot')) {
                $table->decimal('list_tkp_snapshot', 16, 4)->nullable()->after('basis_tkp');
            }
            if (! Schema::hasColumn('calculation_positions', 'minimum_tkp_snapshot')) {
                $table->decimal('minimum_tkp_snapshot', 16, 4)->nullable()->after('list_tkp_snapshot');
            }
            if (! Schema::hasColumn('calculation_positions', 'final_tkp')) {
                $table->decimal('final_tkp', 16, 4)->nullable()->after('minimum_tkp_snapshot');
            }
            if (! Schema::hasColumn('calculation_positions', 'targeting_surcharge_total')) {
                $table->decimal('targeting_surcharge_total', 16, 4)->nullable()->after('final_tkp');
            }
            if (! Schema::hasColumn('calculation_positions', 'below_minimum_tkp')) {
                $table->boolean('below_minimum_tkp')->nullable()->after('targeting_surcharge_total');
            }
            if (! Schema::hasColumn('calculation_positions', 'oa_tkp_price_list_id')) {
                $table->unsignedBigInteger('oa_tkp_price_list_id')->nullable()->after('price_list_id');
            }
            if (! Schema::hasColumn('calculation_positions', 'oa_tkp_price_list_version')) {
                $table->string('oa_tkp_price_list_version', 64)->nullable()->after('oa_tkp_price_list_id');
            }
            if (! Schema::hasColumn('calculation_positions', 'oa_tkp_price_list_year')) {
                $table->unsignedSmallInteger('oa_tkp_price_list_year')->nullable()->after('oa_tkp_price_list_version');
            }
            if (! Schema::hasColumn('calculation_positions', 'oa_tkp_price_list_revision')) {
                $table->unsignedInteger('oa_tkp_price_list_revision')->nullable()->after('oa_tkp_price_list_year');
            }
        });

        if (! Schema::hasTable('calculation_position_oa_platform_allocations')) {
            Schema::create('calculation_position_oa_platform_allocations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('calculation_position_id');
                $table->unsignedBigInteger('oa_platform_id')->nullable();
                $table->string('platform_code', 64);
                $table->string('platform_name');
                $table->unsignedBigInteger('impressions');
                $table->unsignedInteger('sort')->default(0);
                $table->timestamps();

                $table->foreign('calculation_position_id', 'calc_pos_oa_plat_alloc_pos_fk')
                    ->references('id')
                    ->on('calculation_positions')
                    ->cascadeOnDelete();
                $table->unique(
                    ['calculation_position_id', 'platform_code'],
                    'calc_pos_oa_plat_alloc_code_uq',
                );
                $table->index(
                    ['calculation_position_id', 'sort'],
                    'calc_pos_oa_plat_alloc_sort_idx',
                );
            });
        }

        if (! Schema::hasTable('calculation_position_oa_targeting_selections')) {
            Schema::create('calculation_position_oa_targeting_selections', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('calculation_position_id');
                $table->unsignedBigInteger('oa_targeting_definition_id')->nullable();
                $table->string('targeting_code', 64);
                $table->string('targeting_name');
                $table->string('targeting_category', 32);
                $table->decimal('tkp_surcharge_snapshot', 16, 4);
                $table->unsignedInteger('targeting_revision_number')->default(1);
                $table->unsignedInteger('sort')->default(0);
                $table->timestamps();

                $table->foreign('calculation_position_id', 'calc_pos_oa_tgt_sel_pos_fk')
                    ->references('id')
                    ->on('calculation_positions')
                    ->cascadeOnDelete();
                $table->unique(
                    ['calculation_position_id', 'targeting_code'],
                    'calc_pos_oa_tgt_sel_code_uq',
                );
                $table->index(
                    ['calculation_position_id', 'sort'],
                    'calc_pos_oa_tgt_sel_sort_idx',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('calculation_position_oa_targeting_selections');
        Schema::dropIfExists('calculation_position_oa_platform_allocations');

        Schema::table('calculation_positions', function (Blueprint $table): void {
            foreach ([
                'impressions',
                'basis_tkp',
                'list_tkp_snapshot',
                'minimum_tkp_snapshot',
                'final_tkp',
                'targeting_surcharge_total',
                'below_minimum_tkp',
                'oa_tkp_price_list_id',
                'oa_tkp_price_list_version',
                'oa_tkp_price_list_year',
                'oa_tkp_price_list_revision',
            ] as $column) {
                if (Schema::hasColumn('calculation_positions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
