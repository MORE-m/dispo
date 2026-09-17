import { describe, expect, it } from 'vitest';
import {
    formatFixedPriceNnInput,
    isFixedPriceSettlement,
    parseFixedPriceNnInput,
    pricingSettlementDraftFromSaved,
    settlementPayloadFields,
} from '@/lib/pricing-settlement';

describe('pricing-settlement', () => {
    it('detects fixed price settlement mode', () => {
        expect(isFixedPriceSettlement('fixed_price')).toBe(true);
        expect(isFixedPriceSettlement('normal')).toBe(false);
        expect(isFixedPriceSettlement(null)).toBe(false);
    });

    it('parses German fixed price input', () => {
        expect(parseFixedPriceNnInput('1.250,50')).toBe('1250.50');
        expect(parseFixedPriceNnInput('250,00')).toBe('250.00');
        expect(parseFixedPriceNnInput('250')).toBe('250.00');
        expect(parseFixedPriceNnInput('')).toBe(null);
        expect(parseFixedPriceNnInput('0')).toBe(null);
    });

    it('rejects invalid and negative fixed price input', () => {
        expect(parseFixedPriceNnInput('-10,00')).toBe(null);
        expect(parseFixedPriceNnInput('-1')).toBe(null);
        expect(parseFixedPriceNnInput('abc')).toBe(null);
        expect(parseFixedPriceNnInput('12,345')).toBe(null);
    });

    it('formats stored amounts for draft input', () => {
        expect(formatFixedPriceNnInput('1250.50')).toBe('1.250,5');
        expect(formatFixedPriceNnInput('250.00')).toBe('250');
    });

    it('reloads draft from saved fixed position', () => {
        expect(
            pricingSettlementDraftFromSaved({
                pricing_settlement_mode: 'fixed_price',
                fixed_price_nn: '1250.50',
            }),
        ).toEqual({
            pricing_settlement_mode: 'fixed_price',
            fixed_price_nn_input: '1.250,5',
        });

        expect(
            pricingSettlementDraftFromSaved({
                pricing_settlement_mode: 'normal',
                fixed_price_nn: '999.00',
            }),
        ).toEqual({
            pricing_settlement_mode: 'normal',
            fixed_price_nn_input: '999',
        });
    });

    it('builds payload fields and keeps draft input in normal mode', () => {
        expect(
            settlementPayloadFields({
                pricing_settlement_mode: 'normal',
                fixed_price_nn_input: '500,00',
            }),
        ).toEqual({
            pricing_settlement_mode: 'normal',
            fixed_price_nn: null,
        });

        expect(
            settlementPayloadFields({
                pricing_settlement_mode: 'fixed_price',
                fixed_price_nn_input: '1.250,50',
            }),
        ).toEqual({
            pricing_settlement_mode: 'fixed_price',
            fixed_price_nn: '1250.50',
        });
    });
});
