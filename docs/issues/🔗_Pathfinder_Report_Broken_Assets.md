# 🔗 Pathfinder: 33 missing heading assets and 1 missing sermon audio

## Summary
Diagnostic crawl has identified a systemic issue with heading image resolution for pages, resulting in 33 missing assets in the sitemap and on public views. Additionally, a seeded sermon is missing its associated audio file.

## Findings

### 1. Systemic Heading Image Resolution Bug
**Surface:** All public `/{area}/{slug}` pages and `sitemap.xml`.
**Affected items:** 33 pages (all seeded pages including 'what-we-believe', 'history', 'statement-of-faith', etc.)
**Verification:**
- Tinker verification confirmed that `PageImageCacheService` looks for fallback images in `Storage::disk('public')->exists("pages/headings/{$size}/{$page->slug}.webp")`.
- This maps to `storage/app/public/pages/headings/`, which is empty.
- However, the actual fallback images are committed to the repository at `public/images/headings/{large|small}/`.
- Result: `PageImageCacheService::get()` returns `NULL` for all heading URLs, causing the sitemap to omit these images and pages to fall back to the default `Primary.png` or nothing.

### 2. Missing Sermon Audio
**Surface:** `/christ/sermons/2024/11/the-prodigal-son`
**Affected item:** Sermon "The Prodigal Son" (slug: `the-prodigal-son`)
**Verification:**
- Database entry has `livestream_processing_id` set to `seed-prodigal-son-processing`.
- Associated `MediaProcessingLog` record points to `sermons/seed/2024-11-24.mp3`.
- File check confirmed `storage/app/public/sermons/seed/2024-11-24.mp3` does not exist on disk.
- Result: The audio player on the sermon detail page is non-functional.

## Likely Cause
- **Heading Images:** The `PageImageCacheService` logic is misaligned with the project's asset organization. It expects images in the `public` storage disk (which is usually for user-uploaded/generated content) rather than the `public/images/` directory for committed assets.
- **Sermon Audio:** The `SermonSeeder` creates a reference to a seed audio file that is not included in the repository's sample data.

## Suggested Action
- **Heading Images:** Update `PageImageCacheService::resolveHeadingImageUrl` to also check `public_path("images/headings/{$size}/{$page->slug}.webp")` as a secondary fallback.
- **Sermon Audio:** Provide the missing mock audio file in `storage/app/public/sermons/seed/` or update the seeder to use an existing test asset.

## Risk Note
- Missing heading images in the sitemap reduce SEO visibility for these pages' primary visual content.
- Broken audio on a sermon page provides a poor user experience for site visitors.
