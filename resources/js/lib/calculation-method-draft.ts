/**
 * ADV-001c4b: lokale Methodenauswahl-/Reset-Logik für den Kalkulationswizard.
 *
 * Maßgeblich ist ausschließlich der vom Backend gelieferte methods-Satz.
 * Keine clientseitige Selectability anhand von kind/Engine/Key.
 */

export type CalculationMethodOption = {
    key: string;
    name: string;
    help_text: string | null;
    is_default: boolean;
};

export type CalculationMethodOptions = {
    medium_id: number;
    source: string;
    default_calculation_method_key: string | null;
    methods: CalculationMethodOption[];
};

export type CalculationMethodDraftState = {
    calculation_method_key: string | null;
    calculation_method_name: string | null;
    /** Eingefrorener Key einer nicht mehr live wählbaren Methode; null = Live. */
    historical_calculation_method_key: string | null;
    historical_calculation_method_name: string | null;
};

export const NO_CALCULATION_METHOD_MESSAGE =
    'Für dieses Werbemittel ist derzeit keine freigegebene Berechnungsmethode verfügbar.';

export const HISTORICAL_CALCULATION_METHOD_HINT =
    'Diese Berechnungsmethode ist für neue Positionen nicht mehr auswählbar. Sie bleibt für diese bestehende Position erhalten.';

export function emptyCalculationMethodDraftState(): CalculationMethodDraftState {
    return {
        calculation_method_key: null,
        calculation_method_name: null,
        historical_calculation_method_key: null,
        historical_calculation_method_name: null,
    };
}

export function findMethodOption(
    options: CalculationMethodOptions | null | undefined,
    key: string | null | undefined,
): CalculationMethodOption | null {
    if (!options || key == null || key === '') {
        return null;
    }

    return options.methods.find((method) => method.key === key) ?? null;
}

/**
 * Default nur akzeptieren, wenn der Key tatsächlich in methods liegt.
 */
export function resolveDefaultMethodKey(
    options: CalculationMethodOptions | null | undefined,
): string | null {
    if (!options || options.methods.length === 0) {
        return null;
    }

    const configured = options.default_calculation_method_key;
    if (
        configured &&
        options.methods.some((method) => method.key === configured)
    ) {
        return configured;
    }

    const flagged = options.methods.find((method) => method.is_default);

    return flagged?.key ?? null;
}

export function initMethodStateForNewPosition(
    options: CalculationMethodOptions | null | undefined,
): CalculationMethodDraftState {
    const key = resolveDefaultMethodKey(options);
    if (!key) {
        return emptyCalculationMethodDraftState();
    }

    const option = findMethodOption(options, key);

    return {
        calculation_method_key: key,
        calculation_method_name: option?.name ?? null,
        historical_calculation_method_key: null,
        historical_calculation_method_name: null,
    };
}

/**
 * Bestehende Position: Live-Key behalten oder historischen Freeze lokal halten.
 * Kein automatischer Wechsel auf den aktuellen Default.
 */
export function initMethodStateForExistingPosition(
    options: CalculationMethodOptions | null | undefined,
    frozenKey: string | null | undefined,
    frozenName: string | null | undefined,
): CalculationMethodDraftState {
    const key =
        frozenKey != null && String(frozenKey).trim() !== ''
            ? String(frozenKey)
            : null;

    if (!key) {
        return initMethodStateForNewPosition(options);
    }

    const live = findMethodOption(options, key);
    if (live) {
        return {
            calculation_method_key: key,
            calculation_method_name: live.name,
            historical_calculation_method_key: null,
            historical_calculation_method_name: null,
        };
    }

    const name =
        frozenName != null && String(frozenName).trim() !== ''
            ? String(frozenName)
            : null;

    return {
        calculation_method_key: key,
        calculation_method_name: name,
        historical_calculation_method_key: key,
        historical_calculation_method_name: name,
    };
}

/**
 * Echter Mediumwechsel: alten Key verwerfen, Default des neuen Mediums setzen.
 */
export function resetMethodStateForMediumChange(
    options: CalculationMethodOptions | null | undefined,
): CalculationMethodDraftState {
    return initMethodStateForNewPosition(options);
}

export function hasHistoricalMethodBaseline(
    state: CalculationMethodDraftState,
): boolean {
    return state.historical_calculation_method_key != null;
}

export function isHistoricalMethodActive(
    state: CalculationMethodDraftState,
): boolean {
    return (
        hasHistoricalMethodBaseline(state) &&
        state.calculation_method_key === state.historical_calculation_method_key
    );
}

export function selectLiveCalculationMethod(
    state: CalculationMethodDraftState,
    options: CalculationMethodOptions | null | undefined,
    key: string,
): CalculationMethodDraftState {
    const option = findMethodOption(options, key);
    if (!option) {
        return state;
    }

    return {
        ...state,
        calculation_method_key: option.key,
        calculation_method_name: option.name,
    };
}

export function restoreHistoricalCalculationMethod(
    state: CalculationMethodDraftState,
): CalculationMethodDraftState {
    if (state.historical_calculation_method_key == null) {
        return state;
    }

    return {
        ...state,
        calculation_method_key: state.historical_calculation_method_key,
        calculation_method_name: state.historical_calculation_method_name,
    };
}

/**
 * Inventarwechsel bei gleichem Medium erhält den Key; echter Mediumwechsel resettet.
 */
export function methodStateAfterMediumIdChange(
    previousMediumId: number,
    nextMediumId: number,
    previousState: CalculationMethodDraftState,
    nextOptions: CalculationMethodOptions | null | undefined,
): CalculationMethodDraftState {
    if (previousMediumId === nextMediumId) {
        return previousState;
    }

    return resetMethodStateForMediumChange(nextOptions);
}

export function hasSubmittableCalculationMethodKey(
    state: Pick<CalculationMethodDraftState, 'calculation_method_key'>,
): boolean {
    const key = state.calculation_method_key;

    return key != null && key.trim() !== '';
}

/**
 * Moderner Wizard-Payload: nur calculation_method_key, nie null/leer.
 * Kein konkurrierendes spot_method.
 */
export function calculationMethodPayloadFields(
    state: Pick<CalculationMethodDraftState, 'calculation_method_key'>,
): { calculation_method_key: string } | null {
    if (!hasSubmittableCalculationMethodKey(state)) {
        return null;
    }

    return {
        calculation_method_key: state.calculation_method_key as string,
    };
}

export function historicalMethodLabel(
    state: CalculationMethodDraftState,
): string {
    const name = state.calculation_method_name?.trim();
    if (name) {
        return name;
    }

    return state.calculation_method_key?.trim() || 'Unbekannt';
}
