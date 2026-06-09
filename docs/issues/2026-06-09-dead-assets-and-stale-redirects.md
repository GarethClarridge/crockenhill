# 🪦 Mortician: Possibly dead — Unused Assets & Redirect Cruft

## 1. Unused Large Image Assets in `public/images/photos/`

**Artefacts:**
- `public/images/photos/LauriesFlowers.jpg` (378.52 KB)
- `public/images/photos/LauriesFlowers.webp` (301.17 KB)
- `public/images/photos/LauriesTulips.jpg` (268.48 KB)
- `public/images/photos/LauriesTulips.webp` (137.93 KB)

**Evidence:**
Grep search for `images/photos` returns zero references in the codebase (excluding file lists and archived reports).
```bash
grep -rn "images/photos" . --exclude-dir=.git --exclude=all_images.txt --exclude=unused_images_report.txt
```

**Risk:**
Low — These appear to be legacy gallery photos that are no longer part of any page or automated layout.

**Recommendation:**
Safe to remove if these specific images are not intentionally kept for future use.

---

## 2. Legacy Meeting Images in `public/images/meetings/`

**Artefacts:**
- `public/images/meetings/1150/` (11 files)
- `public/images/meetings/baby-talk/` (6 files)
- `public/images/meetings/bible-study/` (2 files)
- `public/images/meetings/buzz-club/` (12 files)
- `public/images/meetings/coffee-cup/` (8 files)
- `public/images/meetings/link/` (10 files)
- `public/images/meetings/sunday-services/` (12 files)

**Evidence:**
Meeting photos are now managed via Spatie Media Library, as seen in `app/Models/Meeting.php`. A migration service exists to move these legacy files: `app/Services/MeetingPhotoMigrationService.php`.
Grep search for `images/meetings` in `resources/views` returns zero matches, confirming the frontend no longer relies on these hardcoded paths.

**Risk:**
Low — The migration service preserves originals during import to Media Library. Once migrated, these folders in `public/` are redundant.

**Recommendation:**
Safe to remove after verifying migration is complete in production.

---

## 3. Redirect Typo & Redundancy in `config/redirects.php`

**Finding:**
1. Typo in key: `'contacttus' => '/'` (line 27).
2. Redundant entries: Several redirects are defined twice with identical targets (e.g., `aboutus/pastor` and `about-us/pastor`).

**Evidence:**
```php
// config/redirects.php
'contacttus' => '/', // Line 27: likely intended to be 'contactus'
...
'aboutus/pastor' => '/church/pastor', // Line 33
...
'about-us/pastor' => '/church/pastor', // Line 48
```

**Risk:**
Low — Fixing typos improves SEO/UX. Removing redundancy simplifies the configuration.

**Recommendation:**
Correct the typo and consolidate redundant entries.
