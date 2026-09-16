import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';

/**
 * ADV-001c4b isolierte Suite: Wizard-Methodenauswahl und Medienfilter.
 * Läuft nur über playwright.adv001c4b.config.ts (eigene DB, Port 8012).
 *
 * BL-P4-02b: Spot Classic bietet live average + calendar (beide released).
 * Historische/nicht wählbare Methode: fixed_price (weiterhin planned).
 */

const e2eDb = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    '../../database/e2e-adv001c4b.sqlite',
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

function runTinker(php: string): string {
    return execFileSync('php', ['artisan', 'tinker', '--execute', php], {
        cwd: projectRoot,
        env: {
            ...process.env,
            APP_ENV: 'testing',
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: e2eDb,
            DB_URL: '',
        },
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    }).trim();
}

type WizardPagePayload = {
    catalog: {
        media: Array<{
            id: number;
            name: string;
            code: string;
            is_active: boolean;
            is_bookable_for_new_positions: boolean;
            calculation_method_options?: {
                methods: Array<{ key: string; name: string }>;
                default_calculation_method_key: string | null;
            } | null;
        }>;
        rules: Array<{
            inventory_id: number;
            advertising_medium_id: number;
            is_active: boolean;
        }>;
        inventories: Array<{ id: number; name: string; is_active: boolean }>;
    };
    calculation?: {
        id: number;
        positions: Array<{
            calculation_method_key?: string | null;
            calculation_method_name?: string | null;
            advertising_medium_id: number;
            inventory_id: number;
        }>;
    } | null;
};

async function readWizardPayload(page: Page): Promise<WizardPagePayload> {
    return page.evaluate(() => {
        const script = document.querySelector(
            'script[data-page][type="application/json"]',
        );
        if (!script?.textContent) {
            throw new Error('Inertia data-page Script fehlt');
        }
        const payload = JSON.parse(script.textContent) as {
            props?: WizardPagePayload;
        };
        if (!payload.props?.catalog) {
            throw new Error('Wizard-Payload nicht gefunden');
        }

        return payload.props;
    });
}

async function expectLiveMethodChooser(page: Page) {
    await expect(
        page.locator('[data-test="calculation-method-fieldset-0"]'),
    ).toBeVisible();
    await expect(
        page.locator('[data-test="calculation-method-radio-0-average"]'),
    ).toBeVisible();
    await expect(
        page.locator('[data-test="calculation-method-radio-0-calendar"]'),
    ).toBeVisible();
    await expect(
        page.locator('[data-test="calculation-method-single-0"]'),
    ).toHaveCount(0);
    await expect(
        page.locator('[data-test="calculation-method-empty-0"]'),
    ).toHaveCount(0);
}

test.describe.serial('ADV-001c4b Wizard Berechnungsmethoden', () => {
    test('neue Spot-Classic-Position zeigt Durchschnitt und Kalenderplaner', async ({
        page,
    }) => {
        await login(page, 'sales@example.com');
        await page.goto('/kalkulationen/neu');
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();

        await expectLiveMethodChooser(page);
        await expect(
            page.locator('[data-test="calculation-method-radio-0-average"]'),
        ).toBeChecked();

        const payload = await readWizardPayload(page);
        const spot = payload.catalog.media.find(
            (medium) => medium.code === 'spot_classic',
        );
        expect(spot?.is_bookable_for_new_positions).toBe(true);
        expect(
            spot?.calculation_method_options?.default_calculation_method_key,
        ).toBe('average');
        const keys =
            spot?.calculation_method_options?.methods.map(
                (method) => method.key,
            ) ?? [];
        expect(keys).toEqual(expect.arrayContaining(['average', 'calendar']));
        expect(keys).not.toContain('fixed_price');
    });

    test('Methodenauswahl bleibt über Wizard-Navigation erhalten und speichert', async ({
        page,
    }) => {
        await login(page, 'sales@example.com');
        await page.goto('/kalkulationen/neu');

        await page.locator('#customer').fill('C4b Nav GmbH');
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();

        await expectLiveMethodChooser(page);
        await expect(
            page.locator('[data-test="calculation-method-radio-0-average"]'),
        ).toBeChecked();

        await page
            .locator('[data-test="position-length-seconds-0"]')
            .fill('30');
        await page.locator('[data-test="range-end-0-0"]').selectOption('9');
        await page.locator('[data-test="range-spots-0-0"]').fill('2');

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();

        await expect(
            page.locator('[data-test="calculation-method-radio-0-average"]'),
        ).toBeChecked();

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.locator('[data-test="wizard-save"]').click();
        await expect(page).toHaveURL(/\/kalkulationen\/\d+$/, {
            timeout: 30_000,
        });

        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page.locator('[data-test="calculation-method-radio-0-average"]'),
        ).toBeChecked();

        const props = await readWizardPayload(page);
        expect(props.calculation?.positions[0]?.calculation_method_key).toBe(
            'average',
        );
    });

    test('Medienwechsel setzt Methodenstate neu; Inventarwechsel behält Methode', async ({
        page,
    }) => {
        await login(page, 'sales@example.com');
        await page.goto('/kalkulationen/neu');
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();

        const props = await readWizardPayload(page);
        const spotA = props.catalog.media.find(
            (medium) => medium.code === 'spot_classic',
        );
        const spotB = props.catalog.media.find(
            (medium) => medium.code === 'spot_classic_b',
        );
        expect(spotA).toBeTruthy();
        expect(spotB?.is_bookable_for_new_positions).toBe(true);

        const mediumSelect = page.locator('[data-test="position-medium-0"]');
        await expect(mediumSelect).toBeVisible();
        const optionCodes = await mediumSelect.locator('option').evaluateAll(
            (nodes, ids) =>
                nodes.map((node) => {
                    const id = Number((node as HTMLOptionElement).value);
                    return ids[id] ?? String(id);
                }),
            Object.fromEntries(
                props.catalog.media.map((medium) => [medium.id, medium.code]),
            ),
        );
        expect(optionCodes).toContain('spot_classic');
        expect(optionCodes).toContain('spot_classic_b');

        await mediumSelect.selectOption(String(spotB!.id));
        await expect(
            page.locator('[data-test="calculation-method-radio-0-average"]'),
        ).toBeChecked();

        const inventories = props.catalog.inventories.filter(
            (item) => item.is_active,
        );
        expect(inventories.length).toBeGreaterThan(1);
        await page.locator('[data-test="position-inventory-0"]').selectOption({
            label: inventories[1].name,
        });

        await expect(mediumSelect).toHaveValue(String(spotB!.id));
        await expect(
            page.locator('[data-test="calculation-method-radio-0-average"]'),
        ).toBeChecked();
    });

    test('historische Methode wird angezeigt und nicht automatisch ersetzt', async ({
        page,
    }) => {
        const created = runTinker(`
$medium = \\App\\Models\\AdvertisingMedium::query()->where('code', 'spot_classic')->firstOrFail();
$inventory = \\App\\Models\\Inventory::query()->where('code', 'RH')->firstOrFail();
$user = \\App\\Models\\User::query()->where('email', 'sales@example.com')->firstOrFail();
$freeze = app(\\App\\Services\\DynamicField\\ConfigurationSnapshotFreezeService::class);
$base = $freeze->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
$pos = $freeze->resolveLivePositionSchema((int) $medium->id)['schema_fingerprint'];
$calc = app(\\App\\Services\\Calculation\\CalculationWriter::class)->create([
    'planning_mode' => 'manual',
    'schema_fingerprint' => $base,
    'customer_name' => 'C4b Historisch',
    'campaign' => 'C4b-Hist',
    'order_discount_percent' => '0',
    'ae_enabled' => false,
    'positions' => [[
        'inventory_id' => $inventory->id,
        'advertising_medium_id' => $medium->id,
        'schema_fingerprint' => $pos,
        'calculation_method_key' => 'average',
        'length_seconds' => 30,
        'total_spot_count' => 1,
        'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
    ]],
], $user);
$position = $calc->positions()->firstOrFail();
// BL-P4-02b: calendar ist live wählbar; historische Nicht-Verfügbarkeit über fixed_price.
\\Illuminate\\Support\\Facades\\DB::table('calculation_positions')->where('id', $position->id)->update([
    'calculation_method_key' => 'fixed_price',
    'calculation_method_name' => 'Historischer Festpreis',
    'spot_method' => 'fixed_price',
]);
echo $calc->id;
`);

        const idMatch = created.match(/(\d+)\s*$/);
        const id = idMatch?.[1];
        expect(id).toMatch(/^\d+$/);

        await login(page, 'sales@example.com');
        await page.goto(`/kalkulationen/${id}`);
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();

        await expect(
            page.locator('[data-test="calculation-method-historical-0"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="calculation-method-historical-label-0"]'),
        ).toContainText('Historischer Festpreis');
        await expect(
            page.locator('[data-test="calculation-method-historical-0"]'),
        ).toContainText(/nicht mehr auswählbar/);

        await expect(
            page.locator('[data-test="calculation-method-fieldset-0"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="calculation-method-radio-0-average"]'),
        ).not.toBeChecked();

        await page
            .locator('[data-test="position-length-seconds-0"]')
            .fill('25');
        await expect(
            page.locator('[data-test="calculation-method-historical-label-0"]'),
        ).toContainText('Historischer Festpreis');
        await expect(
            page.locator('[data-test="calculation-method-radio-0-average"]'),
        ).not.toBeChecked();

        await page
            .locator('[data-test="calculation-method-radio-0-average"]')
            .check();
        await expect(
            page.locator('[data-test="calculation-method-historical-0"]'),
        ).toHaveCount(0);
        await expect(
            page.locator(
                '[data-test="calculation-method-restore-historical-0"]',
            ),
        ).toBeVisible();

        await page
            .locator('[data-test="calculation-method-restore-historical-0"]')
            .click();
        await expect(
            page.locator('[data-test="calculation-method-historical-label-0"]'),
        ).toContainText('Historischer Festpreis');
    });

    test('nicht buchbare Medien bleiben ausgeschlossen; Tastaturfokus sichtbar', async ({
        page,
    }) => {
        await login(page, 'sales@example.com');
        await page.goto('/kalkulationen/neu');
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();

        const props = await readWizardPayload(page);
        const unbookable = props.catalog.media.filter(
            (medium) =>
                !medium.is_bookable_for_new_positions || !medium.is_active,
        );
        const mediumSelect = page.locator('[data-test="position-medium-0"]');
        const values = await mediumSelect
            .locator('option')
            .evaluateAll((nodes) =>
                nodes.map((node) => Number((node as HTMLOptionElement).value)),
            );

        for (const medium of unbookable) {
            if (medium.id === Number(await mediumSelect.inputValue())) {
                continue;
            }
            expect(values).not.toContain(medium.id);
        }

        await page.locator('[data-test="position-medium-0"]').focus();
        await expect(
            page.locator('[data-test="position-medium-0"]'),
        ).toBeFocused();
        await page.keyboard.press('Tab');
    });
});
