<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL setzt bei der ersten TIMESTAMP-Spalte ohne explizites Default oft
 * ON UPDATE CURRENT_TIMESTAMP. Beim Speichern von decided_at wurde dadurch
 * submitted_at mit der MySQL-Sessionzeit überschrieben (lokal ≠ UTC).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dispo_order_approval_requests')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement(
            'ALTER TABLE dispo_order_approval_requests
             MODIFY submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
        );
    }

    public function down(): void
    {
        // Absichtlich kein Restore von ON UPDATE CURRENT_TIMESTAMP.
    }
};
