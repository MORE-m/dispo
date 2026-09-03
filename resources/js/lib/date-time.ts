/**
 * Fachliche Anzeigezeitzone. Persistenz bleibt UTC; Darstellung immer Berlin.
 */
export const DISPLAY_TIMEZONE = 'Europe/Berlin';

const dateTimeFormatter = new Intl.DateTimeFormat('de-DE', {
    timeZone: DISPLAY_TIMEZONE,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
});

/**
 * UTC-/Offset-Zeitstempel → `DD.MM.YYYY, HH:mm` in Europe/Berlin.
 * Unabhängig von Browser- und Systemzeitzone.
 */
export function formatDateTime(value: string | null | undefined): string {
    if (value == null || value === '') {
        return '–';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '–';
    }

    return dateTimeFormatter.format(date);
}

/**
 * Reines Kalenderdatum (`YYYY-MM-DD`) ohne Zeitzonenverschiebung.
 * Kein `new Date('YYYY-MM-DD')`, damit kein Tageswechsel entsteht.
 */
export function formatDateOnly(value: string | null | undefined): string {
    if (value == null || value === '') {
        return '–';
    }

    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value.trim());
    if (match === null) {
        return '–';
    }

    return `${match[3]}.${match[2]}.${match[1]}`;
}
