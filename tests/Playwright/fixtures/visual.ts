import { test as base, expect, Page } from '@playwright/test';

const FREEZE_STYLES = `
  *, *::before, *::after {
    animation-duration: 0s !important;
    animation-delay: 0s !important;
    transition-duration: 0s !important;
    transition-delay: 0s !important;
    caret-color: transparent !important;
  }
  html { scroll-behavior: auto !important; }
  /* Hide dev-only overlays that would otherwise pollute snapshots. */
  div.phpdebugbar,
  [id^="phpdebugbar"],
  [class*="phpdebugbar"] {
    display: none !important;
  }
`;

async function stabilise(page: Page): Promise<void> {
  await page.waitForLoadState('domcontentloaded');
  await page.addStyleTag({ content: FREEZE_STYLES });
  await page.evaluate(() => document.fonts.ready);

  await page.evaluate(async () => {
    document.querySelectorAll('img[loading="lazy"]').forEach((img) => {
      img.setAttribute('loading', 'eager');
      (img as HTMLImageElement).fetchPriority = 'high';
    });

    const totalHeight = document.documentElement.scrollHeight;
    const step = window.innerHeight;
    for (let y = 0; y <= totalHeight; y += step) {
      window.scrollTo(0, y);
      await new Promise((r) => requestAnimationFrame(() => r(null)));
    }
    window.scrollTo(0, 0);

    const images = Array.from(document.images);
    await Promise.all(
      images.map((img) =>
        img.complete && img.naturalWidth > 0
          ? Promise.resolve()
          : new Promise<void>((resolve) => {
              img.addEventListener('load', () => resolve(), { once: true });
              img.addEventListener('error', () => resolve(), { once: true });
            })
      )
    );

    await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(() => r(null))));
  });
}

type VisualFixtures = {
  stablePage: Page;
};

export const test = base.extend<VisualFixtures>({
  stablePage: async ({ page }, use) => {
    const originalGoto = page.goto.bind(page);
    page.goto = async (url, options) => {
      await originalGoto(url, options);
      const response = await originalGoto(url, options);
      await stabilise(page);
      return response;
    };
    await use(page);
  },
});

export { expect };
