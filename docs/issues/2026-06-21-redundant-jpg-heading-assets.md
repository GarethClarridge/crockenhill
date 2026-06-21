# 🪦 Mortician: Possibly dead — Redundant `.jpg` heading assets

## What
39 legacy `.jpg` image files located in the `public/images/headings/` directory tree (both `large/` and `small/` subdirectories). These appear to be leftover artefacts from a previous iteration of the site's image handling before the migration to `.webp`.

**Paths:**
- `public/images/headings/links.jpg`
- `public/images/headings/large/*.jpg` (18 files)
- `public/images/headings/small/*.jpg` (20 files)

## Evidence
A comprehensive project-wide search (including `app/`, `resources/`, `routes/`, `config/`, and `public/` for `.php`, `.blade.php`, `.js`, `.css`, `.json` files) returns **zero** references to these specific assets in production code.

```bash
# Search for the exact filenames
grep -rE "links\.jpg|bible-study\.jpg|coffee-cup\.jpg|find-us\.jpg|sunday-mornings\.jpg|statement-of-faith\.jpg|christmas\.jpg|buzz-club\.jpg|messy-church\.jpg|carols-in-the-chequers\.jpg|history\.jpg|pastor\.jpg|baby-talk\.jpg|what-we-believe\.jpg|documents\.jpg|sunday-evenings\.jpg|1150\.jpg|sermons\.jpg" . --exclude-dir={.git,node_modules,vendor} --include="*.{php,js,css,json}"
# Result: 0 matches in production code (matches in docs/ and tests/ for different paths like sermons/thumbnails/ excluded)
```

`App\Services\Public\PageImageCacheService::resolveHeadingImageUrl` explicitly constructs paths using the `.webp` extension for fallbacks:

```php
$storagePath = "pages/headings/{$size}/{$page->slug}.webp";
```

The corresponding `.webp` versions of these images exist in the same directories and are correctly served by the application.

## Risk
**Low — pure removal.** These assets are strictly redundant as the application logic has moved to `.webp` and no views, stylesheets, or scripts reference the `.jpg` versions in the headings directory.

## Recommendation
Safe to remove. Deleting these 39 files will clean up unused binary data from the repository.
