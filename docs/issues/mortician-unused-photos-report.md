# 🪦 Mortician: Possibly dead — Unused Assets in `public/images/photos/`

## 1. Unused Large Image Assets in `public/images/photos/`

**Artefacts:**
The entire directory `public/images/photos/` appears to be dead. It contains 38 files:
- `1150.jpg`
- `1150.webp`
- `LauriesFlowers.jpg`
- `LauriesFlowers.webp`
- `LauriesTulips.jpg`
- `LauriesTulips.webp`
- `baby-talk.jpg`
- `baby-talk.webp`
- `bible-study.jpg`
- `bible-study.webp`
- `buzz-club.jpg`
- `buzz-club.webp`
- `carols-in-the-chequers.jpg`
- `carols-in-the-chequers.webp`
- `christianity-explored.jpg`
- `christianity-explored.webp`
- `coffee-cup.jpg`
- `coffee-cup.webp`
- `documents.jpg`
- `documents.webp`
- `find-us.jpg`
- `find-us.webp`
- `history.jpg`
- `history.webp`
- `link.jpg`
- `link.webp`
- `links.jpg`
- `links.webp`
- `pastor.jpg`
- `pastor.webp`
- `sermons.jpg`
- `sermons.webp`
- `statement-of-faith.jpg`
- `statement-of-faith.webp`
- `sunday-services.jpg`
- `sunday-services.webp`
- `what-we-believe.jpg`
- `what-we-believe.webp`

**Evidence:**
Project-wide grep for `images/photos` returns zero references in the codebase (excluding file lists, archived reports, and this report).
Precise greps for representative filenames also return zero matches for these specific paths. Filenames that exist in both `images/photos/` and `images/headings/` (e.g., `sermons.webp`, `links.webp`) are only referenced via their `images/headings/` paths.

```bash
# General search
grep -rn "images/photos" . --exclude-dir=.git --exclude-dir=storage --exclude-dir=vendor

# Specific filename search (example)
grep -rn "LauriesFlowers" . --exclude-dir=.git --exclude-dir=storage --exclude-dir=vendor
```

**Risk:**
Low — These appear to be legacy photos that have been superseded by the `images/headings/` directory or by images managed via Spatie Media Library.

**Recommendation:**
Safe to remove. The directory `public/images/photos/` can be deleted in its entirety.

---

## 2. Redundant `.jpg` Assets in `public/images/headings/`

**Artefacts:**
Many `.jpg` files in `public/images/headings/large/` and `public/images/headings/small/` appear to be redundant because the system exclusively resolves their `.webp` counterparts.
- `public/images/headings/large/*.jpg`
- `public/images/headings/small/*.jpg`

**Evidence:**
`App\Services\Public\PageImageCacheService` explicitly looks for `.webp` files:
```php
$storagePath = "pages/headings/{$size}/{$page->slug}.webp";
```
Grep search for `.jpg` references in `resources/views` associated with headings returns zero results.

**Risk:**
Medium — While `PageImageCacheService` uses `.webp`, some hardcoded CSS or JS might still reference `.jpg` if they bypass the cache service. No such references were found during the audit, but the directory structure is still actively used for `.webp` assets.

**Recommendation:**
Human review recommended before removing these `.jpg` files to ensure no legacy hardcoded CSS/JS references them.
