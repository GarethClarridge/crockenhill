# Church Service Structure & Sections — Simplification Review (Phase 2, July 2026)

Reviewed 2026-07-02 against the doctrine and template in
[`docs/plans/JULY-2026-SIMPLIFICATION-REVIEW-PLAN-2026-07-02.md`](../../plans/JULY-2026-SIMPLIFICATION-REVIEW-PLAN-2026-07-02.md).
This domain is the home of the review's exemplar,
[`docs/plans/LLM-FIRST-SERVICE-STRUCTURE-PIPELINE-2026-07-01.md`](../../plans/LLM-FIRST-SERVICE-STRUCTURE-PIPELINE-2026-07-01.md)
(phases 1–5 complete, phase 6 "promote and retire" pending on maintainer go). The central output of
this review is the **concrete retirement map** for phase 6 — including four corrections to the plan's
own deletion list — plus findings on the parts of the domain that survive promotion.

Findings doc only; no code changes.

---

## 1. Scope reviewed

- `app/Services/ChurchService/` — 26 top-level services, `Structure/` (6 files, the LLM path),
  `SectionPublication/` (3 files). ~8,300 lines.
- The livestream/reclassification pipeline jobs that own section structure:
  `AnalyzeSegments`, `ClassifyServiceSections`, `TranscribeSpeechSegments`, `ClassifySpeechSections`,
  `ProjectLivestreamServiceStructure`, `AlignWithOos`, `ResolveReadingReferences`,
  `MatchSongsFromTranscript`, `ReclassifyIntroOutroSections`, `ReconcileServiceSections`,
  `TranscribeFullService`, `DetectServiceStructure`; the mode branches in
  `app/Services/Processing/ProcessingPipelineBuilder.php`.
- Order-of-service email import: `app/Http/Controllers/Api/MailgunInboundWebhookController.php` and
  the `api/webhooks/mailgun/inbound` route, `app/Services/Email/` (5 files),
  `app/Jobs/ProcessInboundOosEmail.php`, `app/Actions/InboundEmail/` (4 actions),
  `app/Actions/PrefillChurchServiceFromInboundEmail.php`, model `InboundEmail`.
- Service review workflow: `app/Actions/ServiceReview/` (5 actions),
  `ChurchServiceReviewStateService` / `ChurchServiceCanonicalStateService` /
  `ChurchServiceCanonicalUpdateService` / `ChurchServiceReviewSynchronizer`,
  Livewire `app/Livewire/Admin/ChurchServices/` (9 components, 2 concerns, 13 view partials),
  `app/Queries/ReviewInboxQuery.php` and related queries.
- Console: `StructureEvaluateCommand`, `StructureShadowReportCommand`; `scripts/section-extraction/`
  (6 scripts, 1,123 lines).
- Config `config/service-tracking.php` (33 lines) and the `service_structure` block of
  `config/media-processing.php`.
- Models `ChurchService` (147), `ChurchServiceItem` (196), `ServiceSection` (285); the domain enums
  (`ChurchServiceReviewState`, `ChurchServiceCanonicalConflictState/Reason`, `ServiceSectionStatus`,
  `ServiceSectionPublicationStatus`, `ServiceSectionSongMatchType`, `ChurchServiceItemSource`,
  `ChurchServiceRollupStatus`, `InboundEmailStatus`, `ServiceStructureMode`).
- This domain's tests (~60 files across Unit/Integration/Feature; proportionality assessed in §4.9).

Prior art checked: `docs/plans/SIMPLIFICATION-PLAN.md`,
`docs/architecture/simplification-backlog.md` (church-service backlog marked complete; PR 23/24
parked), `docs/operations/section-extraction-findings-2026-06-20.md`. Nothing below duplicates an
open tracker item; §4.3 updates the status of backlog PR 24.

## 2. What this area is for

Each Sunday the church records a livestream and (usually) someone emails an order of service (OOS).
This domain turns those two inputs into: a canonical `ChurchService` record with its ordered items
(songs, readings, sermon, notices); timed `ServiceSection` rows over the recording; an extracted,
published sermon (and optionally children's talk / song clips); and "songs we sang" linkage. The
operator's involvement is a review inbox: confirm anything the pipeline was unsure about, approve
publications. The value to the church is a reliable public archive of sermons and services with
minimal weekly operator effort.

The complexity budget question for everything here is: *does this file reduce Sunday-to-published
effort or error, and is it still needed once one LLM call owns "understanding what was said"?*

## 3. Complexity inventory

### 3.1 The two parallel structure paths

The domain currently carries **both** the heuristic classification cluster and its LLM replacement,
by design (bridge period, `SERVICE_STRUCTURE_MODE=off|shadow|primary`). Sizes:

| Path | Production code | Tests |
|------|----------------|-------|
| Heuristic cluster (dead at phase 6) | 11 services, 3,324 lines + 6 jobs, 1,730 lines = **5,054 lines** | **~8,900 lines** (20 test files) |
| LLM path (permanent) | `Structure/` 978 + `DetectServiceStructure` 462 + `TranscribeFullService` 178 + data objects (`ServiceStructure` 255, `ServiceStructureSection` 184, `ChurchServiceTranscript` 167) = **~2,220 lines** | ~1,880 lines |
| Eval/shadow tooling | 794 lines (2 commands) | ~500 lines + fixtures |
| Legacy scenario scripts (`scripts/section-extraction/`) | 1,123 lines | `SectionExtractionScriptsTest` |

So the retirement payoff is roughly **14,000 lines** (production + tests + scripts) — replaced by an
LLM path a third the size that also does more (native OOS anchoring, reading references, sung-title
identification in one call).

### 3.2 The survivors

| File | Lines | Role after promotion |
|------|-------|----------------------|
| `ChurchServiceItemSyncService` | 912 | Multi-source OOS *item* merge (email / OpenLP / manual / livestream projection) — the domain's largest file, ~170-line `sync()` + 30 private helpers |
| `StructureMergePolicy` + `ChurchServiceStructureMergeService` | 380 + 278 | Staged-merge planning when an OOS import collides with existing items |
| `ServiceSectionSyncService` | 349 | The `sync()` seam — the one boundary both paths emit into (doctrine §4 working as intended) |
| `LivestreamChurchServiceProjectionService` + `LivestreamSectionToServiceItemMapper` | 330 + 131 | Creates/links the canonical `ChurchService` when no OOS import exists (retained per the plan's phase 4 audit) |
| Review/canonical state services (4 files) | 532 | Review-state derivation, canonical conflict recording, `needs_review` sync |
| `SectionPublication/` handlers | 543 | Publication approval → publish per section type |
| Email import (`app/Services/Email/` + actions + job) | ~1,320 | Mailgun webhook → parse → auto-import or review |
| Livewire admin surface | 2,290 (components) + 13 partials | Inbox, workbench, upload, songs |

### 3.3 State machines

The workflow carries nine enums plus per-row booleans: service review (3 states), canonical conflict
(3 states × 4 reasons, 6 dedicated DB columns, plus a history array in `import_metadata`), rollup
status (6, derived), section status (2), section publication (5), song match (3), item source (4),
inbound email (4), structure mode (3). Section-level `needs_manual_review` + `review_flags` and
service-level `needs_review` sit alongside. §4.5–4.6 assess which of these operators actually see.

## 4. Findings

### 4.1 The retirement map — what phase 6 actually deletes (the big one)

Every consumer of every heuristic-cluster class was traced (grep over `app/` + `config/`). The
cluster is **cleanly self-contained**: nothing outside it depends on it except the four seams noted
in 4.2. Files whose only consumers are inside the cluster (safe to delete at phase 6):

**Services (11, 3,324 lines):**

| File | Lines | Consumers (all in-cluster) |
|------|------:|----------------------------|
| `StructuralSectionAligner` | 777 | `OosAlignmentService` |
| `SpeechSectionClassificationService` | 616 | `ClassifySpeechSections` |
| `SongSectionAligner` | 549 | `OosAlignmentService` |
| `OosAlignmentService` | 264 | `AlignWithOos`, `MatchSongsFromTranscript` (mode-gated), `ReconcileServiceSections` |
| `ServiceSectionClassifier` | 222 | `ClassifyServiceSections`, `ClassifySpeechSections` (+ type-only imports, see 4.2) |
| `ReadingReferenceExtractor` | 211 | `ResolveReadingReferences` |
| `SectionItemAlignmentScorer` | 166 | `StructuralSectionAligner` |
| `SectionAlignmentBaselineRestorer` | 164 | aligners (but see 4.2 — flag registry must move first) |
| `AlignmentTriggerCalculator` | 162 | `OosAlignmentService` (the `UnmatchedSongReviewApplicator` reference is a dead import + stale docblock only) |
| `PresentationItemClassifier` | 131 | `StructuralSectionAligner` |
| `MediaInterludeCueDetector` | 62 | `StructuralSectionAligner` |

**Jobs (6, 1,730 lines):** `ClassifySpeechSections` (617), `ResolveReadingReferences` (290),
`TranscribeSpeechSegments` (279), `ReclassifyIntroOutroSections` (276), `ClassifyServiceSections`
(190), `AlignWithOos` (78).

**Plus:** the heuristic branches of `ProcessingPipelineBuilder::buildLivestreamChainJobs()` /
`buildSectionReclassificationChainJobs()`, **the auto-trim pipeline** (`buildAutoTrimVideoPipeline()`
and `buildAutoTrimVideoPostReviewChainJobs()`, which instantiate `ClassifyServiceSections`,
`TranscribeSpeechSegments`, `ClassifySpeechSections`, and `ReclassifyIntroOutroSections`
unconditionally — see seam 5 in 4.2), the `Off` case of `ServiceStructureMode`,
`scripts/section-extraction/` (1,123 lines) with `SectionExtractionScriptsTest`, and ~8,900 lines of
heuristic-path tests (list in 4.9).

**Four corrections to the plan's phase 6 deletion list** (the plan itself says "audit each for
non-cluster consumers before deletion" — this is that audit):

1. **`LivestreamChurchServiceProjectionService` + `LivestreamSectionToServiceItemMapper` are NOT
   deletable.** The plan's phase 4 audit already retained `ProjectLivestreamServiceStructure` in the
   primary chain (it creates/links the canonical `ChurchService` when no OOS import exists —
   `ProcessingPipelineBuilder.php:150-173` documents this), and both services exist solely to serve
   that job. The phase 6 list at the bottom of the plan pre-dates the phase 4 decision and was never
   updated. They move from "delete" to "keep".
2. **`StructureMergePolicy` / `ChurchServiceStructureMergeService` are not cluster code at all.**
   Their consumers are `InboundEmailImportService` and `ImportChurchServiceFromOpenLp` — the OOS
   *import* path, which is orthogonal to section classification. Remove them from the phase 6 list
   (they get their own scrutiny in 4.6).
3. **`SongSectionAligner` is deletable whole.** The plan hedges "keep the DB-matching core reused by
   `MatchSongsFromTranscript`" — but that core actually lives in the songs domain
   (`SongLyricsMatchingService`, `SongLyricOcrService`, `Song::matchKeyVariants()`), which
   `MatchSongsFromTranscript` uses directly. Nothing in the aligner is reused. No carve-out needed.
4. **Two type/constant homes must move before their hosts are deleted** — detailed in 4.2.

### 4.2 Five load-bearing seams the retirement must handle first

These are the concrete blockers between "flip to primary" and "delete the cluster". None of the
first four is large; all should be done as preparatory commits *before* the deletion pass so each
deletion is purely subtractive. Seam 5 (auto-trim) is larger and is the real gate on deleting the
four classification jobs.

1. **`ReconcileServiceSections` is not mode-aware — the late-OOS-arrival path re-runs the heuristic
   aligner in primary mode.** When an OOS email lands after a run has completed,
   `ChurchServiceObserver` → `ChurchServiceReconciliationDispatcher` → `ReconcileServiceSections`
   calls `OosAlignmentService::alignForProcessingLog()` unconditionally
   (`app/Jobs/ReconcileServiceSections.php:67`). In primary mode that lets the heuristic aligner
   rewrite the LLM's OOS anchoring — the exact overwrite the phase 4 work suppressed inside
   `MatchSongsFromTranscript`. The fix is also the better design: in primary mode, reconcile by
   re-running `DetectServiceStructure` against the **stored transcript artifact** (which
   deliberately survives cleanup) with the newly-arrived OOS items — no media access, one LLM call,
   and the heuristic reconcile path dies with the cluster. Until then this is a live correctness
   gap for the primary-mode soak: a late OOS email will degrade an LLM-anchored run.
2. **The seam contract type lives on a class scheduled for deletion.** The `ClassifiedSection`
   array shape — the very contract of the `sync()` seam — is a PHPStan type defined on
   `ServiceSectionClassifier`, imported by `ServiceSectionSyncService` (line 27) and
   `App\Data\ServiceStructure` (line 100). Move the type to `ServiceSectionSyncService` (or a small
   Data object) first; then the classifier deletes cleanly.
3. **The review-flag registry lives on a heuristic class the LLM path depends on.**
   `SectionAlignmentBaselineRestorer::OOS_REVIEW_FLAGS/OOS_REVIEW_REASONS` is where the LLM path's
   soft flags (`structure_low_confidence`, `structure_micro_section`, etc.) are registered so
   re-runs clear stale flags idempotently (the F18 trap). Move the registry into
   `Structure/` (e.g. onto the validator) before deleting the restorer.
4. **Service-level review sync after the aligner dies.** `ChurchServiceReviewSynchronizer` (kept —
   `DeleteLivestreamUpload` also uses it) is today invoked from `OosAlignmentService`. In primary
   mode, section flags reach the service-level `needs_review` via
   `LivestreamChurchServiceProjectionService` (verified: `needs_review` propagation at lines
   143–174). Confirm during the soak that no review trigger is lost when the aligner stops running —
   then the synchronizer's only callers are the projection path and upload deletion.
5. **The auto-trim pipeline is a second, un-gated consumer of the classification jobs.**
   `ProcessingPipelineBuilder::buildAutoTrimVideoPipeline()` and
   `buildAutoTrimVideoPostReviewChainJobs()` instantiate `ClassifyServiceSections`,
   `TranscribeSpeechSegments`, `ClassifySpeechSections`, and `ReclassifyIntroOutroSections`
   directly and with **no `ServiceStructureMode` gate at all** (`ProcessingPipelineBuilder.php:99-119,
   251-320`) — the mode-gating that phase 4 added lives only on the livestream chain. So the four
   classification jobs are not deletable while auto-trim depends on them: doing so would either break
   the auto-trim entry point (a pre-trimmed video that still needs section understanding) or silently
   drop its structure detection. Before the deletion pass, auto-trim must either be migrated onto the
   LLM detector or, if auto-trim uploads are no longer used, retired as its own decision (open
   question, 4.9). **The migration is not a straight job swap**, because the LLM path is
   livestream-only by guard: auto-trim runs are `MediaType::Video`, but `DetectServiceStructure::handle()`
   skips anything whose `processing_type !== MediaType::Livestream` (`DetectServiceStructure.php:88-95`),
   and the downstream structure/projection/song/publication jobs carry the same livestream-only guard
   (`ProjectLivestreamServiceStructure`, `MatchSongsFromTranscript`, `AlignWithOos`,
   `PrepareSectionPublicationCandidates`, `PublishApprovedServiceSection` — each verified to `return`
   early on `processing_type !== MediaType::Livestream`). Note the *audio/thumbnail/record* jobs are
   **not** in this set and are **not** blockers: `CreateSermonRecord`, `EnhanceAudio`,
   `GenerateThumbnail`, and `IdentifySpeaker` already carry explicit `MediaType::Video` branches, so
   they process auto-trim (Video) runs today — don't spend the migration widening them. So dropping in `TranscribeFullService` →
   `DetectServiceStructure` as-is would let an auto-trim upload run but produce **no LLM sections and
   no service linkage**. The migration must first widen those guards to cover the auto-trim media
   type (or add a dedicated auto-trim LLM path) — a real design task, not a rename — before the four
   heuristic jobs can be deleted. This is the real gate on jobs → services deletion, above and beyond
   the livestream soak.

**The concrete path to retiring the heuristic classifier**, restated as a sequence:
merge the stack → set `mode=shadow` + real detector/transcriber in production → accumulate Sundays →
`structure:shadow-report` + fill a real manifest for `structure:evaluate` → land the five
preparatory items above (including migrating or retiring the auto-trim pipeline, seam 5) →
flip `mode=primary` → soak (~8 services, plan's suggested gate) → delete
in dependency order (jobs → services → builder branches → **`ProcessingPhaseRegistry` cleanup** →
collapse `ServiceStructureMode` → heuristic tests and `scripts/section-extraction/`). Each deletion
is its own commit with a green suite.

One more consumer sits in that chain and must not be forgotten: `ProcessingPhaseRegistry` is a live
class (it backs `StandardProcessingResponse::progressForLog()`, `StandardProcessingResponse.php:330`)
that **imports and anchors every job being deleted** — `ClassifyServiceSections`,
`ClassifySpeechSections`, `TranscribeSpeechSegments`, `ReclassifyIntroOutroSections`, `AlignWithOos`,
and `ResolveReadingReferences` all appear in its `use` block and phase tables
(`ProcessingPhaseRegistry.php:9-28` and the livestream/reclassification phase entries). Deleting the
job classes without pruning the registry's imports, phase rows, and progress anchors in the same step
leaves dangling references that fail PHPStan and the suite — so registry/timeline cleanup is an
explicit deletion step, not an afterthought. With that step included, nothing else in the codebase
blocks it.

### 4.3 `ChurchServiceItemSyncService` (912 lines) — proportionate today, over-general tomorrow

The domain's largest file survives promotion: it merges OOS *items* (not sections) from four
sources with per-source authority rules, cross-source song-title matching (~120 lines), position
fallback, conflict snapshots, staged updates, and resequencing with uniqueness assertions. Its
complexity is a direct function of **sources × authority rules**, and all four sources are real
today.

But promotion changes the shape of the problem: in primary mode the LLM anchors sections to items
natively, and the projection path (`Livestream` source) only writes items when *no* OOS import
exists — after which a later email import is the only collision case. Direction (post-phase-6, not
now): reassess whether the `Livestream` source still needs full merge authority, whether
cross-source song-title matching still fires (the LLM's `song_title` + `MatchSongsFromTranscript`
confirmation may make it redundant), and only then consider splitting policy from mechanics. This
mirrors the backlog's advice on its sibling (`SongCatalogSyncService`, PR 23): don't split while the
semantics are still moving. Backlog PR 24 ("separate anomaly detection from
`ServiceSectionClassifier`") is **superseded by this review** — the classifier is now on the
deletion list; note that in the backlog rather than actioning PR 24.

### 4.4 The heuristic cluster is exactly what the doctrine says to collapse — evidence for the wrap-up

Worth recording as calibration for other domains: the cluster's internals show the cost of
approximating judgement in code. `StructuralSectionAligner` (777 lines) implements a
dynamic-programming optimal alignment with traceback (`optimalAlignment()`/`traceback()`,
lines 245–421) plus positional fallbacks, presentation-item decisions, media-interlude detection,
and mismatch bookkeeping; `OosAlignmentService` hard-codes nine English dismissal phrases to infer
children's talks (`inferChildrensTalkFromDismissalMarkers()`); `SongSectionAligner` maintains
song-introduction cue lists and catalogue-occurrence pairings. Each is well-written and tested —
and all of it is a hand-built approximation of "read the transcript and say what happened", now one
typed LLM call. The lesson for Phases 1/3–5: when you find sequence aligners, cue-phrase lists, and
confidence arithmetic cooperating across files, that's the signature.

### 4.5 Review state is stored twice and its granular half is never read

`ChurchService` review/conflict state lives in **both** `import_metadata` JSON and normalised DB
columns, with `ChurchServiceReviewStateService::normalizedColumns()` (167 lines) translating one
into the other on every sync. The canonical-conflict half of that machinery — 3-state enum ×
4-reason enum, six dedicated columns (`canonical_conflict_state/detected_at/incoming_source/
reviewed_previously/canonical_changed/reason`), plus an append-only `canonical_conflict_history`
in the JSON — is **written on every import but read by no UI, query, or presenter**. Grep confirms:
outside the services that write it, its only consumer is
`ChurchServiceReviewSynchronizer`, which collapses it into the single `needs_review` boolean the
inbox shows. Operators see "needs review", never "reopened because canonical changed with
conflicts".

Direction: pick one storage (the columns), and shrink the granularity to what the inbox displays —
plausibly `needs_review` + one human-readable reason string. The history can stay in JSON if an
audit trail is wanted. This deletes an enum, most of another, several columns' worth of ceremony,
and much of the state service. Flagged as removal candidate R3.

### 4.6 The staged structure-merge workflow needs a usage check

`StructureMergePolicy` (380) + `ChurchServiceStructureMergeService` (278) +
`ResolvePendingStructureMerge` action (173) + `PendingStructureMergeMetadata` /
`StructureMergeResolution` / `StructureMergeResult` data objects + the
`pending-structure-merge.blade.php` partial ≈ **950+ lines** exist to *stage* an incoming OOS import
for operator review instead of merging it, when it would collide with high-confidence
livestream-derived items or a previously-reviewed service. The policy's auto-merge carve-outs
(enrichment-only, song-identification match, semantic title match) suggest most real imports
auto-resolve. If the staged path fires rarely — and when it fires the operator nearly always accepts
the incoming email — the whole staging apparatus could collapse to "merge + set `needs_review` with
a diff note", reusing the ordinary review flow. This is a business-logic proportionality question
only the operator can answer (open question Q1). Also note: promotion *reduces* the collision
surface this workflow guards (LLM anchoring means livestream runs stop fabricating item structure
when an OOS exists), so re-measure after phase 6, not before.

### 4.7 The email import path is a pre-exemplar hybrid — finish the job

The OOS email import is half-converted to the doctrine: item extraction is already one typed LLM
call (`OpenAiOosEmailItemExtractor`, 173 lines, `gpt-4.1-nano`, json_schema — the pattern the
exemplar borrowed), but **date and service-time extraction is still ~300 lines of regex heuristics**
in `OosEmailParserService` (432 lines: `extractIsoDate`, `extractNumericDate`, `extractTextualDate`,
`resolveYear`, `monthNumber`, confidence arithmetic). One extraction call returning
`{date, service, items, confidence}` — validated deterministically (date parses, service in enum,
future-dated within tolerance) and gated by the existing `review_threshold`/`auto_import_threshold`
— would delete most of the parser and likely handle the odd formats ("this Sunday", "Sept 14th")
better than the regexes. The sanitiser, webhook signature validation, thresholds, and
`InboundEmailImportService` are proportionate and stay. The manual-paste escape hatch
(`SubmitEmailText`) reuses the same pipeline — good shape, keep.

Answering the brief's question — *does simplifying the sync seam make the OOS import simpler too?*
Mostly indirectly: the import's own parsing gets simpler by the same doctrine (above), and
promotion shrinks what the merge policy must defend against (4.6). The `ChurchServiceItemSyncService`
seam itself stays as-is until the 4.3 reassessment.

### 4.8 Shadow/eval tooling: keep both — they are the regression suite for every future model change

`StructureEvaluateCommand` (568) is permanent infrastructure by the plan's own phase 6 note ("model
upgrades are an eval run, not a config flip") — agreed, and it is the tool that survives cleanly
because it evaluates against a fixed manifest, not against the heuristic pipeline.

`StructureShadowReportCommand` (226) and shadow *mode* are a different case. Shadow is worth keeping
in principle — `mode` deliberately collapses to `shadow|primary` (not just `primary`) — but it
**cannot be marked permanent as-implemented**, because its baseline is the very cluster R1 deletes.
Shadow runs the heuristic chain first and then appends `TranscribeFullService`/`DetectServiceStructure`,
and `DetectServiceStructure::diffAgainstAuthoritativeSections()` compares the LLM proposal to the
heuristic `service_sections` (the job's own docblock, `DetectServiceStructure.php:41,380-384`:
"the heuristic sections stay authoritative"). Once the heuristic cluster is gone there is no
authoritative baseline to diff against, so re-using `shadow` to validate a future prompt/model change
requires **redefining the baseline first** — diff the candidate model against the currently-bound
model's stored output, or against a curated `structure:evaluate` manifest, rather than against a
heuristic that no longer runs. Treat that redesign as part of the phase 6 work, not a keep-as-is:
keep the `shadow|primary` mode switch and the report command, but re-point the diff before the
heuristic pipeline is deleted, or shadow mode ships broken. `StructureEvaluateCommand`'s
manifest-based path is the natural home for that new baseline. Retire `scripts/section-extraction/`
instead (already in the plan). One nit for phase 6: the eval command's `--detector=openai` default
means a bare local run costs money; defaulting to the bound detector (mock in CI) would be safer —
park for the implementation pass.

### 4.9 Tests: proportionate to the bridge, heavy after it

~8,900 lines of tests exist solely to pin the heuristic cluster: `StructuralSectionAlignerTest`
(Feature *and* Integration variants), `SongSectionAlignerTest`, `SpeechSectionClassificationServiceTest`,
`ClassifyServiceSectionsTest`, `ClassifySpeechSectionsTest`, `AlignWithOosTest`,
`OosAlignmentServiceTest` + `OosAlignmentServiceCharacterizationTest` (characterisation scaffolding
by its own name), `ReclassifyIntroOutroSectionsTest`, `ProjectLivestreamServiceStructureTest`*,
`LivestreamChurchServiceProjectionServiceTest`*, `LivestreamSectionToServiceItemMapperTest`*,
`SectionItemAlignmentScorerTest`, `SectionAlignmentBaselineRestorerTest`,
`MediaInterludeCueDetectorTest`, `ServiceSectionClassifierTest`, `ChurchServicePipelineAlignmentTest`,
`ReconcileServiceSectionsTest`, `SectionExtractionScriptsTest` (* = these three cover retained code
and survive). That volume was the right call for a bridge that had to stay byte-identical — but it
is a standing tax on CI and a reason not to invest another line in heuristic-path coverage between
now and phase 6. The LLM path's ~1,880 lines (validator matrix, adapter-vs-faked-HTTP, mapper shape
against `ServiceSection::validationRules()`, builder mode pins, golden `sync()` round-trip) cover
the seams that matter — no under-tested seam found there. The retained-side tests
(`ChurchServiceItemSyncServiceTest`, 50 test methods; sync/merge/review-state suites, ~2,270 lines)
are heavy but match the merge semantics they pin; revisit only after 4.3/4.6 change those semantics.

### 4.10 Small dead things found in passing

- `ServiceSectionStatus::Skipped` has **no writer anywhere** in `app/` — only
  `SchemaGuardrailAudit` queries for it (and by construction finds nothing). A two-case enum where
  one case is dead.
- `UnmatchedSongReviewApplicator` imports `AlignmentTriggerCalculator` it never uses (docblock
  reference only) — and that stale coupling is what makes the calculator *look* like it has a
  non-cluster consumer.
- `ReviewsServiceSections`' docblock still says it serves "the review dashboard (until it retires)";
  the dashboard routes are already redirects into the unified inbox (`routes/web.php:200-203`) —
  comment is stale, and if the dashboard component is fully gone, `ServiceReviewDashboardQuery`'s
  name is too.

## 5. Opportunities unlocked

Weighted equally with removals, per the doctrine. All become cheap *because* structure comes from
one typed call over a full transcript:

1. **Late-OOS re-anchoring becomes better, not just simpler** (pairs with finding 4.2.1). Today a
   late email triggers the full heuristic realignment over stale per-segment transcripts. With the
   stored transcript artifact, reconciliation is one `DetectServiceStructure` re-run — no media
   access, LLM-quality anchoring, and it deletes `ReconcileServiceSections`' heuristic dependency.
2. **Richer section metadata for the review UI and public service pages, one schema field at a
   time.** The prompt/schema already carries per-section titles, reading references, and sung
   titles; `ServiceFlowBuilder` and the workbench already read section metadata. Adding e.g. a
   one-sentence section summary, speaker hints, or notices extraction is a schema + prompt change
   plus one eval run — no new services.
3. **Automatic service summaries.** The full transcript exists per run; a summary (for the service
   page, the newsletter, or search) is one more typed call — previously impossible without the
   transcript artifact.
4. **Song matching can shed its heaviest fallback.** `MatchSongsFromTranscript` (583 lines) keeps a
   three-tier fallback: LLM `song_title` hint → video-frame OCR → per-section audio extraction +
   local Whisper of the song's opening. In primary mode the full transcript is guaranteed, so the
   third tier can become `ChurchServiceTranscript::sliceText()` over the section's interval —
   deleting this job's ffmpeg/extraction/Whisper plumbing (~150 lines) and its slowest path. (Whether
   OCR is still needed for lyrics-on-screen-only cases is open question Q4.)
5. **One-call email parsing** (finding 4.7) — same doctrine, second application, ~300 lines of date
   regex retired and probably better extraction on informal emails.
6. **Model upgrades as routine** — already designed in (eval harness + shadow mode), worth restating
   as the mechanism that keeps all of the above cheap to iterate.

## 6. Removal candidates (needs decision)

| # | Candidate | Cost of keeping | Cost/risk of removing |
|---|-----------|-----------------|----------------------|
| R1 | **Heuristic classification cluster** — 11 services + 6 jobs (5,054 lines), builder branches, `scripts/section-extraction/` (1,123), ~8,900 test lines (§4.1) | Dual maintenance of every seam it touches; mode branches in builder/timeline/retry registry; CI time for ~8.9k test lines; permanent "which path ran?" ambiguity when debugging | Gated on phase 6 soak evidence (shadow report + eval + ~8 clean services). Fallback after removal is the designed one: manual review + segment confirmation. Four preparatory items (§4.2) must land first. **Recommend: yes, per the existing plan — this review adds the corrected file list.** |
| R2 | **Staged structure-merge workflow** (~950 lines, §4.6) | A second bespoke review flow (staging, resolution action, dedicated partial) for a collision case promotion makes rarer | If operators do use it to rescue mis-merges, collapsing to "merge + needs_review + diff note" loses the pre-merge safety. Needs Q1 answered; re-measure after promotion |
| R3 | **Canonical-conflict granular state** — 2 enums, 6 columns, dual JSON/column storage (§4.5) | Every import pays the recording ceremony; state service stays 167 lines; two sources of truth to keep consistent | Losing forensic granularity nobody currently reads. Mitigation: keep `canonical_conflict_history` in JSON as the audit trail, surface one reason string |
| R4 | **Regex date/service extraction in `OosEmailParserService`** (~300 lines, §4.7) | Two extraction mechanisms in one 432-line parser; regex maintenance for informal date formats | LLM call adds latency/cost (pennies at nano-model rates) and needs the deterministic date/service validation kept as the gate. Thresholds already exist |
| R5 | **`ServiceSectionStatus::Skipped`** (dead enum case + guardrail query + DB check constraint if present) | Trivial, but it advertises a workflow state that cannot occur | Confirm no historical rows hold `skipped` before dropping (Q5); needs a migration touch, so slightly above quick-win size |

## 7. Quick wins (<1 hour each)

1. **Update the phase 6 deletion list** in `LLM-FIRST-SERVICE-STRUCTURE-PIPELINE-2026-07-01.md` with
   the four corrections from §4.1–4.2 (projection services retained; merge policy/service off the
   list; `SongSectionAligner` whole; note the two type/registry moves) — prevents the next session
   rediscovering this audit.
2. **Move the `ClassifiedSection` PHPStan type** from `ServiceSectionClassifier` to
   `ServiceSectionSyncService` and update the two `@phpstan-import-type` references. Zero behaviour;
   unblocks classifier deletion.
3. **Move `OOS_REVIEW_FLAGS`/`OOS_REVIEW_REASONS`** from `SectionAlignmentBaselineRestorer` to the
   `Structure/` namespace (the validator is the natural home) with the restorer re-pointing at the
   new constant. Zero behaviour; unblocks restorer deletion.
4. **Drop the dead `AlignmentTriggerCalculator` import** (and stale docblock sentence) from
   `UnmatchedSongReviewApplicator` — after which the calculator is verifiably cluster-only.
5. **Fix the stale "review dashboard (until it retires)" docblock** on `ReviewsServiceSections`, and
   rename `ServiceReviewDashboardQuery` if the dashboard is confirmed gone.

## 8. Open questions for the user

1. **Pending structure merges (R2):** since the staged-merge flow shipped, roughly how many times
   has the inbox shown a "pending structure merge", and when it did, did you ever choose anything
   other than accepting the incoming email's structure?
2. **Canonical conflict detail (R3):** when a service shows as needing review after a re-import, do
   you ever need to know *why* (canonical changed vs conflicts vs both), or is the diff on the
   workbench enough? Would losing the detected/reopened distinction change how you work?
3. **Rollout status:** is production currently on `SERVICE_STRUCTURE_MODE=off` or has shadow been
   enabled since the stack merged? This determines whether phase 6 is weeks away or can start
   accumulating evidence now.
4. **Song OCR (opportunity 4):** are there services where a song is on-screen but never audibly
   sung/introduced (e.g. instrumental items) — i.e. does the video-OCR fallback ever produce the
   only match? If not, song matching can eventually shrink to LLM title + transcript slice.
5. **`ServiceSectionStatus::Skipped` (R5):** do any historical rows use `skipped` (production check:
   `service_sections.status = 'skipped'`)? If zero, the case and its guardrail query can go.
6. **Mailgun route health:** is the webhook the way OOS emails actually arrive week to week, or has
   `SubmitEmailText` manual paste become the de-facto path? (If paste dominates, the webhook's
   throttles/signature plumbing is defending an unused door — worth knowing before investing in R4.)

## 9. Out of scope, noted for later phases

- **Phase 1 (media pipeline):** whether `AnalyzeSegments`/RMS segmentation shrinks once
  `source_segment_ids` synthesis proves out in primary mode; `ProcessingPhaseRegistry`'s mode-aware
  retry offsets; the segment-confirmation UI's dependency on `LivestreamSegment` rows.
- **Phase 3 (sermons):** the extraction/publication tail (`ExtractSermon`,
  `SermonExtractionPlanResolver`, `SermonCandidateConfidenceService`) — consumed here, owned there.
- **Phase 4 (songs):** the "one song matcher" question — `MatchSongsFromTranscript`'s matching core
  (`SongLyricsMatchingService`) vs whatever the songs domain's `Sync/` reconcilers use; opportunity 4
  feeds into it.
- **Phase 6 (admin/Livewire):** ChurchServices' 9 components / 13 partials against the shared-trait
  question; `ReviewInboxQuery` (304 lines) shape; the 334-line `ChurchServiceFormData`.
- **Phase 7 (platform):** whether `config/service-tracking.php` merges into `media-processing.php`'s
  service blocks; `ChurchServiceItemSource`/metadata cast Data objects in the app/Data census.
- **Phase 9 (code quality):** `StructureEvaluateCommand` default-detector nit (§4.8); dead-import
  sweep beyond §4.10; `ChurchServiceReviewStateService` idiom polish if it survives R3.
