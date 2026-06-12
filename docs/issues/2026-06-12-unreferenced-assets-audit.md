# 🪦 Mortician: Possibly dead — Unreferenced Assets

## 1. Unused Photo Assets in `public/images/photos/`

**Artefacts:**
- `public/images/photos/` (38 files, including `LauriesFlowers.jpg`, `1150.webp`, etc.)

**Evidence:**
Project-wide grep search for `images/photos` returns zero references in the application logic, Blade templates, or database seeders.

```bash
grep -rn "images/photos" . --exclude-dir=.git --exclude-dir=docs --exclude-dir=storage
```
Result: `0 matches`

Further checks on individual basenames (e.g., `LauriesFlowers`, `LauriesTulips`) also confirm zero usage in the frontend or configuration files.

**Risk:**
Low — These appear to be legacy assets from an older version of the site that are no longer integrated into the current design or data models.

**Recommendation:**
Safe to remove.

---

## 2. Redundant JPG Heading Images

**Artefacts:**
- `public/images/headings/large/*.jpg`
- `public/images/headings/small/*.jpg`

**Evidence:**
The application has transitioned to WebP for heading images. Grep searches for the `.jpg` versions of these assets within `resources/views` and `app/Services` return no results, whereas their `.webp` counterparts are actively referenced in `SitemapService.php` and various Blade components.

```bash
grep -rn "images/headings" . --exclude-dir=.git --exclude-dir=docs --exclude-dir=storage | grep ".jpg"
```
Result: `0 matches`

**Risk:**
Low — The application correctly falls back to `default.webp` or handles missing images via `onerror` attributes in components like `x-page-card`.

**Recommendation:**
Safe to remove if storage optimization is desired.

---

## 3. Unreferenced Podcast Artwork

**Artefact:**
- `public/images/podcast/EveningArtwork.png`

**Evidence:**
`config/podcast.php` and `PodcastFeedTest.php` both explicitly reference the `.jpg` version (`/images/podcast/EveningArtwork.jpg`). The `.png` version is unreferenced.

**Risk:**
Low — Pure removal of a redundant file format.

**Recommendation:**
Safe to remove.

---

## 4. Redirect Typo in `config/redirects.php`

**Artefact:**
- `'contacttus' => '/'` (Line 28 of `config/redirects.php`)

**Evidence:**
This is a clear typo of `'contactus'`. While it has a corresponding test in `tests/Feature/RedirectsTest.php`, it serves no functional purpose and clutters the configuration.

**Risk:**
Medium — Requires removing the associated test case to avoid build failures.

**Recommendation:**
Safe to remove alongside its test.
