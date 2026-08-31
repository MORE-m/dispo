import { expect, test } from 'vitest';
import { formatPercent, moneyDeduction } from '@/components/form-field';

test('formatPercent removes trailing zeros', () => {
    expect(formatPercent('10.0000')).toBe('10 %');
    expect(formatPercent('7.5000')).toBe('7,5 %');
    expect(formatPercent(15)).toBe('15 %');
});

test('moneyDeduction prefixes negative amounts', () => {
    expect(moneyDeduction('120')).toBe('−120,00 €');
    expect(moneyDeduction('0')).toBe('0,00 €');
});
