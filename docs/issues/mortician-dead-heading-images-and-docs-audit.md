# 🪦 Mortician: Dead Assets Audit — Heading Images

## 1. Unreferenced Heading Images in `public/images/headings/`

**Artefacts:**
Most files in the `public/images/headings/` directory and its `large/` and `small/` subdirectories appear to be dead.
Specifically:
- `public/images/headings/large/*.jpg` (18 files)
- `public/images/headings/small/*.jpg` (18 files)
- Most `.webp` files in these directories (e.g., `1150.webp`, `baby-talk.webp`, `links.webp`, `pastor.webp`)

**Exceptions (STILL USED):**
- `public/images/headings/large/sermons.webp` (referenced in `SitemapService.php` and sermon views)
- `public/images/headings/small/default.webp` (referenced as fallback in `PageCardPresenter.php` and `page-card.blade.php`)
- `public/images/headings/large/default.webp` (potential fallback)

**Evidence:**
Project-wide grep for filenames like `1150.webp`, `links.webp`, and `pastor.webp` returns zero matches in the application code.
`App\Services\Public\PageImageCacheService` (the service responsible for resolving page headings) explicitly looks for `.webp` files in the `public` storage disk at paths like `pages/headings/{$size}/{$page->slug}.webp`. This translates to `storage/app/public/pages/headings/`, not the `public/images/headings/` directory in the repository root.

```bash
# Example search for a specific heading image
grep -rn "1150.webp" . --exclude-dir=.git --exclude-dir=storage --exclude-dir=vendor
# Output: (only lists of files or archived reports)

# Check for .jpg usage in headings
grep -r "\.jpg" resources/views | grep "images/headings"
# Output: (empty)
```

**Risk:**
Low to Medium — Removing these assets is safe for the application's current logic, as it favors storage-managed assets or specific fallbacks. However, some external links (e.g., from old social media posts) might theoretically point to these paths directly if they were ever shared.

**Recommendation:**
Safe to remove most files. Retain `default.webp` and `sermons.webp` (large and small). A cleanup script could be used to remove all `.jpg` and unreferenced `.webp` files from these directories.
