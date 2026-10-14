<?php

namespace App\Enums;

enum CalculationKind: string
{
    case SpotClassic = 'spot_classic';

    /** BL-P5-01a / SWF-001–SWF-005: Trailer (nur trailer_station_voice), kein Spotlängenindex. */
    case SwfTrailer = 'swf_trailer';

    /** PO-ALLONGE-1: SWF-Medium Allonge (nur allonge × Durchschnitt), kein Spotlängenindex. */
    case SwfAllonge = 'swf_allonge';
}
