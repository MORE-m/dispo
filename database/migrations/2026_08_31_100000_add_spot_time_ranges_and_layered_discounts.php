<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calculation_position_time_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calculation_position_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('start_hour');
            $table->unsignedTinyInteger('end_hour_exclusive');
            $table->string('day_group', 16);
            $table->unsignedInteger('spot_count');
            $table->unsignedInteger('sort')->default(0);
            $table->decimal('average_second_price', 16, 4)->nullable();
            $table->decimal('range_gross', 14, 2)->nullable();
            $table->timestamps();

            $table->index(['calculation_position_id', 'sort'], 'position_time_range_sort');
        });

        Schema::create('calculation_position_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calculation_position_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('custom_label')->nullable();
            $table->decimal('percent', 7, 4);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['calculation_position_id', 'sort'], 'position_discount_sort');
        });

        Schema::create('calculation_order_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calculation_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('custom_label')->nullable();
            $table->decimal('percent', 7, 4);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['calculation_id', 'sort'], 'order_discount_sort');
        });

        Schema::table('calculations', function (Blueprint $table) {
            $table->boolean('ae_enabled')->default(false);
        });

        Schema::table('calculation_positions', function (Blueprint $table) {
            $table->boolean('needs_spot_redistribution')->default(false);
        });

        $this->backfillExistingCalculations();
    }

    public function down(): void
    {
        Schema::table('calculation_positions', function (Blueprint $table) {
            $table->dropColumn('needs_spot_redistribution');
        });

        Schema::table('calculations', function (Blueprint $table) {
            $table->dropColumn('ae_enabled');
        });

        Schema::dropIfExists('calculation_order_discounts');
        Schema::dropIfExists('calculation_position_discounts');
        Schema::dropIfExists('calculation_position_time_ranges');
    }

    private function backfillExistingCalculations(): void
    {
        $now = now();

        $positions = DB::table('calculation_positions')->orderBy('id')->get();

        foreach ($positions as $position) {
            $rows = DB::table('spot_classic_plan_rows')
                ->where('calculation_position_id', $position->id)
                ->orderBy('hour')
                ->orderBy('day_group')
                ->get()
                ->unique(fn (object $row): string => $row->hour.'|'.$row->day_group)
                ->values();

            if ($rows->count() === 1) {
                $row = $rows->first();
                DB::table('calculation_position_time_ranges')->insert([
                    'calculation_position_id' => $position->id,
                    'start_hour' => (int) $row->hour,
                    'end_hour_exclusive' => (int) $row->hour + 1,
                    'day_group' => $row->day_group,
                    'spot_count' => max(0, (int) $position->total_spot_count),
                    'sort' => 0,
                    'average_second_price' => $position->average_second_price,
                    'range_gross' => $position->media_gross,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } elseif ($rows->count() > 1) {
                DB::table('calculation_positions')
                    ->where('id', $position->id)
                    ->update(['needs_spot_redistribution' => true]);
            }

            if ((float) $position->position_discount_percent > 0) {
                DB::table('calculation_position_discounts')->insert([
                    'calculation_position_id' => $position->id,
                    'type' => 'other',
                    'custom_label' => 'Positionsrabatt',
                    'percent' => $position->position_discount_percent,
                    'sort' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $calculations = DB::table('calculations')->orderBy('id')->get();

        foreach ($calculations as $calculation) {
            if ((float) $calculation->order_discount_percent > 0) {
                DB::table('calculation_order_discounts')->insert([
                    'calculation_id' => $calculation->id,
                    'type' => 'other',
                    'custom_label' => 'Auftragsrabatt',
                    'percent' => $calculation->order_discount_percent,
                    'sort' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $hasExplicitAe = DB::table('calculation_positions')
                ->where('calculation_id', $calculation->id)
                ->where('ae_percent', '>', 0)
                ->exists();

            if ($hasExplicitAe) {
                DB::table('calculations')
                    ->where('id', $calculation->id)
                    ->update(['ae_enabled' => true]);
            }
        }
    }
};
