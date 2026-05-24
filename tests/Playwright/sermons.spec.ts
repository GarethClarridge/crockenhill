import { test, expect } from './fixtures/visual';

test('sermons index', async ({ stablePage }) => {
  await stablePage.goto('/christ/sermons');
  await expect(stablePage).toHaveScreenshot('sermons-index.png', {
    fullPage: true,
  });
});

test('sermon detail', async ({ stablePage }) => {
  await stablePage.goto('/christ/sermons');

  const firstSermonLink = stablePage
    .locator('a[href*="/christ/sermons/"][href*="/20"]')
    .first();

  const href = await firstSermonLink.getAttribute('href');
  expect(href, 'expected at least one dated sermon link on the index page').toMatch(
    /\/christ\/sermons\/\d{4}\/\d{1,2}\/[a-z0-9-]+/
  );

  await stablePage.goto(href!);
  await expect(stablePage).toHaveScreenshot('sermon-detail.png', {
    fullPage: true,
  });
});
