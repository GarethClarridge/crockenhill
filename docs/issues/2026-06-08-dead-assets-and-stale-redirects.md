# 🪦 Mortician: Possibly dead — Large Assets & Stale Config

## 1. Unused Large Image Assets (> 100 KB)

**Artefacts:**
- `public/images/podcast/EveningArtwork.png` (474 KB)
- `public/images/headings/large/sermons.jpg` (744 KB)
- `public/images/headings/large/christmas.jpg` (177 KB)

**Evidence:**
- **EveningArtwork.png**: `config/podcast.php` explicitly references `/images/podcast/EveningArtwork.jpg`. Grep search for `EveningArtwork.png` returns zero references in the codebase.
- **sermons.jpg**: `resources/views/sermons/*.blade.php` files use `asset('/images/headings/large/sermons.webp')`. Grep search for `sermons.jpg` returns zero references in the codebase.
- **christmas.jpg**: `resources/views/full-width-pages/christmas.blade.php` uses `asset('/images/homepage/christmas2023.webp')`. Grep search for `headings/large/christmas.jpg` returns zero matches.

**Risk:**
Low — These are binary assets. Removing them is safe as they are not referenced by string.

**Recommendation:**
Safe to remove.

---

## 2. Stale Configuration: `config/redirects.php`

**Finding:**
Several redirect targets point to non-existent or incorrectly named slugs.

**Evidence:**
1.  **Missing Target**: `'reopening' => '/attending-in-person'`. The target slug `/attending-in-person` does not exist in the `pages` table or `PageSeeder.php`.
2.  **Incorrect Slug**: `'whats-on/carolsatthechequers' => '/community/carols-at-the-chequers'`. The actual page slug in the system is `carols-in-the-chequers`. The redirect is currently broken because it points to a non-existent URL.

**Risk:**
Medium — Broken redirects result in 404s for users coming from legacy URLs.

**Recommendation:**
Update `config/redirects.php` to point to valid slugs or remove if no longer relevant.

---

## 3. Potentially Unreferenced Route: `sermons.thumbnail.card`

**Finding:**
The route `sermons.thumbnail.card` (mapping to `SermonAssetController@serveCardThumbnail`) appears to have no callers.

**Evidence:**
- `grep -r "sermons.thumbnail.card" resources/views` returns zero matches.
- `grep -r "serveCardThumbnail" resources/views` returns zero matches.
- The standard thumbnail route `sermons.thumbnail` is heavily used.

**Risk:**
Low — Removing an unreferenced route and its controller method simplifies the codebase.

**Recommendation:**
Worth a human review to see if this variant was intended for a future feature or is truly dead.
