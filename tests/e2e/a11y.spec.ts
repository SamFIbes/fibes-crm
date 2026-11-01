import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

async function signIn(page: import('@playwright/test').Page) {
    await page.goto('/login');
    await page.locator('#email').fill('shell@fibes.test');
    await page.locator('#password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/dashboard/);
    await page.getByTestId('locale-en').click();
}

const pages = ['/dashboard', '/customers', '/tickets', '/dispatch', '/settings'];

for (const locale of ['en', 'ar'] as const) {
    test(`core screens have no serious accessibility issues in ${locale}`, async ({ page }) => {
        await signIn(page);
        if (locale === 'ar') {
            await page.getByTestId('locale-ar').click();
            await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
        }

        for (const path of pages) {
            await page.goto(path);
            const results = await new AxeBuilder({ page }).analyze();
            const serious = results.violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
            expect(serious, `${path} ${locale} ${serious.map((item) => item.id).join(',')}`).toEqual([]);
        }

        await page.getByTestId('locale-en').click();
    });
}
