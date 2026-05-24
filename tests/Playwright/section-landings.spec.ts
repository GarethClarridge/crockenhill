import { test, expect } from './fixtures/visual';

const sections = [
  { path: '/christ', name: 'christ' },
  { path: '/church', name: 'church' },
  { path: '/community', name: 'community' },
  { path: '/calendar', name: 'calendar' },
];

for (const { path, name } of sections) {
  test(`section landing — ${name}`, async ({ stablePage }) => {
    await stablePage.goto(path);
    await expect(stablePage).toHaveScreenshot(`section-${name}.png`, {
      fullPage: true,
    });
  });
}
