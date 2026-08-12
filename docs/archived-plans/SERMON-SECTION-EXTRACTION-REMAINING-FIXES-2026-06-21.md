# Section-Extraction Findings — Remaining Work (2026-06-21)

> **ARCHIVED 2026-07-05 — all work complete or superseded.** Workstream D (F15) and Part 3 (F2)
> were implemented and merged (statuses below). Part 2 — the LLM service-structure spike, the only
> then-outstanding item — was superseded by the LLM-first pipeline plan, whose phases 1–5 have
> shipped; the remaining retirement work is Workstream 1 of
> [the archived July simplification parent](JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md).
> Note: several classes this document describes (`StructuralSectionAligner`,
> `SectionAlignmentBaselineRestorer`, `SongSectionAligner`) are on that backlog's deletion list.

## Context

The 2026-06-20 findings (`docs/operations/section-extraction-findings-2026-06-20.md`) were triaged into
correctness-critical (P1), quality (P2), and traceability/gated (P3+) work. The **P1 and P2 items are now
implemented, tested, and pass all four quality gates** (Pint, PHPStan, parallel suite, Dusk):

- **F5/F3/F17/F10** — `SermonExtractionPlanResolver` ranked-evidence reading selection, max-pairing-gap,
  preamble demotion, over-long-sermon → manual review; plus a duration cap in
  `SermonCandidateConfidenceService`.
- **F12/F17/invariant** — `ResolveReadingReferences` benediction guard, `reading_reference_missing` flag, and
  the review-state ownership fix (clearing a flag now recomputes `needs_manual_review`).
- **F13/F18/F9** — `SongSectionAligner` duplicate-occurrence blocking and corroboration-required
  `song_name_reference_only`; `Song::matchKeyVariants()` "O/Oh" comparison-time variants.
- Stale `SectionExtractionScriptsTest` updated to the nine-scenario contract.

This document covered **three remaining pieces**, all deliberately deferred because each warranted focused
attention rather than being rushed alongside the correctness fixes:

1. **Workstream D — F15** presentation-item positional fallback (P3, traceability only). **✅ Implemented and
   merged** (`StructuralSectionAligner`, `SectionAlignmentBaselineRestorer`, `StructuralSectionAlignerTest`).
2. **Part 3 — F2** end-to-end children's-talk publication test (P3, test-only). **✅ Implemented and merged**
   (`tests/Feature/Operations/ChildrensTalkPublicationWorkflowTest.php`).
3. **Part 2 — the LLM service-structure spike** (gated; owns the classification-cluster findings
   **F1, F7, F11, F16**). **⏳ Still gated — the only outstanding work in this document.**

**Retracted / no-action:** F4, F6 (retracted); F8 (correct no-match on an OoS omission); **F14 deferred to
investigation** — the reading-reference extractor is designed to name passages from *unannounced* recited
content and never sees the OoS, so an "explicit reference spoken" signal is wrong; a genuine fix needs
semantic passage validation after inspecting the stored transcript + raw model response.

---

## Workstream D — F15: positional fallback for presentation-type OoS items (P3) — ✅ DONE

**Status.** Implemented and merged. The additive fallback pass lives in
`StructuralSectionAligner::applyPositionalFallback()` / `linkPositionalFallback()` (DP untouched);
`presentation_positional_fallback` is registered in **both** `SectionAlignmentBaselineRestorer::OOS_REVIEW_FLAGS`
and `OOS_REVIEW_REASONS`; and all four prescribed cases are covered in `StructuralSectionAlignerTest`
(single-candidate link, already-aligned negative, two-candidate ambiguity negative, rerun idempotency). The
original design notes below are retained for reference.

**Problem.** Presentation items with generic slide names ("Andrew Talk.pptx", "epap.pptx", "Isaiah.pptx",
"Slide1.JPG") never content-anchor, so `StructuralSectionAligner` leaves them as `item_gap` (unaligned). The
corresponding section (e.g. the children's talk) is correctly detected but carries no OoS linkage, hurting
traceability. This produces **no wrong extraction** — it is a traceability gap only.

**File.** `app/Services/ChurchService/StructuralSectionAligner.php`.

**Why it was deferred.** This class is a Needleman–Wunsch global aligner with carefully-tuned
conflict/gap/mismatch invariants (`optimalAlignment()` → `traceback()` → `applyPair()`, where only genuine
type conflicts count toward the structural-mismatch total). A naive change to the DP scoring risks regressing
those invariants and the existing `StructuralSectionAlignerTest`.

**Approach — an additive pass, leave the DP untouched.**
1. In `align()`, after the `foreach ($pairs ...) applyPair()` loop, collect the unaligned remainders directly
   from `$pairs`: the `item_gap` items and the `section_gap` sections (the pair kinds already distinguish
   these — no new DP state needed).
2. Add `applyPositionalFallback(array $itemGaps, array $sectionGaps, array $presentationDecisions): void`.
   For each unaligned **presentation/image** item that the `PresentationItemClassifier` resolved or *suspected*
   as a concrete type (ChildrensTalk / Notices / BibleReading), find an unaligned section of that compatible
   type. **Only link when there is exactly one unambiguous compatible unaligned section** — this conservatism
   avoids the wrong-link risk that made a broader heuristic unsafe.
3. The link is **low-confidence, flagged, traceability-only**: set `church_service_item_id`/`matched_item_id`,
   write `metadata.oos_alignment.positional_fallback = true`, add a new review flag
   `presentation_positional_fallback`, **do not** change `section_type`, **do not** boost confidence, and
   **do not** alter the structural-mismatch count. It must never affect sermon extraction.
4. Register `presentation_positional_fallback` in `SectionAlignmentBaselineRestorer::OOS_REVIEW_FLAGS` and
   `OOS_REVIEW_REASONS` so reruns clear it idempotently (this was the trap that bit F18 — see
   [the song-matching work]).

**Reuse.** `PresentationItemClassifier` (already injected) for the resolved/suspected type;
`resolvedItemType()`; `baseAlignmentMetadata()`; the `ReadsSectionMetadata` trait.

**Tests** (`tests/Integration/Services/StructuralSectionAlignerTest.php`):
- A generic "Andrew Talk.pptx" presentation item + one unaligned `childrens_talk` section → fallback links
  them with `presentation_positional_fallback`, low confidence, `section_type` unchanged.
- Negative: when the presentation item already aligned in the DP, the fallback does **not** fire.
- Negative: when **two** compatible unaligned sections exist, the ambiguous item stays unaligned (no guess).
- Rerun idempotency: the flag clears on a second alignment pass.

**Risk.** Low if kept additive and single-candidate-only. The blast radius is limited to previously-unaligned
items/sections; confirm the existing `StructuralSectionAlignerTest` and `OosAlignmentService` tests stay green.

---

## Part 3 — F2: end-to-end children's-talk publication test (P3, test-only) — ✅ DONE

**Status.** Implemented and merged as
`tests/Feature/Operations/ChildrensTalkPublicationWorkflowTest.php`. It drives the full prepare → approve →
publish chain (`PrepareSectionPublicationCandidates` → `ApproveSectionForPublication` →
`PublishApprovedServiceSection`) against a detected `childrens_talk` section, mocking only ffmpeg extraction and
the speaker-identification provider, and asserts a published `Sermon` with `content_type = childrens_talk` and
the section reaching `ServiceSectionPublicationStatus::Published` with `published_sermon_id` set. The original
design notes below are retained for reference.

**Gap.** The content-type assertion already exists
(`PublishApprovedServiceSectionTest::it_publishes_childrens_talk_sections_with_childrens_talk_content_type`).
The real, untested gap is the **full prepare → approve → publish workflow** — the local regression harness
stops after `ExtractSermon` and never runs `PrepareSectionPublicationCandidates`, approval, or
`PublishApprovedServiceSection`.

**Approach.** Add a feature test in the existing `tests/Feature/Operations/` directory that drives the workflow
end to end against a detected `childrens_talk` section: prepare candidates → approve → publish, asserting a
`Sermon` is created with `content_type = childrens_talk` and the section reaches
`ServiceSectionPublicationStatus::Published` with `published_sermon_id` set. Mock media/storage with
`Storage::fake('public')` as the existing publication tests do; reuse `SectionPublicationHandlerFactory` and
`SermonPublicationHandler` config wiring from `PublishApprovedServiceSectionTest`.

**Risk.** None to production — test-only.

---

## Part 2 — LLM service-structure spike (gated; owns F1, F7, F11, F16)

The classification-cluster findings (RMS bisecting songs/readings, micro-sections, catechism-vs-children's-talk,
under-segmentation) are best fixed structurally by the spike in
`docs/plans/LLM-SERVICE-SECTION-CLASSIFICATION-SPIKE-2026-06-19.md` rather than by accreting more heuristics. **Two
prerequisites are blocking and must land first** (both verified during the review):

- **Phase −1 — full-service transcript acquisition.** `TranscribeSpeechSegments`
  (`app/Jobs/TranscribeSpeechSegments.php`, the `whereNotIn` excluding `Song`/`Sermon`) skips exactly the
  blocks the LLM must read, and stores plain text rather than timestamped words. Produce a full-service,
  timestamped/chunked transcript independent of RMS classification. (Dovetails with the deferred
  `obs_localvocal_transcript_sourcing` work.)
- **Phase 0 — human-reviewed evaluation manifest.** The nine regression scenarios are explicitly *observed,
  mutable* baselines (testing guide), Gemini's notes are fallible, and Scenario E has none — they are **not**
  ground truth. Build a manifest of expected typed intervals, sermon boundaries, song occurrences, scripture
  references and tolerances so the spike's "30s / 90%" gate is meaningful.

**Corrections to carry into the spike (from the review):**
- **Keep deterministic OoS alignment downstream.** The spike schema has no OoS item id but `sync()` needs
  `church_service_item_id`; it cannot do 1:1 linkage or solve F15. Keep `AlignWithOos` /
  `StructuralSectionAligner` / `SongSectionAligner` on the LLM-produced sections (or add a validated
  `oos_item_id` with strict uniqueness before retiring them).
- **Binding defaults:** production `heuristic`; tests `mock`; **throw** on an unknown
  `section_structure.service` value (no silent mock fallback).
- The `heuristic` adapter must reproduce the **full** classification cluster, not just
  `ServiceSectionClassifier`.
- **Source-agnostic structural validation** at the `sync → extraction` boundary (duration / overlap / coverage
  / single primary sermon), so high-confidence LLM output cannot bypass the safety net. The duration half of
  this already exists from the F10 work (`SermonExtractionPlanResolver` decline + `SermonCandidateConfidenceService`
  cap sharing `max_sermon_duration_seconds`); extend it with overlap/coverage/single-sermon checks.
- **F11 prompt nudge** ("childrens_talk requires structural cues — children dismissed, parent-addressed
  language") belongs in the spike prompt, and can also be applied to the current
  `SpeechSectionClassificationService` prompt now as a near-zero-cost improvement.

**How the spike resolves the cluster (verify against the manifest in Phase 0):** F1/F16 (whole readings/songs,
no RMS bisection), F7 (no audio-cue micro-sections), F10 root-cause (segments inside the block), F11 (prompt).
**Fallback if the spike is deferred:** F1 via a `bible_reading` case in
`ClassifySpeechSections::mergeAdjacentSameTypeSections`; F16 via a post-match adjacent-song merge.

**Phasing (from the spike doc):** Phase −1 + Phase 0 (prerequisites) → Phase 1 interface/adapters/tests →
Phase 2 shadow wiring → Phase 3 promote/retire, gated on a real-data soak. Do not promote past `heuristic`
until Phase 0 clears the manifest gate.

---

## Verification (all remaining work)

1. **Per-change tests** via `vendor/bin/sail artisan test --compact --filter=...`.
2. **Regression harness** — re-run affected scenarios with `run-downstream.php` (tee output) and review
   baselines: F15 on `sep25`/`mar26` (generic `.pptx` items now linked, flagged); spike work against the
   Phase 0 manifest, not the mutable baselines.
3. **Four gates:** `vendor/bin/sail bin pint --dirty --format agent`, `vendor/bin/sail composer phpstan`,
   `vendor/bin/sail artisan test --compact --parallel`, `vendor/bin/sail artisan dusk`. (Note: the parallel
   suite has a known sermon-identity ordering flake — rerun once before treating a red as real.)

## Invariants to honour

- Any new review flag must be registered in `SectionAlignmentBaselineRestorer` (or cleared idempotently by its
  owning job) or reruns will not reset it.
- F15 is traceability-only: it must never change `section_type`, boost confidence, or influence extraction.
- The spike must not bypass the source-agnostic structural validation; `heuristic` stays the production default
  and fallback until parity is proven on real data.
- British English in all new user-facing strings/flags and test assertions; mock the AI via the existing
  `mock` bindings so CI never calls an external API.
