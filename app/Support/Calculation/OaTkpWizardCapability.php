<?php

namespace App\Support\Calculation;

use App\Enums\EngineCapabilityStatus;
use App\Services\Calculation\OaTkp\OaTkpWriterPositionResolver;

/**
 * PO-CALC-TKP-1D2a: Wizard-Development-Capability für Normal-TKP.
 *
 * Getrennt vom Domain-/Registry-Release (`pair_status = released`):
 * - Capability ON: Method Options (nur Kalkulationswizard), HTTP Preview, Pricing Metadata
 * - Capability OFF: D1-Verhalten (alles HTTP-TKP blocked, Options ohne TKP)
 * - Store/Update bleiben auch bei Capability ON geschlossen (solange Pair planned)
 *
 * Kein Environment-/NODE_ENV-Hack: nur zentrale Config `dispo.oa_tkp_wizard_capability`
 * (Default false). Tests setzen die Config explizit.
 */
final class OaTkpWizardCapability
{
    public const CONFIG_KEY = 'dispo.oa_tkp_wizard_capability';

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
     * HTTP Store/Update für TKP: nur bei echtem Registry-Release.
     * Capability soft-öffnet Persistenz bewusst nicht.
     */
    public static function allowsHttpMutation(): bool
    {
        return self::pairIsReleased();
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
