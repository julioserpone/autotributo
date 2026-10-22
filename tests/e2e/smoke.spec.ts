import { expect, test } from '@playwright/test';

test('la SPA carga y la API responde', async ({ page, request }) => {
    await page.goto('/');

    await expect(page.getByRole('heading', { name: 'Panel' })).toBeVisible();
    await expect(page.getByText('API conectada')).toBeVisible();

    const response = await request.get('/api/v1/health');
    expect(response.ok()).toBeTruthy();

    const body = (await response.json()) as { status: string };
    expect(body.status).toBe('ok');
});
