<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('discount_limit_percent', 7, 4)->nullable()->after('role');
            $table->boolean('can_special_approve')->default(false)->after('discount_limit_percent');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['discount_limit_percent', 'can_special_approve']);
        });
    }
};
