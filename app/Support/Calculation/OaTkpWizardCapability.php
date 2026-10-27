<?php

namespace App\Support\Calculation;

use App\Enums\EngineCapabilityStatus;
use App\Services\Calculation\OaTkp\OaTkpWriterPositionResolver;

/**
 * PO-CALC-TKP-1D2a / 1D4b-1: Wizard-Development-Capability für Normal-TKP.
 *
 * Getrennt vom Domain-/Registry-Release (`pair_status = released`):
 * - Capability ON: Method Options (nur Kalkulationswizard), HTTP Preview, Pricing Metadata
 * - Capability OFF: D1-Verhalten (alles HTTP-TKP blocked, Options ohne TKP)
 * - Store/Update / UI-Save: nur bei Registry-Release (`allowsHttpMutation`)
 *
 * Kein Environment-/NODE_ENV-Hack: nur zentrale Config `dispo.oa_tkp_wizard_capability`
 * (Default false). Tests setzen die Config explizit.
 *
 * PO-CALC-TKP-1D4b-1: Test-only-Fake für `allowsHttpMutation` simuliert das
 * Release-Signal isoliert – ohne produktive Registry/CATALOG zu ändern.
 */
final class OaTkpWizardCapability
{
    public const CONFIG_KEY = 'dispo.oa_tkp_wizard_capability';

    /**
     * Isolierte Testsimulation des Registry-Release-Signals für HTTP-Mutation/Save.
     * null = produktiver Pair-Status; true/false = Test-Override.
     */
    private static ?bool $httpMutationOverrideForTests = null;

    public static function isEnabled(): bool
    {
        return (bool) config(self::CONFIG_KEY, false);
    }

    /**
     * Preview/Metadata/Wizard-Options: freigegeben, wenn Pair released ODER Capability aktiv
     * und Engine v1 mindestens implemented.
     */
    public static function allowsWizardPreviewSurface(): bool
    {
        if (self::pairIsReleased()) {
            return true;
        }

        return self::isEnabled() && self::engineVersionIsImplemented();
    }

    /**
     * HTTP Store/Update und UI-Save für TKP: nur bei echtem Registry-Release.
     * Capability soft-öffnet Persistenz bewusst nicht.
     *
     * In Unit-/Feature-Tests darf {@see fakeHttpMutationAllowedForTests()} das Signal
     * isoliert setzen; die produktive EngineProfileRegistry bleibt unverändert.
     */
    public static function allowsHttpMutation(): bool
    {
        if (self::$httpMutationOverrideForTests !== null) {
            return self::$httpMutationOverrideForTests;
        }

        return self::pairIsReleased();
    }

    /**
     * Test-only: simuliert Registry-Release-Signal für Mutation/Save.
     * Wirft außerhalb von Unit-Tests – kein produktiver Schalter.
     */
    public static function fakeHttpMutationAllowedForTests(?bool $allowed): void
    {
        if (! app()->runningUnitTests()) {
            throw new \LogicException(
                'OA-TKP HTTP-Mutation-Fake ist nur in Unit-/Feature-Tests erlaubt.',
            );
        }

        self::$httpMutationOverrideForTests = $allowed;
    }

    /**
     * Test-only: Override zurücksetzen (tearDown).
     */
    public static function clearHttpMutationFakeForTests(): void
    {
        self::$httpMutationOverrideForTests = null;
    }

    public static function pairIsReleased(): bool
    {
        return EngineProfileRegistry::pairStatus(
            EngineProfileRegistry::PROFILE_OA_TKP,
            OaTkpWriterPositionResolver::METHOD_KEY,
        ) === EngineCapabilityStatus::Released;
    }

    public static function engineVersionIsImplemented(): bool
    {
        $status = EngineProfileRegistry::statusForVersion(
            EngineProfileRegistry::PROFILE_OA_TKP,
            OaTkpWriterPositionResolver::METHOD_KEY,
            'v1',
        );

        return $status === EngineCapabilityStatus::Implemented
            || $status === EngineCapabilityStatus::Released;
    }
}
