# Open Issues

Consolidated tracker for audit findings (Mortician = dead code/assets, Pathfinder =
broken links/SEO). Last reconciled against the codebase **2026-06-18**.

Resolved items are listed at the bottom for provenance; the source per-issue reports
they came from have been removed now that the work is done.

---

## 🟡 Open

### O5 · Legacy meeting images in `public/images/meetings/`

**Artefacts:** `public/images/meetings/{1150,baby-talk,bible-study,buzz-club,coffee-cup,link,sunday-services}/`

Meeting photos are now managed via Spatie Media Library on `app/Models/Meeting.php`.
`app/Services/MeetingPhotoMigrationService.php` imports the legacy files and preserves
the originals during import. Grep for `images/meetings` in `resources/views` returns
zero matches — the frontend no longer reads these hardcoded paths.

**Risk:** Low, **gated.** Confirm the migration has completed in *production* before
deleting, since the import is what moves these into Media Library.

**Action:** Verify production migration, then delete the seven folders.

---

### O6 · Redundant `.jpg` heading assets in `public/images/headings/`

**Artefacts:** `public/images/headings/large/*.jpg`, `public/images/headings/small/*.jpg`
(as of 2026-06-18: 18 `.jpg` vs 20 `.webp` in `large/`).

`App\Services\Public\PageImageCacheService` resolves only `.webp`:

```php
$storagePath = "pages/headings/{$size}/{$page->slug}.webp";
```

No `.jpg` references were found in `resources/views`. The directory itself is still
actively used for its `.webp` assets.

**Risk:** Medium — a hardcoded CSS/JS `.jpg` reference that bypasses the cache service
would break silently. None found, but human review recommended before pruning.

**Action:** Human review, then prune the `.jpg` files if confirmed unreferenced.

---

### O7 · Dual-spelling pastor redirects (keep — not a bug)

`config/redirects.php` defines both `aboutus/pastor` and `about-us/pastor`, each →
`/church/pastor`. The original Mortician report flagged these as "redundant," but they
are two **distinct legacy inbound URLs** (with/without hyphen) intentionally pointing
at the same target — correct redirect behaviour.

**Action:** None. Documented so it isn't "cleaned up" by mistake.

---

### O8 · Legacy sermon update artefacts (`UpdateSermonRecord` & `UpdateSermonRequest`)

**Artefacts:** `app/Jobs/UpdateSermonRecord.php`, `app/Http/Requests/UpdateSermonRequest.php`

These artefacts have been superseded by the `UnifiedMediaProcessor` and Livewire-based
admin forms. Grep search returns zero production callers in `app/`, `resources/`,
`routes/`, or `config/`.

**Risk:** Low — isolated classes with no callers.

**Action:** Remove both classes and retire/migrate legacy tests that still exercise them.

---

### O9 · Dead media validation service (`SermonValidationService`)

**Artefact:** `app/Services/Processing/SermonValidationService.php`

Confirmed dead class with zero production callers. Responsibilities for file
validation and storage checks have been superseded by `MediaValidationService`
and `TempDiskSpace` respectively.

**Risk:** Low — isolated and unreferenced.

**Action:** Safe to remove, along with Unit and Integration tests for the service.

---

## ✅ Recently resolved (2026-06-18 unless noted)

- **O1 — Dead mailable `App\Mail\LivestreamProcessingCompleted`** — removed (class, view, test, `AGENTS.md` reference).
- **O2 — Dead mailable `App\Mail\PermissionError`** — removed (class, view, test, `AGENTS.md` reference).
- **O3 — Dead asset directory `public/images/photos/`** — deleted (38 unreferenced files).
- **O4 — Duplicate `/christ/sermons` sitemap entry** — `SitemapService::addPages()` now excludes the christ-area `sermons` page; covered by `SitemapTest::sitemap_does_not_duplicate_the_christ_sermons_index_page`.
- **R1 — Broken admin delete link** on the sermon detail page — fixed earlier (2026-06-14); `sermon.blade.php` now uses `route('sermons.destroy', …)`.
- **R2 — `contacttus` redirect typo** — already corrected to `contactus` in `config/redirects.php`.
