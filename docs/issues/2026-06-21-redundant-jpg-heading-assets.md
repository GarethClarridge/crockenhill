# 🪦 Mortician: Possibly dead — Redundant `.jpg` heading assets

## What
39 legacy `.jpg` image files located in the `public/images/headings/` directory tree (both `large/` and `small/` subdirectories). These are leftover artefacts from a previous iteration of the site's image handling before the migration to `.webp`.

**Paths:**
- `public/images/headings/links.jpg`
- `public/images/headings/large/*.jpg` (19 files)
- `public/images/headings/small/*.jpg` (19 files)

## Evidence
A comprehensive project-wide search using `ripgrep` (supporting correct glob expansion) for the literal filenames returns **zero** references to these specific assets in production code.

```bash
# Search for the literal filenames using ripgrep
# All 39 files were checked individually
rg -F "links.jpg" . -g '!{.git,node_modules,vendor}/*'
# Result: 0 matches in production code
```

`App\Services\Public\PageImageCacheService::resolveHeadingImageUrl` explicitly constructs fallback paths using the `.webp` extension:

```php
$storagePath = "pages/headings/{$size}/{$page->slug}.webp";
```

The corresponding `.webp` versions of these images exist in the same directories and are correctly served by the application. Previous concerns about `default.jpg` and `link.jpg` were investigated and found to be false positives (matching `maxresdefault.jpg` in YouTube URLs or other unrelated strings).

## Risk
**Low — pure removal.** These assets are strictly redundant as the application logic has transitioned to `.webp` and no views, stylesheets, or scripts reference the `.jpg` versions in the headings directory.

## Recommendation
Safe to remove. Deleting these 39 files will clean up unused binary data from the repository.
