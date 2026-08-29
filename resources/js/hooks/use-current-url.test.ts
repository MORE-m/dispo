import { expect, test } from 'vitest';
import { toUrl } from '@/lib/utils';

function isCurrentUrl(
    urlToCheck: string,
    currentUrlPath: string,
    startsWith = false,
): boolean {
    return startsWith
        ? currentUrlPath.startsWith(urlToCheck)
        : currentUrlPath === urlToCheck;
}

function isCurrentOrParentUrl(
    urlToCheck: string,
    currentUrlPath: string,
): boolean {
    return isCurrentUrl(toUrl(urlToCheck), currentUrlPath, true);
}

test('Kalkulationen-Unterrouten erkennen den Hauptnavigationspunkt', () => {
    expect(isCurrentOrParentUrl('/kalkulationen', '/kalkulationen/neu')).toBe(
        true,
    );
    expect(isCurrentOrParentUrl('/kalkulationen', '/kalkulationen/42')).toBe(
        true,
    );
    expect(isCurrentOrParentUrl('/kalkulationen', '/kalkulationen')).toBe(
        true,
    );
});

test('andere Navigationspunkte bleiben auf Unterrouten inaktiv', () => {
    expect(isCurrentOrParentUrl('/dashboard', '/kalkulationen/neu')).toBe(
        false,
    );
    expect(isCurrentOrParentUrl('/stammdaten', '/kalkulationen/neu')).toBe(
        false,
    );
});
