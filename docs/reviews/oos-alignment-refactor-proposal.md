# OosAlignmentService Refactor Proposal

## Purpose

This note is the forward-looking companion to [oos-alignment-service-review.md](./oos-alignment-service-review.md). It assumes the behavioral inventory in that document is the contract to preserve.

## Design Constraints

The refactor should optimize for:

1. preserving the persisted metadata contract
2. preserving the current transaction boundary
3. preserving rerun semantics based on `base_*`
4. reducing class size without changing algorithmic behavior
5. avoiding extra abstraction layers unless they remove real coupling

## What Not to Do First

The highest-risk move would be jumping straight from direct Eloquent mutation to a DTO-heavy command system. That would introduce:

- a second representation of section state
- a mutation-application layer
- another place for ordering bugs around confidence and review flags

DTOs are still possible later, but they should not be phase 1 unless there is a strong reason to pay that cost.

## Recommended First-Cut Architecture

The lowest-risk architecture keeps a thin coordinator and extracts cohesive collaborators that still operate on in-memory `ServiceSection` models.

```text
AlignWithOos / ReconcileServiceSections
        |
        v
OosAlignmentCoordinator
        |
        +-- ChurchServiceResolver
        +-- SectionAlignmentBaselineRestorer
        +-- PresentationItemClassifier
        +-- SongSectionAligner
        +-- StructuralSectionAligner
        +-- AlignmentTriggerService
        +-- ChurchServiceReviewSynchronizer
```

This keeps the data model unchanged while making the behavior easier to test and reason about.

## Proposed Collaborators

### `ChurchServiceResolver`

Extract from:

- `resolveChurchService()`

Responsibilities:

- choose the target church service
- persist `MediaProcessingLog.church_service_id` when resolution succeeds
- preserve current `fresh()` reload behavior for explicit church-service arguments

### `SectionAlignmentBaselineRestorer`

Extract from:

- `prepareSectionForAlignment()`
- `clearOosReviewFlags()`
- `baseAlignmentMetadata()`
- `persistConfidenceLevel()`
- section metadata helper methods

Responsibilities:

- restore section baseline from `base_*`
- clear OoS-owned metadata and review state
- normalize persisted confidence metadata after alignment

### `PresentationItemClassifier`

Extract from:

- `classifyPresentationItems()`
- `makePresentationDecision()`
- presentation-specific parts of `resolvedItemType()`

Responsibilities:

- resolve presentation items into explicit / strong / weak decisions
- keep the current evidence-tier semantics intact

Why early:

- small, cohesive, and mostly independent of persistence details

### `SongSectionAligner`

Extract from:

- `alignSongSections()`
- `inferRemainingSongSectionLabels()`
- `songMatchScore()`
- song-candidate helper methods
- `applyInferredSongItem()`

Responsibilities:

- perform the current greedy song-matching algorithm
- preserve `similar_text()` behavior
- preserve inferred-vs-confirmed side effects

Important constraint:

- do not "improve" greedy matching into a globally optimal assignment in phase 1

### `StructuralSectionAligner`

Extract from:

- `alignStructuralSections()`
- lookahead helpers
- `applyMatchedItem()`
- `markMismatch()`
- custom-item inference and OoS-reclassifiable-type logic

Responsibilities:

- own the ordered structural diff walk
- preserve lookahead behavior and mismatch reasons
- preserve current authoritative-reclassification rules

### `AlignmentTriggerService`

Extract from or split from:

- `ServiceSectionReviewTriggerEvaluator`

Recommended shape:

- either rename the current class to reflect that it mutates sections
- or split it into:
  - `UnmatchedSongReviewApplicator`
  - `AlignmentTriggerCalculator`

Why:

- the current "evaluator" name hides side effects
- this is the cleanest place to make trigger calculation eventually pure

### `ChurchServiceReviewSynchronizer`

Extract from:

- `syncChurchServiceReviewState()`
- `hasImportReviewSignal()`

Responsibilities:

- rewrite `import_metadata.review_triggers`
- recompute `needs_review`
- preserve import-confidence and canonical-conflict review reopening behavior

## Suggested Coordinator Flow

1. Refresh the processing log.
2. Open the transaction.
3. Resolve the church service.
4. Load items and lock sections.
5. Capture before-state.
6. Restore baseline section state.
7. Run song alignment.
8. Run structural alignment.
9. Apply unmatched-song review state and compute triggers.
10. Persist sections.
11. Sync parent review state.
12. Return `AlignmentResult`.

## DTO Guidance

### Phase 1 recommendation

Do not introduce section-mutation DTOs yet. Let extracted collaborators mutate the same in-memory `ServiceSection` models the current service already mutates.

Benefits:

- smallest behavioral delta
- no second mutation layer
- easiest comparison against existing tests

### Phase 2 option

If the collaborators become stable and the team wants purer domain logic later, then introduce DTOs such as:

- `AlignmentContext`
- `SongAlignmentResult`
- `StructuralAlignmentResult`
- `AlignmentResult`

Only do this after phase 1 if the additional indirection is paying for itself.

## Extraction Order and Risk Criteria

### Risk criteria

The ordering below is based on four criteria:

1. self-containment of the logic
2. number of downstream metadata contracts touched
3. sensitivity to the current transaction/persistence ordering
4. likelihood of changing rerun behavior accidentally

### Recommended order

1. `PresentationItemClassifier`

Reason:

- most self-contained logic
- lowest persistence coupling
- easiest to validate against existing tests

2. `ChurchServiceReviewSynchronizer`

Reason:

- small API surface
- limited algorithmic complexity
- isolates parent-review policy from section alignment
- makes later coordinator slimming easier

3. `SectionAlignmentBaselineRestorer`

Reason:

- centralizes rerun/idempotency rules early
- reduces the chance of accidentally starting later extractions from the wrong state

4. `SongSectionAligner`

Reason:

- cohesive cluster of logic
- high downstream contract surface, but mostly localized
- easier to compare before/after with focused tests once baseline restoration is isolated

5. `StructuralSectionAligner`

Reason:

- largest policy surface
- most branchy algorithm
- benefits from earlier extraction of presentation classification and baseline logic

6. Split or rename `ServiceSectionReviewTriggerEvaluator`

Reason:

- once aligners are smaller, the remaining impurity in the evaluator is easier to isolate
- this is where a pure trigger calculator can emerge if desired

7. Introduce a thin `OosAlignmentCoordinator`

Reason:

- final step once responsibilities are already externalized
- avoids building a coordinator that still contains most of the old logic

## Success Criteria

The refactor is successful if all of the following remain true:

- existing tests still pass
- new characterization tests cover the documented gaps
- stored metadata keys and meanings do not change
- `aligned = false` cases still preserve current linking behavior
- reruns do not compound confidence or stale OoS flags
- reporting still counts only confirmed livestream song matches
