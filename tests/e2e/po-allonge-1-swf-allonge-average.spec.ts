import { expect, test, type Page, type Response } from '@playwright/test';

/**
 * PO-ALLONGE-1: SWF-Medium Allonge × Durchschnitt.
 * Isoliert (playwright.po-allonge-1.config.ts, Port 8067, eigene SQLite-DB).
 *
 * Synthetisch: Default 10 s, Nutzer 15 s, 10 × 2,00 × 15 × 1,30 = 390,00.
 * Keine operativen Startwerte.
 */

const allongeMedium = 'Allonge';
const unconfirmedInventoryName = 'Allonge Testsender Unbestätigt';

type AllongeCatalogExpectation = {
    inventoryName: string;
    /** null: Aufschlag fehlt. '0': ausdrücklich 0 %, nicht null. */
    surchargePercent: null | '0';
    confirmed: boolean;
};

async function logout(page: Page) {
    await page.locator('[data-test="sidebar-menu-button"]').click();
    await page.locator('[data-test="logout-button"]').click();
    await expect(page.getByRole('link', { name: 'Anmelden' })).toBeVisible({
        timeout: 15_000,
    });
}

async function login(page: Page, email = 'sales@example.com') {
    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill('password');
    await page.locator('[data-test="login-button"]').click();
    await page.waitForFunction(
        () => !window.location.pathname.includes('/login'),
        undefined,
        { timeout: 30_000 },
    );
}

async function waitForPreview(page: Page) {
    await expect(page.locator('[data-test="preview-loading"]')).toHaveCount(0, {
        timeout: 20_000,
    });
}

async function openNewCalculationStepTwo(page: Page, customer: string) {
    await page.goto('/kalkulationen/neu');
    await page.locator('[data-test="crm-customer-search"]').fill(customer);
    await page.locator('[data-test="crm-customer-use-freitext"]').click();
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
}

async function selectAllonge(page: Page, index: number, inventoryCode: string) {
    const expand = page.locator(`[data-test="position-inventory-expand-${index}"]`);
    if (await expand.isVisible()) {
        await expand.click();
    }
    await page
        .locator(`[data-test="position-inventory-tile-${index}-${inventoryCode}"]`)
        .click();
    await page
        .locator(`[data-test="position-medium-${index}"]`)
        .selectOption({ label: allongeMedium });
}

function isCatalogPartial(headers: Record<string, string>): boolean {
    return (headers['x-inertia-partial-data'] ?? '').includes('catalog');
}

/**
 * Wartet auf den bestehenden Katalog-Reload, nicht nur auf die HTTP-Antwort.
 *
 * `waitForResponse` ist erfüllt, sobald die Antwort verfügbar ist. Inertia
 * übernimmt die Props erst danach in `inertia:success` (nach setPage).
 * Ein folgender Preisjahr-Wechsel liest sonst noch den vorherigen Katalog.
 * Es wird kein zusätzlicher Produkt-Hook eingeführt.
 */
async function reloadCatalogWithoutRemount(
    page: Page,
    expected: AllongeCatalogExpectation,
) {
    const length = page.locator('[data-test="position-length-seconds-0"]');
    const priceYear = page.locator('[data-test="position-price-year-0"]');
    const lengthBefore = await length.inputValue();
    const yearBefore = await priceYear.inputValue();
    const responsePromise = page.waitForResponse(
        (response) =>
            response.request().method() === 'GET' &&
            response.ok() &&
            isCatalogPartial(response.request().headers()),
        { timeout: 20_000 },
    );
    const applied = page.evaluate((expectation) => {
        return new Promise<string>((resolve, reject) => {
            let pending = 0;
            let adopted = false;
            let latest = 'noch kein Katalog in inertia:success';

            const isCatalogVisit = (visit: { only?: string[] } | undefined) =>
                Array.isArray(visit?.only) && visit.only.includes('catalog');

            const cleanup = () => {
                window.clearTimeout(timeout);
                document.removeEventListener('inertia:start', onStart);
                document.removeEventListener('inertia:success', onSuccess);
                document.removeEventListener('inertia:finish', onFinish);
            };

            const settle = () => {
                if (adopted && pending === 0) {
                    cleanup();
                    resolve(latest);
                }
            };

            const timeout = window.setTimeout(() => {
                cleanup();
                reject(
                    new Error(
                        `Katalog-Reload nicht übernommen (${latest}).`,
                    ),
                );
            }, 20_000);

            const onStart = (event: Event) => {
                const visit = (event as CustomEvent<{ visit?: { only?: string[] } }>)
                    .detail?.visit;
                if (isCatalogVisit(visit)) {
                    pending += 1;
                }
            };

            const onSuccess = (event: Event) => {
                const catalog = (
                    event as CustomEvent<{
                        page?: {
                            props?: {
                                catalog?: {
                                    inventories?: Array<{ id: number; name: string }>;
                                    media?: Array<{ id: number; kind?: string | null }>;
                                    rules?: Array<{
                                        inventory_id: number;
                                        advertising_medium_id: number;
                                        surcharge_percent?: string | null;
                                        swf_allonge_confirmed_at?: string | null;
                                    }>;
                                };
                            };
                        };
                    }>
                ).detail?.page?.props?.catalog;
                if (!catalog) {
                    return;
                }

                const inventory = catalog.inventories?.find(
                    (item) => item.name === expectation.inventoryName,
                );
                const medium = catalog.media?.find(
                    (item) => item.kind === 'swf_allonge',
                );
                const rule = catalog.rules?.find(
                    (item) =>
                        item.inventory_id === inventory?.id &&
                        item.advertising_medium_id === medium?.id,
                );
                const surcharge = rule?.surcharge_percent ?? null;
                const confirmed = Boolean(rule?.swf_allonge_confirmed_at);
                const explicitZero =
                    surcharge !== null &&
                    surcharge !== '' &&
                    Number(surcharge) === 0;
                const missing = surcharge === null || surcharge === '';
                const surchargeMatches =
                    expectation.surchargePercent === '0'
                        ? explicitZero
                        : missing;
                latest = `Aufschlag ${surcharge === null || surcharge === '' ? 'fehlt' : surcharge}, bestätigt ${confirmed ? 'ja' : 'nein'}`;
                adopted = Boolean(
                    inventory &&
                        medium &&
                        rule &&
                        surchargeMatches &&
                        confirmed === expectation.confirmed,
                );
                settle();
            };

            const onFinish = (event: Event) => {
                const visit = (event as CustomEvent<{ visit?: { only?: string[] } }>)
                    .detail?.visit;
                if (!isCatalogVisit(visit)) {
                    return;
                }

                pending = Math.max(0, pending - 1);
                settle();
            };

            document.addEventListener('inertia:start', onStart);
            document.addEventListener('inertia:success', onSuccess);
            document.addEventListener('inertia:finish', onFinish);
            window.dispatchEvent(new Event('dispo:reload-catalog'));
        });
    }, expected);

    const response = await responsePromise;
    await response.finished();
    await applied;
    await expect(length).toHaveValue(lengthBefore);
    await expect(priceYear).toHaveValue(yearBefore);
}

type CombinationIdentity = {
    id: number;
    inventory_name: string;
    inventory_code: string | null;
    advertising_medium_name: string;
};

type SavedAllongeRule = CombinationIdentity & {
    default_length_seconds: number | null;
    surcharge_percent: string | null;
    allonge_confirmation_label: string | null;
};

function combinationLabel(rule: {
    inventory_name: string;
    inventory_code: string | null;
}): string {
    return rule.inventory_code
        ? `${rule.inventory_name} (${rule.inventory_code})`
        : rule.inventory_name;
}

function isInertiaGet(response: Response, pathnamePattern: RegExp): boolean {
    const request = response.request();
    if (request.method() !== 'GET' || !response.ok()) {
        return false;
    }
    // Beim Redirect nach PUT liefert request.headers() nur einen Teil.
    // X-Inertia steht dann zuverlässig auf der Antwort.
    const inertia =
        request.headers()['x-inertia'] === 'true' ||
        response.headers()['x-inertia'] === 'true';
    if (!inertia) {
        return false;
    }

    return pathnamePattern.test(new URL(request.url()).pathname);
}

function postedField(response: Response, field: string): string | null {
    const postData = response.request().postData();
    if (postData === null) {
        return null;
    }

    const contentType = response.request().headers()['content-type'] ?? '';
    if (contentType.includes('application/json')) {
        const payload = JSON.parse(postData) as Record<string, unknown>;
        if (!Object.prototype.hasOwnProperty.call(payload, field)) {
            return null;
        }
        const value = payload[field];
        if (typeof value === 'string' || typeof value === 'number') {
            return String(value);
        }

        return null;
    }

    return new URLSearchParams(postData).get(field);
}

/**
 * Speichert Länge und Aufschlag der Allonge-Kombination des genannten Inventars.
 *
 * Der Inventarfilter lädt die Liste per Inertia nach. Solange diese Antwort
 * fehlt, ist die erste Allonge-Zeile noch ein anderes Inventar.
 * „bestätigt am“ kann außerdem schon von der zuvor geöffneten Kombination
 * stammen und darf den gerade ausgelösten PUT nicht abschließen.
 */
async function setAllongeConfirmation(
    page: Page,
    inventoryLabel: string,
    length: string,
    surcharge: string,
) {
    await page.goto('/administration/kombinationen');
    const filteredList = page.waitForResponse(
        (response) =>
            isInertiaGet(response, /^\/administration\/kombinationen$/) &&
            new URL(response.request().url()).searchParams.has('inventory_id'),
        { timeout: 20_000 },
    );
    await page
        .locator('[data-test="combination-filter-inventory"]')
        .selectOption({ label: inventoryLabel });
    const listed = (await (await filteredList).json()) as {
        props?: { rules?: CombinationIdentity[] };
    };
    const matches = (listed.props?.rules ?? []).filter(
        (rule) =>
            rule.advertising_medium_name === allongeMedium &&
            combinationLabel(rule) === inventoryLabel,
    );
    expect(matches, `Allonge-Zeile für ${inventoryLabel}`).toHaveLength(1);
    const rule = matches[0];

    await page.locator(`[data-test="combination-open-${rule.id}"]`).click();
    await expect(page.getByRole('heading', { level: 1 })).toContainText(
        rule.inventory_name,
        { timeout: 20_000 },
    );
    await page.locator('#default_length_seconds').fill(length);
    await page.locator('#surcharge_percent').fill(surcharge);

    const updateResponse = page.waitForResponse(
        (response) => {
            const request = response.request();

            return (
                (request.method() === 'PUT' || request.method() === 'POST') &&
                new URL(request.url()).pathname ===
                    `/administration/kombinationen/${rule.id}`
            );
        },
        { timeout: 20_000 },
    );
    const savedDetail = page.waitForResponse(
        (response) =>
            isInertiaGet(
                response,
                new RegExp(`^/administration/kombinationen/${rule.id}$`),
            ),
        { timeout: 20_000 },
    );
    await page.locator('[data-test="combination-save-submit"]').click();

    const update = await updateResponse;
    const updateStatus = update.status();
    expect(
        updateStatus,
        updateStatus >= 400 ? await update.text() : `Speicherstatus ${updateStatus}`,
    ).toBeLessThan(400);
    expect(postedField(update, 'default_length_seconds')).toBe(length);
    if (surcharge === '') {
        expect(postedField(update, 'surcharge_percent')).toBe('');
    } else {
        expect(postedField(update, 'surcharge_percent')).toBe(surcharge);
    }

    const detail = await savedDetail;
    const saved = (
        (await detail.json()) as { props?: { rule?: SavedAllongeRule } }
    ).props?.rule;
    expect(saved).toBeTruthy();
    expect(saved?.id).toBe(rule.id);
    expect(combinationLabel(saved as SavedAllongeRule)).toBe(inventoryLabel);
    expect(saved?.advertising_medium_name).toBe(allongeMedium);
    expect(String(saved?.default_length_seconds)).toBe(length);
    if (surcharge === '') {
        expect(saved?.surcharge_percent).toBeNull();
        expect(saved?.allonge_confirmation_label).toContain('nicht bestätigt');
    } else {
        expect(saved?.surcharge_percent).not.toBeNull();
        expect(Number(saved?.surcharge_percent)).toBe(Number(surcharge));
        expect(saved?.allonge_confirmation_label).toContain('bestätigt am');
    }
    await expect(page.locator('[data-test="allonge-confirmation-status"]')).toContainText(
        surcharge === '' ? 'nicht bestätigt' : 'bestätigt am',
        { timeout: 20_000 },
    );
}

async function enabledOtherPriceYear(page: Page): Promise<string> {
    const priceYear = page.locator('[data-test="position-price-year-0"]');
    const currentYear = await priceYear.inputValue();
    const otherYear = await priceYear.locator('option:not([disabled])').evaluateAll(
        (options, current) =>
            options
                .map((option) => (option as HTMLOptionElement).value)
                .find((value) => value !== '' && value !== current) ?? null,
        currentYear,
    );
    expect(otherYear).not.toBeNull();

    return otherYear as string;
}

async function fillRange(page: Page, index: number, spots: string) {
    if ((await page.locator(`[data-test="range-spots-${index}-0"]`).count()) === 0) {
        await page.locator(`[data-test="range-add-${index}"]`).click();
    }
    await page.locator(`[data-test="range-start-${index}-0"]`).selectOption('8');
    await page.locator(`[data-test="range-end-${index}-0"]`).selectOption('9');
    await page.locator(`[data-test="range-day-${index}-0"]`).selectOption('mo_fr');
    await page.locator(`[data-test="range-spots-${index}-0"]`).fill(spots);
}

test.describe.serial('PO-ALLONGE-1 SWF Allonge × Durchschnitt', () => {
    test('unbestätigte 30/0-Regel sperrt die Kalkulation', async ({ page }) => {
        test.setTimeout(120_000);
        await login(page);
        await openNewCalculationStepTwo(page, 'Allonge Unbestätigt GmbH');
        await selectAllonge(page, 0, 'ATU');
        await expect(page.locator('[data-test="allonge-unconfirmed-0"]')).toContainText(
            'nicht bestätigt',
        );
        await expect(
            page.locator('[data-test="calculation-method-radio-0-calendar"]'),
        ).toHaveCount(0);
        await fillRange(page, 0, '10');
        await waitForPreview(page);
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/neu/, { timeout: 15_000 });
        await expect(page.locator('body')).toContainText('nicht bestätigt', {
            timeout: 20_000,
        });
    });

    test('Admin-Speichern bestätigt ausdrücklich 30/0 ohne Checkbox', async ({ page }) => {
        test.setTimeout(120_000);
        await login(page, 'admin@example.com');
        await page.goto('/administration/kombinationen');
        await page
            .locator('[data-test="combination-filter-inventory"]')
            .selectOption({ label: 'Allonge Testsender Unbestätigt (ATU)' });
        const row = page
            .locator('[data-test^="combination-row-"]')
            .filter({
                has: page.getByRole('cell', { name: 'Allonge', exact: true }),
            })
            .first();
        await row.locator('[data-test^="combination-open-"]').click();
        await expect(page.locator('[data-test="allonge-confirmation-status"]')).toContainText(
            'nicht bestätigt',
        );
        await expect(page.getByRole('checkbox', { name: /bestätigt/i })).toHaveCount(0);
        await page.locator('#default_length_seconds').fill('30');
        await page.locator('#surcharge_percent').fill('0');
        await page.locator('[data-test="combination-save-submit"]').click();
        await expect(page.locator('[data-test="allonge-confirmation-status"]')).toContainText(
            'bestätigt am',
            { timeout: 20_000 },
        );
    });

    test('Preview, Save, Reload und Dispo mit Nutzerlänge 15 s', async ({ page }) => {
        test.setTimeout(180_000);
        await login(page);
        await openNewCalculationStepTwo(page, 'Allonge E2E GmbH');
        await selectAllonge(page, 0, 'ATA');
        await expect(page.locator('[data-test="position-length-seconds-0"]')).toHaveValue('10');
        await expect(page.locator('[data-test="allonge-unconfirmed-0"]')).toHaveCount(0);
        await expect(
            page.locator('[data-test="spot-components-activate-0"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="pricing-settlement-radio-0-fixed_price"]'),
        ).toHaveCount(0);
        await page.locator('[data-test="position-length-seconds-0"]').fill('15');
        await fillRange(page, 0, '10');
        await waitForPreview(page);
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText('390,00', {
            timeout: 20_000,
        });

        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await waitForPreview(page);
        await expect(page.locator('[data-test="preview-net-total"]').first()).toContainText(
            '390,00',
            { timeout: 20_000 },
        );
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, { timeout: 30_000 });
        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(page.locator('[data-test="position-length-seconds-0"]')).toHaveValue('15');
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText('390,00', {
            timeout: 20_000,
        });
        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await expect(page.locator('[data-test="preview-net-total"]').first()).toContainText(
            '390,00',
            { timeout: 20_000 },
        );

        await page.locator('[data-test="dispo-order-create-open"]').click();
        await page.locator('[data-test="dispo-order-submit"]').click();
        await expect(page).toHaveURL(/\/dispoauftraege\/\d+$/, { timeout: 30_000 });
        await expect(page.locator('body')).toContainText('Allonge Testsender A');
        await expect(page.locator('body')).toContainText('390,00');
        await expect(page.locator('[data-test="dispo-order-position-components-0"]')).toHaveCount(0);
    });

    test('Wechsel von Spot mit Allonge-Komponente räumt Komponenten und Festpreis ab', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page);
        await openNewCalculationStepTwo(page, 'Allonge Komponentenwechsel GmbH');
        const expand = page.locator('[data-test="position-inventory-expand-0"]');
        if (await expand.isVisible()) {
            await expand.click();
        }
        await page.locator('[data-test="position-inventory-tile-0-ATA"]').click();
        await page.locator('[data-test="position-medium-0"]').selectOption({ label: 'Spot Classic' });
        await page.locator('[data-test="spot-components-activate-0"]').click();
        await page.locator('[data-test="spot-component-length-main_spot-0"]').fill('20');
        await page.locator('[data-test="spot-components-add-allonge-0"]').click();
        await page.locator('[data-test="pricing-settlement-radio-0-fixed_price"]').check();
        await page.locator('[data-test="position-medium-0"]').selectOption({ label: allongeMedium });

        await expect(page.locator('[data-test="spot-components-section-0"]')).toHaveCount(0);
        await expect(page.locator('[data-test="pricing-settlement-radio-0-fixed_price"]')).toHaveCount(0);
        await expect(page.locator('[data-test="position-length-seconds-0"]')).toHaveValue('10');
        await page.locator('[data-test="position-length-seconds-0"]').fill('15');
        await fillRange(page, 0, '10');
        await waitForPreview(page);
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText('390,00', {
            timeout: 20_000,
        });
    });

    test('gespeicherter Snapshot bleibt nach Entbestätigung nutzbar, Live-Rebind nicht', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await login(page);
        await openNewCalculationStepTwo(page, 'Allonge Snapshot GmbH');
        await selectAllonge(page, 0, 'ATA');
        await page.locator('[data-test="position-length-seconds-0"]').fill('15');
        await fillRange(page, 0, '10');
        await waitForPreview(page);
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText('390,00', {
            timeout: 20_000,
        });
        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, { timeout: 30_000 });
        const calculationUrl = page.url();

        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await page.locator('[data-test="dispo-order-create-open"]').click();
        await page.locator('[data-test="dispo-order-submit"]').click();
        await expect(page).toHaveURL(/\/dispoauftraege\/\d+$/, { timeout: 30_000 });
        const dispoUrl = page.url();
        await expect(page.locator('body')).toContainText('390,00');

        await logout(page);
        await login(page, 'admin@example.com');
        await page.goto('/administration/kombinationen');
        await page
            .locator('[data-test="combination-filter-inventory"]')
            .selectOption({ label: 'Allonge Testsender A (ATA)' });
        const row = page
            .locator('[data-test^="combination-row-"]')
            .filter({
                has: page.getByRole('cell', { name: 'Allonge', exact: true }),
            })
            .first();
        await row.locator('[data-test^="combination-open-"]').click();
        await page.locator('#surcharge_percent').fill('');
        await page.locator('[data-test="combination-save-submit"]').click();
        await expect(page.locator('[data-test="allonge-confirmation-status"]')).toContainText(
            'nicht bestätigt',
            { timeout: 20_000 },
        );

        await logout(page);
        await login(page);
        await page.goto(calculationUrl);
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(page.locator('[data-test="allonge-unconfirmed-0"]')).toHaveCount(0);
        await expect(page.locator('[data-test="allonge-snapshot-note-0"]')).toContainText(
            'bleiben gültig',
        );
        await expect(page.locator('[data-test="allonge-snapshot-note-0"]')).not.toContainText(
            'gesperrt',
        );
        await expect(page.locator('[data-test="position-length-seconds-0"]')).toHaveValue('15');
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText('390,00', {
            timeout: 20_000,
        });
        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(calculationUrl, { timeout: 30_000 });
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText('390,00', {
            timeout: 20_000,
        });

        await page.goto(dispoUrl);
        await expect(page.locator('body')).toContainText('390,00');

        await page.goto(calculationUrl);
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        const priceYear = page.locator('[data-test="position-price-year-0"]');
        const currentYear = await priceYear.inputValue();
        const otherYear = await priceYear
            .locator('option:not([disabled])')
            .evaluateAll(
                (options, current) =>
                    options
                        .map((option) => (option as HTMLOptionElement).value)
                        .find((value) => value !== '' && value !== current) ?? null,
                currentYear,
            );
        if (otherYear !== null) {
            await priceYear.selectOption(otherYear);
            await expect(page.locator('[data-test="allonge-unconfirmed-0"]')).toContainText(
                'Kalkulation ist gesperrt',
            );
            await page.reload();
            await page.getByRole('button', { name: '2. Werbeelemente' }).click();
            await expect(page.locator('[data-test="allonge-snapshot-note-0"]')).toBeVisible();
        }
        await page.getByRole('button', { name: 'Werbeelement hinzufügen' }).click();
        await selectAllonge(page, 1, 'ATA');
        await expect(page.locator('[data-test="allonge-unconfirmed-1"]')).toContainText(
            'Kalkulation ist gesperrt',
        );
        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(calculationUrl, { timeout: 20_000 });
        await expect(page.locator('body')).toContainText('nicht bestätigt', {
            timeout: 20_000,
        });
        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(page.locator('[data-test="position-length-seconds-0"]')).toHaveValue('15');
        await expect(page.locator('[data-test="allonge-snapshot-note-0"]')).toBeVisible();
        await expect(page.locator('[data-test="position-length-seconds-1"]')).toHaveCount(0);
        await page.goto(dispoUrl);
        await expect(page.locator('body')).toContainText('390,00');
    });

    test('gespeicherter Wechsel übernimmt die Vergleichsbasis ohne Reload', async ({
        page,
        browser,
    }) => {
        test.setTimeout(300_000);
        const adminContext = await browser.newContext({
            baseURL: `http://127.0.0.1:${process.env.E2E_PO_ALLONGE_1_PORT ?? '8067'}`,
        });
        const admin = await adminContext.newPage();

        try {
            await login(admin, 'admin@example.com');
            await setAllongeConfirmation(
                admin,
                'Allonge Testsender A (ATA)',
                '10',
                '30',
            );
            await setAllongeConfirmation(
                admin,
                'Allonge Testsender Unbestätigt (ATU)',
                '30',
                '0',
            );

            await login(page);
            await openNewCalculationStepTwo(page, 'Allonge Basis GmbH');
            await selectAllonge(page, 0, 'ATA');
            await page.locator('[data-test="position-length-seconds-0"]').fill('15');
            await fillRange(page, 0, '10');
            await waitForPreview(page);
            await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText(
                '390,00',
                { timeout: 20_000 },
            );
            await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
            await page.locator('[data-test="wizard-save"]').click();
            await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, { timeout: 30_000 });
            const calculationUrl = page.url();

            await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
            await page.locator('[data-test="dispo-order-create-open"]').click();
            await page.locator('[data-test="dispo-order-submit"]').click();
            await expect(page).toHaveURL(/\/dispoauftraege\/\d+$/, { timeout: 30_000 });
            const dispoUrl = page.url();
            await expect(page.locator('body')).toContainText('390,00');

            await page.goto(calculationUrl);
            await page.getByRole('button', { name: '2. Werbeelemente' }).click();
            await selectAllonge(page, 0, 'ATU');
            await page.locator('[data-test="position-length-seconds-0"]').fill('15');
            await expect(page.locator('[data-test="allonge-unconfirmed-0"]')).toHaveCount(0);

            await setAllongeConfirmation(
                admin,
                'Allonge Testsender Unbestätigt (ATU)',
                '30',
                '',
            );
            await reloadCatalogWithoutRemount(page, {
                inventoryName: unconfirmedInventoryName,
                surchargePercent: null,
                confirmed: false,
            });
            await expect(page.locator('[data-test="position-length-seconds-0"]')).toHaveValue(
                '15',
            );
            await expect(page.locator('[data-test="allonge-unconfirmed-0"]')).toContainText(
                'Kalkulation ist gesperrt',
            );
            await expect(page.locator('[data-test="allonge-snapshot-note-0"]')).toHaveCount(0);

            await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
            await page.locator('[data-test="wizard-save"]').click();
            await expect(page.locator('body')).toContainText('nicht bestätigt', {
                timeout: 20_000,
            });
            await page.getByRole('button', { name: '2. Werbeelemente' }).click();
            await expect(page.locator('[data-test="position-length-seconds-0"]')).toHaveValue(
                '15',
            );
            await expect(page.locator('[data-test="allonge-unconfirmed-0"]')).toContainText(
                'Kalkulation ist gesperrt',
            );

            await setAllongeConfirmation(
                admin,
                'Allonge Testsender Unbestätigt (ATU)',
                '30',
                '0',
            );
            await reloadCatalogWithoutRemount(page, {
                inventoryName: unconfirmedInventoryName,
                surchargePercent: '0',
                confirmed: true,
            });
            await expect(page.locator('[data-test="allonge-unconfirmed-0"]')).toHaveCount(0);
            await waitForPreview(page);
            await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText('300,00', {
                timeout: 20_000,
            });
            await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
            await page.locator('[data-test="wizard-save"]').click();
            await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, { timeout: 30_000 });
            await page.getByRole('button', { name: '2. Werbeelemente' }).click();
            await expect(page.locator('[data-test="position-length-seconds-0"]')).toHaveValue(
                '15',
            );
            await expect(page.locator('body')).not.toContainText('neu bepreist');

            await setAllongeConfirmation(
                admin,
                'Allonge Testsender Unbestätigt (ATU)',
                '30',
                '',
            );
            await reloadCatalogWithoutRemount(page, {
                inventoryName: unconfirmedInventoryName,
                surchargePercent: null,
                confirmed: false,
            });
            await expect(page.locator('[data-test="allonge-snapshot-note-0"]')).toContainText(
                'bleiben gültig',
            );
            await expect(page.locator('[data-test="allonge-snapshot-note-0"]')).not.toContainText(
                'gesperrt',
            );
            await expect(page.locator('[data-test="allonge-unconfirmed-0"]')).toHaveCount(0);
            await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText('300,00', {
                timeout: 20_000,
            });

            const nextYear = await enabledOtherPriceYear(page);
            await page.locator('[data-test="position-price-year-0"]').selectOption(nextYear);
            await expect(page.locator('[data-test="allonge-unconfirmed-0"]')).toContainText(
                'Kalkulation ist gesperrt',
            );
            await expect(page.locator('body')).toContainText('neu bepreist');
            await page.locator('[data-test="position-price-year-0"]').selectOption(
                await page.locator('[data-test="position-price-year-0"] option').evaluateAll(
                    (options, selected) =>
                        options
                            .map((option) => (option as HTMLOptionElement).value)
                            .find((value) => value !== selected) ?? selected,
                    nextYear,
                ),
            );
            await expect(page.locator('[data-test="allonge-snapshot-note-0"]')).toContainText(
                'bleiben gültig',
            );

            await setAllongeConfirmation(
                admin,
                'Allonge Testsender Unbestätigt (ATU)',
                '30',
                '0',
            );
            await reloadCatalogWithoutRemount(page, {
                inventoryName: unconfirmedInventoryName,
                surchargePercent: '0',
                confirmed: true,
            });
            await page.locator('[data-test="position-price-year-0"]').selectOption(nextYear);
            await expect(page.locator('[data-test="allonge-unconfirmed-0"]')).toHaveCount(0);
            await waitForPreview(page);
            await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText('600,00', {
                timeout: 20_000,
            });
            await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
            await page.locator('[data-test="wizard-save"]').click();
            await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, { timeout: 30_000 });
            await page.getByRole('button', { name: '2. Werbeelemente' }).click();
            await expect(page.locator('[data-test="position-price-year-0"]')).toHaveValue(nextYear);
            await expect(page.locator('body')).toContainText('neu bepreist');

            await setAllongeConfirmation(
                admin,
                'Allonge Testsender Unbestätigt (ATU)',
                '30',
                '',
            );
            await reloadCatalogWithoutRemount(page, {
                inventoryName: unconfirmedInventoryName,
                surchargePercent: null,
                confirmed: false,
            });
            await expect(page.locator('[data-test="allonge-snapshot-note-0"]')).toContainText(
                'bleiben gültig',
            );
            await expect(page.locator('[data-test="allonge-unconfirmed-0"]')).toHaveCount(0);
            await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText('600,00', {
                timeout: 20_000,
            });

            const pinnedYear = await page
                .locator('[data-test="position-price-year-0"] option')
                .evaluateAll(
                    (options, selected) =>
                        options
                            .map((option) => (option as HTMLOptionElement).value)
                            .find((value) => value !== '' && value !== selected) ?? null,
                    nextYear,
                );
            expect(pinnedYear).not.toBeNull();
            await page
                .locator('[data-test="position-price-year-0"]')
                .selectOption(pinnedYear as string);
            await expect(page.locator('[data-test="allonge-unconfirmed-0"]')).toContainText(
                'Kalkulation ist gesperrt',
            );
            await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
            await page.locator('[data-test="wizard-save"]').click();
            await expect(page.locator('body')).toContainText('nicht bestätigt', {
                timeout: 20_000,
            });
            await page.getByRole('button', { name: '2. Werbeelemente' }).click();
            await expect(page.locator('[data-test="allonge-unconfirmed-0"]')).toContainText(
                'Kalkulation ist gesperrt',
            );
            await expect(page.locator('[data-test="allonge-snapshot-note-0"]')).toHaveCount(0);
            await page.locator('[data-test="position-price-year-0"]').selectOption(nextYear);
            await expect(page.locator('[data-test="allonge-snapshot-note-0"]')).toContainText(
                'bleiben gültig',
            );
            await expect(page.locator('[data-test="range-gross-0-0"]')).toContainText('600,00', {
                timeout: 20_000,
            });

            await page.locator('[data-test="position-medium-0"]').selectOption({
                label: 'Spot Classic',
            });
            await expect(page.locator('[data-test="allonge-snapshot-note-0"]')).toHaveCount(0);
            await expect(page.locator('[data-test="allonge-unconfirmed-0"]')).toHaveCount(0);
            await page.locator('[data-test="position-medium-0"]').selectOption({
                label: allongeMedium,
            });
            await expect(page.locator('[data-test="allonge-snapshot-note-0"]')).toContainText(
                'bleiben gültig',
            );
            await expect(page.locator('[data-test="allonge-unconfirmed-0"]')).toHaveCount(0);

            await admin.goto(dispoUrl);
            await expect(admin.locator('body')).toContainText('390,00');
        } finally {
            await adminContext.close();
        }
    });
});
