const VALIDATION_KEY_FALLBACKS: Record<string, string> = {
    'validation.min.numeric': 'Der Wert muss den Mindestwert erfüllen.',
    'validation.min.integer': 'Der Wert muss den Mindestwert erfüllen.',
    'validation.required': 'Dieses Feld ist erforderlich.',
    'validation.numeric': 'Bitte eine gültige Zahl eingeben.',
    'validation.integer': 'Bitte eine ganze Zahl eingeben.',
};

export function humanizeValidationMessage(message: string): string {
    const trimmed = message.trim();
    if (trimmed === '') {
        return trimmed;
    }

    if (VALIDATION_KEY_FALLBACKS[trimmed]) {
        return VALIDATION_KEY_FALLBACKS[trimmed];
    }

    if (trimmed.startsWith('validation.')) {
        return 'Bitte prüfe die markierten Felder.';
    }

    return trimmed;
}

export function mapValidationErrors(
    errors: Record<string, string | string[]>,
): Record<string, string[]> {
    const mapped: Record<string, string[]> = {};

    for (const [key, value] of Object.entries(errors)) {
        const messages = Array.isArray(value) ? value : [String(value)];
        mapped[key] = messages.map(humanizeValidationMessage);
    }

    return mapped;
}

export function firstValidationMessage(
    errors: Record<string, string[]>,
): string | null {
    for (const messages of Object.values(errors)) {
        if (messages.length > 0) {
            return humanizeValidationMessage(messages[0]);
        }
    }

    return null;
}
