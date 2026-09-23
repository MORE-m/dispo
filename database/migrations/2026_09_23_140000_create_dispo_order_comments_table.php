<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispo_order_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispo_order_id')->constrained('dispo_orders')->restrictOnDelete();
            $table->string('type', 64);
            $table->text('body');
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->string('created_by_name');
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('dispo_order_comments')
                ->restrictOnDelete();
            $table->timestamps();

            $table->index(['dispo_order_id', 'id']);
            $table->index(['dispo_order_id', 'type']);
            $table->unique('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispo_order_comments');
    }
};
