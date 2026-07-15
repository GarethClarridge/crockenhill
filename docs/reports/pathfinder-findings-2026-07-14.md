# 🔗 Pathfinder Report: Link & Asset Audit (2026-07-14)

## Summary
A diagnostic crawl of the Crockenhill Baptist Church site has confirmed systematic failures in heading image resolution and specific missing sermon assets. These findings corroborate and expand upon previous reports (O12, O13).

## 1. Systematic Heading Image Failure (Ref: O13)
**Status:** Reproducible Bug
**Impact:** 14+ public pages, `sitemap.xml`, and social share tags.

### Evidence
The `PageImageCacheService` is hardcoded to look for fallback images in `Storage::disk('public')` (mapping to `storage/app/public/pages/headings/`). However, the project's standard heading assets are committed to `public/images/headings/`.

**Affected Pages (returning `null` for heading images despite assets existing in `public/`):**
- `/church/links`
- `/christ/sermons`
- `/church/find-us`
- `/church/history`
- `/church/pastor`
- `/church/statement-of-faith`
- `/church/what-we-believe`
- `/community/baby-talk`
- `/community/bible-study`
- `/community/carols-in-the-chequers`
- `/community/coffee-cup`
- `/community/sunday-mornings`
- `/community/sunday-evenings`
- `/community/buzz-club`

**Technical Proof:**
```php
$page = Page::where('slug', 'history')->first();
$service = app(PageImageCacheService::class);
// Returns {"desktop":null,"mobile":null,"small":null,"tablet":null}
// Despite public/images/headings/large/history.webp existing.
```

## 2. Missing Seeded Sermon Audio (Ref: O12)
**Status:** Broken Asset (Seeded Data)
**Impact:** Broken audio player for flagship seeded sermon.

### Evidence
The sermon **"The Prodigal Son"** (`the-prodigal-son`) has a completed `MediaProcessingLog` entry but the file is missing from the physical disk.

- **Expected Path:** `storage/app/public/sermons/seed/2024-11-24.mp3`
- **Result:** File does not exist.
- **UI Impact:** The sermon page renders a player that fails to load.

## 3. Sitemap Omissions
**Status:** SEO Degradation
**Impact:** Missing `<image:image>` tags for Meeting pages in `sitemap.xml`.

### Evidence
`MeetingSitemapPresenter` only looks for Media Library 'photos' and does not attempt to resolve the hardcoded heading images that exist for many meetings (e.g., `1150`, `messy-church`, `buzz-club`) in `public/images/headings/large/`.

## Suggested Actions
1. **Fix `PageImageCacheService`**: Add a `public_path('images/headings/...')` fallback check to the resolution logic.
2. **Update `MeetingSitemapPresenter`**: Allow meetings to resolve their hardcoded heading images for sitemap inclusion.
3. **Repair Seeder**: Include a physical mock audio file in `storage/app/public/sermons/seed/` or update the seeder to use a valid placeholder.

---
*Reported by Pathfinder 🔗*
