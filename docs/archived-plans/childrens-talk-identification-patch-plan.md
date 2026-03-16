# Childrens Talk Identification Patch Plan

## Goal

Patch the OoS alignment flow so that:

1. weak `presentations` heuristics do not silently promote sections to `childrens_talk`
2. OoS-owned childrens-talk review flags are recalculated cleanly on every alignment run
3. any section left in manual review by OoS alignment also surfaces at the `ChurchService` level

This plan is scoped to the alignment/review pipeline only. It does not change publication, extraction, or admin UX beyond making their inputs more reliable.

## Summary Of The Current Problems

### 1. Post-song `presentations` is treated as proof of `childrens_talk`

`OosAlignmentService::classifyPresentationItems()` currently maps:

- pre-first-song `presentations` -> `notices`
- post-first-song `presentations` -> `childrens_talk`

That value then flows through `resolvedItemType()` and can reclassify an `OTHER` section during alignment.

This is too aggressive because `presentations` is only a container/plugin type from OpenLP. It can hold a childrens talk, but it can also hold notices, prayer prompts, interview slides, videos, liturgy, or other generic material. Position alone is weak evidence.

### 2. `ambiguous_childrens_talk` is not treated as a recalculated OoS flag

The alignment reset path clears a few older OoS review flags, but not the new childrens-talk ambiguity flag. Once it is written into `metadata.review_flags`, later clean alignment runs can preserve it unintentionally.

### 3. Section-level manual review does not reliably make the parent service reviewable

When OoS alignment marks a section `needs_manual_review = true`, the parent `ChurchService` can still end the run with `needs_review = false` if no evaluator trigger was produced. That leaves section-level blockers visible in the review dashboard but missing from service-level review counts and filters.

## Patch Strategy

## 1. Replace Hard Presentation-Type Promotion With Evidence Tiers

### Target file

- `app/Services/OosAlignmentService.php`

### Implementation approach

Replace the current `presentation item id -> ServiceSectionType` map with a richer per-item decision payload.

Suggested private array shape:

```php
/**
 * @return array<int, array{
 *     resolved_type: ServiceSectionType,
 *     suspected_type: ServiceSectionType|null,
 *     evidence: 'explicit'|'strong'|'weak',
 *     requires_review: bool,
 *     review_flag: string|null,
 *     reason: string
 * }>
 */
```

No new enum or value object is required for the first patch; keep it private to `OosAlignmentService` unless the shape starts leaking elsewhere.

### Decision rules

For `ChurchServiceItem::$type === 'presentations'`:

1. `metadata.section_type` present and valid
   - `resolved_type = metadata.section_type`
   - `evidence = explicit`
   - `requires_review = false`
   - This is the only no-review path for inferred childrens talks.

2. Title clearly indicates childrens talk
   - Match current children wording (`children`, `children's`, `childrens talk`)
   - `resolved_type = CHILDRENS_TALK`
   - `evidence = strong`
   - `requires_review = true`
   - `review_flag = inferred_childrens_talk`

3. Title clearly indicates notices
   - Match `notices`, `notice`, `announcements`, `announcement`
   - `resolved_type = NOTICES`
   - `evidence = strong`
   - `requires_review = false`
   - This is lower-risk than childrens talk because it does not trigger speaker/publication-specific handling.

4. Otherwise, if item is before the first song
   - `resolved_type = OTHER`
   - `suspected_type = NOTICES`
   - `evidence = weak`
   - `requires_review = false`

5. Otherwise, if item is after the first song
   - `resolved_type = OTHER`
   - `suspected_type = CHILDRENS_TALK`
   - `evidence = weak`
   - `requires_review = false`

### Behaviour change

Weak evidence should never reclassify a section type.

That means:

- a generic post-song presentation titled `Prayer` stays `OTHER`
- a generic post-song presentation titled `Interview` stays `OTHER`
- a post-song presentation explicitly titled `Children's Talk` can become `CHILDRENS_TALK`, but should be review-required unless the source explicitly set `metadata.section_type`

### Alignment changes

Update `resolvedItemType()` so that weak presentation heuristics resolve to `ServiceSectionType::OTHER`.

This keeps ordering intact during structural alignment without over-promoting the section type.

Then update `alignStructuralSections()` so that:

1. explicit and strong presentation decisions can still drive reclassification of an `OTHER` section
2. weak decisions only attach metadata hints and do not alter `section_type`
3. strong childrens-talk decisions set:
   - `needs_manual_review = true`
   - `metadata.review_flags[] = 'inferred_childrens_talk'`
   - `metadata.review_reason = 'inferred_childrens_talk'` when there is no stronger OoS-owned reason already present

### Metadata to write

Whenever a presentation item participates in a structural match, store a small trace in `metadata['oos_alignment']`, for example:

```php
'presentation_inference' => [
    'resolved_type' => 'childrens_talk',
    'suspected_type' => null,
    'evidence' => 'strong',
    'reason' => 'presentation_title_children_keyword',
]
```

For weak cases:

```php
'presentation_inference' => [
    'resolved_type' => 'other',
    'suspected_type' => 'childrens_talk',
    'evidence' => 'weak',
    'reason' => 'post_first_song_presentation',
]
```

This gives the review UI and future debugging enough context without mutating the section into the wrong domain type.

### Existing tests to replace or update

The current test asserting that any pre-first-song presentation becomes `NOTICES` should be rewritten.

Replace it with:

- generic pre-first-song `presentations` item stays `OTHER` and stores a suspected `NOTICES` hint
- pre-first-song item explicitly titled `Notices` becomes `NOTICES`

### New tests to add

In `tests/Unit/Services/OosAlignmentServiceTest.php`:

- `it_does_not_reclassify_a_generic_post_song_presentation_to_childrens_talk`
- `it_marks_title_inferred_childrens_talks_for_manual_review`
- `it_allows_explicit_presentation_section_type_to_bypass_manual_review`
- `it_records_suspected_childrens_talk_metadata_without_reclassifying_when_evidence_is_weak`

## 2. Recalculate OoS-Owned Childrens-Talk Flags From Scratch

### Target file

- `app/Services/OosAlignmentService.php`

### Implementation approach

Introduce a single source of truth for OoS-owned review flags and reasons.

Suggested private constants:

```php
private const OOS_REVIEW_FLAGS = [
    'oos_structure_mismatch',
    'unmatched_song_section',
    'song_alignment_inferred',
    'ambiguous_childrens_talk',
    'inferred_childrens_talk',
];

private const OOS_REVIEW_REASONS = [
    'oos_structure_mismatch',
    'unmatched_song_section',
    'song_alignment_inferred',
    'ambiguous_childrens_talk',
    'inferred_childrens_talk',
];
```

Then use those constants in both places that currently do partial cleanup:

1. `prepareSectionForAlignment()`
2. `applyMatchedItem()`

### Required code changes

1. Add a helper that removes OoS-owned flags from `metadata.review_flags`
2. If `metadata.review_reason` is one of the OoS-owned reasons and no replacement is being written during this run, unset it
3. Remove stale `presentation_inference` metadata from the previous run before recalculating

This ensures that every alignment pass rebuilds OoS alignment state from the latest items/sections instead of accumulating stale childrens-talk flags.

### Ambiguity rule

Revise the ambiguity count to follow the new evidence tiers.

Recommended rule:

- only count items whose presentation decision resolves to `CHILDRENS_TALK`
- if more than one such item exists, any section promoted to `CHILDRENS_TALK` during this run gets:
  - `review_flags[] = 'ambiguous_childrens_talk'`
  - `review_reason = 'ambiguous_childrens_talk'` unless a more specific OoS-owned reason is preferred
  - `needs_manual_review = true`

Weak post-song presentation hints should not contribute to ambiguity by themselves because they are no longer being promoted.

### New tests to add

In `tests/Unit/Services/OosAlignmentServiceTest.php`:

- `it_clears_ambiguous_childrens_talk_when_the_oos_is_no_longer_ambiguous`
- `it_clears_inferred_childrens_talk_flags_when_a_section_no_longer_matches_that_rule`

This test should run alignment twice in sequence against the same section:

1. first with two childrens-talk-resolving presentation items
2. then with one remaining childrens-talk-resolving item

Expected result after the second run:

- no `ambiguous_childrens_talk` flag
- `needs_manual_review` only remains true if another blocker still exists
- no stale `review_reason` from the earlier alignment

## 3. Ensure Section-Level Manual Review Propagates To Service-Level Review

### Target files

- `app/Services/ServiceSectionReviewTriggerEvaluator.php`
- `app/Services/OosAlignmentService.php`

### Implementation approach

Add an explicit review trigger when any aligned section still needs manual review at the end of evaluation.

Suggested trigger name:

- `manual_review_sections`

This is intentionally generic. It covers childrens-talk inference, OoS mismatch cleanup, and future section-level blockers without needing a bespoke service-level trigger for every reason.

### Required code changes

In `ServiceSectionReviewTriggerEvaluator::evaluate()`:

1. after all per-section updates, check whether any section has `needs_manual_review === true`
2. if yes, append `manual_review_sections`

In `OosAlignmentService::syncChurchServiceReviewState()`:

1. no structural change is required if `manual_review_sections` is always emitted correctly
2. optionally add a defensive fallback:
   - if any section still has `needs_manual_review === true`, force `needs_review = true` even if a future evaluator refactor drops the trigger by mistake

Recommended final rule:

```php
$needsSectionReview = $sections->contains(
    fn (ServiceSection $section): bool => $section->needs_manual_review
);
```

and then:

```php
'needs_review' => $reviewTriggers !== []
    || $needsSectionReview
    || $this->hasImportReviewSignal(...)
    || $this->reviewStateService->hasOutstandingCanonicalConflict(...)
```

This keeps the service-level flag aligned with the actual reviewable objects in the system.

### New tests to add

In `tests/Unit/Services/OosAlignmentServiceTest.php`:

- `it_marks_the_parent_service_for_review_when_childrens_talk_inference_requires_manual_review`

Expected assertions:

- the section has `needs_manual_review = true`
- the result contains `manual_review_sections`
- the parent `ChurchService` has `needs_review = true`
- `import_metadata.review_triggers` contains `manual_review_sections`

## Implementation Order

1. refactor presentation classification to use decision payloads
2. update structural alignment to consume that payload and stop promoting weak heuristics
3. centralize OoS review flag cleanup
4. add service-level `manual_review_sections` propagation
5. update existing tests and add new regression coverage

## Suggested Commands When Implementing

Use the normal project quality gates from the repo instructions:

```bash
vendor/bin/sail artisan test --compact tests/Unit/Services/OosAlignmentServiceTest.php
vendor/bin/sail composer phpstan
vendor/bin/sail bin pint --dirty
```

## Expected Outcome

After this patch:

- explicit childrens-talk classification remains fast and trusted
- inferred childrens talks become reviewable instead of silently authoritative
- weak OpenLP `presentations` evidence becomes metadata, not a type mutation
- OoS childrens-talk review flags stop sticking between alignment runs
- service-level review state stays in sync with section-level manual-review blockers
