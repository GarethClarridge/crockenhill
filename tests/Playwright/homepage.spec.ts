import { test, expect } from './fixtures/visual';

test.describe('homepage', () => {
  test('full page', async ({ stablePage }) => {
    await stablePage.goto('/');
    await expect(stablePage).toHaveScreenshot('homepage-full.png', {
      fullPage: true,
    });
  });

  test('header only', async ({ stablePage }) => {
    await stablePage.goto('/');
    const header = stablePage.locator('header.site-header');
    await expect(header).toHaveScreenshot('homepage-header.png');
  });
});
