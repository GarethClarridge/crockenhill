# Talks: one concept, typed, detected as `short_talk`

**Date:** 2026-09-18
**Status:** Planned, not started. Split out of the historic video defect plan's BC-07 ruling
(`HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md` §4.1c), which now points here.
Verified against code at `b6cbd9acf`.
**Scope:** Replace the sermon-or-children's-talk split with a single *talk* concept carrying a
type, make the detector find *short talks* structurally instead of judging audience, move the
audience/type call to approval, and present non-sermon talks through one type-parameterised
public surface. Nothing in the historic lane blocks on this: the 191 long `other` sections stay
unpublished until PR5 re-detects them.
**Cannot proceed without the operator:** the four decisions in §2 (taken 2026-09-18); the
per-type confirmation of every short talk at approval (§6); the production env rename (PR1);
dispatching the re-detection pass (PR5 — auto mode blocks pipeline dispatch, see memory
`retranscribing_a_completed_historic_run`).

## 1. Why

BC-07 asked whether a slide-backed series talk pitched at children, with no explicit address
to or dismissal of children, is a `childrens_talk`. The census (§3) showed the question is
malformed: the corpus holds **non-sermon talks**, some aimed at children, some at adults, and
the detector is asked to decide *audience* when what it can actually see is *structure* — a
substantial spoken item that is not the sermon, usually with a projected item behind it.

The code already has the seam, presently conflated:

| Axis | Enum | Stored on | What it answers |
|---|---|---|---|
| Detection | `ServiceSectionType` (`childrens_talk` is one of eight cases) | `service_sections.section_type`, `church_service_items.section_type` (MySQL enums), the detector's JSON schema | "what did the detector see?" |
| Publication | `SermonContentType` (`sermon` \| `childrens_talk`) | `sermons.content_type` (MySQL enum) | "how does the site present it?" |

Because both axes name the same thing, retyping a section to `childrens_talk` is a publication
act — that is the asymmetry BC-07 tripped over. This plan gives each axis its own vocabulary:
detection says `short_talk`, publication says which *kind* of talk, and a person makes the
second call once, at approval, with the material in front of them.

## 2. Decisions (operator, 2026-09-18)

| # | Decision | Ruling | Why |
|---|---|---|---|
| D1 | Storage naming | **Keep the `sermons` table and `Sermon` model.** Rename `SermonContentType` → `TalkType`; keep the `content_type` column name. | The table already *is* the talk store (children's talks are Sermon rows). A table/model rename touches routes, SEO, the API, promotion bundles and the historic output contract for no behaviour. A later `Sermon` → `Talk` class rename stays mechanical. |
| D2 | Talk types | **`sermon`, `childrens_talk`, `partner_update`, `testimony`.** No catch-all. | These are the clusters the census actually shows. Baptisms, tributes, eulogies and pre-service audio are not talks and stay `other`, unpublished. A generic "other talk" would become the lazy default at approval. |
| D3 | Public presentation | **One type-parameterised talks surface.** Children's Corner becomes the `childrens_talk` instance of shared listing/show/card views; each new type gets a page from the same views. The sermon archive is untouched. | Children's Corner is already a trimmed copy of the sermon views (card differs from `sermon-card` by 230 diff lines, mostly the route name). A third copy is the thing to avoid. |
| D4 | Exposure | **Members-only by default, per-type flip.** One per-type rule replaces `CHILDRENS_TALKS_PUBLIC`. Sermons public; every other type behind verified login until flipped. | Partner updates and testimonies name individuals. The existing verified-email gate is the right shape; it just needs to key on type rather than on one boolean. |

Two related rulings carried in from the historic plan: nothing in the 191-section bucket is
retyped by hand (`feedback_repair_through_pipeline_not_by_hand`), and the review queue growing
by every short talk that needs a type is the queue doing its job
(`feedback_review_queue_is_the_point`).

## 3. Measured state (local DB, 2026-09-18 — see `local_machine_is_separate_from_prod`)

| Population | n |
|---|---|
| Sermon rows: published 823, quarantined historic 442 | 1,265 |
| Children's-talk rows (`content_type = childrens_talk`), all published, all `manual` | 3 |
| `childrens_talk` sections — historic 185 (146 pending approval, 39 n/a), weekly 17 (5 pending) | 202 |
| `other` sections over 120 s, across 153 runs; 142 of those runs have no children's talk | 191 |
| … of which historic 174, weekly 17; with an OoS item 61 (presentations 41, images 9, media 6, custom 4, songs 1) | |
| … duration bands: 2–5 min 94, 5–10 min 74, 10–20 min 20, 20 min+ 3 | |

Reading the 191 titles (`storage/scratch/` census queries are reproduced in §9), the clusters
are real and **do not follow the OoS item type**:

- **Children's teaching series** — Heidelberg catechism weeks (≥16), "heroes of faith"
  (Tyndale, Thomas Bilney, Augustine of Hippo, Amy Carmichael, Jim Elliot, Billy Graham, William
  and Catherine Booth, Eric Freeder), Joseph's reunion, the repentant thief, the Good Samaritan.
- **Partner and mission presentations** — Release International (×3), Nigeria persecution
  (×3), Ukraine, Barnabas, Word One-to-One, Bibles for Zimbabwe, Mind the Gap Africa, Voice of
  Persecuted Christians, Sri Lanka video, Operation Forgiveness (3,021 s — the longest).
- **Testimonies** — Gavin Peacock, Charlene's Olympic testimony, baptismal testimonies,
  "Personal testimony", "Mission testimony", Abbeywood Church interview.
- **Not talks** — "Baptism of Roy", "Baptisms", "Family tribute and eulogy", "Reflection and
  prayer for Queen Elizabeth II", "Unidentifiable service audio" (3,667 s), pre-service audio,
  "Church sharing and prayer", and at least one mis-typed reading (§38 "Bible Reading").

This table is the **truth set** PR5's measurement is scored against.

## 4. Target design

### 4.1 Publication axis: `TalkType`

```php
enum TalkType: string
{
    case Sermon = 'sermon';
    case ChildrensTalk = 'childrens_talk';
    case PartnerUpdate = 'partner_update';
    case Testimony = 'testimony';

    public function label(): string;        // Sermon · Children's talk · Partner update · Testimony
    public function pluralLabel(): string;  // Sermons · Children's Corner · Partner updates · Testimonies
    public function routeSlug(): string;    // sermons · childrens-corner · partner-updates · testimonies
    public function isSermon(): bool;
    /** @return list<self> */
    public static function nonSermon(): array;
}
```

- `SermonContentType` is deleted, not aliased. Every consumer (60 app files, 90 test files —
  `grep -rln SermonContentType`) moves to `TalkType`. The `sermons.content_type` enum widens.
- Presentation copy that varies by type (page heading, meta description, empty-state text,
  card eyebrow) lives in **one** presenter, `TalkTypePresenter`, not on the enum and not in
  four Blade files.
- `SermonExposurePolicy` gains `isTypePublic(TalkType)` and `canAccessType(TalkType, ?User)`.
  `childrensTalksArePublic()` and `canAccessChildrensCorner()` are deleted; all seven callers
  (`SermonBuilder::whereVisibleInSitemap`, `SitemapService`, `Header`,
  `EnsureChildrensCornerAccess`, `SermonAssetController`, `exposesContentTypeOnChurchService`,
  `shouldIncludeInSitemap`) pass the talk's type. Config becomes one list:

  ```php
  // config/church.php
  'talks' => ['public_types' => explode(',', env('PUBLIC_TALK_TYPES', 'sermon'))],
  ```

  `sermon` is always public regardless of the list (the policy enforces it; the list cannot
  hide sermons). `CHILDRENS_TALKS_PUBLIC` is removed, with a `PROD-ACTIONS-PENDING` entry.
- `publicRouteName()` / `canonicalUrl()`: sermons keep `sermons.show.dated`; every other type
  routes to `talks.show` with the type's slug (§4.4).
- **Upsert key.** `SermonCreationService::findByDateAndServiceAndContentType()` keys a talk on
  `(date, service, content_type)`. Two testimonies in one service (run 1311 "Baptismal
  testimonies" is one section today, but the class is real) would overwrite each other. For a
  section-published talk the identity is the section: look up by `service_sections.published_sermon_id`
  first (already unique), and only fall back to the date/service/type key for the primary
  sermon path. A test with two testimonies in one service pins it.
- Unchanged and deliberately so: the sermon archive (`whereSermon()`, `BrowseSermons`, the
  API, the podcast feed, promotion bundles which accept only `sermon`,
  `BootstrapSpeakerProfilesCommand`'s sermon filter).

### 4.2 Detection axis: `short_talk`

`ServiceSectionType::ChildrensTalk` becomes `ShortTalk = 'short_talk'` (label "Short talk").
The detector stops judging audience. The prompt rule becomes:

> Label a section `short_talk` when it is a substantial spoken item that is not the sermon —
> typically with a projected item behind it: teaching for children, a catechism question, a
> hero-of-faith or Bible-character presentation, a mission or partner presentation, a
> testimony or interview. A whole talk is ONE `short_talk` section even when the speaker
> prays or asks questions inside it. Do not use it for an ordinance (baptism, communion), a
> tribute, notices, or pre-service audio.

The structural cues the prompt uses today (children addressed, called forward, dismissed;
parents addressed) are not thrown away — they become a **proposal**. The JSON schema gains a
nullable `talk_type` on each section, constrained to `TalkType::nonSermon()` values, with the
rule: propose `childrens_talk` only on those cues; `partner_update` when a named mission,
society or partner presents its work; `testimony` when a person recounts their own story or is
interviewed; null otherwise. The proposal is stored, never acted on:

```php
// ServiceSectionMetadata gains
public ?TalkTypeMetadata $talkType = null;   // key `talk_type`
// TalkTypeMetadata { ?string proposed; ?array{value:string,user_id:int,at:string} reviewed; }
// publicationTalkType(): ?TalkType   — reviewed value, never the proposal
```

This mirrors the speaker `predicted` / `reviewed` shape already on the section, and reuses
its review-flag discipline: a section whose talk type is unreviewed carries a review reason,
exactly as an unreviewed speaker does.

A second, deterministic proposal source: the OoS item. `OosSemanticItemKind` already has
`ChildrensTalk`, `MissionaryFocus` and `Interview`; `CompileOosSemanticAnnotations::canonicalType()`
maps the first to `childrens_talk` and the others to `other`. It maps all three to `short_talk`,
and the item metadata carries the kind so the workbench can show "OoS says: partner update"
beside "detector proposed: children's talk". `ServiceSectionType::inferFromTitle()`'s
`children` / `family talk` branch returns `ShortTalk`; no new title keywords are added (the
census shows titles are unreliable for audience).

Renames that follow, because a class named for children's talks serving testimonies is wrong:

| Today | Becomes | Note |
|---|---|---|
| `ChildrensTalkSpeakerService`, metadata key `childrens_talk_speaker` | `TalkSpeakerService`, key `talk_speaker` | Same detector, same thresholds (memory `childrens_talk_speaker_shortlist_2026_09_03`). One migration rewrites the JSON key on existing rows; no dual-read. |
| `ChildrensTalkBoundaryEvidenceService`, key `childrens_talk_boundary` | `ShortTalkBoundaryEvidenceService`, key `short_talk_boundary` | Same. |
| `RedetectChildrensTalkSpeakersCommand` | `services:redetect-talk-speakers` | Scope rule unchanged (only disposition-class outcomes). |
| Flags `ambiguous_childrens_talk`, `inferred_childrens_talk` | `ambiguous_short_talk`, `inferred_short_talk` | `ServiceStructureValidator` registered lists, `SectionReviewFlagPolicy`. |
| `SermonPublicationHandler` | `TalkPublicationHandler` | Config key `short_talk`. The commented-out `sermon` handler stays a comment (§8). |
| `ServiceSection::hasResolvedChildrensTalkSpeaker()` and friends | `hasResolvedTalkSpeaker()` … | |

Everything keyed on `ServiceSectionType::ChildrensTalk` moves to `ShortTalk` with no
semantic change: `requiresStructuralUncertaintyReview()`, `HoldSectionForContentReview::HOLDABLE_TYPES`,
`HistoricReleaseReviewHolds::SpokenContentTypes`, `HistoricProcessingResultSectionKey`,
`ServiceReviewDashboardQuery`'s speaker-review reason, `ServiceFlowBuilder`'s icon,
`MockServiceStructureService`'s cue list, `ServiceStructureValidator`'s type list.

**Classification signature.** `ServiceSection::classificationSignature()` already folds in
the publication speaker for a children's talk. It must also fold in the *reviewed* talk type,
so changing the type after approval invalidates the approval the same way changing the
speaker does (`TalkPublicationHandler::publish()` already refuses on a signature mismatch).

### 4.3 Approval: the one new operator decision

The workbench section review panel
(`resources/views/livewire/admin/church-services/partials/section-review-panel.blade.php`)
already shows, for a children's talk, a speaker block with the detector's prediction and a
choose-or-type speaker control. A short talk gets one more control in the same block:

- **Talk type** — `<x-select>` over `TalkType::nonSermon()`, prefilled from the detector
  proposal when there is one, with the proposal and the OoS kind shown as "Detector proposed:
  Children's talk · OoS item: presentation" in the same style as "Detected speaker".
- `ApproveSectionForPublication::approvalBlocker()` returns "Choose the talk type before
  approving publication" when no reviewed type exists, alongside the existing speaker blocker.
- `SaveServiceSection` accepts `talk_type` in the section edit payload, records it under
  `metadata.talk_type.reviewed` with user and time, and clears it when a section is retyped
  away from `short_talk` (as it clears the speaker today).
- Retyping a section *to* `short_talk` from the type dropdown still works; the speaker and
  talk-type requirements then apply before approval. The old act of "retype to children's
  talk" no longer exists — that is the BC-07 asymmetry removed.

No new screen. No bulk-type control: each short talk is confirmed one at a time, because the
census showed no bulk rule is safe.

### 4.4 Public surface: one set of views, four instances

| Today | Becomes |
|---|---|
| `ChildrensCornerController` (index, show) | `TalkController` (index, show) taking a `TalkType` resolved from the route's `{type}` slug; 404 on `sermons` (the sermon archive owns that slug) |
| `/christ/childrens-corner`, `/christ/childrens-corner/{sermon:slug}` | `/christ/{type}` and `/christ/{type}/{sermon:slug}` where `{type}` is constrained to the non-sermon `routeSlug()`s. **The Children's Corner URLs are byte-identical**, so no redirect and no SEO change. Route names `talks.index`, `talks.show`. |
| `childrens-corner.access` middleware | `talks.access` — resolves the type from the route and calls `canAccessType()` |
| `childrens-corner/index.blade.php`, `show.blade.php`, `components/childrens-talk-card.blade.php` | `talks/index.blade.php`, `talks/show.blade.php`, `components/talk-card.blade.php` — the existing files moved and parameterised; copy comes from `TalkTypePresenter` |
| Header nav's single Children's Corner entry | One loop over `TalkType::nonSermon()`, rendering each type the viewer can access, in the same list item style |
| Sitemap: `childrens-corner.index` when public | One entry per public non-sermon type; per-talk entries via `shouldIncludeInSitemap()` |
| Service archive page: `kind` = `sermon` \| `childrens_talk` with a hard-coded label pair | `kind` = `talk`, label from `TalkType::label()`; `PublicChurchServiceArchiveService::sermonEntry()` finds the section by `ShortTalk` for any non-sermon type |
| `analytics-context`: content group "Children's Corner" vs "Sermons" | content group = `pluralLabel()` |
| Admin sermons list badge + edit screen `isChildrensTalk` branches | Badge shows `label()`; a type filter joins the existing filter bar; edit screen branches on `isSermon()` and says "Speaker" for every non-sermon type |

**Two show templates, not four and not one.** The sermon page (`sermons/sermon.blade.php`,
510 lines: points, transcript, scripture filters, series, related sermons) stays the sermon
page. The talk page is the current Children's Corner page (147 lines: date, speaker, watch,
listen, back link) with the type's copy substituted. Merging them is a design-refresh
question, not this plan's (§8).

Design rules (from `.claude/skills/frontend-design/SKILL.md`, which stays authoritative):
public pages on `x-page.shell`, `x-card`, teal palette, `wire:navigate`, `x-button` variants;
the card keeps its aspect-video thumbnail and eyebrow; empty state per type through `x-card`;
touch targets and focus rings as today. No new components — the card, page shell and CTA
already exist.

### 4.5 Schema changes

Three enum columns and one JSON key, each in its own migration using the project's
`DB::statement(... MODIFY COLUMN ... ENUM(...))` pattern
(`2026_07_12_221655_remove_skipped_from_service_sections_status.php`), MySQL-guarded, with a
`down()`:

1. `sermons.content_type`: widen to `('sermon','childrens_talk','partner_update','testimony')`. No data change.
2. `service_sections.section_type` and `church_service_items.section_type`: add `short_talk`,
   `UPDATE … SET section_type='short_talk' WHERE section_type='childrens_talk'` (202 sections;
   items likewise), then drop `childrens_talk` from the enum. The `publication_media_check`
   constraint names only `song`, so it is unaffected; assert that in the migration test.
3. `service_sections.metadata`: for every migrated section, `JSON_SET(metadata, '$.talk_type',
   JSON_OBJECT('proposed','childrens_talk'))` — they were detected under the structural-cue
   rule, so the proposal is honest — and rename `childrens_talk_speaker` → `talk_speaker`,
   `childrens_talk_boundary` → `short_talk_boundary` via `JSON_SET` + `JSON_REMOVE`.
4. `database/schema/mysql-schema.sql` is regenerated (memory `schema_dump_blocked` explains why
   the dump is load-bearing).

The 146 pending-approval historic sections are unapproved, so the new signature and blocker
apply to them naturally: each needs a type confirmed before approval. That is the intended
review queue, not a regression.

`HistoricNormalOutputContract` lists `content_type` as a portable field and the canary pins
the enum; both are updated in PR1. Promotion bundles keep accepting `sermon` only.

## 5. Delivery slices

Sized by review surface and blast radius, not effort (`feedback_agent_executed_plan_sizing`).
Each lands with `pint --dirty`, `composer phpstan`, the affected tests, then the full parallel
suite and Dusk — **never while a data pass is running** (`dusk_repoints_db_during_run`).

| PR | Outcome the operator can see | Depends on | Blast radius |
|---|---|---|---|
| **PR1 — `TalkType` and per-type exposure** | `SermonContentType` gone; `sermons.content_type` widened; `PUBLIC_TALK_TYPES=sermon,childrens_talk` behaves exactly as `CHILDRENS_TALKS_PUBLIC=true` did (existing `SermonExposurePolicyTest`, `ChildrensCornerPagesTest`, sitemap and archive tests pass unchanged in behaviour). Upsert-by-section for published talks with the two-testimonies test. `PROD-ACTIONS-PENDING` entry for the env rename. | — | Live public read path; one prod migration; one env rename |
| **PR2 — Talks surface** | `/christ/childrens-corner` unchanged to a visitor; `/christ/partner-updates` and `/christ/testimonies` exist with their empty states behind verified login; header shows each accessible type; Children's Corner views, controller, middleware and card are gone, replaced by the `talks/*` set; admin list filters by type. Dusk covers one talk page per type and the nav. | PR1 | Public views, routes (additive), header |
| **PR3 — `short_talk` detection** | Detector emits `short_talk` plus a `talk_type` proposal; migrations 2–3 applied; every `ChildrensTalk` consumer renamed; mock detector and structure tests updated; proposal visible in the workbench panel (read-only). Measurement artifact: the new prompt run read-only (`shadow` mode) over the nine blind runs and the 61 item-bearing long-`other` sections, scored against §3's truth table for (a) is it a short talk, (b) proposed type; recorded in `storage/scratch/` with counts in this plan's §9. | PR1 | Detector contract, three enum columns, JSON metadata of 202 rows, ~40 files |
| **PR4 — Approval and publication** | Talk-type select and blocker in the panel; signature includes the reviewed type; `TalkPublicationHandler` under the `short_talk` key; `SermonCreationOptions::fromServiceSection()` maps the reviewed type. End-to-end test (the existing `ChildrensTalkPublicationWorkflowTest`, generalised) drives a `testimony` from prepare → approve → publish and asserts it renders at `/christ/testimonies/{slug}` for a verified member and 404s for a guest. | PR1, PR3 | Section publication path (already the children's-talk path); workbench panel |
| **PR5 — Re-detection pass** | Gate: PR3's measurement shows the new prompt finds ≥ the talks the truth table names in the 61 with no false `short_talk` on the "not talks" rows; anything short of that is a prompt fix first (`feedback_measure_before_generalizing_a_fix`). Then the 142 runs with a long `other` and no short talk are re-detected **through the pipeline** (detection phase only, transcript reused; workers restarted first — `queue_workers_run_stale_code_after_commit`), operator-dispatched, respecting the historic lane's staging and dispatch rules. Outcome: short-talk candidates in the workbench for the operator to type, speaker and approve. | PR4 | Data only; no code |

PR2 and PR3 are independent of each other and can proceed in parallel after PR1. Nothing here
is a calendar gate (`feedback_no_calendar_time_gates`).

## 6. Operator workflow after PR4

1. A run is detected. Any `short_talk` section arrives in the workbench with a proposed type
   (or none), a predicted speaker (or a shortlist), and the boundary evidence it has today.
2. The operator confirms the type and the speaker in the panel and presses Approve. Either
   missing → the blocker names it.
3. Publication creates the Sermon row with that type. It appears on `/christ/{type}` for
   whoever `PUBLIC_TALK_TYPES` admits, and on the service archive page with the type's label.
4. Flipping a type public is an env change and a deploy; the sitemap, nav and archive follow.

Consent is an editorial judgement at step 2, not a machine gate: partner updates and
testimonies name people, and the default of members-only (D4) is what makes approval safe to
do first and reconsider later.

## 7. Acceptance

1. `grep -rn "SermonContentType\|ChildrensTalk\b\|childrens_talk_speaker\|childrens-corner\.\(index\|show\)\|CHILDRENS_TALKS_PUBLIC" app config routes resources` returns nothing after PR4 (the Children's Corner *route path* and the `childrens_talk` *enum value* legitimately remain).
2. A visitor's experience of `/christ/childrens-corner` and `/christ/sermons` is unchanged (Playwright baselines, `playwright_visual_regression`).
3. `ChildrensTalkPublicationWorkflowTest`'s generalised successor passes for each of the three non-sermon types.
4. Two testimonies published from one service produce two Sermon rows.
5. Changing a reviewed talk type after approval blocks publication until re-approval.
6. The PR3 measurement artifact exists and PR5's gate is stated against it with numbers.
7. After PR5, the 191-section bucket is either typed short talks awaiting the operator, or `other` with a recorded reason, and nothing in it was retyped by hand.

## 8. Out of scope, named so nobody re-derives it

- **Primary sermon through section publication.** Sermons are still created by `ExtractSermon`
  with no approval step; the `sermon` handler stays a comment in `config/media-processing.php`.
  It is the obvious follow-on and this plan's `TalkPublicationHandler` is the seam for it.
- **`Sermon` → `Talk` class and table rename.** D1.
- **Merging the sermon show template with the talk show template.** Design refresh.
- **Speaker-ID calibration** for short talks (memory `childrens_talk_speaker_shortlist_2026_09_03` still owes Q1(b)).
- **Historic rows.** The 442 quarantined historic sermons are not touched; historic release
  remains owned by the historic plan.

## 9. Evidence

Census queries run read-only against the local database 2026-09-18 (results in §3):

```sql
SELECT content_type, publication_state, source_type, COUNT(*) FROM sermons GROUP BY 1,2,3;
SELECT section_type, publication_status, COUNT(*) FROM service_sections GROUP BY 1,2;
SELECT (l.historic_import_operation_id IS NOT NULL) historic, (s.church_service_item_id IS NOT NULL) has_item, COUNT(*)
  FROM service_sections s JOIN media_processing_logs l ON l.id = s.media_processing_log_id
  WHERE s.section_type = 'other' AND (s.end_time - s.start_time) > 120 GROUP BY 1,2;
SELECT i.type, i.title, ROUND(s.end_time - s.start_time) dur, s.media_processing_log_id
  FROM service_sections s JOIN church_service_items i ON i.id = s.church_service_item_id
  WHERE s.section_type = 'other' AND (s.end_time - s.start_time) > 120 ORDER BY dur DESC;
SELECT s.title, ROUND(s.end_time - s.start_time) dur, s.media_processing_log_id
  FROM service_sections s WHERE s.section_type = 'other' AND s.church_service_item_id IS NULL
  AND (s.end_time - s.start_time) > 300 ORDER BY dur DESC;
```

PR3's measurement and PR5's counts are appended here when they exist.
