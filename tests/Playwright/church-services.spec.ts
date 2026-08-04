import { test, expect } from './fixtures/visual';

test('church services archive', async ({ stablePage }) => {
  await stablePage.goto('/church/services');
  await expect(stablePage).toHaveScreenshot('church-services-index.png', {
    fullPage: true,
  });
});

test('church service detail', async ({ stablePage }) => {
  await stablePage.goto('/church/services');

  const firstServiceLink = stablePage
    .locator('a[href*="/church/services/"][href*="/20"]')
    .first();

  const href = await firstServiceLink.getAttribute('href');
  expect(href, 'expected at least one dated service link on the archive page').toMatch(
    /\/church\/services\/\d{4}-\d{2}-\d{2}\/(morning|evening|other)/
  );

  await stablePage.goto(href!);
  await expect(stablePage).toHaveScreenshot('church-service-detail.png', {
    fullPage: true,
  });
});
