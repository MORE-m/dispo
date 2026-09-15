import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';

/**
 * BL-P4-01c isolierte Suite: Preisjahrwahl im Kalkulationswizard (PO-PRI-YEAR-1).
 * Nur über playwright.blp401c.config.ts (DB e2e-bl-p4-01c.sqlite, Port 8019).
 */

const e2eDb = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    '../../database/e2e-bl-p4-01c.sqlite',
);

const e2eEnv = {
    ...process.env,
    APP_ENV: 'testing',
    E2E_SERVER: '1',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: e2eDb,
    DB_URL: '',
};

async function loginAsSales(page: Page) {
    await page.goto('/login');
    await page.locator('#email').fill('sales@example.com');
    await page.locator('#password').fill('password');
    await page.locator('[data-test="login-button"]').click();
    await page.waitForFunction(
        () => !window.location.pathname.includes('/login'),
        undefined,
        { timeout: 30_000 },
    );
}

function phpEval(code: string): string {
    try {
        return execFileSync('php', ['artisan', 'tinker', '--execute=' + code], {
            encoding: 'utf8',
            env: e2eEnv,
        }).trim();
    } catch (error) {
        const err = error as { stderr?: Buffer | string; stdout?: Buffer | string; message?: string };
        const stderr = typeof err.stderr === 'string' ? err.stderr : err.stderr?.toString() ?? '';
        const stdout = typeof err.stdout === 'string' ? err.stdout : err.stdout?.toString() ?? '';
        throw new Error(`phpEval failed: ${stderr || stdout || err.message || String(error)}`);
    }
}

function ensureNextYearActiveList(): { year: number; version: string } {
    const output = phpEval(`
use App\\Enums\\DayGroup;
use App\\Enums\\PriceListStatus;
use App\\Models\\Inventory;
use App\\Models\\PriceList;
use App\\Models\\PriceListItem;
use App\\Support\\PriceList\\PriceListCalendar;
$year = PriceListCalendar::currentYear() + 1;
$version = 'e2e-next';
foreach (Inventory::query()->where('is_active', true)->get() as $inventory) {
    $existing = PriceList::query()
        ->where('inventory_id', $inventory->id)
        ->where('year', $year)
        ->where('status', PriceListStatus::Active)
        ->first();
    if ($existing !== null) {
        continue;
    }
    $list = PriceList::factory()->create([
        'inventory_id' => $inventory->id,
        'year' => $year,
        'status' => PriceListStatus::Active,
        'version' => $version.'-'.$inventory->code,
        'valid_from' => sprintf('%04d-01-01', $year),
    ]);
    foreach (range(0, 23) as $hour) {
        foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
            PriceListItem::factory()->create([
                'price_list_id' => $list->id,
                'hour' => $hour,
                'day_group' => $group,
                'second_price' => '2.0000',
            ]);
        }
    }
}
$sample = PriceList::query()
    ->where('year', $year)
    ->where('status', PriceListStatus::Active)
    ->where('version', $version.'-RH')
    ->first()
    ?? PriceList::query()
        ->where('year', $year)
        ->where('status', PriceListStatus::Active)
        ->where('version', 'like', $version.'%')
        ->first();
echo $year.'|'.($sample?->version ?? $version);
`);
    const [year, version] = output.split('|');
    return { year: Number(year), version };
}

async function waitForCalculationPreview(page: Page) {
    await expect(page.locator('[data-test="preview-loading"]')).toHaveCount(0, {
        timeout: 15_000,
    });
    await expect(
        page.locator('[data-test="preview-net-total"]').first(),
    ).toBeVisible({ timeout: 15_000 });
}

test.describe.serial('BL-P4-01c Wizard-Preisjahrwahl', () => {
    test('aktuelles Jahr vorausgewählt, Folgejahr speicherbar, Budget-Jahr sichtbar', async ({
        page,
    }) => {
        const next = ensureNextYearActiveList();
        await loginAsSales(page);
        await page.goto('/kalkulationen/neu');

        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        const yearSelect = page.locator('[data-test="position-price-year-0"]');
        await expect(yearSelect).toBeVisible();
        const currentYear = await yearSelect.inputValue();
        expect(Number(currentYear)).toBe(next.year - 1);
        await expect(yearSelect.locator('option')).toContainText([
            String(next.year),
        ]);

        await page.locator('[data-test="range-end-0-0"]').selectOption('9');
        await page.locator('[data-test="range-spots-0-0"]').fill('2');
        await page.locator('[data-test="position-length-seconds-0"]').fill('30');
        await yearSelect.selectOption(String(next.year));
        await expect(
            page.getByText('Nach dem Speichern wird die Position'),
        ).toBeVisible();
        await waitForCalculationPreview(page);

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 20_000 });
        await expect(page.getByText('Kalkulation gespeichert')).toBeVisible();

        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page.locator('[data-test="position-price-year-0"]'),
        ).toHaveValue(String(next.year));
        await expect(
            page.locator('[data-test="position-price-year-0"] option:checked'),
        ).toContainText(next.version);

        await page.goto('/kalkulationen/neu');
        await page
            .getByRole('radio', { name: /Mit Budget planen/i })
            .click({ force: true });
        await page.getByRole('button', { name: '2. Planungsrahmen' }).click();
        await expect(page.locator('[data-test="budget-price-year"]')).toBeVisible();
        await page
            .locator('#budget-element-inventory-0')
            .selectOption({ label: 'Radio Hamburg' });
        await expect(
            page.locator('[data-test="budget-price-year"]'),
        ).toHaveValue(String(next.year - 1));
        await expect(
            page.locator('[data-test="budget-price-year"] option'),
        ).toContainText([String(next.year)]);
    });

    test('fehlende aktuelle Liste ohne Fallback und 409 bei veralteter Expected-ID', async ({
        page,
    }) => {
        const probe = phpEval(`
use App\\Enums\\PriceListStatus;
use App\\Models\\Inventory;
use App\\Models\\PriceList;
use App\\Support\\PriceList\\PriceListCalendar;
$inventory = Inventory::query()->where('code', 'RH')->firstOrFail();
$year = PriceListCalendar::currentYear();
$list = PriceList::query()
    ->where('inventory_id', $inventory->id)
    ->where('year', $year)
    ->where('status', PriceListStatus::Active)
    ->first();
echo ($list?->id ?? 0).'|'.$year.'|'.$inventory->id;
`);
        const [listIdRaw, yearRaw, inventoryIdRaw] = probe.split('|');
        const listId = Number(listIdRaw);
        const year = Number(yearRaw);
        const inventoryId = Number(inventoryIdRaw);
        expect(listId).toBeGreaterThan(0);

        await loginAsSales(page);
        await page.goto('/kalkulationen/neu');
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page.locator('[data-test="position-price-year-0"]'),
        ).toHaveValue(String(year));

        // Archiviert die aktuelle Jahresliste → Option bleibt sichtbar, Speichern fail-closed.
        phpEval(`
use App\\Enums\\PriceListStatus;
use App\\Models\\PriceList;
$list = PriceList::query()->findOrFail(${listId});
$list->status = PriceListStatus::Archived;
$list->save();
echo 'ok';
`);

        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await expect(
            page.locator('[data-test="position-price-year-missing-0"]'),
        ).toBeVisible();

        // Stellt Active wieder her und prüft 409 bei manipulierter Expected-ID.
        phpEval(`
use App\\Enums\\PriceListStatus;
use App\\Models\\PriceList;
$list = PriceList::query()->findOrFail(${listId});
$list->status = PriceListStatus::Active;
$list->save();
echo 'ok';
`);

        // API-409 über Wizard-Update nach Speichern einer Position.
        await page.goto('/kalkulationen/neu');
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await page.locator('[data-test="range-end-0-0"]').selectOption('9');
        await page.locator('[data-test="range-spots-0-0"]').fill('1');
        await page.locator('[data-test="position-length-seconds-0"]').fill('30');
        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 20_000 });

        const calcUrl = page.url();
        const calcId = Number(calcUrl.match(/kalkulationen\/(\d+)/)?.[1] ?? 0);
        expect(calcId).toBeGreaterThan(0);

        const metaRaw = phpEval(`
use App\\Models\\Calculation;
use App\\Services\\Calculation\\CalculationWriter;
$calc = Calculation::query()->with([
    'positions.planRows',
    'positions.timeRanges',
    'positions.discounts',
    'orderDiscounts',
    'configurationSnapshot',
])->findOrFail(${calcId});
$payload = app(CalculationWriter::class)->payloadFromCalculation($calc);
$position = $payload['positions'][0] ?? null;
echo json_encode([
    'lock_version' => $calc->lock_version,
    'schema_fingerprint' => $payload['schema_fingerprint'] ?? null,
    'position' => $position,
], JSON_THROW_ON_ERROR);
`);
        const meta = JSON.parse(metaRaw) as {
            lock_version: number;
            schema_fingerprint: string;
            position: {
                id: number;
                client_key: string;
                advertising_medium_id: number;
                schema_fingerprint: string;
                length_seconds: number;
            };
        };
        expect(meta.position?.schema_fingerprint).toBeTruthy();

        const token = await page.evaluate(() => {
            const row = document.cookie
                .split('; ')
                .find((part) => part.startsWith('XSRF-TOKEN='));
            return row
                ? decodeURIComponent(row.slice('XSRF-TOKEN='.length))
                : '';
        });

        const conflict = await page.request.put(`/kalkulationen/${calcId}`, {
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': token,
            },
            data: {
                planning_mode: 'manual',
                lock_version: meta.lock_version,
                schema_fingerprint: meta.schema_fingerprint,
                order_discount_percent: '0',
                positions: [
                    {
                        id: meta.position.id,
                        client_key: meta.position.client_key,
                        inventory_id: inventoryId,
                        advertising_medium_id:
                            meta.position.advertising_medium_id,
                        schema_fingerprint: meta.position.schema_fingerprint,
                        spot_method: 'average',
                        price_year: year + 1,
                        expected_price_list_id: 999999,
                        length_seconds: meta.position.length_seconds,
                        total_spot_count: 1,
                        position_discount_percent: '0',
                        ae_percent: '0',
                        plan_rows: [{ hour: 8, day_group: 'mo_fr' }],
                    },
                ],
            },
        });

        const body = await conflict.json();
        expect(conflict.status(), JSON.stringify(body)).toBe(409);
        expect(String(body.message ?? '')).toContain(
            'Preisliste hat sich geändert',
        );
    });

    test('historischer Pin bleibt nach Same-Year-Nachfolger sichtbar; Active-Drift → 409', async ({
        page,
    }) => {
        await loginAsSales(page);
        await page.goto('/kalkulationen/neu');
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        await page.locator('[data-test="range-end-0-0"]').selectOption('9');
        await page.locator('[data-test="range-spots-0-0"]').fill('1');
        await page.locator('[data-test="position-length-seconds-0"]').fill('30');
        const pinnedVersion = await page
            .locator('[data-test="position-price-year-0"] option:checked')
            .textContent();
        expect(pinnedVersion ?? '').toMatch(/·/);

        await page.getByRole('button', { name: '3. Konditionen' }).click();
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 20_000 });
        const calcId = Number(page.url().match(/kalkulationen\/(\d+)/)?.[1] ?? 0);
        expect(calcId).toBeGreaterThan(0);

        const successorInfo = phpEval(`
use App\\Enums\\DayGroup;
use App\\Enums\\PriceListStatus;
use App\\Models\\Calculation;
use App\\Models\\PriceList;
use App\\Models\\PriceListItem;
$calc = Calculation::query()->with('positions')->findOrFail(${calcId});
$position = $calc->positions->firstOrFail();
$pinned = PriceList::query()->findOrFail($position->price_list_id);
$year = (int) $pinned->year;
$pinnedVersion = (string) $pinned->version;
$pinnedId = (int) $pinned->id;
$inventoryId = (int) $pinned->inventory_id;
PriceList::query()
    ->where('inventory_id', $inventoryId)
    ->where('year', $year)
    ->where('status', PriceListStatus::Active)
    ->update(['status' => PriceListStatus::Archived->value]);
$successor = PriceList::factory()->create([
    'inventory_id' => $inventoryId,
    'year' => $year,
    'status' => PriceListStatus::Active,
    'version' => 'e2e-successor-'.$year,
    'revision_number' => ((int) PriceList::query()->where('inventory_id', $inventoryId)->where('year', $year)->max('revision_number')) + 1,
    'valid_from' => sprintf('%04d-01-01', $year),
]);
foreach (range(0, 23) as $hour) {
    foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
        PriceListItem::factory()->create([
            'price_list_id' => $successor->id,
            'hour' => $hour,
            'day_group' => $group,
            'second_price' => '9.0000',
        ]);
    }
}
echo $pinnedVersion.'|'.$successor->version.'|'.$year.'|'.$inventoryId.'|'.$pinnedId;
`);
        const [pinnedVer, successorVer, yearRaw, inventoryIdRaw, pinnedIdRaw] =
            successorInfo.split('|');
        const year = Number(yearRaw);
        const inventoryId = Number(inventoryIdRaw);
        const pinnedId = Number(pinnedIdRaw);

        await page.reload();
        await page.getByRole('button', { name: '2. Werbeelemente' }).click();
        const selected = page.locator(
            '[data-test="position-price-year-0"] option:checked',
        );
        await expect(selected).toContainText(pinnedVer);
        await expect(selected).toContainText('historisch');
        await expect(selected).not.toContainText(successorVer);

        // Realistischer Active-Wechsel: Folgejahr A erwartet, Activate macht B aktiv.
        const drift = phpEval(`
use App\\Enums\\DayGroup;
use App\\Enums\\PriceListStatus;
use App\\Models\\PriceList;
use App\\Models\\PriceListItem;
use App\\Support\\PriceList\\PriceListCalendar;
$nextYear = PriceListCalendar::currentYear() + 1;
$inventoryId = ${inventoryId};
$expected = PriceList::query()
    ->where('inventory_id', $inventoryId)
    ->where('year', $nextYear)
    ->where('status', PriceListStatus::Active)
    ->first();
if ($expected === null) {
    $expected = PriceList::factory()->create([
        'inventory_id' => $inventoryId,
        'year' => $nextYear,
        'status' => PriceListStatus::Active,
        'version' => 'e2e-drift-A',
        'revision_number' => ((int) PriceList::query()->where('inventory_id', $inventoryId)->where('year', $nextYear)->max('revision_number')) + 1,
    ]);
    foreach (range(0, 23) as $hour) {
        foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
            PriceListItem::factory()->create([
                'price_list_id' => $expected->id,
                'hour' => $hour,
                'day_group' => $group,
                'second_price' => '2.0000',
            ]);
        }
    }
}
$draft = PriceList::factory()->create([
    'inventory_id' => $inventoryId,
    'year' => $nextYear,
    'status' => PriceListStatus::Draft,
    'version' => 'e2e-drift-B',
    'revision_number' => ((int) PriceList::query()->where('inventory_id', $inventoryId)->where('year', $nextYear)->max('revision_number')) + 1,
]);
foreach (range(0, 23) as $hour) {
    foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
        PriceListItem::factory()->create([
            'price_list_id' => $draft->id,
            'hour' => $hour,
            'day_group' => $group,
            'second_price' => '3.0000',
        ]);
    }
}
$expected->status = PriceListStatus::Archived;
$expected->save();
$draft->status = PriceListStatus::Active;
$draft->save();
echo $expected->id.'|'.$draft->id.'|'.$nextYear;
`);
        const [expectedIdRaw, , nextYearRaw] = drift.split('|');
        const expectedId = Number(expectedIdRaw);
        const nextYear = Number(nextYearRaw);

        const metaRaw = phpEval(`
use App\\Models\\Calculation;
use App\\Services\\Calculation\\CalculationWriter;
$calc = Calculation::query()->with([
    'positions.planRows',
    'positions.timeRanges',
    'positions.discounts',
    'orderDiscounts',
    'configurationSnapshot',
])->findOrFail(${calcId});
$payload = app(CalculationWriter::class)->payloadFromCalculation($calc);
echo json_encode([
    'lock_version' => $calc->lock_version,
    'schema_fingerprint' => $payload['schema_fingerprint'] ?? null,
    'position' => $payload['positions'][0] ?? null,
    'pinned_id' => $calc->positions->first()->price_list_id,
], JSON_THROW_ON_ERROR);
`);
        const meta = JSON.parse(metaRaw) as {
            lock_version: number;
            schema_fingerprint: string;
            pinned_id: number;
            position: {
                id: number;
                client_key: string;
                advertising_medium_id: number;
                schema_fingerprint: string;
                length_seconds: number;
            };
        };
        expect(meta.pinned_id).toBe(pinnedId);

        const token = await page.evaluate(() => {
            const row = document.cookie
                .split('; ')
                .find((part) => part.startsWith('XSRF-TOKEN='));
            return row
                ? decodeURIComponent(row.slice('XSRF-TOKEN='.length))
                : '';
        });

        const conflict = await page.request.put(`/kalkulationen/${calcId}`, {
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': token,
            },
            data: {
                planning_mode: 'manual',
                lock_version: meta.lock_version,
                schema_fingerprint: meta.schema_fingerprint,
                order_discount_percent: '0',
                positions: [
                    {
                        id: meta.position.id,
                        client_key: meta.position.client_key,
                        inventory_id: inventoryId,
                        advertising_medium_id:
                            meta.position.advertising_medium_id,
                        schema_fingerprint: meta.position.schema_fingerprint,
                        spot_method: 'average',
                        price_year: nextYear,
                        expected_price_list_id: expectedId,
                        length_seconds: meta.position.length_seconds,
                        total_spot_count: 1,
                        position_discount_percent: '0',
                        ae_percent: '0',
                        plan_rows: [{ hour: 8, day_group: 'mo_fr' }],
                    },
                ],
            },
        });
        const driftBody = await conflict.json();
        expect(conflict.status(), JSON.stringify(driftBody)).toBe(409);
        expect(String(driftBody.message ?? '')).toContain(
            'Preisliste hat sich geändert',
        );

        const stillPinned = phpEval(
            `echo App\\Models\\Calculation::query()->findOrFail(${calcId})->positions()->firstOrFail()->price_list_id;`,
        );
        expect(Number(stillPinned)).toBe(pinnedId);
        expect(Number(stillPinned)).not.toBe(expectedId);
        expect(year).toBeGreaterThan(2000);
    });
});
