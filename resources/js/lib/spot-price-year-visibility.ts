import { isFreePosition } from '@/lib/free-position-wizard';
import { isTkpPosition } from '@/lib/oa-tkp-wizard';

/**
 * Spot-/SWF-Preisjahr inkl. „keine aktive Liste“-Hinweis.
 * Nicht für OA-TKP (OA-Preislistenjahr) und nicht für Free Position/Native.
 */
export function shouldShowSpotPriceYearControls(position: {
    calculation_method_key: string | null;
}): boolean {
    return !isFreePosition(position) && !isTkpPosition(position);
}
