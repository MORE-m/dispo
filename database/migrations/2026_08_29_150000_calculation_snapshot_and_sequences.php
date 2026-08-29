<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calculation_number_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_seq')->default(0);
            $table->timestamps();
        });

        Schema::table('calculation_positions', function (Blueprint $table) {
            $table->decimal('average_second_price', 16, 4)->nullable()->after('total_spot_count');
            $table->unsignedSmallInteger('length_index')->nullable()->after('average_second_price');
            $table->unsignedBigInteger('inventory_medium_rule_id')->nullable()->after('advertising_medium_id');
        });

        if (Schema::hasTable('calculation_positions')) {
            $positions = DB::table('calculation_positions')->select('id', 'client_key', 'total_spot_count')->get();

            foreach ($positions as $position) {
                $updates = [];

                if ($position->client_key === null || $position->client_key === '') {
                    $updates['client_key'] = (string) Str::uuid();
                }

                if ((int) $position->total_spot_count === 0) {
                    $sum = (int) DB::table('spot_classic_plan_rows')
                        ->where('calculation_position_id', $position->id)
                        ->sum('spot_count');

                    if ($sum > 0) {
                        $updates['total_spot_count'] = $sum;
                    }
                }

                if ($updates !== []) {
                    DB::table('calculation_positions')->where('id', $position->id)->update($updates);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('calculation_positions', function (Blueprint $table) {
            $table->dropColumn(['average_second_price', 'length_index', 'inventory_medium_rule_id']);
        });

        Schema::dropIfExists('calculation_number_sequences');
    }
};
