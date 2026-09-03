<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calculations', function (Blueprint $table) {
            $table->json('special_approval_reasons')->nullable()->after('requires_special_approval');
            $table->decimal('personal_discount_limit_percent', 7, 4)->nullable()->after('special_approval_reasons');
        });

        Schema::table('dispo_orders', function (Blueprint $table) {
            $table->string('approval_kind', 32)->default('regular')->after('requires_special_approval');
            $table->json('special_approval_reasons')->nullable()->after('approval_kind');
        });

        Schema::create('dispo_order_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispo_order_id')->constrained('dispo_orders')->restrictOnDelete();
            $table->unsignedInteger('cycle_number');
            $table->string('status', 32);
            $table->string('kind', 32);
            $table->json('special_approval_reasons')->nullable();
            $table->foreignId('submitted_by_id')->constrained('users')->restrictOnDelete();
            $table->string('submitted_by_name');
            $table->timestamp('submitted_at');
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('decided_by_name')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('decision_note')->nullable();
            $table->unsignedInteger('submitted_lock_version');
            $table->unsignedTinyInteger('open_guard')->nullable();
            $table->timestamps();

            $table->unique(['dispo_order_id', 'cycle_number']);
            $table->unique(['dispo_order_id', 'open_guard']);
            $table->index('status');
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispo_order_approval_requests');

        Schema::table('dispo_orders', function (Blueprint $table) {
            $table->dropColumn(['approval_kind', 'special_approval_reasons']);
        });

        Schema::table('calculations', function (Blueprint $table) {
            $table->dropColumn(['special_approval_reasons', 'personal_discount_limit_percent']);
        });
    }
};
