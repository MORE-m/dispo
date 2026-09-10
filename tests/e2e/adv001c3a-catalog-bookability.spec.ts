import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    expect,
    test,
    type Page,
} from '@playwright/test';

/**
 * ADV-001c3a isolierte Suite: Null-kind-Medien + Wizard-Buchbarkeit.
 * Läuft nur über playwright.adv001c3a.config.ts (eigene DB, Port 8008).
 */

const e2eDb = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    '../../database/e2e-adv001c3a.sqlite',
);

const projectRoot = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    '../..',
);

async function login(page: Page, email: string) {
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

/**
 * Aktive InventoryMediumRule in der isolierten E2E-SQLite anlegen
 * (kein produktiver App-Test-Hook).
 */
function attachActiveInventoryRule(mediumId: number, inventoryId: number) {
    const php = `
\\App\\Models\\InventoryMediumRule::query()->updateOrCreate(
    ['inventory_id' => ${inventoryId}, 'advertising_medium_id' => ${mediumId}],
    [
        'is_active' => true,
        'default_length_seconds' => 30,
        'surcharge_percent' => '0',
        'is_discountable' => true,
        'is_ae_eligible' => true,
    ]
);
echo 'ok';
`.trim();

    execFileSync('php', ['artisan', 'tinker', '--execute', php], {
        cwd: projectRoot,
        env: {
            ...process.env,
            APP_ENV: 'testing',
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: e2eDb,
            DB_URL: '',
        },
        stdio: ['ignore', 'pipe', 'pipe'],
    });
}

type WizardCatalogPayload = {
    inventories: Array<{ id: number; name: string; is_active: boolean }>;
    media: Array<{
        id: number;
        name: string;
        code: string;
        is_active: boolean;
        is_bookable_for_new_positions: boolean;
        unbookable_reason: string | null;
    }>;
    rules: Array<{
        inventory_id: number;
        advertising_medium_id: number;
        is_active: boolean;
    }>;
};

async function readWizardCatalog(page: Page): Promise<WizardCatalogPayload> {
    return page.evaluate(() => {
        const script = document.querySelector(
            'script[data-page][type="application/json"]',
        );
        if (!script?.textContent) {
            throw new Error('Inertia data-page Script fehlt');
        }
        const payload = JSON.parse(script.textContent) as {
            props?: { catalog?: WizardCatalogPayload };
        };
        if (!payload.props?.catalog) {
            throw new Error('Wizard-Katalog-Payload nicht gefunden');
        }

        return payload.props.catalog;
    });
}

test.describe.serial('ADV-001c3a Katalog Null-kind und Wizard', () => {
    test('Werbemittel ohne Kind in Nicht-Spots anlegen und Status getrennt zeigen', async ({
        page,
    }) => {
        await login(page, 'admin@example.com');
        const suffix = Date.now().toString().slice(-6);

        await page.goto('/administration/katalog/werbemittel/neu');
        await expect(
            page.locator('[data-test="medium-catalog-note"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="medium-kind-select"]'),
        ).toHaveCount(0);

        const categorySelect = page.locator(
            '[data-test="medium-category-select"]',
        );
        const options = categorySelect.locator('option');
        expect(await options.count()).toBeGreaterThanOrEqual(7);

        await page
            .locator('[data-test="medium-name-input"]')
            .fill(`E2E OA ${suffix}`);
        await page
            .locator('[data-test="medium-code-input"]')
            .fill(`e2e_oa_${suffix}`);
        await categorySelect.selectOption({ label: 'Online Audio (online_audio)' });
        await page.locator('[data-test="medium-create-submit"]').click();
        await expect(page).toHaveURL(/werbemittel\/\d+$/, { timeout: 30_000 });

        await expect(
            page.locator('[data-test="medium-status-panel"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="medium-status-badge"]'),
        ).toHaveText('Aktiv');
        await expect(
            page.locator('[data-test="medium-bookability-badge"]'),
        ).toHaveText('Noch nicht technisch verfügbar');
        await expect(
            page.locator('[data-test="medium-unbookable-reason"]'),
        ).toContainText(/Berechnungsmethode|technisch/i);
        await expect(
            page.locator('[data-test="medium-kind-readonly"]'),
        ).toHaveCount(0);
    });

    test('Null-kind nicht im Wizard wählbar, Spot Classic weiterhin speicherbar', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page, 'admin@example.com');
        const suffix = Date.now().toString().slice(-6);
        const visibleName = `Wizard Null ${suffix}`;

        await page.goto('/administration/katalog/werbemittel/neu');
        await page.locator('[data-test="medium-name-input"]').fill(visibleName);
        await page
            .locator('[data-test="medium-code-input"]')
            .fill(`wiz_null_${suffix}`);
        await page
            .locator('[data-test="medium-category-select"]')
            .selectOption({ label: 'Spots (spots)' });
        await page.locator('[data-test="medium-create-submit"]').click();
        await expect(page).toHaveURL(/werbemittel\/\d+$/, { timeout: 30_000 });

        const mediumId = Number(/werbemittel\/(\d+)/.exec(page.url())![1]);
        expect(mediumId).toBeGreaterThan(0);

        await page.goto('/kalkulationen/neu');
        await expect(page.getByText('Neue Kalkulation')).toBeVisible({
            timeout: 30_000,
        });

        let catalog = await readWizardCatalog(page);
        const inventory =
            catalog.inventories.find(
                (item) => item.is_active && item.name === 'Radio Hamburg',
            ) ?? catalog.inventories.find((item) => item.is_active);
        expect(inventory, 'Aktives Inventar im E2E-Katalog').toBeTruthy();

        // Aktive Inventarregel herstellen – ohne Buchbarkeit bliebe Medium trotzdem auswählbar
        // wenn nur die Regel fehlte; mit Regel beweist der Test den Buchbarkeitsfilter.
        attachActiveInventoryRule(mediumId, inventory!.id);

        await page.reload();
        await expect(page.getByText('Neue Kalkulation')).toBeVisible({
            timeout: 30_000,
        });

        catalog = await readWizardCatalog(page);
        const nullMedium = catalog.media.find((item) => item.name === visibleName);
        expect(nullMedium, `Medium „${visibleName}“ im Payload`).toBeTruthy();
        expect(nullMedium!.is_active).toBe(true);
        expect(nullMedium!.is_bookable_for_new_positions).toBe(false);
        expect(nullMedium!.unbookable_reason).toMatch(/Berechnungsmethode|technisch/i);

        const activeRule = catalog.rules.find(
            (rule) =>
                rule.is_active &&
                rule.advertising_medium_id === nullMedium!.id &&
                rule.inventory_id === inventory!.id,
        );
        expect(activeRule, 'Aktive InventoryMediumRule für Null-kind').toBeTruthy();

        // Wizard-Auswahlstruktur bis c4: nur Spot Classic + Buchbarkeit + aktive Regel.
        const selectableForInventory = catalog.media.filter(
            (medium) =>
                medium.is_bookable_for_new_positions &&
                medium.is_active &&
                medium.code === 'spot_classic' &&
                catalog.rules.some(
                    (rule) =>
                        rule.is_active &&
                        rule.inventory_id === inventory!.id &&
                        rule.advertising_medium_id === medium.id,
                ),
        );
        expect(
            selectableForInventory.some((item) => item.id === nullMedium!.id),
        ).toBe(false);
        expect(
            selectableForInventory.some((item) => item.code === 'spot_classic'),
        ).toBe(true);

        // Sichtbarer Name darf nicht als auswählbares Medium im UI auftauchen.
        await expect(page.getByText(visibleName, { exact: true })).toHaveCount(0);

        // Speichern als Admin (bereits eingeloggt) – Spot-Classic-Pfad bis Persistenz.
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page.locator('[data-test="position-inventory-0"]'),
        ).toBeVisible({ timeout: 15_000 });
        await page.locator('[data-test="range-spots-0-0"]').fill('10');
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 30_000 });
    });
});
