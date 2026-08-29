import { money, formatSecondPrice } from '@/components/form-field';

export function PositionPriceSummary({
    averageSecondPrice,
    mediaGross,
    nnInvest,
}: {
    averageSecondPrice?: string | null;
    mediaGross: string;
    nnInvest: string;
}) {
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
                Brutto{' '}
                <span className="text-foreground font-medium">
                    {money(mediaGross)}
                </span>
            </span>
            <span aria-hidden="true" className="text-border hidden sm:inline">
                ·
            </span>
            <span className="text-muted-foreground">
                Netto{' '}
                <span className="text-foreground font-medium">
                    {money(nnInvest)}
                </span>
            </span>
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
