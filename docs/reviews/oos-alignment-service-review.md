# OosAlignmentService Behavioral Inventory

## Purpose

This note is the current-state behavioral inventory for `App\Services\OosAlignmentService` and the collaborators that materially affect its behavior:

- `App\Jobs\AlignWithOos`
- `App\Jobs\ReconcileServiceSections`
- `App\Services\ServiceSectionReviewTriggerEvaluator`
- `App\Services\MediaProcessingIdentityResolver`
- `App\Services\ChurchServiceReviewStateService`

The goal of this document is to preserve the existing contract before refactoring. It intentionally focuses on observed behavior, mutation boundaries, and downstream dependencies. The refactor shape now lives in the companion note [oos-alignment-refactor-proposal.md](./oos-alignment-refactor-proposal.md).

## Executive Summary

`OosAlignmentService` is still the effective coordinator for the entire OoS alignment workflow. In one public method it:

1. resolves the target `ChurchService`
2. restores `ServiceSection` baseline state from prior OoS metadata
3. performs song matching
4. performs structural matching and selective reclassification
5. applies confidence and review state changes
6. asks a collaborator to derive service-level review triggers
7. persists section changes
8. syncs `ChurchService.needs_review` and `ChurchService.import_metadata.review_triggers`

The highest-risk hidden contracts are:

- `aligned = false` is not write-free. If church-service resolution succeeds and the method later bails because items or sections are missing, `MediaProcessingLog.church_service_id` is still written inside the same transaction and commits atomically with the empty result.
- `ServiceSectionReviewTriggerEvaluator` is not pure. It mutates unmatched song sections before returning trigger strings.
- Rerun safety depends on `metadata.oos_alignment.base_*` plus OoS-owned flag clearing.
- Confidence is persisted redundantly in both `service_sections.confidence` and section metadata.
- The persisted OoS metadata is consumed by reporting, timeline, and review UI code, so it is a public contract in practice.
- The song matcher is greedy and order-sensitive, not globally optimal.
- The current string scorer is `similar_text()`, which is behaviorally significant and should not be swapped casually.

## Lifecycle and Transaction Boundary

### Call sequence

`alignForProcessingLog()` currently executes in this order:

1. Refresh the processing log with `MediaProcessingLog::query()->find($processingLog->id)` before opening the transaction.
2. Enter `DB::transaction(...)`.
3. Resolve the target `ChurchService` with `resolveChurchService()`.
4. Read ordered `ChurchServiceItem` rows.
5. Read and `lockForUpdate()` the `ServiceSection` rows for the processing log.
6. Capture pre-alignment state for late-arrival comparison.
7. Restore every section to its stored pre-OoS baseline.
8. Run song alignment.
9. Run structural alignment.
10. Run review-trigger evaluation, which may further mutate unmatched song sections.
11. Persist confidence metadata and `save()` each section.
12. Recompute and persist parent review state on `ChurchService`.
13. Return summary metrics.

### What is atomic

Everything from step 2 onward is inside one transaction. That includes:

- any `MediaProcessingLog.church_service_id` write performed by `resolveChurchService()`
- all `ServiceSection` mutations and saves
- the `ChurchService` review-state write

This matters because the "empty result but linked log" behavior is transactional, not a dangling write outside the transaction.

### What is not locked

- `MediaProcessingLog` is refreshed before the transaction and is not locked for update.
- `ChurchService` is not locked for update.
- `ChurchServiceItem` rows are read but not locked.

There is also a refresh-then-lock gap: the code refreshes the processing log before the transaction begins, then only later locks `ServiceSection` rows inside the transaction. A concurrent process could modify sections in that window.

### Empty-result cases

The method returns `emptyResult()` when:

- the refreshed processing log is gone
- no church service can be resolved
- the resolved church service has no items
- the processing log has no sections

Important nuance:

- If church-service resolution succeeds but items are empty or sections are empty, the method still may have already persisted `MediaProcessingLog.church_service_id` inside the transaction.

## Persistence Mechanics

### Which writes use `save()` vs `saveQuietly()`

`ServiceSection`

- persisted with regular `save()`
- model events fire

`MediaProcessingLog`

- linked with `saveQuietly()`
- model events are suppressed

`ChurchService`

- review state is persisted with `saveQuietly()`
- model events are suppressed

This distinction is behaviorally relevant. The current app registers a `ChurchServiceObserver` in `ModelObserverServiceProvider`, and quiet saves bypass it entirely, although that observer currently only reacts when `date` or `service` changes. There is no dedicated `ServiceSection` or `MediaProcessingLog` observer registered there today, but `save()` still dispatches normal Eloquent model events.

### `forceFill()` usage

The service uses `forceFill()` before quiet saves for:

- `MediaProcessingLog.church_service_id`
- `ChurchService.needs_review`
- `ChurchService.import_metadata`

Today those attributes are already fillable on the models, so `forceFill()` is not required for correctness right now. It still encodes intent: these writes should bypass mass-assignment policy even if the models become stricter later.

### `fresh()` reload behavior

When the caller passes an explicit `ChurchService`, `resolveChurchService()`:

1. may persist the processing-log link
2. then returns `$churchService->fresh()`

That reload discards any stale in-memory state from the passed model. It is relevant because later review sync reads `import_metadata` from the reloaded instance. Removing the reload during refactoring could accidentally make review-state recomputation depend on stale model data.

## Read/Write Inventory

| Concern | Reads | Writes | Notes |
| --- | --- | --- | --- |
| Church-service identity | `media_processing_logs`, `processing_metadata`, `church_services` | `media_processing_logs.church_service_id` | Write can commit even when `aligned = false`. |
| OoS items | `church_service_items` ordered by `position`, `id` | none | Read-only inputs. |
| Detected sections | `service_sections` ordered by `section_order`, `id` with `lockForUpdate()` | `church_service_item_id`, `section_type`, `title`, `confidence`, `needs_manual_review`, `metadata` | Main mutable aggregate. |
| Trigger evaluation | section metadata and state | section metadata, confidence, manual-review bit | Evaluator is impure. |
| Parent review sync | `church_services.import_metadata`, config thresholds | `church_services.needs_review`, `church_services.import_metadata.review_triggers` | Quiet save suppresses observer/model events. |

## Baseline Restoration and Idempotency

Before applying new OoS decisions, `prepareSectionForAlignment()` restores section state from `metadata.oos_alignment.base_*` and clears OoS-owned state.

Restored fields:

- `confidence`
- `needs_manual_review`
- `title`
- `church_service_item_id`

Special-case behavior:

- If `metadata.classification_mode === 'openlp_aligned'`, title and matched item are reset to `null` instead of using stored base values.

Cleared fields:

- `metadata.oos_alignment`
- `metadata.song_id`
- `metadata.reading_reference`
- OoS-owned review flags
- OoS-owned review reasons when no non-OoS flag remains

Preserved fields:

- non-OoS review flags
- non-OoS review reasons
- unrelated metadata

This restore-and-reapply pattern is the main idempotency mechanism. A refactor that starts from current in-memory values instead of `base_*` will change rerun behavior.

## Confidence Semantics

`ServiceSectionConfidence` is load-bearing for both alignment decisions and rerun safety.

### Resolution order

`ServiceSectionConfidence::resolve()` uses:

1. explicit `section.confidence` when numeric
2. `metadata.confidence_score` when numeric
3. `metadata.confidence_level`
4. fallback level `none`

### Numeric defaults and thresholds

- `scoreForLevel('high')` => `0.90`
- `scoreForLevel('low')` => `0.50`
- `scoreForLevel('none')` => `0.10`
- high threshold => `0.85`
- low threshold => `0.50`

The helper also:

- clamps to `[0.0, 1.0]`
- rounds to 3 decimals

### Persistence contract

After alignment, `persistConfidenceLevel()` writes:

- `service_sections.confidence`
- `metadata.confidence_level`
- `metadata.confidence_score`

That duplication is deliberate current behavior, not incidental debug output.

## Song Alignment Behavior

### Matching algorithm

Song alignment is a greedy best-match loop:

1. derive ordered OoS song items
2. derive ordered detected song sections
3. for each OoS song item, choose the unmatched section with the highest score
4. accept the match only when the best score is at least `0.85`

This is not a globally optimal assignment algorithm. Different input ordering can change outcomes, and that order-sensitivity is part of current behavior.

### Scoring inputs

Item candidates come from:

- `openlp_search_title`
- `source_title`
- `title`

Section candidates come from:

- `section.title`
- `metadata.oos_alignment.song_title_matched`
- `metadata.oos_alignment.matched_item_title`
- `metadata.linked_song_canonical_key`

All candidates are normalized through `Song::canonicalizeKey()`:

- strip any `@...` suffix
- lowercase
- normalize whitespace

### Scoring function

The matcher returns:

- `1.0` for exact canonical string equality
- otherwise the best `similar_text()` similarity ratio

`similar_text()` is therefore a behavioral dependency. Replacing it with another distance metric, even a plausible one, would change match outcomes.

### Dead-looking `song_id` fast path

`songMatchScore()` contains a shortcut for matching on `item.song_id` against `section.metadata.song_id`.

In ordinary reruns, `prepareSectionForAlignment()` clears top-level `metadata.song_id` before song scoring runs. In practice the matching pass is therefore title/canonical-key driven on reruns, even though the method signature suggests an ID-based shortcut.

### Confirmed match side effects

A confirmed match:

- links `church_service_item_id`
- overwrites `title` with the OoS title
- stores top-level `metadata.song_id`
- stores `oos_alignment.song_match_type = confirmed`
- stores `oos_alignment.song_match_score`
- stores `oos_alignment.song_match_strategy = normalized_title`
- stores `oos_alignment.song_title_matched`
- raises confidence into at least the high band

### Inferred song-label side effects

Order-based inferred labeling only happens when:

- unmatched song sections remain
- unmatched OoS song items remain
- none of the remaining sections already has song-title evidence
- each remaining OoS item yields a unique canonical title candidate

An inferred label:

- links `church_service_item_id`
- copies the OoS title into `title`
- stores `song_match_type = inferred`
- does not store top-level `metadata.song_id`
- sets manual review
- clamps confidence into `0.70..0.84`

## Structural Alignment Behavior

Structural alignment walks ordered non-song sections against ordered non-song OoS items.

Possible outcomes:

- direct type match
- `OTHER` reclassified to an OoS-authoritative type
- weak presentation hint only
- mismatch on the section side
- mismatch on the item side
- terminal type mismatch

### Item-type resolution

Resolved OoS type precedence:

1. `item.metadata.section_type` when valid
2. `type = songs` => `SONG`
3. `type = bibles` => `BIBLE_READING`
4. `type = presentations` => explicit or strong presentation decision, otherwise `OTHER`
5. `type = custom` => regex-based title inference
6. everything else => `OTHER`

### OoS-authoritative reclassification

Only these OoS-resolved types are considered strong enough to reclassify a detected `OTHER` section:

- `CHILDRENS_TALK`
- `BIBLE_READING`
- `PRAYER`
- `NOTICES`
- `WELCOME`

`SERMON` is intentionally excluded.

### Presentation evidence tiers

Presentation items use a three-tier model:

- `explicit`: trusted `metadata.section_type`
- `strong`: title keyword inference
- `weak`: position-only hint

Weak presentation evidence writes metadata hints but does not reclassify the section and does not itself force manual review.

## Review-Flag Behavior

### OoS-owned section flags

The alignment pass treats these as fully owned and recalculated on every run:

- `oos_structure_mismatch`
- `unmatched_song_section`
- `song_alignment_inferred`
- `ambiguous_childrens_talk`
- `inferred_childrens_talk`

The same set is treated as OoS-owned review reasons.

### Section-level review state

Stored on `ServiceSection`:

- `needs_manual_review`
- `metadata.review_flags`
- `metadata.review_reason`

### Service-level review state

Stored on `ChurchService`:

- `needs_review`
- `import_metadata.review_triggers`

### Mutation paths

`applyMatchedItem()`

- clears OoS-owned review flags
- clears OoS-owned review reason if no flags remain
- preserves non-OoS flags

`markMismatch()`

- adds `oos_structure_mismatch`
- sets `review_reason = oos_structure_mismatch`
- forces manual review
- decreases confidence by `0.20`

`applyInferredSongItem()`

- adds `song_alignment_inferred`
- sets `review_reason = song_alignment_inferred`
- forces manual review

`applyPresentationDecisionMetadata()`

- always writes inference metadata
- adds `inferred_childrens_talk` for strong childrens-talk inference
- adds `ambiguous_childrens_talk` when multiple childrens-talk items resolve
- may override `review_reason` to `ambiguous_childrens_talk`

`ServiceSectionReviewTriggerEvaluator::evaluate()`

- mutates unmatched songs to add `unmatched_song_section`
- sets `song_match_type = unmatched` if it is absent
- forces manual review on those sections
- decreases confidence by `0.10`
- returns trigger strings

The evaluator name undersells how much state it mutates.

## Parent Review Sync Behavior

`syncChurchServiceReviewState()` removes or rewrites `import_metadata.review_triggers`, then sets `needs_review` true when any of these are true:

- there are alignment review triggers
- any section still needs manual review
- email-import confidence is in the manual-review band
- `ChurchServiceReviewStateService` reports an outstanding canonical conflict

Important consequence:

- `review_triggers = []` does not mean `needs_review = false`.

## Downstream Contracts

The persisted OoS metadata is already consumed elsewhere.

### Reporting

`PublicSongUsageService` only counts detected livestream songs when:

- `section_type = song`
- `metadata.oos_alignment.song_match_type = confirmed`

Confirmed vs inferred is therefore a reporting contract.

### Review UI

The review dashboard reads:

- `needs_manual_review`
- `review_reason`
- `review_flags`
- `oos_alignment.song_match_type`

Changing those keys changes the admin review experience.

### Timeline and audit output

`ServiceRecordTimeline` reads `metadata.oos_alignment`, including expected-item and mismatch context. That metadata functions as a persisted audit trail.

## Collaborator Notes

### `AlignWithOos`

- queue entrypoint for active livestream processing
- logs step start/complete/skipped state
- treats `aligned = false` as skipped rather than failed

### `ReconcileServiceSections`

- late-arrival entrypoint for completed livestream logs
- validates log identity against the target church service
- passes `lateArrival: true`

### `MediaProcessingIdentityResolver`

- small, cohesive resolver for extracted date/service identity
- already looks like a clean boundary

### `ServiceSectionReviewTriggerEvaluator`

- captures alignment snapshots
- derives trigger strings
- mutates unmatched song sections

### `ChurchServiceReviewStateService`

- keeps canonical-conflict reopening logic out of the alignment class
- current alignment code still owns adjacent review-sync policy around it

## Characterization Coverage Before Refactoring

This section now references actual existing test methods rather than paraphrased labels.

### Existing anchor tests

Song alignment

- `tests/Unit/Services/OosAlignmentServiceTest.php::it_matches_song_sections_by_title_instead_of_position`
- `tests/Unit/Services/OosAlignmentServiceTest.php::it_applies_a_low_confidence_oos_label_to_titleless_song_sections_and_keeps_them_under_review`
- `tests/Unit/Services/OosAlignmentServiceTest.php::it_marks_unmatched_detected_song_sections_with_an_explicit_unmatched_state`
- `tests/Unit/Services/OosAlignmentServiceTest.php::it_aligns_song_sections_successfully_even_when_the_song_catalog_entry_is_missing`

Structural alignment

- `tests/Unit/Services/OosAlignmentServiceTest.php::it_enriches_bible_readings_and_raises_confidence_when_structure_agrees`
- `tests/Unit/Services/OosAlignmentServiceTest.php::it_does_not_force_structural_matches_to_high_confidence_when_the_base_confidence_is_very_low`
- `tests/Unit/Services/OosAlignmentServiceTest.php::it_reclassifies_an_other_section_to_childrens_talk_when_the_oos_item_signals_it`
- `tests/Unit/Services/OosAlignmentServiceTest.php::it_does_not_reclassify_an_other_section_when_the_oos_item_also_resolves_to_other`
- `tests/Unit/Services/OosAlignmentServiceTest.php::it_only_uses_title_based_type_inference_for_custom_items`

Presentation and childrens-talk review

- `tests/Unit/Services/OosAlignmentServiceTest.php::it_reclassifies_a_speech_section_to_childrens_talk_when_a_post_song_presentation_title_signals_it`
- `tests/Unit/Services/OosAlignmentServiceTest.php::it_classifies_a_presentation_titled_notices_as_notices_via_strong_title_match`
- `tests/Unit/Services/OosAlignmentServiceTest.php::it_does_not_reclassify_a_generic_post_song_presentation_to_childrens_talk`
- `tests/Unit/Services/OosAlignmentServiceTest.php::it_marks_title_inferred_childrens_talks_for_manual_review`
- `tests/Unit/Services/OosAlignmentServiceTest.php::it_allows_explicit_presentation_section_type_to_bypass_manual_review`
- `tests/Unit/Services/OosAlignmentServiceTest.php::it_records_suspected_childrens_talk_metadata_without_reclassifying_when_evidence_is_weak`
- `tests/Unit/Services/OosAlignmentServiceTest.php::it_clears_ambiguous_childrens_talk_when_the_oos_is_no_longer_ambiguous`
- `tests/Unit/Services/OosAlignmentServiceTest.php::it_clears_inferred_childrens_talk_flags_when_a_section_no_longer_matches_that_rule`
- `tests/Unit/Services/OosAlignmentServiceTest.php::it_marks_the_parent_service_for_review_when_childrens_talk_inference_requires_manual_review`
- `tests/Unit/Services/OosAlignmentServiceTest.php::it_flags_for_review_when_multiple_post_song_presentations_exist`

Trigger evaluator

- `tests/Unit/Services/ServiceSectionReviewTriggerEvaluatorTest.php::it_triggers_unmatched_song_sections_and_updates_metadata`
- `tests/Unit/Services/ServiceSectionReviewTriggerEvaluatorTest.php::it_triggers_oos_structure_mismatch`
- `tests/Unit/Services/ServiceSectionReviewTriggerEvaluatorTest.php::it_triggers_late_oos_alignment_changed`
- `tests/Unit/Services/ServiceSectionReviewTriggerEvaluatorTest.php::it_triggers_too_many_low_confidence_sections`

Parent review sync

- `tests/Unit/Services/OosAlignmentServiceTest.php::it_clears_stale_alignment_review_triggers_when_the_service_now_aligns_cleanly`
- `tests/Unit/Services/OosAlignmentServiceTest.php::it_preserves_existing_import_review_flags_when_alignment_triggers_clear`
- `tests/Unit/Services/OosAlignmentServiceTest.php::it_does_not_clear_review_reopened_for_an_outstanding_canonical_conflict`

Job and pipeline entrypoints

- `tests/Unit/Jobs/AlignWithOosTest.php::it_aligns_existing_sections_when_a_matching_service_exists`
- `tests/Unit/Jobs/AlignWithOosTest.php::it_no_ops_without_a_matching_church_service`
- `tests/Feature/ChurchServicePipelineAlignmentTest.php::pipeline_without_oos_keeps_audio_only_sections_usable`
- `tests/Feature/ChurchServicePipelineAlignmentTest.php::pipeline_with_oos_enriches_detected_song_sections_after_audio_first_classification`
- `tests/Feature/ChurchServicePipelineAlignmentTest.php::late_oos_reconciliation_updates_existing_sections_without_full_reprocessing`

### Behavior that still needs explicit characterization

- explicit test that a resolved church-service link is persisted even when `aligned = false` because items are missing
- explicit test that the same link is persisted even when `aligned = false` because sections are missing
- rerun test proving baseline restore prevents confidence compounding and clears stale OoS-owned flags only
- rerun test proving non-OoS review flags survive alignment
- legacy `classification_mode = openlp_aligned` reset behavior
- test covering `metadata.linked_song_canonical_key` as a positive song-match candidate
- no-op late-arrival test proving unchanged before/after state does not emit `late_oos_alignment_changed`
- tests for the structural lookahead branches:
  - expected type exists later among remaining sections
  - current section type exists later among remaining items
  - neither lookahead applies, so terminal mismatch is recorded

## Refactor Hazards to Preserve

- The processing-log link write and the empty result can commit together.
- Reruns must restore `base_*` state before applying new OoS decisions.
- Non-OoS review flags must survive reruns.
- Inferred songs must remain below the high-confidence threshold and must not set top-level `metadata.song_id`.
- Confirmed songs must keep `song_match_type = confirmed` for downstream reporting.
- `review_triggers` may clear while `ChurchService.needs_review` remains true.
- The current song matcher is greedy and order-sensitive.
- The current string-matching function is `similar_text()`.

## Companion Proposal

The extraction seams, smaller architecture proposal, and justified extraction order now live in [oos-alignment-refactor-proposal.md](./oos-alignment-refactor-proposal.md).
