import { describe, expect, it } from 'vitest';
import {
    calculationMethodPayloadFields,
    emptyCalculationMethodDraftState,
    HISTORICAL_CALCULATION_METHOD_HINT,
    historicalMethodLabel,
    initMethodStateForExistingPosition,
    initMethodStateForNewPosition,
    isHistoricalMethodActive,
    methodStateAfterMediumIdChange,
    NO_CALCULATION_METHOD_MESSAGE,
    resetMethodStateForMediumChange,
    resolveDefaultMethodKey,
    restoreHistoricalCalculationMethod,
    selectLiveCalculationMethod,
    type CalculationMethodOptions,
} from '@/lib/calculation-method-draft';

function options(overrides?: Partial<CalculationMethodOptions>): CalculationMethodOptions {
    return {
        medium_id: 1,
        source: 'category',
        default_calculation_method_key: 'average',
        methods: [
            {
                key: 'average',
                name: 'Durchschnitt',
                help_text: 'Mittelwert über den Zeitraum',
                is_default: true,
            },
        ],
        ...overrides,
    };
}

function multiOptions(): CalculationMethodOptions {
    return options({
        default_calculation_method_key: 'average',
        methods: [
            {
                key: 'average',
                name: 'Durchschnitt',
                help_text: 'Hilfe A',
                is_default: true,
            },
            {
                key: 'calendar',
                name: 'Kalender',
                help_text: 'Hilfe B',
                is_default: false,
            },
        ],
    });
}

describe('calculation-method-draft (ADV-001c4b)', () => {
    it('returns null default when zero options', () => {
        const empty = options({
            default_calculation_method_key: 'average',
            methods: [],
        });
        expect(resolveDefaultMethodKey(empty)).toBeNull();
        expect(initMethodStateForNewPosition(empty)).toEqual(
            emptyCalculationMethodDraftState(),
        );
        expect(NO_CALCULATION_METHOD_MESSAGE).toMatch(/keine freigegebene/);
    });

    it('initializes single option as default without inventing average', () => {
        const single = options();
        const state = initMethodStateForNewPosition(single);
        expect(state.calculation_method_key).toBe('average');
        expect(state.calculation_method_name).toBe('Durchschnitt');
        expect(state.historical_calculation_method_key).toBeNull();
    });

    it('rejects configured default that is not in methods', () => {
        const broken = options({
            default_calculation_method_key: 'missing',
            methods: [
                {
                    key: 'calendar',
                    name: 'Kalender',
                    help_text: null,
                    is_default: false,
                },
            ],
        });
        expect(resolveDefaultMethodKey(broken)).toBeNull();
        expect(initMethodStateForNewPosition(broken).calculation_method_key).toBeNull();
    });

    it('preselects default among multiple options', () => {
        const state = initMethodStateForNewPosition(multiOptions());
        expect(state.calculation_method_key).toBe('average');
    });

    it('supports conscious live method switch', () => {
        const opts = multiOptions();
        let state = initMethodStateForNewPosition(opts);
        state = selectLiveCalculationMethod(state, opts, 'calendar');
        expect(state.calculation_method_key).toBe('calendar');
        expect(state.calculation_method_name).toBe('Kalender');
    });

    it('keeps stable state when re-initializing identical existing live key', () => {
        const opts = options();
        const first = initMethodStateForExistingPosition(
            opts,
            'average',
            'Durchschnitt',
        );
        const second = initMethodStateForExistingPosition(
            opts,
            'average',
            'Alter Name',
        );
        expect(first.calculation_method_key).toBe('average');
        expect(second.calculation_method_key).toBe('average');
        expect(second.calculation_method_name).toBe('Durchschnitt');
        expect(second.historical_calculation_method_key).toBeNull();
    });

    it('resets method on medium change', () => {
        const oldState = initMethodStateForExistingPosition(
            multiOptions(),
            'calendar',
            'Kalender',
        );
        const next = resetMethodStateForMediumChange(options());
        expect(oldState.calculation_method_key).toBe('calendar');
        expect(next.calculation_method_key).toBe('average');
        expect(next.historical_calculation_method_key).toBeNull();
    });

    it('keeps method key when inventory changes but medium id stays', () => {
        const previous = initMethodStateForExistingPosition(
            multiOptions(),
            'calendar',
            'Kalender',
        );
        const next = methodStateAfterMediumIdChange(
            10,
            10,
            previous,
            options(),
        );
        expect(next).toEqual(previous);
        expect(next.calculation_method_key).toBe('calendar');
    });

    it('resets method when medium id changes with inventory', () => {
        const previous = initMethodStateForExistingPosition(
            multiOptions(),
            'calendar',
            'Kalender',
        );
        const next = methodStateAfterMediumIdChange(
            10,
            20,
            previous,
            options(),
        );
        expect(next.calculation_method_key).toBe('average');
        expect(next.historical_calculation_method_key).toBeNull();
    });

    it('does not auto-switch historical method when live default differs', () => {
        const live = options();
        const state = initMethodStateForExistingPosition(
            live,
            'calendar',
            'Historischer Kalender',
        );
        expect(isHistoricalMethodActive(state)).toBe(true);
        expect(state.calculation_method_key).toBe('calendar');
        expect(state.calculation_method_name).toBe('Historischer Kalender');
        expect(HISTORICAL_CALCULATION_METHOD_HINT).toMatch(/nicht mehr auswählbar/);
    });

    it('keeps historical key on unchanged edit representation', () => {
        const state = initMethodStateForExistingPosition(
            options(),
            'fixed_price',
            null,
        );
        expect(state.calculation_method_key).toBe('fixed_price');
        expect(historicalMethodLabel(state)).toBe('fixed_price');
        expect(isHistoricalMethodActive(state)).toBe(true);
    });

    it('restores historical state after conscious live switch cancel', () => {
        const opts = multiOptions();
        let state = initMethodStateForExistingPosition(
            opts,
            'legacy_x',
            'Legacy X',
        );
        state = selectLiveCalculationMethod(state, opts, 'average');
        expect(isHistoricalMethodActive(state)).toBe(false);
        expect(state.calculation_method_key).toBe('average');
        state = restoreHistoricalCalculationMethod(state);
        expect(isHistoricalMethodActive(state)).toBe(true);
        expect(state.calculation_method_key).toBe('legacy_x');
        expect(state.calculation_method_name).toBe('Legacy X');
    });

    it('payload contains calculation_method_key and no spot_method', () => {
        const state = initMethodStateForNewPosition(options());
        const payload = calculationMethodPayloadFields(state);
        expect(payload).toEqual({ calculation_method_key: 'average' });
        expect(payload).not.toHaveProperty('spot_method');
    });

    it('does not emit null or empty calculation_method_key', () => {
        expect(
            calculationMethodPayloadFields(emptyCalculationMethodDraftState()),
        ).toBeNull();
        expect(
            calculationMethodPayloadFields({ calculation_method_key: '' }),
        ).toBeNull();
        expect(
            calculationMethodPayloadFields({ calculation_method_key: '   ' }),
        ).toBeNull();
    });
});
