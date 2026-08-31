<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_proposals', function (Blueprint $table) {
            $table->string('status', 32)->default('current')->after('strategy');
            $table->string('algorithm_version', 32)->nullable()->after('status');
            $table->string('input_fingerprint', 64)->nullable()->after('algorithm_version');
            $table->timestamp('calculated_at')->nullable()->after('input_fingerprint');
        });

        Schema::table('calculations', function (Blueprint $table) {
            $table->string('budget_proposal_status', 32)->nullable()->after('budget_strategy');
        });
    }

    public function down(): void
    {
        Schema::table('calculations', function (Blueprint $table) {
            $table->dropColumn('budget_proposal_status');
        });

        Schema::table('budget_proposals', function (Blueprint $table) {
            $table->dropColumn(['status', 'algorithm_version', 'input_fingerprint', 'calculated_at']);
        });
    }
};
