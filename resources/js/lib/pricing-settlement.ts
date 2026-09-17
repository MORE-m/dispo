export type PricingSettlementMode = 'normal' | 'fixed_price';

export function isFixedPriceSettlement(
    mode: string | null | undefined,
): boolean {
    return mode === 'fixed_price';
}

export function parseFixedPriceNnInput(raw: string): string | null {
    const trimmed = raw.trim();
    if (trimmed === '') {
        return null;
    }

    let normalized = trimmed.replace(/\s/g, '').replace(/€/g, '');

    if (normalized.includes(',')) {
        normalized = normalized.replace(/\./g, '').replace(',', '.');
    } else {
        const dotMatches = normalized.match(/\./g);
        const dotCount = dotMatches?.length ?? 0;
        if (
            dotCount > 1 ||
            (dotCount === 1 && /^\d{1,3}(\.\d{3})+$/.test(normalized))
        ) {
            normalized = normalized.replace(/\./g, '');
        }
    }

    if (!/^\d+(\.\d{1,2})?$/.test(normalized)) {
        return null;
    }

    const amount = Number(normalized);
    if (!Number.isFinite(amount) || amount <= 0) {
        return null;
    }

    return amount.toFixed(2);
}

export function formatFixedPriceNnInput(
    stored: string | null | undefined,
): string {
    if (stored === null || stored === undefined || stored === '') {
        return '';
    }

    const amount = Number(stored);
    if (!Number.isFinite(amount)) {
        return '';
    }

    return new Intl.NumberFormat('de-DE', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    }).format(amount);
}

export function settlementPayloadFields(draft: {
    pricing_settlement_mode: PricingSettlementMode;
    fixed_price_nn_input: string;
}): {
    pricing_settlement_mode: PricingSettlementMode;
    fixed_price_nn: string | null;
} {
    if (draft.pricing_settlement_mode === 'fixed_price') {
        return {
            pricing_settlement_mode: 'fixed_price',
            fixed_price_nn: parseFixedPriceNnInput(draft.fixed_price_nn_input),
        };
    }

    return {
        pricing_settlement_mode: 'normal',
        fixed_price_nn: null,
    };
}

export function fixedPriceSettlementValidationMessage(draft: {
    pricing_settlement_mode: PricingSettlementMode;
    fixed_price_nn_input: string;
}): string | null {
    if (draft.pricing_settlement_mode !== 'fixed_price') {
        return null;
    }

    if (parseFixedPriceNnInput(draft.fixed_price_nn_input) === null) {
        return 'Festpreis erfordert einen N/N-Endbetrag größer 0.';
    }

    return null;
}

export function defaultPricingSettlementDraft(): {
    pricing_settlement_mode: PricingSettlementMode;
    fixed_price_nn_input: string;
} {
    return {
        pricing_settlement_mode: 'normal',
        fixed_price_nn_input: '',
    };
}

export function pricingSettlementDraftFromSaved(position: {
    pricing_settlement_mode?: string | null;
    fixed_price_nn?: string | null;
}): {
    pricing_settlement_mode: PricingSettlementMode;
    fixed_price_nn_input: string;
} {
    const mode =
        position.pricing_settlement_mode === 'fixed_price'
            ? 'fixed_price'
            : 'normal';

    return {
        pricing_settlement_mode: mode,
        fixed_price_nn_input: formatFixedPriceNnInput(position.fixed_price_nn),
    };
}
