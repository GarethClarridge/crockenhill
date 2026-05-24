import { test, expect } from './fixtures/visual';

test('meeting detail', async ({ stablePage }) => {
  await stablePage.goto('/community');

  const firstMeetingLink = stablePage
    .locator('a[href*="/community/"]')
    .filter({ hasNot: stablePage.locator('[href$="/community"]') })
    .first();

  const href = await firstMeetingLink.getAttribute('href');
  expect(href, 'expected at least one meeting link on /community').toBeTruthy();

  await stablePage.goto(href!);
  await expect(stablePage).toHaveScreenshot('meeting-detail.png', {
    fullPage: true,
  });
});
