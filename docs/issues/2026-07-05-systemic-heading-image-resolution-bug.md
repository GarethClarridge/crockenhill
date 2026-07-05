# 🔗 Pathfinder: Systemic Heading Image Resolution Bug

## Summary
Diagnostic crawl and sitemap inspection have identified a systemic bug where page heading images are not being resolved, resulting in missing images in the `sitemap.xml` and potentially in public page headers.

## Findings

### 1. Sitemap Missing Page Images
**Surface:** `/sitemap.xml`
**Affected items:** Almost all public pages (e.g., `/church/history`, `/church/pastor`, `/church/statement-of-faith`)
**Verification:**
- **Sitemap Content:** Inspection of `public/sitemap.xml` shows `<url>` entries for pages lack `<image:image>` tags.
- **Tinker Verification:**
  ```php
  $page = App\Models\Page::where("slug", "history")->first();
  $tag = app(App\Sitemap\PageSitemapPresenter::class)->toSitemapTag($page);
  // Result: $tag->images is empty.
  ```

### 2. Resolution Path Mismatch
**Service:** `App\Services\Public\PageImageCacheService`
**Verification:**
- The service attempts to resolve images using:
  ```php
  $storagePath = "pages/headings/{$size}/{$page->slug}.webp";
  if (Storage::disk('public')->exists($storagePath)) {
      return Storage::disk('public')->url($storagePath);
  }
  ```
- `Storage::disk('public')` maps to `storage/app/public/`, so it looks for `storage/app/public/pages/headings/{size}/{slug}.webp`.
- **Actual Location:** Committed assets are located in `public/images/headings/{size}/{slug}.webp`.
- **Result:** The service fails to find the files, returning `null`.

## Likely Cause
The codebase was migrated to use `Storage::disk('public')` for dynamic assets, but the static heading images were committed directly to the `public/` directory and the cache service was not updated to check both locations or the correct location.

## Suggested Action
Update `App\Services\Public\PageImageCacheService::resolveHeadingImageUrl` to also check the `public/images/headings/` directory (potentially via a new `public_images` disk or direct `public_path()` check) when a file is missing from the `public` storage disk.

## Risk Note
Missing images in the sitemap negatively impact SEO and "rich" search results. If these images are also missing from the frontend (whenever `PageImagePresenter` is used), it significantly degrades the visual experience of the site.
