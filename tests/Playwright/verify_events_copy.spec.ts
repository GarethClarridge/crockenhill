import { test, expect } from '@playwright/test';

test('verify calendar events empty state copy', async ({ page }) => {
  // Login first
  await page.goto('http://localhost:8000/login');
  await page.fill('input[name="email"]', 'admin@crockenhill.org');
  await page.fill('input[name="password"]', 'password');
  await page.click('button[type="submit"]');

  // Go to a meeting events page that is empty
  await page.goto('http://localhost:8000/meetings/1150/events');

  // Check for the new copy
  const emptyStateText = page.locator('p.text-gray-500', { hasText: 'No meetings have been scheduled for this group yet.' });
  await expect(emptyStateText).toBeVisible();

  await page.screenshot({ path: 'meeting_events_final.png' });
});
