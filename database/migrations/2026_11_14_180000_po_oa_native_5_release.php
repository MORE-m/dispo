<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PO-OA-NATIVE-1 / NATIVE-5: Native-Ad als Free Position v1 konfigurieren.
 *
 * Schema-Migration = NEIN. Datenmigration = JA.
 *
 * - native_ad → kind=free_position, override, default method free_position
 * - Assignment free_position × free_position (Profil free_position), sort 10
 * - Podcast-Rule inv_more_kombi_podcast × native_ad (active, redaktion)
 * - MORE-Kombi Online Audio × native_ad wird NICHT angelegt
 * - Vier bestehende Native-Ad-Rules bleiben unberührt
 *
 * Fail-closed; IDs nur über Codes. Idempotent. Down nur wenn Zustand noch exakt dem Release entspricht.
 */
return new class extends Migration
{
    private const MEDIUM_CODE = 'native_ad';

    private const METHOD_KEY = 'free_position';

    private const PROFILE_KEY = 'free_position';

    private const CATEGORY_KEY = 'online_audio';

    private const PODCAST_INVENTORY = 'inv_more_kombi_podcast';

    private const MORE_OA_INVENTORY = 'inv_more_kombi_online_audio';

    private const PLANNING_KEY = 'redaktion';

    private const BOOKING_CODE = 'Online Audio (UA)';

    private const SORT = 10;

    public function up(): void
    {
        $this->assertTables();

        DB::transaction(function (): void {
            $nativeCount = (int) DB::table('advertising_media')->where('code', self::MEDIUM_CODE)->count();
            if ($nativeCount === 0) {
                // Fresh migrate vor Katalog-Seed: noop; Bootstrapper setzt denselben Vertrag.
                return;
            }
            if ($nativeCount !== 1) {
                throw new RuntimeException(
                    'NATIVE-5: erwartet genau ein advertising_media mit code=native_ad.',
                );
            }

            $methodCount = (int) DB::table('calculation_methods')->where('key', self::METHOD_KEY)->count();
            if ($methodCount !== 1) {
                throw new RuntimeException(
                    'NATIVE-5: erwartet genau eine calculation_methods mit key=free_position.',
                );
            }

            $podcastCount = (int) DB::table('inventories')->where('code', self::PODCAST_INVENTORY)->count();
            if ($podcastCount !== 1) {
                throw new RuntimeException(
                    'NATIVE-5: erwartet genau ein Inventar inv_more_kombi_podcast.',
                );
            }

            $medium = DB::table('advertising_media')->where('code', self::MEDIUM_CODE)->first();
            $categoryKey = DB::table('advertising_categories')
                ->where('id', (int) $medium->category_id)
                ->value('key');
            if ($categoryKey !== self::CATEGORY_KEY) {
                throw new RuntimeException(
                    'NATIVE-5: native_ad muss Kategorie online_audio haben.',
                );
            }

            $methodId = (int) DB::table('calculation_methods')->where('key', self::METHOD_KEY)->value('id');
            $this->configureNativeAd((int) $medium->id, $methodId);
            $this->ensurePodcastRule((int) $medium->id);
            $this->assertMoreKombiOnlineAudioAbsent((int) $medium->id);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('advertising_media')
            || ! Schema::hasTable('advertising_medium_calculation_methods')
            || ! Schema::hasTable('inventory_medium_rules')
        ) {
            return;
        }

        DB::transaction(function (): void {
            $medium = DB::table('advertising_media')->where('code', self::MEDIUM_CODE)->first();
            if ($medium === null) {
                return;
            }
            $mediumId = (int) $medium->id;

            $methodId = DB::table('calculation_methods')->where('key', self::METHOD_KEY)->value('id');
            if ($methodId !== null) {
                $this->rollbackNativeAdIfUnchanged($mediumId, (int) $methodId);
            }

            $this->rollbackPodcastRuleIfUnchanged($mediumId);
        });
    }

    private function configureNativeAd(int $mediumId, int $methodId): void
    {
        $medium = DB::table('advertising_media')->where('id', $mediumId)->first();
        $assignments = DB::table('advertising_medium_calculation_methods')
            ->where('advertising_medium_id', $mediumId)
            ->get();

        $freeRows = $assignments->filter(
            fn ($row): bool => (int) $row->calculation_method_id === $methodId,
        );

        if ($freeRows->count() > 1) {
            throw new RuntimeException(
                'NATIVE-5: native_ad hat mehrdeutige free_position-Zuordnungen.',
            );
        }

        $foreignActive = $assignments->first(
            fn ($row): bool => (int) $row->calculation_method_id !== $methodId
                && (bool) $row->is_active,
        );
        if ($foreignActive !== null) {
            throw new RuntimeException(
                'NATIVE-5: native_ad hat widersprüchliches aktives Assignment (nicht free_position).',
            );
        }

        if ($freeRows->count() === 1) {
            $row = $freeRows->first();
            if ((string) $row->engine_profile_key !== self::PROFILE_KEY
                || ! (bool) $row->is_active
                || (int) $row->sort !== self::SORT
            ) {
                throw new RuntimeException(
                    'NATIVE-5: bestehendes free_position-Assignment weicht vom Release-Vertrag ab.',
                );
            }

            if ((string) $medium->kind !== self::METHOD_KEY
                || (string) $medium->calculation_method_mode !== 'override'
                || (int) $medium->default_calculation_method_id !== $methodId
            ) {
                throw new RuntimeException(
                    'NATIVE-5: native_ad Medium-Vertrag weicht vom Free-Position-Release ab.',
                );
            }

            // Discount/AE Initialzustand absichern, ohne Admin-false zu überschreiben wenn schon true.
            if (! (bool) $medium->is_discountable || ! (bool) $medium->is_ae_eligible) {
                throw new RuntimeException(
                    'NATIVE-5: native_ad muss is_discountable und is_ae_eligible = true haben.',
                );
            }

            return;
        }

        $canInstallFresh = (
            ($medium->kind === null || $medium->kind === '')
            && (string) $medium->calculation_method_mode === 'inherit'
            && $medium->default_calculation_method_id === null
            && $assignments->isEmpty()
        );

        if (! $canInstallFresh) {
            throw new RuntimeException(
                'NATIVE-5: native_ad weicht vom erwarteten Pre-State ab und wird nicht überschrieben.',
            );
        }

        $now = now();
        DB::table('advertising_media')->where('id', $mediumId)->update([
            'kind' => self::METHOD_KEY,
            'calculation_method_mode' => 'override',
            'default_calculation_method_id' => $methodId,
            'is_discountable' => true,
            'is_ae_eligible' => true,
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

    private function ensurePodcastRule(int $mediumId): void
    {
        $inventoryId = (int) DB::table('inventories')->where('code', self::PODCAST_INVENTORY)->value('id');
        $existing = DB::table('inventory_medium_rules')
            ->where('inventory_id', $inventoryId)
            ->where('advertising_medium_id', $mediumId)
            ->get();

        if ($existing->count() > 1) {
            throw new RuntimeException(
                'NATIVE-5: doppelte Podcast×native_ad Rules.',
            );
        }

        if ($existing->count() === 1) {
            $row = $existing->first();
            if (! (bool) $row->is_active
                || (string) $row->planning_responsibility_key !== self::PLANNING_KEY
            ) {
                throw new RuntimeException(
                    'NATIVE-5: bestehende Podcast×native_ad Rule weicht vom Release-Initialzustand ab.',
                );
            }

            return;
        }

        $now = now();
        DB::table('inventory_medium_rules')->insert([
            'inventory_id' => $inventoryId,
            'advertising_medium_id' => $mediumId,
            'is_active' => true,
            'booking_code' => self::BOOKING_CODE,
            'planning_responsibility_key' => self::PLANNING_KEY,
            'hint_text' => null,
            'sort' => 0,
            'default_length_seconds' => 30,
            'surcharge_percent' => 0,
            'is_discountable' => true,
            'is_ae_eligible' => true,
            'component_calculation_strategy' => 'shared_total_length',
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'swf_allonge_confirmed_at' => null,
        ]);
    }

    private function assertMoreKombiOnlineAudioAbsent(int $mediumId): void
    {
        $inventoryId = DB::table('inventories')->where('code', self::MORE_OA_INVENTORY)->value('id');
        if ($inventoryId === null) {
            return;
        }

        $exists = DB::table('inventory_medium_rules')
            ->where('inventory_id', (int) $inventoryId)
            ->where('advertising_medium_id', $mediumId)
            ->exists();

        if ($exists) {
            throw new RuntimeException(
                'NATIVE-5: inv_more_kombi_online_audio × native_ad darf nicht existieren.',
            );
        }
    }

    private function rollbackNativeAdIfUnchanged(int $mediumId, int $methodId): void
    {
        $kind = DB::table('advertising_media')->where('id', $mediumId)->value('kind');
        $mode = DB::table('advertising_media')->where('id', $mediumId)->value('calculation_method_mode');
        $defaultMethodId = DB::table('advertising_media')
            ->where('id', $mediumId)
            ->value('default_calculation_method_id');

        $assignments = DB::table('advertising_medium_calculation_methods')
            ->where('advertising_medium_id', $mediumId)
            ->get();

        $assignment = $assignments->count() === 1 ? $assignments->first() : null;
        $exactlyRelease = (string) $kind === self::METHOD_KEY
            && (string) $mode === 'override'
            && (int) $defaultMethodId === $methodId
            && $assignment !== null
            && (int) $assignment->calculation_method_id === $methodId
            && (string) $assignment->engine_profile_key === self::PROFILE_KEY
            && (bool) $assignment->is_active
            && (int) $assignment->sort === self::SORT;

        if (! $exactlyRelease) {
            // Spätere Adminänderungen: fail-safe, kein Blind-Reset.
            return;
        }

        DB::table('advertising_medium_calculation_methods')
            ->where('advertising_medium_id', $mediumId)
            ->where('calculation_method_id', $methodId)
            ->delete();

        DB::table('advertising_media')->where('id', $mediumId)->update([
            'kind' => null,
            'calculation_method_mode' => 'inherit',
            'default_calculation_method_id' => null,
            'updated_at' => now(),
        ]);
    }

    private function rollbackPodcastRuleIfUnchanged(int $mediumId): void
    {
        $inventoryId = DB::table('inventories')->where('code', self::PODCAST_INVENTORY)->value('id');
        if ($inventoryId === null) {
            return;
        }

        $rows = DB::table('inventory_medium_rules')
            ->where('inventory_id', (int) $inventoryId)
            ->where('advertising_medium_id', $mediumId)
            ->get();

        if ($rows->count() !== 1) {
            return;
        }

        $row = $rows->first();
        $exactlyRelease = (bool) $row->is_active
            && (string) $row->planning_responsibility_key === self::PLANNING_KEY
            && (string) $row->booking_code === self::BOOKING_CODE
            && (int) $row->default_length_seconds === 30
            && (bool) $row->is_discountable
            && (bool) $row->is_ae_eligible;

        if (! $exactlyRelease) {
            return;
        }

        DB::table('inventory_medium_rules')->where('id', (int) $row->id)->delete();
    }

    private function assertTables(): void
    {
        foreach ([
            'advertising_categories',
            'advertising_media',
            'calculation_methods',
            'advertising_medium_calculation_methods',
            'inventories',
            'inventory_medium_rules',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("NATIVE-5: Voraussetzung fehlt ({$table}).");
            }
        }
    }
};
