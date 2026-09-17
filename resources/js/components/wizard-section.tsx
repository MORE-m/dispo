import {
    formatPercent,
    money,
    formatSecondPrice,
} from '@/components/form-field';
import { isFixedPriceSettlement } from '@/lib/pricing-settlement';

export function PositionPriceSummary({
    positionIndex,
    averageSecondPrice,
    mediaGross,
    nnInvest,
    pricingSettlementMode,
    effectivePayFactorPercent,
    effectiveDiscountPercent,
}: {
    positionIndex: number;
    averageSecondPrice?: string | null;
    mediaGross: string;
    nnInvest: string;
    pricingSettlementMode?: string | null;
    effectivePayFactorPercent?: string | null;
    effectiveDiscountPercent?: string | null;
}) {
    const fixedPrice = isFixedPriceSettlement(pricingSettlementMode);

    return (
        <div className="bg-muted/20 border-border/60 flex flex-wrap gap-x-3 gap-y-1 rounded-lg border px-3 py-2 text-sm">
            <span className="text-muted-foreground">
                Ø-Sekundenpreis{' '}
                <span className="text-foreground font-medium">
                    {formatSecondPrice(averageSecondPrice)}
                </span>
            </span>
            <span aria-hidden="true" className="text-border hidden sm:inline">
                ·
            </span>
            <span className="text-muted-foreground">
                Mediabrutto{' '}
                <span
                    className="text-foreground font-medium"
                    data-test={`fixed-price-media-gross-${positionIndex}`}
                >
                    {money(mediaGross)}
                </span>
            </span>
            <span aria-hidden="true" className="text-border hidden sm:inline">
                ·
            </span>
            <span className="text-muted-foreground">
                {fixedPrice ? 'N/N-Festpreis' : 'Netto'}{' '}
                <span
                    className="text-foreground font-medium"
                    data-test={`fixed-price-nn-preview-${positionIndex}`}
                >
                    {money(nnInvest)}
                </span>
            </span>
            {fixedPrice && effectivePayFactorPercent ? (
                <>
                    <span
                        aria-hidden="true"
                        className="text-border hidden sm:inline"
                    >
                        ·
                    </span>
                    <span className="text-muted-foreground">
                        Payfaktor{' '}
                        <span
                            className="text-foreground font-medium"
                            data-test={`fixed-price-pay-factor-${positionIndex}`}
                        >
                            {formatPercent(effectivePayFactorPercent)}
                        </span>
                    </span>
                </>
            ) : null}
            {fixedPrice && effectiveDiscountPercent ? (
                <>
                    <span
                        aria-hidden="true"
                        className="text-border hidden sm:inline"
                    >
                        ·
                    </span>
                    <span className="text-muted-foreground">
                        Gesamtabschlag{' '}
                        <span
                            className="text-foreground font-medium"
                            data-test={`fixed-price-discount-${positionIndex}`}
                        >
                            {formatPercent(effectiveDiscountPercent)}
                        </span>
                    </span>
                </>
            ) : null}
        </div>
    );
}

/** Shared card shell classes for wizard sections. */
export const wizardCardClass =
    'gap-0 overflow-hidden rounded-xl border-border/70 py-0 shadow-xs';

export const wizardCardHeaderClass =
    'border-border/60 bg-muted/20 border-b px-5 py-4';

export const wizardCardTitleClass =
    'text-sm font-semibold tracking-tight text-foreground';

export const wizardCardContentClass = 'px-5 py-5';
