<?php

namespace Tests\Concerns;

use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use App\Support\Calculation\EngineProfileRegistry;

/**
 * PO-SWF-CALENDAR-1: Calendar-Zuordnung für SWF-Testkataloge ergänzen.
 */
trait AddsSwfCalendarMethodAssignment
{
    protected function assignSwfMediumCalendar(AdvertisingMedium $medium, string $engineProfileKey): void
    {
        $calendar = CalculationMethod::query()->where('key', 'calendar')->firstOrFail();

        $exists = AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $medium->id)
            ->where('calculation_method_id', $calendar->id)
            ->where('engine_profile_key', $engineProfileKey)
            ->exists();

        if ($exists) {
            return;
        }

        $assignment = new AdvertisingMediumCalculationMethod;
        $assignment->advertising_medium_id = $medium->id;
        $assignment->calculation_method_id = $calendar->id;
        $assignment->engine_profile_key = $engineProfileKey;
        $assignment->is_active = true;
        $assignment->sort = 20;
        $assignment->lock_version = 1;
        $assignment->save();
    }

    protected function assignSwfCategoryCalendarToTrailerProfile(): void
    {
        $category = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS)
            ->firstOrFail();
        $calendar = CalculationMethod::query()->where('key', 'calendar')->firstOrFail();

        $exists = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $category->id)
            ->where('calculation_method_id', $calendar->id)
            ->where('engine_profile_key', EngineProfileRegistry::PROFILE_SWF_TRAILER)
            ->exists();

        if ($exists) {
            return;
        }

        $assignment = new AdvertisingCategoryCalculationMethod;
        $assignment->advertising_category_id = $category->id;
        $assignment->calculation_method_id = $calendar->id;
        $assignment->engine_profile_key = EngineProfileRegistry::PROFILE_SWF_TRAILER;
        $assignment->is_active = true;
        $assignment->sort = 20;
        $assignment->lock_version = 1;
        $assignment->save();
    }
}
