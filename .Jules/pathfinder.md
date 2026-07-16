# Pathfinder Journal: Broken Link & Asset Reporter

## 2026-05-28 - Hardcoded meeting routes returning 404
**Pattern:** Hardcoded links to `/meetings/{slug}` in Blade templates.
**Cause:** The public route for meetings was moved to `/community/{slug}` in `routes/web.php`, but several templates still reference the old `/meetings/` prefix.
**Action:** Always verify meeting links against both `/meetings/` and `/community/` prefixes during crawls.

## 2026-05-28 - Stale redirect to missing privacy page
**Pattern:** `config/redirects.php` entry pointing to a non-existent slug.
**Cause:** `about-us/privacy-policy` redirects to `/church/privacy-policy`, but the page slug in the database is `privacy-notice`.
**Action:** Cross-reference redirect targets against the `pages` table and `route:list`.

## 2026-06-17 - Missing Seed Audio Asset
**Pattern:** Sermon records created by seeders point to audio files that do not exist in the environment's storage.
**Cause:** `SermonSeeder` assumes the presence of `sermons/seed/2024-11-24.mp3` in the public disk, but this file is not bundled with the repository or generated during setup.
**Action:** Cross-check `audio_file_path` against `Storage::disk('public')->exists()` specifically for seeded records.

## 2026-06-26 - Community route monopolization
**Pattern:** Pages in the 'community' area return 404 if no matching Meeting record exists.
**Cause:** The route `GET /community/{meeting:slug}` in `routes/web.php` uses implicit model binding for the `Meeting` model. Because it is defined before the catch-all `{area}/{slug}` route, any URL starting with `/community/` is intercepted. If the slug doesn't exist in the `meetings` table, Laravel returns a 404 before it can reach the general page controller, even if a `Page` with that slug exists.
**Action:** When checking community links, verify both `Page` and `Meeting` existence, and prefer using `Meeting` slugs for the `/community/` prefix.
## 2026-06-28 - Systemic Heading Image Resolution Bug
**Pattern:** Every public page missing its heading image in sitemap and views despite files existing in `public/images/headings/`.
**Cause:** `PageImageCacheService` only checks `Storage::disk('public')` (mapping to `storage/app/public/pages/headings/`) but committed assets are in `public/images/headings/`.
**Action:** Verify against both storage disk and public path in future diagnostics.

## 2026-07-14 - Heading Image Resolution Gap
**Pattern:** Committed heading images in `public/images/headings/` are ignored by `PageImageCacheService`.
**Cause:** Service is hardcoded to check `Storage::disk('public')` (`storage/app/public/pages/headings/`) and Media Library, but omits the committed assets directory.
**Action:** Verify if production pages use Media Library or rely on committed assets; update service with `public_path()` fallback if the latter.
