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

  // Related pages on meeting routes come from RelatedPagePresenter::random(),
  // so the cards re-shuffle on every render — both the *content* and the
  // *height* of the section change. Hide it outright so the rest of the
  // page (header, info card, footer) is what the snapshot enforces.
  await stablePage.addStyleTag({
    content: '[data-testid="related-pages"] { display: none !important; }',
  });

  await expect(stablePage).toHaveScreenshot('meeting-detail.png', {
    fullPage: true,
  });
});
