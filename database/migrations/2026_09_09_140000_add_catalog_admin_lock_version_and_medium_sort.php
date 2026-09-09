<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADV-001b: lock_version für Katalog-Admin + Sortierung an Werbemitteln.
 * Keine Änderung an Keys, Codes, Kategoriezuordnungen oder Snapshots.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('advertising_categories') && ! Schema::hasColumn('advertising_categories', 'lock_version')) {
            Schema::table('advertising_categories', function (Blueprint $table) {
                $table->unsignedInteger('lock_version')->default(1)->after('sort');
            });
            DB::table('advertising_categories')->whereNull('lock_version')->update(['lock_version' => 1]);
        }

        if (Schema::hasTable('advertising_media')) {
            if (! Schema::hasColumn('advertising_media', 'lock_version')) {
                Schema::table('advertising_media', function (Blueprint $table) {
                    $table->unsignedInteger('lock_version')->default(1)->after('is_active');
                });
                DB::table('advertising_media')->whereNull('lock_version')->update(['lock_version' => 1]);
            }

            if (! Schema::hasColumn('advertising_media', 'sort')) {
                Schema::table('advertising_media', function (Blueprint $table) {
                    $table->unsignedInteger('sort')->default(0)->after('is_active');
                });
                DB::table('advertising_media')->whereNull('sort')->update(['sort' => 0]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('advertising_media')) {
            Schema::table('advertising_media', function (Blueprint $table) {
                if (Schema::hasColumn('advertising_media', 'lock_version')) {
                    $table->dropColumn('lock_version');
                }
                if (Schema::hasColumn('advertising_media', 'sort')) {
                    $table->dropColumn('sort');
                }
            });
        }

        if (Schema::hasTable('advertising_categories') && Schema::hasColumn('advertising_categories', 'lock_version')) {
            Schema::table('advertising_categories', function (Blueprint $table) {
                $table->dropColumn('lock_version');
            });
        }
    }
};
