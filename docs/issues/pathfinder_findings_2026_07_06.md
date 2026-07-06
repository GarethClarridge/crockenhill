# 🔗 Pathfinder Findings: 2026-07-06

## 1. Systemic Heading Image Resolution Bug
**Summary:** `PageImageCacheService` fails to resolve heading images for dynamic pages because it searches the wrong directory.

**Evidence:**
- `PageImageCacheService` uses `Storage::disk('public')->exists("pages/headings/{$size}/{$page->slug}.webp")`.
- This resolves to `storage/app/public/pages/headings/`.
- Committed assets are actually located in `public/images/headings/`.
- **Verification:** `app(App\Services\Public\PageImageCacheService::class)->get($page)` returns `null` for the 'history' page, even though `public/images/headings/large/history.webp` exists on disk.

**Affected Surfaces:**
- All dynamic pages in the sitemap (missing `<image:loc>`).
- On-page renders of dynamic pages (falling back to default or missing images).

**Suggested Action:** Update `PageImageCacheService` to check both the `public` storage disk (for user-uploaded/generated content) and the `public/images/headings/` directory (for committed assets).

---

## 2. Missing Seed Audio Asset
**Summary:** The seeded "The Prodigal Son" sermon references a non-existent audio file in the local environment.

**Evidence:**
- `Sermon` slug: `the-prodigal-son`.
- `audio_file_path`: `sermons/seed/2024-11-24.mp3`.
- **Verification:** `Storage::disk('public')->exists('sermons/seed/2024-11-24.mp3')` returns `false`.
- The public serving route `/christ/sermons/the-prodigal-son/audio` returns a `404 Not Found`.

**Affected Surfaces:**
- Sermon detail page (audio player will fail to load media).
- Podcast feed (enclosure URL is generated but will return 404 to subscribers).

**Suggested Action:** Restore the missing audio file to `storage/app/public/sermons/seed/2024-11-24.mp3` or update `SermonSeeder` to handle missing assets more gracefully during environment setup.

---

## 3. Broken Test: Homepage Page Card Assertions
**Summary:** `Tests\Feature\HomepageContentTest` fails because it asserts on `aria-label` attributes that were removed or changed in card components to follow the "Stretched Link" a11y pattern.

**Evidence:**
- Test `homepage_renders_full_width_page_card_footer_ctas` expects `aria-label="Learn about "`.
- `resources/views/components/page-card.blade.php` uses an `x-button` with `aria-hidden="true"` for the footer CTA, and the primary stretched link contains the heading text, making a redundant `aria-label` unnecessary and absent.
- **Verification:** Running `php artisan test --compact tests/Feature/HomepageContentTest.php` fails with "Failed asserting that 0 is equal to 2 or is greater than 2."

**Suggested Action:** Update the test to assert on the presence of the "Learn about" text within the page content instead of the specific `aria-label` attribute, or update the component to include the attribute if deemed necessary for other reasons.
