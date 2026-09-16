<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calculation_position_planner_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('calculation_position_id');
            $table->date('date');
            $table->unsignedTinyInteger('hour');
            $table->unsignedInteger('spot_count');
            $table->string('day_group', 16);
            $table->decimal('second_price', 16, 4);
            $table->decimal('line_gross', 14, 2);
            $table->timestamps();

            $table->unique(
                ['calculation_position_id', 'date', 'hour'],
                'position_planner_entry_unique',
            );

            $table->foreign('calculation_position_id', 'calc_pos_planner_entry_fk')
                ->references('id')
                ->on('calculation_positions')
                ->cascadeOnDelete();
        });

        Schema::table('dispo_order_positions', function (Blueprint $table) {
            $table->json('planner_entries_snapshot')->nullable()->after('time_ranges_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('dispo_order_positions', function (Blueprint $table) {
            $table->dropColumn('planner_entries_snapshot');
        });

        Schema::dropIfExists('calculation_position_planner_entries');
    }
};
