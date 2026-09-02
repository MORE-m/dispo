import { describe, expect, it } from 'vitest';
import {
    firstValidationMessage,
    humanizeValidationMessage,
    mapValidationErrors,
} from './validation-errors';

describe('validation-errors', () => {
    it('replaces known validation keys with German text', () => {
        expect(humanizeValidationMessage('validation.min.numeric')).toBe(
            'Der Wert muss den Mindestwert erfüllen.',
        );
        expect(humanizeValidationMessage('validation.required')).toBe(
            'Dieses Feld ist erforderlich.',
        );
    });

    it('keeps already translated messages', () => {
        const message =
            'Die Spotanzahl muss mindestens 1 betragen.';
        expect(humanizeValidationMessage(message)).toBe(message);
    });

    it('maps unknown validation keys to a generic hint', () => {
        expect(humanizeValidationMessage('validation.custom_rule')).toBe(
            'Bitte prüfe die markierten Felder.',
        );
    });

    it('maps error bags without validation keys', () => {
        const mapped = mapValidationErrors({
            'positions.0.time_ranges.0.spot_count': 'validation.min.numeric',
            target_budget_nn: 'Das Zielbudget muss größer als 0 sein.',
        });

        expect(mapped['positions.0.time_ranges.0.spot_count'][0]).not.toContain(
            'validation.',
        );
        expect(mapped.target_budget_nn[0]).toBe(
            'Das Zielbudget muss größer als 0 sein.',
        );
        expect(firstValidationMessage(mapped)).toBe(
            'Der Wert muss den Mindestwert erfüllen.',
        );
    });
});
