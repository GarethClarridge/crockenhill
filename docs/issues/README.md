# Open Issues

Consolidated tracker for audit findings (Mortician = dead code/assets, Pathfinder =
broken links/SEO, public UX review = visitor journeys). Last reconciled against the codebase and
production **2026-07-12**.

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
would indicate a completion-transition bug worth checking while in there. **Pathfinder confirmed missing file 2026-07-14.**

**Action:** make the seeder set the sermon's `audio_file_path` and ship (or generate) a small
seed audio file; alternatively mark the seeded log `failed` so the UI states are honest.

### O13 · Heading-image resolution: committed assets invisible to `PageImageCacheService` (investigate before "fixing")

Two Pathfinder crawls (2026-07-05/06) and a follow-up audit (2026-07-14) report pages and `sitemap.xml` missing heading images.
Verified mechanism: `PageImageCacheService::resolveHeadingImageUrl()` resolves (1) Spatie Media
Library `headings` media, then (2) `Storage::disk('public')` at `pages/headings/{size}/{slug}.webp`
— it never reads the committed `public/images/headings/` directory, which is only referenced
*directly* via `asset()` (sitemap sermons image, sermon Blade share images, `page-card` default).
Confirmed 14+ affected pages in 2026-07-14 audit; see `docs/reports/pathfinder-findings-2026-07-14.md`.

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

### O16 · The production free-Bible request CTA ends at a 404

The original 2026-07-11 UX report diagnosed the `#get-a-free-bible` jump link as a dead CTA. That
was incorrect: it intentionally moves to the section containing the real **Request a free Bible**
button. The real production failure is its destination: `/christ/free-bible` returned the site's
404 page on 2026-07-12.

Current `master` already contains the complete feature: the bespoke
`resources/views/pages/christ/free-bible.blade.php` view, `BibleRequestForm` Livewire component,
mail flow, feature tests, and a `free-bible` row in `PageSeeder`. `PageController::show()` requires
the matching `pages` row before it resolves the bespoke view, so production most likely lacks that
row (or has drifted slug/area data). This is an inference from the code and production behaviour;
verify the production row before changing code.

**Action:** inspect the production `pages` row for `area = christ`, `slug = free-bible`; restore or
correct it through the normal deployment/content process, then verify the page renders and the
form can deliver to the configured public address. Do not build a second form.

### O17 · Find Us omits the address, postcode, and a usable map link from its content

Verified on production 2026-07-12: `/church/find-us` explains parking, buses, and lifts well, but
the body never states the full address or `BR8 8JS` and contains no directions/map link. The
postcode appears only in the global footer. The heading image is a non-interactive map, so it does
not complete the primary task of opening directions on a phone.

**Who benefits:** first-time visitors travelling to a service or event.
**What observably improves:** the address can be copied and directions opened without searching
the footer or manually entering the postcode.

**Action:** put the full address and a prominent external map link near the start of the page.
Adding an exterior building photo would further reduce arrival uncertainty but needs a suitable
maintainer-supplied asset and consent decision.

### O18 · Christianity Explored invites sign-up but provides no response path

Verified on production and current seeded content 2026-07-12: the page ends its invitation with
"Why not sign up" but has no date, contact link, form, or named next step. Its meeting data is
deliberately `Occasional` with no time or location, so the details card cannot compensate.

**Who benefits:** visitors who want to explore Christianity without first attending a service.
**What observably improves:** a visitor can register interest or contact a named person directly
from the invitation.

**Action:** maintainer to decide whether another course is expected. If yes, add a standing
"Register interest" route and copy promising an invitation when dates are known. If no, decide
whether to replace the sign-up promise with a standing one-to-one offer. Reuse an existing contact
mechanism; do not create a speculative course date.

### O20 · Sunday mornings opens with a garbled sentence

Verified on production 2026-07-12: the flagship visitor page begins, "Our Sunday meetings are the
centre of our church life are our Sunday services...". The duplicated clause is also production
content, so correcting a seeder alone may not update the live page.

**Action:** correct the production page content and its source-of-truth fixture/import if one is
maintained. Keep the strong existing reassurance about dress, language, YouTube, and children.

### O21 · Sunday-morning details omit the known time and actionable location

Verified on production and current `MeetingSeeder` 2026-07-12: the page body says the main service
starts at 10:30am, but the details card shows only `Sunday / Crockenhill Baptist Church / All
welcome`. The template already renders a time when `Meeting::start_time` is populated;
`sunday-mornings` has `start_time = null` in both production output and the seeder. The location is
also only a name, with no postcode or directions link.

**Action:** set the canonical Sunday-morning start time to 10:30 in production and maintained seed
data, then verify the card renders it. Coordinate any address/map treatment with O17. Avoid
building against recurrence fields scheduled for removal in backlog item 3.5.

## 🟡 Open — owned by the July 2026 backlog (do not fix separately)

| Issue | Where it lives now |
|---|---|
| O5 · Legacy meeting photo folders `public/images/meetings/*` (gated on prod photo-migration; the `link/` folder is already gone) | Backlog item **2.6** |
| O6 · Redundant `.jpg` heading assets (33 files; the `.webp` siblings are live — prune `.jpg` only) | Backlog item **2.1** (issue-tracker intake block) |
| O8 · Dead `UpdateSermonRecord` job + `UpdateSermonRequest` form request (+ their test files) | Backlog item **2.1** |
| O9 · Dead `SermonValidationService` (+ Unit/Integration tests, stale config comment) | Backlog item **2.1** |
| O14 · Dead `public/images/podcast/*.webp` artwork + unused `PageImagePresenter::headingImageSrcset()` | Backlog item **2.1** (issue-tracker intake block) |
| O19 · Related-page cards surface legal/policy noise, out-of-season events, robotic "Learn about..." labels, and repeated title/description copy | Reassess while implementing backlog item **3.1**, which deletes/folds the current presentation path including `RelatedPagePresenter`; do not patch that scheduled-to-change seam first |

## 🔵 Newcomer-UX opportunities — owned by the active plan

The 2026-07-11 review also produced several coherent opportunities rather than discrete defects.
They are the evidence and intake for
`docs/plans/NEWCOMER-UX-BACKLOG-2026-07-11.md`, which owns their sequencing, dependencies, and
acceptance criteria. Keep findings here and delivery decisions in the plan.

Priority audience: first, someone unfamiliar with Christianity and nervous about attending;
second, a committed Christian new to the area. The initiative should improve one of three journeys:
attend a Sunday service, attend another event, or start learning about Jesus.

### N1 · Create an explicit mobile-first newcomer path

At 390px, the homepage hero occupies most of the first viewport but contains mission-statement
jump links rather than a service time or visit CTA. The main navigation has only Christ / Church /
Community and no newcomer-labelled entry point. Consider a single **New here?** navigation item
and page that assembles existing material: service times, the excellent Sunday-morning
what-to-expect copy, children, parking, and who to look for on arrival. Pair it with a homepage
hero CTA and visible Sunday times; do not rename the three existing top-level areas.

**Who benefits:** nervous first-time visitors, especially on mobile.
**What observably improves:** service time and a what-to-expect route are reachable from the first
viewport and one plainly labelled navigation choice.

### N2 · Surface children's provision before families commit to visiting

The homepage contains no mention of children, while the Sunday-morning page already explains that
children stay for the first part and then join Outback. Reuse a short version of that factual copy
on the homepage and any New here page.

**Who benefits:** parents and carers considering a first visit.
**What observably improves:** they can confirm children are welcome without discovering a deep
subpage first.

### N3 · Add human and arrival trust assets

The Leadership page has warm named biographies but no leader photographs or welcome video. Find Us
has no exterior building photo. Several public cards fall back to the blue/teal generated gradient
(`default.webp`), including otherwise people-focused activities. These are not broken interfaces;
they are an asset-and-consent decision.

**Who benefits:** visitors who want to recognise people and the building before arriving.
**What observably improves:** approved, current photographs replace selected placeholders and make
the arrival and greeting recognisable.

### N4 · Decide whether the site can promise current weekly information

Coffee Cup and Baby Talk are approachable alternatives to Sunday, but the homepage has no dated
"This week" view. Do not build one until a maintainer owns freshness. If approved, start with a
small manually maintained block; only automate after the content proves useful.

**Who benefits:** visitors looking for a lower-pressure first event.
**What observably improves:** the homepage states which approachable activities are actually
happening this week, with dates or an explicit freshness timestamp.

### N5 · Test visitor-question headings on the homepage

The homepage is organised around the church's three mission statements. Keep that language, but
consider adding plain-English scanning labels that answer visitor questions. This is a content
experiment, not a verified defect, and should be evaluated with N1 rather than shipped as isolated
copy churn.

**Who benefits:** visitors unfamiliar with church vocabulary.
**What observably improves:** first-click testing or analytics shows more use of visit, event, and
learn-about-Jesus routes without reducing access to the existing vision language.

**Maintainer decisions before promotion:** consent/appetite for identifiable photographs or video;
an owner for weekly content; and whether Christianity Explored will run again or should become a
standing one-to-one offer.

## 🟢 Investigated — keep, no action

- **O7 · Dual-spelling pastor redirects** — `config/redirects.php` maps both `aboutus/pastor` and
  `about-us/pastor` to `/church/pastor`. Two distinct legacy inbound URLs, not a duplicate.
  Documented so it isn't "cleaned up" by mistake.
- **O15 · `MediaProcessingRequest` abstract form request** — flagged as possibly dead
  (2026-07-06 Mortician); verdict **alive**. It is the base class for the six active media API
  form requests and centralises authorization + processing-id shape validation. Leave alone.
- **2026-07-11 newcomer review corrections** — rechecked against current `master` and production
  on 2026-07-12. The desktop menu is now an opaque, non-overlapping three-column overlay; homepage
  and Find Us hero contrast is sufficient with the current scrims; sermon freeze-frame taste is
  not a defect and the project already has thumbnail selection; the cited 16–30px inline/tall
  links are not by themselves a WCAG target-size failure (inline-text and spacing exceptions
  apply); and adding hero images to every landing page is a subjective redesign, not an audit
  finding. These claims should not be revived without new evidence.

## ✅ Resolved

- **O10 — Unused `<x-icon-button>` component** — removed in commit `aa31358c4` (PR #1024).
- **O1 — Dead mailable `App\Mail\LivestreamProcessingCompleted`** — removed (class, view, test, `AGENTS.md` reference). *(2026-06-18)*
- **O2 — Dead mailable `App\Mail\PermissionError`** — removed (class, view, test, `AGENTS.md` reference). *(2026-06-18)*
- **O3 — Dead asset directory `public/images/photos/`** — deleted (38 unreferenced files). *(2026-06-18)*
- **O4 — Duplicate `/christ/sermons` sitemap entry** — `SitemapService::addPages()` now excludes the christ-area `sermons` page; covered by `SitemapTest`. *(2026-06-18)*
- **R1 — Broken admin delete link** on the sermon detail page — fixed 2026-06-14.
- **R2 — `contacttus` redirect typo** — corrected to `contactus` in `config/redirects.php`.
