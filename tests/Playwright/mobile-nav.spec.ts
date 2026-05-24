import { test, expect } from './fixtures/visual';

test('mobile nav — open menu', async ({ stablePage }, testInfo) => {
  test.skip(
    testInfo.project.name !== 'mobile-chromium',
    'mobile-nav snapshots only run on the mobile-chromium project'
  );

  await stablePage.goto('/');

  const toggle = stablePage.getByRole('button', { name: 'Open navigation' });
  await toggle.click();

  await expect(
    stablePage.getByRole('button', { name: 'Close navigation' })
  ).toHaveAttribute('aria-expanded', 'true');

  await expect(stablePage).toHaveScreenshot('mobile-nav-open.png');
});
