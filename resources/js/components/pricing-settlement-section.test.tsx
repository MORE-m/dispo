import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { PricingSettlementSection } from './pricing-settlement-section';

afterEach(() => {
    cleanup();
});

describe('PricingSettlementSection', () => {
    it('renders settlement modes and fixed price input', () => {
        const onModeChange = vi.fn();
        const onFixedPriceInputChange = vi.fn();

        render(
            <PricingSettlementSection
                positionIndex={0}
                mode="fixed_price"
                fixedPriceNnInput="250,00"
                showFixedPriceValidation={false}
                onModeChange={onModeChange}
                onFixedPriceInputChange={onFixedPriceInputChange}
            />,
        );

        expect(
            screen.getByTestId('pricing-settlement-mode-0'),
        ).toBeTruthy();
        expect(
            screen.getByTestId('pricing-settlement-option-0-fixed_price'),
        ).toBeTruthy();
        expect(screen.getByTestId('fixed-price-nn-0')).toHaveValue('250,00');

        fireEvent.click(
            screen.getByTestId('pricing-settlement-radio-0-normal'),
        );
        expect(onModeChange).toHaveBeenCalledWith('normal');
    });
});
