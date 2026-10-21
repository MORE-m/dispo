<?php

namespace App\Enums;

enum CalculationKind: string
{
    case SpotClassic = 'spot_classic';

    /** BL-P5-01a / SWF-001–SWF-005: Trailer (nur trailer_station_voice), kein Spotlängenindex. */
    case SwfTrailer = 'swf_trailer';

    /** PO-ALLONGE-1: SWF-Medium Allonge (nur allonge × Durchschnitt), kein Spotlängenindex. */
    case SwfAllonge = 'swf_allonge';

    /** PO-SWF-PROMO-1: SWF-Medium Promo/Moderation (nur promo_moderation × Durchschnitt), kein Spotlängenindex. */
    case SwfPromo = 'swf_promo';

    /** PO-SWF-CONTEST-1: SWF-Medium Gewinnspiel/Pay-Off (nur contest_payoff × Durchschnitt), kein Spotlängenindex. */
    case SwfContest = 'swf_contest';

    /**
     * PO-CALC-TKP-1B: Online-Audio Normal-TKP (Positions-Freeze-Spiegel).
     * Medium-kind im Katalog bleibt null; Bookability bleibt planned/fail-closed.
     */
    case OaTkp = 'oa_tkp';
}
