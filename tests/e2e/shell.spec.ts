import { expect, test } from '@playwright/test';

async function signIn(page: import('@playwright/test').Page, email: string) {
    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/dashboard/);
}

test('sign-in uses the official logo and keeps it left-to-right in Arabic', async ({ page }) => {
    await page.goto('/login');
    const logo = page.getByTestId('brand-logo').first();
    await expect(logo).toHaveAttribute('dir', 'ltr');
    await expect(logo.locator('img')).toHaveAttribute('src', /\/brand\/logo-full-on-/);
    await page.getByTestId('locale-ar').click();
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.getByTestId('brand-logo').first()).toHaveAttribute('dir', 'ltr');
    await page.getByTestId('theme-toggle').click();
    await expect(page.locator('html')).toHaveClass(/dark/);
});

test('super admin can open the menu, the command palette, and the collapsed sidebar', async ({ page }) => {
    await signIn(page, 'shell@fibes.test');
    await page.getByTestId('locale-en').click();
    await expect(page.locator('aside [data-nav="users"]')).toBeVisible();
    await expect(page.locator('aside [data-nav="customers"]')).toBeVisible();
    await expect(page.locator('aside [data-nav="billing"]')).toBeVisible();

    await page.keyboard.press('Control+K');
    await expect(page.getByRole('dialog')).toBeVisible();
    await expect(page.getByRole('option', { name: 'Users' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);

    await page.getByTestId('theme-toggle').click();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.getByTestId('locale-ar').click();
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.getByTestId('brand-logo')).toHaveAttribute('dir', 'ltr');

    await page.getByTestId('sidebar-collapse').click();
    await expect(page.getByTestId('brand-logo')).toHaveCount(0);
    await expect(page.locator('[data-brand="icon"]')).toHaveAttribute('dir', 'ltr');
});

test('settings, areas, and rates open for a super admin', async ({ page }) => {
    await signIn(page, 'shell@fibes.test');
    await page.goto('/settings');
    await expect(page.getByTestId('settings-page')).toBeVisible();
    await page.goto('/geography');
    await expect(page.getByTestId('geography-page')).toBeVisible();
    await page.goto('/rates');
    await expect(page.getByTestId('rates-page')).toBeVisible();
    await page.goto('/customers');
    await expect(page.getByTestId('customers-page')).toBeVisible();
    await page.keyboard.press('c');
    await expect(page.getByTestId('customer-drawer')).toBeVisible();
    await page.goto('/tickets?new=1');
    await expect(page.getByTestId('ticket-drawer')).toBeVisible();
    await page.goto('/calls');
    await expect(page.getByTestId('calls-page')).toBeVisible();
    await page.goto('/performance');
    await expect(page.getByTestId('performance-page')).toBeVisible();
    await page.goto('/automations');
    await expect(page.getByTestId('automations-page')).toBeVisible();
    await expect(page.getByTestId('automation-rule').first()).toBeVisible();
    await page.goto('/imports');
    await expect(page.getByTestId('imports-page')).toBeVisible();
    await page.goto('/qa');
    await expect(page.getByTestId('qa-page')).toBeVisible();
    await expect(page.getByTestId('qa-kpis')).toBeVisible();
    await page.goto('/incidents');
    await expect(page.getByTestId('incidents-page')).toBeVisible();
    await page.goto('/calls/board');
    await expect(page.getByTestId('calls-board')).toBeVisible();
    await page.goto('/inventory');
    await expect(page.getByTestId('inventory-page')).toBeVisible();
    await page.goto('/tasks');
    await expect(page.getByTestId('tasks-page')).toBeVisible();
    await page.keyboard.press('n');
    await expect(page.getByTestId('task-drawer')).toBeVisible();
    await page.goto('/tasks?view=week');
    await expect(page.getByTestId('calendar')).toBeVisible();
    await page.goto('/tasks?view=month');
    await expect(page.getByTestId('month-board')).toBeVisible();
    await page.goto('/leads');
    await expect(page.getByTestId('leads-page')).toBeVisible();
    await page.keyboard.press('n');
    await expect(page.getByTestId('lead-drawer')).toBeVisible();
    await page.goto('/opportunities');
    await expect(page.getByTestId('opportunities-page')).toBeVisible();
    await page.goto('/approvals');
    await expect(page.getByTestId('approvals-page')).toBeVisible();
    await page.goto('/quotes');
    await expect(page.getByTestId('quotes-page')).toBeVisible();
    await page.goto('/orders');
    await expect(page.getByTestId('orders-page')).toBeVisible();
    await page.goto('/billing');
    await expect(page.getByTestId('billing-page')).toBeVisible();
    await page.goto('/catalog');
    await expect(page.getByTestId('catalog-page')).toBeVisible();
    await page.goto('/coverage');
    await expect(page.getByTestId('coverage-page')).toBeVisible();
    await expect(page.getByTestId('coverage-map')).toBeVisible();
    await page.goto('/dispatch');
    await expect(page.getByTestId('dispatch-map')).toBeVisible();
    await page.setViewportSize({ width: 360, height: 740 });
    await page.goto('/coverage');
    await expect(page.getByTestId('coverage-page')).toBeVisible();
});

test('a technician can open today on a phone and queue a start while offline', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(page, 'tech@fibes.test');
    await page.goto('/tech');
    await expect(page.getByTestId('tech-app')).toBeVisible();
    await expect(page.locator('link[rel="manifest"]')).toHaveAttribute('href', '/manifest.webmanifest');
    await expect(page.getByTestId('tech-job')).toBeVisible();
    await page.context().setOffline(true);
    await page.getByRole('button', { name: 'Start' }).first().click();
    await expect(page.getByTestId('sync-queue')).toBeVisible();
    await page.context().setOffline(false);
    await page.getByTestId('sync-queue').click();
    await expect(page.getByText('En route').first()).toBeVisible();
});

test('a deal can be dragged to the next stage in English and Arabic', async ({ page }) => {
    test.setTimeout(90_000);
    await page.setViewportSize({ width: 1280, height: 800 });
    await signIn(page, 'shell@fibes.test');
    await page.getByTestId('locale-en').click();

    const openDeal = async (name: string, phone: string) => {
        await page.goto('/leads');
        await page.getByRole('button', { name: 'Add a lead' }).click();
        await page.locator('#lead-name').fill(name);
        await page.locator('#lead-phone').fill(phone);
        await page.locator('#lead-location').fill('https://maps.google.com/?q=33.89,35.50');
        await page.locator('#lead-service').selectOption('ftth');
        await page.getByTestId('lead-drawer').getByRole('button', { name: 'Save lead' }).click();
        await page.getByRole('button', { name }).click();
        await page.locator('#who').selectOption({ label: 'Sam Al-Nakhel' });
        await page.getByRole('button', { name: 'Assign', exact: true }).click();
        await page.getByRole('button', { name: 'Log first contact', exact: true }).click();
        const checked = page.waitForResponse((response) => response.url().includes('/coverage/checks') && response.request().method() === 'POST');
        await page.getByRole('button', { name: 'Check coverage', exact: true }).click();
        await checked;
        await page.getByRole('button', { name: 'Qualify', exact: true }).click();
        await page.getByRole('button', { name: 'Open in the pipeline', exact: true }).click();
    };

    await openDeal('Pipeline Nour', '71 222 333');
    await openDeal('Pipeline Hadi', '71 222 444');
    await page.goto('/opportunities');
    await expect(page.getByTestId('opportunity-card').first()).toBeVisible();

    const drag = async (name: string) => {
        const card = page.getByTestId('opportunity-card').filter({ hasText: name });
        const target = page.locator('[data-column="site_survey"]');
        await card.scrollIntoViewIfNeeded();
        await target.scrollIntoViewIfNeeded();
        const from = await card.boundingBox();
        const to = await target.boundingBox();
        if (!from || !to) {
            throw new Error('missing drag boxes');
        }
        await page.mouse.move(from.x + 24, from.y + 24);
        await page.mouse.down();
        await page.mouse.move(to.x + 48, to.y + 90, { steps: 20 });
        const moved = page.waitForResponse((response) => response.url().includes('/stage') && response.request().method() === 'POST');
        await page.mouse.up();
        await moved;
        await expect(target.getByText(name)).toBeVisible();
    };

    await drag('Pipeline Nour');
    await page.getByTestId('locale-ar').click();
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await page.waitForTimeout(400);
    await drag('Pipeline Hadi');
    await page.setViewportSize({ width: 360, height: 740 });
    await expect(page.getByTestId('opportunities-page')).toBeVisible();
});

test('a won deal shows on the floor screen within two seconds', async ({ browser }) => {
    test.setTimeout(60_000);
    const sales = await browser.newContext();
    const floor = await browser.newContext();
    const page = await sales.newPage({ viewport: { width: 1280, height: 900 } });
    await signIn(page, 'shell@fibes.test');
    await page.getByTestId('locale-en').click();
    await page.goto('/performance');
    await page.getByRole('button', { name: 'New TV link' }).click();
    const tokenText = await page.getByTestId('tv-token').innerText();
    const token = new URL(`http://floor.local${tokenText}`).searchParams.get('token');
    const board = await floor.newPage({ viewport: { width: 1280, height: 900 } });
    await board.goto(`/tv/sales?token=${token}`);
    await expect(board.getByTestId('tv-board')).toBeVisible();

    await page.goto('/opportunities');
    await page.getByTestId('opportunity-card').filter({ hasText: 'Floor Nour' }).click();
    await page.locator('#stage').selectOption('won');
    await page.locator('#override').fill('Sign at the install.');
    await page.getByRole('button', { name: 'Move' }).click();
    await expect(board.getByText('Floor Nour')).toBeVisible({ timeout: 4000 });
    await sales.close();
    await floor.close();
});

test('each role opens on its own home', async ({ page }) => {
    test.setTimeout(90_000);
    const homes: [string, string][] = [
        ['shell@fibes.test', 'management'],
        ['sales@fibes.test', 'sales'],
        ['sup@fibes.test', 'supervisor'],
        ['agent@fibes.test', 'agent'],
        ['ops@fibes.test', 'operations'],
        ['noc@fibes.test', 'noc'],
        ['finance@fibes.test', 'finance'],
        ['boss@fibes.test', 'management'],
        ['wh@fibes.test', 'warehouse'],
    ];

    for (const [email, home] of homes) {
        await page.context().clearCookies();
        await signIn(page, email);
        await expect(page.getByTestId('dashboard-page')).toHaveAttribute('data-role', home);
        await page.getByTestId('dashboard-tiles').locator('a').first().click();
        await expect(page.getByTestId('report-page')).toBeVisible();
    }
});

test('a field technician does not see users or billing', async ({ page }) => {
    await signIn(page, 'tech@fibes.test');
    await expect(page.locator('aside [data-nav="tech"]')).toBeVisible();
    await expect(page.locator('aside [data-nav="customers"]')).toBeVisible();
    await expect(page.locator('aside [data-nav="users"]')).toHaveCount(0);
    await expect(page.locator('aside [data-nav="billing"]')).toHaveCount(0);
});
