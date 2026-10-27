import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';

/**
 * PO-CALC-TKP-1D3d-4: OA-TKP Dispo Playwright Smoke.
 * Isoliert: playwright.po-calc-tkp-d3d4.config.ts, Port 8073.
 */
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const fixturePath = path.join(root, 'database/e2e-po-calc-tkp-d3d4-orders.json');
const e2eDb = path.join(root, 'database/e2e-po-calc-tkp-d3d4.sqlite');
const sampleMp3 = path.join(root, 'tests/fixtures/audio-motif-sample.mp3');

type OrderRef = {
    id: number;
    number: string;
    positionId?: number;
    oaPositionId?: number;
    spotPositionId?: number;
    finalTkp?: string;
    impressions?: number;
    calcFlightStart?: string;
    calcFlightEnd?: string;
    status?: string;
};

type Fixture = {
    handoffCalc: { id: number; number: string };
    oaInProgress: OrderRef;
    mixedInProgress: OrderRef;
    materialOa: OrderRef;
    httpStoreProbe: {
        inventory_id: number;
        advertising_medium_id: number;
        oa_platform_id: number;
        schema_fingerprint: string;
        position_schema_fingerprint: string;
    };
    release: {
        engine_profile_key: string;
        calculation_method_key: string;
        pair_status: string;
        current_released_version: null;
    };
};

function loadFixture(): Fixture {
    return JSON.parse(readFileSync(fixturePath, 'utf8')) as Fixture;
}

function sqliteScalar(sql: string, params: Array<string | number>): string {
    const php = [
        '$pdo = new PDO("sqlite:" . getenv("D3D4_E2E_DB"));',
        '$st = $pdo->prepare(getenv("D3D4_E2E_SQL"));',
        '$params = json_decode(getenv("D3D4_E2E_PARAMS"), true);',
        '$st->execute($params);',
        '$val = $st->fetchColumn();',
        'echo $val === false || $val === null ? "" : (string) $val;',
    ].join('');

    return execFileSync('php', ['-r', php], {
        encoding: 'utf8',
        env: {
            ...process.env,
            D3D4_E2E_DB: e2eDb,
            D3D4_E2E_SQL: sql,
            D3D4_E2E_PARAMS: JSON.stringify(params),
        },
    }).trim();
}

function operationalFlight(positionId: number): { start: string; end: string } {
    return {
        start: sqliteScalar(
            'select operational_flight_start from dispo_order_positions where id=?',
            [positionId],
        ),
        end: sqliteScalar(
            'select operational_flight_end from dispo_order_positions where id=?',
            [positionId],
        ),
    };
}

function orderStatus(orderId: number): string {
    return sqliteScalar('select status from dispo_orders where id=?', [orderId]);
}

function lockVersion(orderId: number): number {
    return Number(
        sqliteScalar('select lock_version from dispo_orders where id=?', [orderId]),
    );
}

function positionFinalTkp(positionId: number): string {
    return sqliteScalar(
        'select cast(final_tkp as text) from dispo_order_positions where id=?',
        [positionId],
    );
}

function calculationCount(): number {
    return Number(sqliteScalar('select count(*) from calculations', []));
}

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

async function openOrder(page: Page, orderId: number) {
    await page.goto(`/dispoauftraege/${orderId}`);
    await expect(page).toHaveURL(new RegExp(`/dispoauftraege/${orderId}$`), {
        timeout: 30_000,
    });
    await expect(page.locator('[data-test="dispo-order-status-badge"]')).toBeVisible();
}

async function csrfHeaders(page: Page): Promise<Record<string, string>> {
    const cookies = await page.context().cookies();
    const xsrf = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');
    expect(xsrf, 'XSRF-TOKEN fehlt').toBeTruthy();
    const cookieHeader = cookies
        .map((cookie) => `${cookie.name}=${cookie.value}`)
        .join('; ');

    return {
        Cookie: cookieHeader,
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': decodeURIComponent(xsrf!.value),
        'X-Requested-With': 'XMLHttpRequest',
    };
}

test.describe.configure({ mode: 'serial' });

test.describe('PO-CALC-TKP-1D3d-4 OA Playwright Smoke', () => {
    test('HTTP Store für OA-TKP bleibt geschlossen; Fixture persistiert', async ({
        page,
        request,
    }) => {
        const fixture = loadFixture();
        expect(fixture.release.pair_status).toBe('planned');
        expect(fixture.release.current_released_version).toBeNull();
        expect(fixture.oaInProgress.impressions).toBe(100_000);
        expect(orderStatus(fixture.oaInProgress.id)).toBe('in_progress');
        expect(operationalFlight(fixture.oaInProgress.positionId!).start).toBe('');
        expect(operationalFlight(fixture.oaInProgress.positionId!).end).toBe('');

        const calcsBefore = calculationCount();
        await login(page, 'sales@example.com');
        const headers = await csrfHeaders(page);
        const probe = fixture.httpStoreProbe;
        const response = await request.post('/kalkulationen', {
            headers,
            data: {
                planning_mode: 'manual',
                customer_name: 'E2E D3d4 HTTP Block Probe',
                order_discount_percent: '0',
                ae_enabled: false,
                schema_fingerprint: probe.schema_fingerprint,
                dynamic_field_values: { campaign_period: null },
                positions: [
                    {
                        inventory_id: probe.inventory_id,
                        advertising_medium_id: probe.advertising_medium_id,
                        schema_fingerprint: probe.position_schema_fingerprint,
                        calculation_method_key: 'tkp',
                        impressions: 100_000,
                        basis_tkp: '10.0000',
                        oa_platform_allocations: [
                            {
                                oa_platform_id: probe.oa_platform_id,
                                impressions: 100_000,
                            },
                        ],
                        oa_targeting_selections: [],
                        position_discount_percent: '0',
                        ae_percent: '15',
                    },
                ],
            },
        });

        expect(response.status()).toBe(422);
        const body = await response.json();
        const messages = JSON.stringify(body);
        expect(messages).toContain('noch nicht freigegeben');
        expect(calculationCount()).toBe(calcsBefore);
    });

    test('Handoff: gespeicherte OA-Position → Dispoauftrag ohne Spotproduktion', async ({
        page,
    }) => {
        const fixture = loadFixture();
        await login(page, 'sales@example.com');
        await page.goto(`/kalkulationen/${fixture.handoffCalc.id}`);
        await expect(page).toHaveURL(
            new RegExp(`/kalkulationen/${fixture.handoffCalc.id}`),
            { timeout: 30_000 },
        );

        await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
        await expect(
            page.locator('[data-test="dispo-order-create-open"]'),
        ).toBeVisible({ timeout: 30_000 });
        await page.locator('[data-test="dispo-order-create-open"]').click();
        await expect(
            page.locator('[data-test="dispo-order-create-dialog"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-create-saved-positions-hint"]'),
        ).toContainText('zuletzt gespeicherten Kalkulationspositionen');
        await page.locator('[data-test="dispo-order-submit"]').click();
        await expect(page).toHaveURL(/dispoauftraege\/\d+/, { timeout: 30_000 });

        await expect(
            page.locator('[data-test="dispo-order-oa-tkp-snapshot-0"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-oa-tkp-prices-0"]'),
        ).toContainText('Impressions');
        await expect(
            page.locator('[data-test="dispo-order-oa-tkp-prices-0"]'),
        ).toContainText('100.000');
        await expect(page.locator('[data-test="dispo-production-lines"]')).toHaveCount(
            0,
        );
        // Draft/Vertrieb: Flight sichtbar, aber nur read-only (kein Edit/Save).
        await expect(
            page.locator('[data-test="dispo-order-operational-flight-readonly"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-operational-flight-save"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="dispo-order-operational-status-actions"]'),
        ).toHaveCount(0);
    });

    test('Dispo-Show + Flight-Übernahme ohne Auto-Save + Persistenz + disposed', async ({
        page,
    }) => {
        const fixture = loadFixture();
        const order = fixture.oaInProgress;
        const positionId = order.positionId!;

        await login(page, 'disposition@example.com');
        await openOrder(page, order.id);

        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
            'In Bearbeitung',
        );
        await expect(
            page.locator('[data-test="dispo-order-oa-tkp-snapshot-0"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-oa-tkp-prices-0"]'),
        ).toContainText('100.000');
        await expect(
            page.locator('[data-test="dispo-order-oa-platforms-0"]'),
        ).toContainText('E2E D3d4 Platform');
        await expect(page.locator('[data-test="dispo-production-lines"]')).toHaveCount(
            0,
        );
        await expect(
            page.locator(`[data-test="dispo-order-operational-flight-${positionId}"]`),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-operational-flight-hint-required"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-oa-operational-flight-blocker-hint"]'),
        ).toBeVisible();

        const snapshotTkpBefore = positionFinalTkp(positionId);

        await page.locator('[data-test="dispo-order-status-action-disposed"]').click();
        await expect(
            page.locator('[data-test="dispo-order-operational-status-error"]'),
        ).toContainText('Verbindlicher Kampagnenzeitraum (operativ)', {
            timeout: 15_000,
        });
        expect(orderStatus(order.id)).toBe('in_progress');

        await page
            .locator('[data-test="dispo-order-operational-flight-adopt"]')
            .click();
        await expect(
            page.locator('[data-test="dispo-order-operational-flight-start"]'),
        ).toHaveValue(order.calcFlightStart!);
        await expect(
            page.locator('[data-test="dispo-order-operational-flight-end"]'),
        ).toHaveValue(order.calcFlightEnd!);

        const afterAdopt = operationalFlight(positionId);
        expect(afterAdopt.start).toBe('');
        expect(afterAdopt.end).toBe('');

        await Promise.all([
            page.waitForResponse(
                (response) =>
                    response.request().method() === 'PUT' &&
                    response.url().includes('/operativer-flight') &&
                    response.ok(),
                { timeout: 30_000 },
            ),
            page.locator('[data-test="dispo-order-operational-flight-save"]').click(),
        ]);

        await expect(
            page.locator('[data-test="dispo-order-operational-flight-status-set"]'),
        ).toBeVisible({ timeout: 30_000 });

        const afterSave = operationalFlight(positionId);
        expect(afterSave.start).toBe(order.calcFlightStart!);
        expect(afterSave.end).toBe(order.calcFlightEnd!);
        expect(positionFinalTkp(positionId)).toBe(snapshotTkpBefore);

        await page.reload();
        await expect(
            page.locator('[data-test="dispo-order-operational-flight-status-set"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-operational-flight-start"]'),
        ).toHaveValue(order.calcFlightStart!);
        await expect(
            page.locator('[data-test="dispo-order-operational-flight-end"]'),
        ).toHaveValue(order.calcFlightEnd!);
        await expect(
            page.locator('[data-test="dispo-order-oa-tkp-prices-0"]'),
        ).toContainText('100.000');
        await expect(
            page.getByText('Kalkulationszeitraum', { exact: false }).first(),
        ).toBeVisible();

        await page.locator('[data-test="dispo-order-status-action-disposed"]').click();
        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
            'Disponiert',
            { timeout: 30_000 },
        );
        expect(orderStatus(order.id)).toBe('disposed');

        await expect(
            page.locator('[data-test="dispo-order-completion-section"]'),
        ).toBeVisible();
        await expect(
            page.locator(
                '[data-test="dispo-order-completion-check-oa_operational_flight"]',
            ),
        ).toContainText('✓');
        await expect(
            page.locator('[data-test="dispo-order-completion-check-customer_confirmation"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-completion-check-approvals"]'),
        ).toBeVisible();
        expect(positionFinalTkp(positionId)).toBe(snapshotTkpBefore);
    });

    test('Mixed Spot+OA: getrennte Darstellung; fehlender OA-Flight blockiert disposed', async ({
        page,
    }) => {
        const fixture = loadFixture();
        const order = fixture.mixedInProgress;
        const oaPositionId = order.oaPositionId!;

        await login(page, 'disposition@example.com');
        await openOrder(page, order.id);

        await expect(
            page.locator('[data-test="dispo-order-oa-tkp-snapshot-1"]'),
        ).toBeVisible();
        await expect(
            page.locator(`[data-test="dispo-order-operational-flight-${oaPositionId}"]`),
        ).toBeVisible();
        await expect(
            page.locator(
                `[data-test="dispo-order-operational-flight-${order.spotPositionId}"]`,
            ),
        ).toHaveCount(0);

        await page.locator('[data-test="dispo-order-status-action-disposed"]').click();
        await expect(
            page.locator('[data-test="dispo-order-operational-status-error"]'),
        ).toContainText('Verbindlicher Kampagnenzeitraum (operativ)', {
            timeout: 15_000,
        });
        expect(orderStatus(order.id)).toBe('in_progress');

        await page
            .locator('[data-test="dispo-order-operational-flight-adopt"]')
            .click();
        await Promise.all([
            page.waitForResponse(
                (response) =>
                    response.request().method() === 'PUT' &&
                    response.url().includes('/operativer-flight') &&
                    response.ok(),
                { timeout: 30_000 },
            ),
            page.locator('[data-test="dispo-order-operational-flight-save"]').click(),
        ]);
        await expect(
            page.locator('[data-test="dispo-order-operational-flight-status-set"]'),
        ).toBeVisible({ timeout: 30_000 });
        expect(operationalFlight(oaPositionId).start).not.toBe('');

        await page.locator('[data-test="dispo-order-status-action-disposed"]').click();
        await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
            'Disponiert',
            { timeout: 30_000 },
        );
        expect(orderStatus(order.id)).toBe('disposed');
    });

    test('Material-Upload an OA ändert Status nicht; keine Spotproduktion', async ({
        page,
    }) => {
        const fixture = loadFixture();
        const order = fixture.materialOa;
        const statusBefore = orderStatus(order.id);
        const tkpBefore = positionFinalTkp(order.positionId!);

        await login(page, 'disposition@example.com');
        await openOrder(page, order.id);

        await expect(
            page.locator('[data-test="dispo-order-material-upload"]'),
        ).toBeVisible();
        await expect(page.locator('[data-test="dispo-production-lines"]')).toHaveCount(
            0,
        );

        await page
            .locator('[data-test="dispo-order-material-upload-category"]')
            .selectOption('audio_motif');
        await page
            .locator('[data-test="dispo-order-material-upload-file"]')
            .setInputFiles(sampleMp3);

        await Promise.all([
            page.waitForResponse(
                (response) =>
                    response.request().method() === 'POST' &&
                    /\/dispoauftraege\/\d+\/uploads$/.test(
                        new URL(response.url()).pathname,
                    ) &&
                    response.ok(),
                { timeout: 30_000 },
            ),
            page.locator('[data-test="dispo-order-material-upload-submit"]').click(),
        ]);

        await expect(
            page.locator('[data-test^="dispo-order-upload-audio-"]').first(),
        ).toBeVisible({ timeout: 30_000 });
        expect(orderStatus(order.id)).toBe(statusBefore);
        expect(positionFinalTkp(order.positionId!)).toBe(tkpBefore);
        await expect(page.locator('[data-test="dispo-production-lines"]')).toHaveCount(
            0,
        );
    });

    test('Vertrieb sieht Flight nur read-only; 409 bei veraltetem lock_version', async ({
        page,
        request,
    }) => {
        const fixture = loadFixture();
        const order = fixture.materialOa;
        const positionId = order.positionId!;

        await login(page, 'sales@example.com');
        await openOrder(page, order.id);

        await expect(
            page.locator(`[data-test="dispo-order-operational-flight-${positionId}"]`),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-operational-flight-readonly"]'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="dispo-order-operational-flight-save"]'),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-test="dispo-order-operational-status-actions"]'),
        ).toHaveCount(0);

        await page.context().clearCookies();
        await login(page, 'disposition@example.com');
        await openOrder(page, order.id);
        const headers = await csrfHeaders(page);
        const stale = await request.put(
            `/dispoauftraege/${order.id}/positionen/${positionId}/operativer-flight`,
            {
                headers,
                data: {
                    lock_version: lockVersion(order.id) + 99,
                    operational_flight_start: '2026-05-01',
                    operational_flight_end: '2026-05-31',
                },
            },
        );
        expect(stale.status()).toBe(409);
    });
});
