<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-P1-05a – persistiertes Notification-/Outbox-Fundament.
 * Keine Fachereignis-Verdrahtung, kein Mailversand in diesem Slice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 191);
            $table->string('event_type', 128);
            $table->string('source_type', 128);
            $table->unsignedBigInteger('source_id');
            $table->string('channel', 32);
            $table->string('status', 32);
            $table->unsignedBigInteger('recipient_user_id')->nullable();
            $table->string('recipient_email');
            $table->string('recipient_name');
            $table->json('payload_json');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->dateTime('available_at');
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->index(['status', 'available_at', 'id'], 'notification_outbox_due_idx');
            $table->index(['event_type', 'source_type', 'source_id'], 'notification_outbox_source_idx');
            $table->index(['recipient_user_id', 'id'], 'notification_outbox_recipient_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_outbox');
    }
};
