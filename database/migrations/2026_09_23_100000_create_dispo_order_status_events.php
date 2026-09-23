<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispo_order_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispo_order_id')->constrained('dispo_orders')->restrictOnDelete();
            $table->string('from_status', 64);
            $table->string('to_status', 64);
            $table->foreignId('changed_by_id')->constrained('users')->restrictOnDelete();
            $table->string('changed_by_name');
            $table->timestamp('changed_at')->useCurrent();
            $table->text('reason')->nullable();
            $table->boolean('is_reopen')->default(false);
            $table->unsignedInteger('lock_version_after');
            $table->timestamps();

            $table->index(['dispo_order_id', 'id']);
            $table->index('changed_at');
            $table->index('to_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispo_order_status_events');
    }
};
