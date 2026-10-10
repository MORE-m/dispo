<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BL-P2-03a / PO-BLP203-1: CRM Salesforce/Meridian – Accounts, Versionen, Import, Konflikte.
 * Calc/Dispo: optionale Account-Verknüpfungen + Snapshots; Freitext bleibt lesbar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);
            $table->string('salesforce_account_id_raw', 32)->nullable();
            $table->string('salesforce_account_id_canonical', 18)->nullable();
            $table->boolean('is_provisional')->default(true);
            $table->string('matching_domain', 255)->nullable();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->foreignId('merged_into_account_id')->nullable()->constrained('crm_accounts')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique('salesforce_account_id_canonical');
            $table->index(['type', 'matching_domain']);
            $table->index(['type', 'is_provisional']);
        });

        Schema::create('crm_account_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_account_id')->constrained('crm_accounts')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('name', 255);
            $table->string('billing_email', 255)->nullable();
            $table->string('matching_domain', 255)->nullable();
            $table->string('meridian_number', 64)->nullable();
            $table->string('source', 32);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('crm_import_id')->nullable();
            $table->timestamps();

            $table->unique(['crm_account_id', 'version_number']);
            $table->index('matching_domain');
        });

        Schema::table('crm_accounts', function (Blueprint $table) {
            $table->foreign('current_version_id')
                ->references('id')
                ->on('crm_account_versions')
                ->nullOnDelete();
        });

        Schema::create('crm_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 32);
            $table->string('original_filename', 255);
            $table->string('stored_path', 512);
            $table->string('checksum_sha256', 64);
            $table->string('mime_type', 127)->nullable();
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('valid_row_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->json('preview')->nullable();
            $table->json('report')->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->string('catalog_fingerprint', 64)->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('checksum_sha256');
        });

        Schema::table('crm_account_versions', function (Blueprint $table) {
            $table->foreign('crm_import_id')
                ->references('id')
                ->on('crm_imports')
                ->nullOnDelete();
        });

        Schema::create('crm_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_account_id')->nullable()->constrained('crm_accounts')->nullOnDelete();
            $table->foreignId('crm_import_id')->nullable()->constrained('crm_imports')->nullOnDelete();
            $table->string('type', 64);
            $table->string('status', 16)->default('open');
            $table->json('details');
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'type']);
        });

        Schema::create('crm_shared_email_domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 255)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        foreach (['gmail.com', 'gmx.de', 'outlook.com', 'hotmail.com', 'yahoo.com', 'googlemail.com'] as $domain) {
            DB::table('crm_shared_email_domains')->insert([
                'domain' => $domain,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('calculations', function (Blueprint $table) {
            $table->foreignId('customer_account_id')->nullable()->after('customer_name')
                ->constrained('crm_accounts')->nullOnDelete();
            $table->foreignId('agency_account_id')->nullable()->after('agency_name')
                ->constrained('crm_accounts')->nullOnDelete();
            $table->foreignId('customer_version_id')->nullable()->after('customer_account_id')
                ->constrained('crm_account_versions')->nullOnDelete();
            $table->foreignId('agency_version_id')->nullable()->after('agency_account_id')
                ->constrained('crm_account_versions')->nullOnDelete();
            $table->string('invoice_recipient', 16)->nullable()->after('agency_version_id');
            $table->string('customer_meridian_number', 64)->nullable()->after('invoice_recipient');
            $table->string('agency_meridian_number', 64)->nullable()->after('customer_meridian_number');
            $table->string('customer_salesforce_account_id', 32)->nullable()->after('agency_meridian_number');
            $table->string('agency_salesforce_account_id', 32)->nullable()->after('customer_salesforce_account_id');
        });

        Schema::table('dispo_orders', function (Blueprint $table) {
            $table->foreignId('customer_account_id')->nullable()->after('customer_name')
                ->constrained('crm_accounts')->nullOnDelete();
            $table->foreignId('agency_account_id')->nullable()->after('agency_name')
                ->constrained('crm_accounts')->nullOnDelete();
            $table->foreignId('customer_version_id')->nullable()->after('customer_account_id')
                ->constrained('crm_account_versions')->nullOnDelete();
            $table->foreignId('agency_version_id')->nullable()->after('agency_account_id')
                ->constrained('crm_account_versions')->nullOnDelete();
            $table->string('invoice_recipient', 16)->nullable()->after('agency_version_id');
            $table->string('customer_meridian_number', 64)->nullable()->after('invoice_recipient');
            $table->string('agency_meridian_number', 64)->nullable()->after('customer_meridian_number');
            $table->string('customer_salesforce_account_id', 32)->nullable()->after('agency_meridian_number');
            $table->string('agency_salesforce_account_id', 32)->nullable()->after('customer_salesforce_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('dispo_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_account_id');
            $table->dropConstrainedForeignId('agency_account_id');
            $table->dropConstrainedForeignId('customer_version_id');
            $table->dropConstrainedForeignId('agency_version_id');
            $table->dropColumn([
                'invoice_recipient',
                'customer_meridian_number',
                'agency_meridian_number',
                'customer_salesforce_account_id',
                'agency_salesforce_account_id',
            ]);
        });

        Schema::table('calculations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_account_id');
            $table->dropConstrainedForeignId('agency_account_id');
            $table->dropConstrainedForeignId('customer_version_id');
            $table->dropConstrainedForeignId('agency_version_id');
            $table->dropColumn([
                'invoice_recipient',
                'customer_meridian_number',
                'agency_meridian_number',
                'customer_salesforce_account_id',
                'agency_salesforce_account_id',
            ]);
        });

        Schema::dropIfExists('crm_shared_email_domains');
        Schema::dropIfExists('crm_conflicts');
        Schema::table('crm_account_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('crm_import_id');
        });
        Schema::dropIfExists('crm_imports');
        Schema::table('crm_accounts', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });
        Schema::dropIfExists('crm_account_versions');
        Schema::dropIfExists('crm_accounts');
    }
};
