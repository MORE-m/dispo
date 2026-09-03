import { expect, test, type Page } from '@playwright/test';

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

async function createAndApprove(page: Page): Promise<void> {
    await login(page, 'sales@example.com');
    await page.goto('/kalkulationen/neu');
    await page.getByRole('button', { name: '2. Werbeelemente' }).click();
    await page.locator('[data-test="range-spots-0-0"]').fill('10');
    await page.getByRole('button', { name: '3. Konditionen' }).click();
    await page.getByRole('button', { name: 'Speichern' }).click();
    await expect(page).toHaveURL(/kalkulationen\/\d+/, { timeout: 15_000 });

    await page.getByRole('button', { name: '4. Zusammenfassung' }).click();
    await page.locator('[data-test="dispo-order-create-open"]').click();
    await page.locator('[data-test="dispo-order-submit"]').click();
    await expect(page).toHaveURL(/dispoauftraege\/\d+/, { timeout: 15_000 });

    await page.locator('[data-test="dispo-order-submit-open"]').click();
    await page.locator('[data-test="dispo-order-submit-confirm"]').click();
    await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
        'Wartet auf Vertriebsfreigabe',
        { timeout: 15_000 },
    );

    const detailUrl = page.url();
    await page.context().clearCookies();
    await login(page, 'sales-b@example.com');
    await page.goto(detailUrl);
    await page.locator('[data-test="dispo-order-approve-open"]').click();
    await page.locator('[data-test="dispo-order-approve-confirm"]').click();
    await expect(page.locator('[data-test="dispo-order-status-badge"]')).toHaveText(
        'Liegt bei Disposition',
        { timeout: 15_000 },
    );
}

async function formatSmokeCaseInContext(
    browser: import('@playwright/test').Browser,
    timezoneId: string,
): Promise<string> {
    const context = await browser.newContext({ timezoneId, locale: 'de-DE' });
    const page = await context.newPage();
    await page.goto('/login');
    const formatted = await page.evaluate(() => {
        return new Intl.DateTimeFormat('de-DE', {
            timeZone: 'Europe/Berlin',
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        }).format(new Date('2026-09-03T19:17:00Z'));
    });
    await context.close();

    return formatted;
}

function parseBerlinDisplayMinutes(value: string): number {
    const [datePart, timePart] = value.split(', ');
    const [day, month, year] = datePart.split('.').map(Number);
    const [hour, minute] = timePart.split(':').map(Number);

    return Date.UTC(year, month - 1, day, hour, minute) / 60_000;
}

test('UTC-Smoke-Fall ist in Berlin und Makassar identisch 03.09.2026, 21:17', async ({
    browser,
}) => {
    const berlin = await formatSmokeCaseInContext(browser, 'Europe/Berlin');
    const makassar = await formatSmokeCaseInContext(browser, 'Asia/Makassar');

    expect(berlin).toBe('03.09.2026, 21:17');
    expect(makassar).toBe('03.09.2026, 21:17');
});

for (const timezoneId of ['Europe/Berlin', 'Asia/Makassar'] as const) {
    test.describe(`Freigabelauf ${timezoneId}`, () => {
        test.use({ timezoneId });

        test('Einreichung und Entscheidung liegen eng beieinander und nutzen Berlin-Format', async ({
            page,
        }) => {
            test.setTimeout(180_000);
            await createAndApprove(page);

            const submitted = (
                await page.locator('[data-test="approval-submitted-at"]').innerText()
            ).trim();
            const decided = (
                await page.locator('[data-test="approval-decided-at"]').innerText()
            ).trim();

            expect(submitted).toMatch(/^\d{2}\.\d{2}\.\d{4}, \d{2}:\d{2}$/);
            expect(decided).toMatch(/^\d{2}\.\d{2}\.\d{4}, \d{2}:\d{2}$/);
            expect(submitted).not.toMatch(/^04\.09\.\d{4}, 0[34]:/);
            expect(
                Math.abs(
                    parseBerlinDisplayMinutes(decided) -
                        parseBerlinDisplayMinutes(submitted),
                ),
            ).toBeLessThan(120);
        });
    });
}
