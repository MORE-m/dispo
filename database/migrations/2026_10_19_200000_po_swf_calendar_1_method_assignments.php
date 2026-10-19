<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PO-SWF-CALENDAR-1: Calendar-Methoden-Zuordnungen für operative SWF-Profile.
 *
 * - Kategorie special_advertising_formats: calendar + Profil swf_trailer (Trailer inherit)
 * - Medien allonge / promo_moderation / contest_payoff: calendar am jeweiligen Override-Profil
 *
 * Average bleibt Default. Settlement / fixed_price Method bleiben OUT.
 * Idempotent; abweichende Verträge fail-closed.
 */
return new class extends Migration
{
    private const SWF_CATEGORY_KEY = 'special_advertising_formats';

    /** @var list<array{code: string, kind: string, profile: string}> */
    private const OVERRIDE_MEDIA = [
        ['code' => 'allonge', 'kind' => 'swf_allonge', 'profile' => 'swf_allonge'],
        ['code' => 'promo_moderation', 'kind' => 'swf_promo', 'profile' => 'swf_promo'],
        ['code' => 'contest_payoff', 'kind' => 'swf_contest', 'profile' => 'swf_contest'],
    ];

    public function up(): void
    {
        $this->assertPrerequisites();

        DB::transaction(function (): void {
            $averageId = $this->methodId('average');
            $calendarId = $this->methodId('calendar');

            $this->ensureCategoryCalendar($averageId, $calendarId);

            foreach (self::OVERRIDE_MEDIA as $media) {
                $this->ensureMediumCalendar(
                    $media['code'],
                    $media['kind'],
                    $media['profile'],
                    $averageId,
                    $calendarId,
                );
            }
        });
    }

    public function down(): void
    {
        $this->assertDownSafe();

        $calendarId = DB::table('calculation_methods')->where('key', 'calendar')->value('id');
        if ($calendarId === null) {
            return;
        }
        $calendarId = (int) $calendarId;

        $categoryId = DB::table('advertising_categories')->where('key', self::SWF_CATEGORY_KEY)->value('id');
        if ($categoryId !== null) {
            DB::table('advertising_category_calculation_methods')
                ->where('advertising_category_id', (int) $categoryId)
                ->where('calculation_method_id', $calendarId)
                ->where('engine_profile_key', 'swf_trailer')
                ->delete();
        }

        foreach (self::OVERRIDE_MEDIA as $media) {
            $mediumId = DB::table('advertising_media')->where('code', $media['code'])->value('id');
            if ($mediumId === null) {
                continue;
            }
            DB::table('advertising_medium_calculation_methods')
                ->where('advertising_medium_id', (int) $mediumId)
                ->where('calculation_method_id', $calendarId)
                ->where('engine_profile_key', $media['profile'])
                ->delete();
        }
    }

    private function assertPrerequisites(): void
    {
        foreach ([
            'advertising_categories',
            'advertising_media',
            'calculation_methods',
            'advertising_category_calculation_methods',
            'advertising_medium_calculation_methods',
            'calculation_positions',
            'dispo_order_positions',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("PO-SWF-CALENDAR-1: Voraussetzung fehlt ({$table}).");
            }
        }
    }

    private function methodId(string $key): int
    {
        $id = DB::table('calculation_methods')->where('key', $key)->value('id');
        if ($id === null) {
            throw new RuntimeException("PO-SWF-CALENDAR-1: calculation_methods.{$key} fehlt.");
        }

        return (int) $id;
    }

    private function ensureCategoryCalendar(int $averageId, int $calendarId): void
    {
        $categoryId = DB::table('advertising_categories')->where('key', self::SWF_CATEGORY_KEY)->value('id');
        if ($categoryId === null) {
            return;
        }
        $categoryId = (int) $categoryId;

        $hasAverage = DB::table('advertising_category_calculation_methods')
            ->where('advertising_category_id', $categoryId)
            ->where('calculation_method_id', $averageId)
            ->where('engine_profile_key', 'swf_trailer')
            ->where('is_active', true)
            ->exists();

        // Ohne vorherigen Trailer-Average-Vertrag (z. B. leere Test-DB) nichts tun.
        if (! $hasAverage) {
            return;
        }

        $exists = DB::table('advertising_category_calculation_methods')
            ->where('advertising_category_id', $categoryId)
            ->where('calculation_method_id', $calendarId)
            ->where('engine_profile_key', 'swf_trailer')
            ->exists();

        if ($exists) {
            return;
        }

        $now = now();
        DB::table('advertising_category_calculation_methods')->insert([
            'advertising_category_id' => $categoryId,
            'calculation_method_id' => $calendarId,
            'engine_profile_key' => 'swf_trailer',
            'is_active' => true,
            'sort' => 20,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function ensureMediumCalendar(
        string $code,
        string $kind,
        string $profile,
        int $averageId,
        int $calendarId,
    ): void {
        $medium = DB::table('advertising_media')->where('code', $code)->first();
        if ($medium === null) {
            return;
        }

        // Medium noch nicht freigeschaltet (leere Test-DB / Katalog ohne Slice) → skip.
        if ((string) $medium->kind !== $kind) {
            return;
        }

        if ((string) $medium->calculation_method_mode !== 'override'
            || (int) $medium->default_calculation_method_id !== $averageId
        ) {
            return;
        }

        $assignments = DB::table('advertising_medium_calculation_methods')
            ->where('advertising_medium_id', $medium->id)
            ->get();

        $hasAverage = $assignments->contains(
            fn ($row): bool => (int) $row->calculation_method_id === $averageId
                && (string) $row->engine_profile_key === $profile
                && (bool) $row->is_active,
        );

        if (! $hasAverage) {
            return;
        }

        $hasCalendar = $assignments->contains(
            fn ($row): bool => (int) $row->calculation_method_id === $calendarId
                && (string) $row->engine_profile_key === $profile,
        );

        if ($hasCalendar) {
            return;
        }

        // Bekannter Vorzustand: genau eine Average-Zuordnung. Sonst fail-closed.
        if ($assignments->count() !== 1) {
            throw new RuntimeException(
                "PO-SWF-CALENDAR-1: Medium {$code} hat unerwartete Methoden-Zuordnungen (nicht nur Average).",
            );
        }

        $now = now();
        DB::table('advertising_medium_calculation_methods')->insert([
            'advertising_medium_id' => $medium->id,
            'calculation_method_id' => $calendarId,
            'engine_profile_key' => $profile,
            'is_active' => true,
            'sort' => 20,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function assertDownSafe(): void
    {
        foreach (['calculation_positions', 'dispo_order_positions'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'calculation_method_key')) {
                continue;
            }
            if (! Schema::hasColumn($table, 'engine_profile_key')) {
                continue;
            }

            $exists = DB::table($table)
                ->where('calculation_method_key', 'calendar')
                ->whereIn('engine_profile_key', ['swf_trailer', 'swf_allonge', 'swf_promo', 'swf_contest'])
                ->exists();

            if ($exists) {
                throw new RuntimeException(
                    "PO-SWF-CALENDAR-1 down(): SWF-Calendar-Positionen in {$table} vorhanden – Rollback verweigert.",
                );
            }
        }
    }
};
