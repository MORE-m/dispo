<?php

namespace App\Enums;

/**
 * ADV-001c1: Implementierungs-/Freigabestatus einer Engine-Profil×Methode×Version-Kombination.
 */
enum EngineCapabilityStatus: string
{
    case Planned = 'planned';
    case Implemented = 'implemented';
    case Released = 'released';
}
