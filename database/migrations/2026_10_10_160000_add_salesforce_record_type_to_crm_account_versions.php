<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-P2-03a Nachzug / PO-BLP203-1 D1:
 * Originalen Salesforce-Account-Datensatztyp je Stammdatenversion speichern.
 * Vorläufige Accounts und ältere Versionen bleiben NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_account_versions', function (Blueprint $table) {
            $table->string('salesforce_record_type', 64)->nullable()->after('meridian_number');
        });
    }

    public function down(): void
    {
        Schema::table('crm_account_versions', function (Blueprint $table) {
            $table->dropColumn('salesforce_record_type');
        });
    }
};
