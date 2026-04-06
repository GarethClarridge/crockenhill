# Media Upload Refactor Plan — Remaining Work

The full combined audit plus implementation history now lives in [docs/archived-plans/media-upload-combined-report-and-plan.md](/Users/garethclarridge/Projects/crockenhill/docs/archived-plans/media-upload-combined-report-and-plan.md).

This file tracks only the remaining refactor work.

## Scope

- Finish the still-open media upload simplification work across validation, cancellation semantics, orchestration reuse, extraction/storage decomposition, and interface cleanup.
- Keep the active plan focused on work that is not yet complete.

## Remaining Work

### 1. Validation Unification

Objective:

- Make upload rules and limits come from one source of truth.

Tasks:

- [ ] Introduce one central media rule builder or config-backed helper.
- [ ] Migrate API controller validation, `ProcessMediaRequest`, and Livewire dynamic rules to that shared source.
- [ ] Bind displayed frontend limits to the same source where feasible.

Exit criteria:

- Audio/video/livestream limits and allowed types are consistent across API, web, and Livewire.

### 2. Status and Cancellation Normalization

Objective:

- Align UI state and persisted backend semantics for cancelled processing.

Tasks:

- [ ] Define explicit cancellation semantics.
- [ ] Either add `cancelled` to the persisted status model or represent cancellation consistently via explicit metadata/state mapping.
- [ ] Update status serialization and UI polling logic accordingly.
- [ ] Document the final retry/cancel transition rules.

Exit criteria:

- Cancel behavior is consistent in DB, API status responses, and Livewire UI.

### 3. Orchestration Deduplication

Objective:

- Remove repeated startup logic between direct video and livestream processing.

Tasks:

- [ ] Extract shared initialization flow for metadata extraction, service inference, and processing log creation.
- [ ] Reuse it across direct video and livestream start paths.
- [ ] Preserve current behavior while reducing duplication.

Exit criteria:

- Startup duplication is materially reduced with no regression in existing integration coverage.

### 4. Service Decomposition (Video/Storage)

Objective:

- Reduce complexity in extraction/storage path code.

Tasks:

- [ ] Split `VideoExtractionService` into focused collaborators for extraction/transcoding, compression policy, and disk/path strategy.
- [ ] Introduce or reuse a single shared storage/disk detection utility.

Exit criteria:

- `VideoExtractionService` is significantly smaller and storage/disk detection is centralized.

### 5. Contract/Interface Simplification (Selective)

Objective:

- Remove unnecessary indirection without losing useful abstraction seams.

Tasks:

- [ ] Evaluate contracts one by one for real abstraction value.
- [ ] Remove only contracts with no alternate implementation value and no near-term extension need.
- [ ] Preserve abstraction boundaries for genuinely swappable systems.

Exit criteria:

- The dependency graph is easier to follow, with fewer pass-through bindings and no loss of testability.

## Suggested Order

1. Validation unification
2. Status and cancellation normalization
3. Orchestration deduplication
4. Service decomposition
5. Contract/interface simplification

## Definition of Done

- [ ] Validation is centralized and consistent in all entry points.
- [ ] Cancellation semantics are coherent across model, API, and UI.
- [ ] Major duplication in startup and storage logic is removed.
- [ ] Media upload tests remain strict, deterministic, and green.
- [ ] Documentation reflects the final status model and validation source of truth.
