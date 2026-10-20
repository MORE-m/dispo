<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PO-CALC-TKP-1A: TKP/oa_tkp als Medium-Override nur für TKP-Kernmedien.
 *
 * Kein Category Assignment auf online_audio (sonst erbt native_ad).
 * Assignment ≠ Release: Registry-Pair bleibt planned.
 * Idempotent; Gate auf pre_stream (ADV-001c2-Down-Guards).
 */
return new class extends Migration
{
    private const CATEGORY_KEY = 'online_audio';

    private const METHOD_KEY = 'tkp';

    private const PROFILE_KEY = 'oa_tkp';

    private const SORT = 10;

    /** @var list<string> */
    private const CORE_MEDIA = [
        'pre_stream',
        'in_stream',
        'pre_stream_influencer',
        'in_stream_influencer',
        'midroll_music_environment',
    ];

    public function up(): void
    {
        $this->assertPrerequisites();

        DB::transaction(function (): void {
            $this->removeCategoryTkpAssignment();

            // Ohne OA-Medien: nur Category-Reste bereinigen; Bootstrap setzt Overrides.
            if (! DB::table('advertising_media')->where('code', 'pre_stream')->exists()) {
                return;
            }

            $methodId = (int) DB::table('calculation_methods')
                ->where('key', self::METHOD_KEY)
                ->value('id');

            foreach (self::CORE_MEDIA as $code) {
                $this->ensureCoreMediumTkpOverride($code, $methodId);
            }

            $this->assertNativeHasNoTkpAssignment($methodId);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('advertising_medium_calculation_methods')) {
            $this->removeCategoryTkpAssignment();

            return;
        }

        $methodId = DB::table('calculation_methods')->where('key', self::METHOD_KEY)->value('id');
        if ($methodId === null) {
            $this->removeCategoryTkpAssignment();

            return;
        }
        $methodId = (int) $methodId;

        foreach (self::CORE_MEDIA as $code) {
            $mediumId = DB::table('advertising_media')->where('code', $code)->value('id');
            if ($mediumId === null) {
                continue;
            }
            $mediumId = (int) $mediumId;

            DB::table('advertising_medium_calculation_methods')
                ->where('advertising_medium_id', $mediumId)
                ->where('calculation_method_id', $methodId)
                ->where('engine_profile_key', self::PROFILE_KEY)
                ->delete();

            $remaining = (int) DB::table('advertising_medium_calculation_methods')
                ->where('advertising_medium_id', $mediumId)
                ->count();

            if ($remaining === 0) {
                DB::table('advertising_media')->where('id', $mediumId)->update([
                    'calculation_method_mode' => 'inherit',
                    'default_calculation_method_id' => null,
                    'updated_at' => now(),
                ]);
            }
        }

        $this->removeCategoryTkpAssignment();
    }

    private function ensureCoreMediumTkpOverride(string $code, int $methodId): void
    {
        $medium = DB::table('advertising_media')->where('code', $code)->first();
        if ($medium === null) {
            throw new RuntimeException("PO-CALC-TKP-1A: Kernmedium „{$code}“ fehlt.");
        }

        $mediumId = (int) $medium->id;
        $assignments = DB::table('advertising_medium_calculation_methods')
            ->where('advertising_medium_id', $mediumId)
            ->get();

        $tkpRows = $assignments->filter(
            fn ($row): bool => (int) $row->calculation_method_id === $methodId,
        );

        if ($tkpRows->count() > 1) {
            throw new RuntimeException(
                "PO-CALC-TKP-1A: Medium „{$code}“ hat mehrdeutige tkp-Zuordnungen.",
            );
        }

        if ($tkpRows->count() === 1) {
            $row = $tkpRows->first();
            if ((string) $row->engine_profile_key !== self::PROFILE_KEY) {
                throw new RuntimeException(
                    "PO-CALC-TKP-1A: Medium „{$code}“/tkp hat abweichendes Profil "
                    .'(erwartet oa_tkp).',
                );
            }

            if ((string) $medium->calculation_method_mode !== 'override'
                || (int) $medium->default_calculation_method_id !== $methodId
            ) {
                throw new RuntimeException(
                    "PO-CALC-TKP-1A: Medium „{$code}“ hat abweichenden Override-Vertrag.",
                );
            }

            return;
        }

        $canInstallFresh = (string) $medium->calculation_method_mode === 'inherit'
            && $medium->default_calculation_method_id === null
            && $assignments->isEmpty();

        if (! $canInstallFresh) {
            throw new RuntimeException(
                "PO-CALC-TKP-1A: Medium „{$code}“ weicht ab und wird nicht überschrieben.",
            );
        }

        $now = now();
        DB::table('advertising_media')->where('id', $mediumId)->update([
            'calculation_method_mode' => 'override',
            'default_calculation_method_id' => $methodId,
            'updated_at' => $now,
        ]);

        DB::table('advertising_medium_calculation_methods')->insert([
            'advertising_medium_id' => $mediumId,
            'calculation_method_id' => $methodId,
            'engine_profile_key' => self::PROFILE_KEY,
            'is_active' => true,
            'sort' => self::SORT,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function assertNativeHasNoTkpAssignment(int $methodId): void
    {
        $nativeId = DB::table('advertising_media')->where('code', 'native_ad')->value('id');
        if ($nativeId === null) {
            return;
        }

        $hasTkp = DB::table('advertising_medium_calculation_methods')
            ->where('advertising_medium_id', (int) $nativeId)
            ->where('calculation_method_id', $methodId)
            ->exists();

        if ($hasTkp) {
            throw new RuntimeException(
                'PO-CALC-TKP-1A: native_ad darf keine tkp-Medium-Zuordnung haben.',
            );
        }
    }

    private function removeCategoryTkpAssignment(): void
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
            ->delete();
    }

    private function assertPrerequisites(): void
    {
        foreach ([
            'advertising_categories',
            'advertising_media',
            'calculation_methods',
            'advertising_category_calculation_methods',
            'advertising_medium_calculation_methods',
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
