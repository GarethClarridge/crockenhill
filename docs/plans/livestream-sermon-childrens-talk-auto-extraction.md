# Plan: Auto-extract livestream sermons & children's talks when confidently detected

## Context

Real livestream uploads weren't reliably auto-extracting sermons or children's talks. (Songs were a separate, already-fixed issue: `LOCAL_WHISPER_URL` pointed at the dead `host.docker.internal:2022` instead of the running Docker service `http://whisper:8000`.) A full step-by-step run of the 2026-06-14 service against its known order of service (`ChurchService 790`) proved the pipeline now **detects everything correctly**: `ClassifySpeechSections` transcribes the long speech blobs and rewrites them into correctly-typed sub-sections — including a `sermon` section (≈40:20–69:43) and a `childrens_talk` section (≈8:46–23:56). But nothing auto-extracts: `ExtractSermon` bails to `manual_review_required`.

Goal: when a sermon/children's talk is detected with **genuinely high confidence**, auto-extract it; otherwise keep falling back to manual review (chosen policy: conservative). Cover **both** sermon and children's talk.

## Root cause (precise)

Auto-extraction requires `SermonExtractionPlanResolver::findPreferredSection()` to find a section with: matching `section_type`, `status=Identified`, `needs_manual_review=false`, **and `confidence ≥ 0.85`** (`ServiceSectionConfidence::HIGH_THRESHOLD`). When found, `ExtractSermon::guardAutoExtractionPolicy()` passes the `service_sections` plan straight through, bypassing the ambiguous segment-duration guard. Today a detected sermon can never satisfy this because **three** gates compound:

1. **Unconditional sermon review-forcing** — `app/Jobs/ClassifySpeechSections.php::payloadFromClassifiedSection()`: any section reclassified *into* `sermon` from a non-sermon original is stamped `needs_manual_review=true`, `review_reason='secondary_sermon_candidate'` — **regardless of confidence**. All livestream sermons are transcript-derived from `other` blobs, so this always fires.
2. **OOS structural-mismatch penalty** — `app/Services/ChurchService/StructuralSectionAligner.php::align()/markMismatch()`: the sermon has no OOS item (sermons never do; the talk is a bare "Ezekiel" image), so it's marked `oos_structure_mismatch` → `needs_manual_review=true` **and `confidence −= 0.20`**. (This −0.20 turns `low`=0.5 into the observed 0.3.)
3. **Moderate model confidence** — `app/Services/ChurchService/SpeechSectionClassificationService.php::applyConfidencePolicy()`: gpt-4o-mini returns 0.6–0.84 on these calls → level `low` (score 0.5), under the 0.85 bar even before the −0.20.

With #1 and #2 forcing review unconditionally, no model improvement alone can ever help — the unconditional gates must be relaxed first.

## Recommended approach

### Change A — relax the unconditional review-forcing for high-confidence sermon/talk

- **`ClassifySpeechSections::payloadFromClassifiedSection()`**: only force `secondary_sermon_candidate` review when the classification is **not** high-confidence *or* a conflicting RMS-detected primary sermon already exists (`LivestreamSegment::is_sermon_segment`). When the classifier is `high` and there's no conflicting primary, trust it (`needs_manual_review` stays false). Keep current behaviour for moderate/low confidence.
- **`StructuralSectionAligner`**: treat `Sermon` and `ChildrensTalk` as legitimately absent from the OOS — exclude them from the structural walk (the same way `Song` is already filtered at lines 42–49), so they are never `markMismatch()`-ed, never get `oos_structure_mismatch`, and never take the −0.20 penalty. Non-sermon/talk structural review behaviour is unchanged.
- Verify `AlignmentTriggerCalculator` / `SectionAlignmentBaselineRestorer` don't re-introduce the flag for these types after the walk.

### Change B — let the classifier reach high confidence on clear cases (the conservative lever)

- Make the speech-classification model explicit and upgrade it from `gpt-4o-mini` to a stronger model via `config('media-processing.section_classification.model')` (used in `SpeechSectionClassificationService`). Keep the 0.85 bar unchanged.
- Refine the system/user prompt (`SpeechSectionClassificationService::buildSystemPrompt()/buildUserPrompt()`) to be decisive (high confidence) for an unambiguous sermon / children's talk while staying conservative on **boundaries**.

**Net effect:** a clearly-preached sermon (or clearly-introduced children's talk) → classifier `high` (review=false, score 0.9) → not force-flagged (A) → not OOS-penalised (A) → `findPreferredSection()` matches → `service_sections` plan → bypasses the ambiguous segment guard → **auto-extracts**. Anything below high confidence still routes to manual review.

## Files to modify
- `app/Jobs/ClassifySpeechSections.php` — conditional `secondary_sermon_candidate` forcing.
- `app/Services/ChurchService/StructuralSectionAligner.php` — exclude `Sermon`/`ChildrensTalk` from mismatch marking/penalty.
- `app/Services/ChurchService/SpeechSectionClassificationService.php` — model config + prompt wording.
- `config/media-processing.php` — confirm/wire `section_classification.model` (key already present).
- Tests under `tests/` (see below).

## Reuse (don't reinvent)
- `App\Support\ServiceSectionConfidence` (`HIGH_THRESHOLD`, `scoreForLevel`, `resolve`, `clamp`) — keep as the single confidence source; do not hardcode new thresholds.
- Existing `service_sections` extraction path: `SermonExtractionPlanResolver::resolve()` + `ExtractSermon::guardAutoExtractionPolicy()` already pass through confident section plans — we only make a section qualify; no extraction-path changes.
- `SermonCandidateConfidenceService` stays as the fallback for non-section/baseline cases.

## Verification (fast — the expensive transcripts already exist)
The ~50-min Whisper transcripts are persisted on `MediaProcessingLog 889`'s sections, so we can iterate **without re-transcribing**:
1. Reset log 889 to `processing`; re-run `ClassifySpeechSections → OosAlignmentService → ExtractSermon` via a `storage/scratch` script with `sermon_disk`/`transcript_disk` forced to `local`.
2. Assert: the sermon section ends `section_type=sermon`, `needs_manual_review=false`, `confidence ≥ 0.85`; `ExtractSermon` creates a `Sermon` (content_type=sermon); the children's-talk section likewise yields a children's-talk record.
3. Automated tests:
   - `StructuralSectionAligner`: a `sermon`/`childrens_talk` section with no OOS counterpart is **not** `oos_structure_mismatch`-flagged and keeps its confidence.
   - `ClassifySpeechSections`: a high-confidence transcript sermon is **not** force-flagged `secondary_sermon_candidate`; a moderate/low one still is; a conflicting RMS primary still forces review.
   - `SermonExtractionPlanResolver`: returns a `service_sections` plan when a high-confidence review-free sermon section exists.
   - Feature test through `ExtractSermon` (classifier mocked to return high confidence) asserts a `Sermon` is auto-created with no `manual_review`.
4. Quality gates (AGENTS.md): `vendor/bin/sail bin pint --dirty`, `vendor/bin/sail composer phpstan`, `vendor/bin/sail artisan test --compact --parallel` (+ `dusk` if UI-adjacent).

## Risks / notes
- Confidence is probabilistic: a stronger model raises but can't guarantee ≥0.85 — acceptable, since sub-threshold falls back to manual review by design. Confirm on this real service.
- Boundary imperfection (sermon cut at 67:00 vs true ~69:13; talk over-wide) is a **separate** classifier-precision problem, out of scope here; quality/visibility gating before publication remains the safety net (extraction ≠ publishing).
- Preserve `secondary_sermon_candidate` review when an RMS primary sermon also exists (avoid duplicate sermons) — only relax for high-confidence + no conflicting primary.
- **Do not tear down log 889 / ChurchService 790** until the fix is verified — they are the test fixture. Final teardown (records, staged temp video, `storage/scratch/phase2_*` and `oos-test`) happens after.
- Optional incidental fixes found during diagnosis: Guzzle string `timeout` (breaks on Guzzle 8) and a Blade float→int deprecation in `ExtractSermon`'s view.
