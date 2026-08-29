<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calculation_positions', function (Blueprint $table) {
            $table->uuid('client_key')->nullable()->after('id');
            $table->string('spot_method', 32)->default('average')->after('kind');
            $table->unsignedInteger('total_spot_count')->default(0)->after('length_seconds');
            $table->unique('client_key');
        });

        Schema::table('budget_proposals', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->nullable()->after('target_budget_nn');
            $table->foreignId('applied_by')->nullable()->after('applied_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('budget_proposals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('applied_by');
            $table->dropColumn('lock_version');
        });

        Schema::table('calculation_positions', function (Blueprint $table) {
            $table->dropUnique(['client_key']);
            $table->dropColumn(['client_key', 'spot_method', 'total_spot_count']);
        });
    }
};
