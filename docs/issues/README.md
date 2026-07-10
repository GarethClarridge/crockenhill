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

- **R3 — Systemic Heading Image Resolution Bug (O13)** — fixed by adding `public_path()` fallback to `PageImageCacheService` and versioning the cache key.
- **R4 — Broken Homepage Card Assertions (O16)** — fixed in `HomepageContentTest` by asserting on button text instead of removed `aria-label`.
- **R5 — Missing Seed Audio Asset (O12)** — hardened `SermonSeeder` to check for file existence and mark the log as `failed` if media is missing.
- **O10 — Unused `<x-icon-button>` component** — removed in commit `aa31358c4` (PR #1024).
- **O1 — Dead mailable `App\Mail\LivestreamProcessingCompleted`** — removed (class, view, test, `AGENTS.md` reference). *(2026-06-18)*
- **O2 — Dead mailable `App\Mail\PermissionError`** — removed (class, view, test, `AGENTS.md` reference). *(2026-06-18)*
- **O3 — Dead asset directory `public/images/photos/`** — deleted (38 unreferenced files). *(2026-06-18)*
- **O4 — Duplicate `/christ/sermons` sitemap entry** — `SitemapService::addPages()` now excludes the christ-area `sermons` page; covered by `SitemapTest`. *(2026-06-18)*
- **R1 — Broken admin delete link** on the sermon detail page — fixed 2026-06-14.
- **R2 — `contacttus` redirect typo** — corrected to `contactus` in `config/redirects.php`.
