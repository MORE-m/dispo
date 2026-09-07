<?php

namespace App\Enums;

/**
 * DF-3.3a1 / DYN-002: Ziel-Ebene eines Field-Set-Assignments.
 */
enum FieldSetAssignmentTargetLayer: string
{
    case Global = 'global';
    case AdvertisingCategory = 'advertising_category';
    case AdvertisingMedium = 'advertising_medium';
}
