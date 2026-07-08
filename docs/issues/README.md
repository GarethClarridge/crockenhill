# Open Issues

Consolidated tracker for audit findings (Mortician = dead code/assets, Pathfinder =
broken links/SEO). Last reconciled against the codebase **2026-07-05**.

Convention: agent-generated per-issue reports get folded into this file (and, where the work is
plan-shaped, into `docs/plans/JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md`) and the source
report files are then deleted — they live in git history. Resolved items are listed at the bottom
for provenance.

---

## 🟠 Open — needs a fix, not yet owned by a plan

### O11 · Footer "Listen to evening sermons" links to the unfiltered archive

`resources/views/components/layout/footer.blade.php` (~line 15): the link labelled "Listen to
evening sermons" points at `/christ/sermons` instead of `/christ/sermons/evening`. One-line
`href` fix; both routes exist and work. Verified still present 2026-07-05.

**Action:** change the `href` to `/christ/sermons/evening`; keep `wire:navigate`.

### O12 · Seeder inconsistency: "The Prodigal Son" sermon has a completed log but no audio

`SermonSeeder` creates a `MediaProcessingLog` (processing_id `seed-prodigal-son-processing`,
status `completed`, `audio_file_path = sermons/seed/2024-11-24.mp3`) but leaves the `Sermon`
row's `audio_file_path` null, and the referenced file does not exist on the `public` disk. Local
dev/seeded environments render a sermon page with a dead audio player. **Dev-only** as far as
verified — but if the same pattern (completed log, null sermon path) exists in production it
would indicate a completion-transition bug worth checking while in there.

**Action:** make the seeder set the sermon's `audio_file_path` and ship (or generate) a small
seed audio file; alternatively mark the seeded log `failed` so the UI states are honest.

### O16 · Dead Meeting Photo Migration code

The `MeetingPhotoMigrationService` and its companion Artisan command `meetings:migrate-photos` are
spent one-shot migration tools from early 2026 (Feb-Mar) that are no longer utilized or referenced
in the application logic, scheduler, or deploy scripts.

- **Command:** `app/Console/Commands/MeetingMigratePhotosCommand.php`
- **Service:** `app/Services/MeetingPhotoMigrationService.php`
- **Tests:** `tests/Integration/Services/MeetingPhotoMigrationServiceTest.php`, `tests/Feature/Console/MeetingMigratePhotosCommandTest.php`

**Evidence:** Grep search returns zero matches for `MeetingPhotoMigrationService` in `app/`
outside its own file and the command. The command is not present in `bootstrap/app.php` or any
CI/deploy scripts. Formally flagged for retirement in `platform-operations-review-2026-07-05.md`.

**Action:** safe to remove; git history provides a recovery path if a re-run is ever required.

### O13 · Heading-image resolution: committed assets invisible to `PageImageCacheService` (investigate before "fixing")

Two Pathfinder crawls (2026-07-05/06) report pages and `sitemap.xml` missing heading images.
Verified mechanism: `PageImageCacheService::resolveHeadingImageUrl()` resolves (1) Spatie Media
Library `headings` media, then (2) `Storage::disk('public')` at `pages/headings/{size}/{slug}.webp`
— it never reads the committed `public/images/headings/` directory, which is only referenced
*directly* via `asset()` (sitemap sermons image, sermon Blade share images, `page-card` default).

**Do not blindly patch the service to read `public_path()`** — the intended primary source is
Media Library, and production pages may well have `headings` media attached (in which case this
is a local/seed-data gap, not a production bug). Investigate first:

1. In production: do `Page` rows have media in the `headings` collection (`media` table,
   `collection_name = 'headings'`)? If yes → the fix is local seeding, not the service.
2. If production pages genuinely resolve to `null` → decide between attaching the committed
   images as Media Library media (one-off import, matching the meetings pattern) or adding a
   `public_path()` fallback to the service.
3. Sitemap half: backlog item 3.4 removes per-page sitemap images entirely — if 3.4 lands first,
   only the on-page rendering half of this issue remains.

## 🟡 Open — owned by the July 2026 backlog (do not fix separately)

| Issue | Where it lives now |
|---|---|
| O5 · Legacy meeting photo folders `public/images/meetings/*` (gated on prod photo-migration; the `link/` folder is already gone) | Backlog item **2.6** |
| O6 · Redundant `.jpg` heading assets (33 files; the `.webp` siblings are live — prune `.jpg` only) | Backlog item **2.1** (issue-tracker intake block) |
| O8 · Dead `UpdateSermonRecord` job + `UpdateSermonRequest` form request (+ their test files) | Backlog item **2.1** |
| O9 · Dead `SermonValidationService` (+ Unit/Integration tests, stale config comment) | Backlog item **2.1** |
| O14 · Dead `public/images/podcast/*.webp` artwork + unused `PageImagePresenter::headingImageSrcset()` | Backlog item **2.1** (issue-tracker intake block) |

## 🟢 Investigated — keep, no action

- **O7 · Dual-spelling pastor redirects** — `config/redirects.php` maps both `aboutus/pastor` and
  `about-us/pastor` to `/church/pastor`. Two distinct legacy inbound URLs, not a duplicate.
  Documented so it isn't "cleaned up" by mistake.
- **O15 · `MediaProcessingRequest` abstract form request** — flagged as possibly dead
  (2026-07-06 Mortician); verdict **alive**. It is the base class for the six active media API
  form requests and centralises authorization + processing-id shape validation. Leave alone.

## ✅ Resolved

- **O10 — Unused `<x-icon-button>` component** — removed in commit `aa31358c4` (PR #1024).
- **O1 — Dead mailable `App\Mail\LivestreamProcessingCompleted`** — removed (class, view, test, `AGENTS.md` reference). *(2026-06-18)*
- **O2 — Dead mailable `App\Mail\PermissionError`** — removed (class, view, test, `AGENTS.md` reference). *(2026-06-18)*
- **O3 — Dead asset directory `public/images/photos/`** — deleted (38 unreferenced files). *(2026-06-18)*
- **O4 — Duplicate `/christ/sermons` sitemap entry** — `SitemapService::addPages()` now excludes the christ-area `sermons` page; covered by `SitemapTest`. *(2026-06-18)*
- **R1 — Broken admin delete link** on the sermon detail page — fixed 2026-06-14.
- **R2 — `contacttus` redirect typo** — corrected to `contactus` in `config/redirects.php`.
