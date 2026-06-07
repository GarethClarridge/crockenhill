# 🪦 Mortician Audit Report - 2026-06-06

## Possibly dead: `public/images/photos/Lauries*`

**Artefacts:**
- `public/images/photos/LauriesFlowers.jpg` (379 KB)
- `public/images/photos/LauriesFlowers.webp` (302 KB)
- `public/images/photos/LauriesTulips.jpg` (269 KB)
- `public/images/photos/LauriesTulips.webp` (138 KB)

**Description:**
High-resolution floral photography assets located in the public photos directory.

**Evidence of Disuse:**
1.  **Project-wide Grep**:
    - `grep -ri "Lauries" . --exclude-dir=.git` -> `0 matches`.
    - `grep -ri "Flowers" . --exclude-dir=.git` -> `0 matches`.
    - `grep -ri "Tulips" . --exclude-dir=.git` -> `0 matches`.
2.  **Database Audit**:
    - `pages` table: `SELECT count(*) FROM pages WHERE body LIKE '%Lauries%' OR description LIKE '%Lauries%'` -> `0`.
    - `meetings` table: `SELECT count(*) FROM meetings WHERE who LIKE '%Lauries%' OR location LIKE '%Lauries%'` -> `0`.
3.  **Media Library Check**:
    - `media` table: `SELECT count(*) FROM media WHERE file_name LIKE '%Lauries%'` -> `0`.
4.  **Seeder Audit**:
    - `PageSeeder.php` and `MeetingSeeder.php` do not contain references to these files or their names.
5.  **Recent History**:
    - These files were included in a centralizing commit (`df606f90`) but appear to be unreferenced artifacts.

**Risk Assessment:**
**Low** — These are binary assets. Since they are not referenced by string in the code, database, or CSS, removing them will not cause a functional regression. However, because they are >100KB, they are being reported as an issue rather than removed via PR to ensure they aren't served by an external system or hardcoded CDN path not visible in this repository.

**Recommendation:**
**Safe to remove.**

---

## Stale Configuration: `config/redirects.php`

**Finding:**
Several redirect targets point to non-existent or incorrectly named slugs.

**Evidence:**
1.  `'reopening' => '/attending-in-person'`: The target slug `/attending-in-person` does not exist in the `pages` table or `PageSeeder`.
2.  `'whats-on/carolsatthechequers' => '/community/carols-at-the-chequers'`: The actual page slug in the system is `carols-in-the-chequers`.

**Recommendation:**
Update `config/redirects.php` to point to valid slugs or remove if no longer relevant.
