<?php

namespace App\Support\Calculation;

use App\Enums\CalculationMethodMode;
use App\Models\AdvertisingMedium;
use App\Models\CalculationMethod;
use App\Services\Calculation\OaTkp\OaTkpWriterPositionResolver;
use App\Support\OnlineAudio\OaTkpCoreMedia;

/**
 * PO-CALC-TKP-1D2a: Soft-Open für Wizard-Method-Options ohne LiveBookability-Release.
 *
 * Nur für den Kalkulationswizard unter {@see OaTkpWizardCapability}.
 * Standard Offers / Admin / andere Consumer bleiben released-only.
 *
 * Erkennung analog Writer: aktive Zuordnung oa_tkp × tkp + Kernmedien-Scope
 * (historisch oft kind=null; kein Medium-Code-Branching für Methodenwahl).
 */
final class OaTkpWizardMethodAvailability
{
    /**
     * @return array{key: string, name: string, help_text: string|null, is_default: bool}|null
     */
    public function optionForMedium(AdvertisingMedium $medium): ?array
    {
        if (! OaTkpWizardCapability::allowsWizardPreviewSurface()) {
            return null;
        }

        if (OaTkpWizardCapability::pairIsReleased()) {
            // Released-Pfad läuft über LiveBookability; hier kein Doppel-Eintrag.
            return null;
        }

        $medium->loadMissing([
            'defaultCalculationMethod',
            'category.defaultCalculationMethod',
            'calculationMethodAssignments.calculationMethod',
            'category.calculationMethodAssignments.calculationMethod',
        ]);

        $code = trim((string) $medium->code);
        if ($code === '' || $code === 'native_ad' || ! OaTkpCoreMedia::contains($code)) {
            return null;
        }

        $method = $this->resolveAssignedTkpMethod($medium);
        if ($method === null || ! $method->is_active) {
            return null;
        }

        $name = trim((string) $method->name);
        if ($name === '') {
            return null;
        }

        $defaultKey = $this->resolveDefaultMethodKey($medium);
        $isDefault = $defaultKey === OaTkpWriterPositionResolver::METHOD_KEY;

        return [
            'key' => OaTkpWriterPositionResolver::METHOD_KEY,
            'name' => $name,
            'help_text' => $this->nullableHelpText($method->help_text),
            'is_default' => $isDefault,
        ];
    }

    private function resolveAssignedTkpMethod(AdvertisingMedium $medium): ?CalculationMethod
    {
        $assignments = $medium->calculation_method_mode === CalculationMethodMode::Override
            ? $medium->calculationMethodAssignments
            : ($medium->category !== null
                ? $medium->category->calculationMethodAssignments
                : collect());

        foreach ($assignments as $assignment) {
            if (! (bool) ($assignment->is_active ?? false)) {
                continue;
            }
            if (trim((string) ($assignment->engine_profile_key ?? '')) !== EngineProfileRegistry::PROFILE_OA_TKP) {
                continue;
            }
            $method = $assignment->calculationMethod;
            if ($method === null || (string) $method->key !== OaTkpWriterPositionResolver::METHOD_KEY) {
                continue;
            }

            return $method;
        }

        return null;
    }

    private function resolveDefaultMethodKey(AdvertisingMedium $medium): ?string
    {
        if ($medium->calculation_method_mode === CalculationMethodMode::Override) {
            $key = $medium->defaultCalculationMethod?->key;

            return $key !== null && $key !== '' ? (string) $key : null;
        }

        $key = $medium->category?->defaultCalculationMethod?->key;

        return $key !== null && $key !== '' ? (string) $key : null;
    }

    private function nullableHelpText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
