<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PO-CALC-TKP-1A: Kategorie online_audio → Method tkp / Profil oa_tkp.
 *
 * Assignment ≠ Release: Registry-Pair bleibt planned; keine Bookability.
 * Keine Medium-Overrides (native_ad bleibt ohne oa_tkp-Override).
 * Idempotent.
 */
return new class extends Migration
{
    private const CATEGORY_KEY = 'online_audio';

    private const METHOD_KEY = 'tkp';

    private const PROFILE_KEY = 'oa_tkp';

    private const SORT = 10;

    public function up(): void
    {
        $this->assertPrerequisites();

        DB::transaction(function (): void {
            $categoryId = (int) DB::table('advertising_categories')
                ->where('key', self::CATEGORY_KEY)
                ->value('id');
            $methodId = (int) DB::table('calculation_methods')
                ->where('key', self::METHOD_KEY)
                ->value('id');

            $existing = DB::table('advertising_category_calculation_methods')
                ->where('advertising_category_id', $categoryId)
                ->where('calculation_method_id', $methodId)
                ->get();

            if ($existing->count() > 1) {
                throw new RuntimeException(
                    'PO-CALC-TKP-1A: mehrdeutige online_audio/tkp-Zuordnung.',
                );
            }

            if ($existing->count() === 1) {
                $row = $existing->first();
                if ((string) $row->engine_profile_key !== self::PROFILE_KEY) {
                    throw new RuntimeException(
                        'PO-CALC-TKP-1A: online_audio/tkp hat abweichendes engine_profile_key '
                        .'(erwartet oa_tkp).',
                    );
                }

                return;
            }

            DB::table('advertising_category_calculation_methods')->insert([
                'advertising_category_id' => $categoryId,
                'calculation_method_id' => $methodId,
                'engine_profile_key' => self::PROFILE_KEY,
                'is_active' => true,
                'sort' => self::SORT,
                'lock_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('advertising_category_calculation_methods')) {
            return;
        }

        $categoryId = DB::table('advertising_categories')->where('key', self::CATEGORY_KEY)->value('id');
        $methodId = DB::table('calculation_methods')->where('key', self::METHOD_KEY)->value('id');
        if ($categoryId === null || $methodId === null) {
            return;
        }

        DB::table('advertising_category_calculation_methods')
            ->where('advertising_category_id', (int) $categoryId)
            ->where('calculation_method_id', (int) $methodId)
            ->where('engine_profile_key', self::PROFILE_KEY)
            ->delete();
    }

    private function assertPrerequisites(): void
    {
        foreach ([
            'advertising_categories',
            'calculation_methods',
            'advertising_category_calculation_methods',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("PO-CALC-TKP-1A: Voraussetzung fehlt ({$table}).");
            }
        }

        if (DB::table('advertising_categories')->where('key', self::CATEGORY_KEY)->value('id') === null) {
            throw new RuntimeException('PO-CALC-TKP-1A: Kategorie online_audio fehlt.');
        }

        if (DB::table('calculation_methods')->where('key', self::METHOD_KEY)->value('id') === null) {
            throw new RuntimeException('PO-CALC-TKP-1A: CalculationMethod tkp fehlt.');
        }
    }
};
