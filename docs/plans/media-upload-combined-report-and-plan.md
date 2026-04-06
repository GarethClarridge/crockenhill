# Media Upload Refactor Plan — Remaining Work

Updated 2026-04-06.

The original combined audit plus implementation history now lives in [docs/archived-plans/media-upload-combined-report-and-plan.md](../archived-plans/media-upload-combined-report-and-plan.md).

This active plan tracks only the refactor work that still appears necessary in the current codebase.

## Current Status

- Validation centralisation is partly done. `MediaValidationService` already drives request and Livewire-facing validation, but backend audio-processing validation still has duplicate limits and file checks.
- Cancellation/status normalisation is effectively complete. The backend transition service, status response payloads, and Livewire upload UI now all handle cancelled processing explicitly.
- Startup orchestration reuse is partly done. `ProcessingInitiator` centralises video and livestream startup, but audio still enters through a separate bespoke path.
- Storage/extraction decomposition is partly done. Some helper extraction already exists, so the remaining work should focus on the biggest hotspots rather than restarting the whole refactor.

## Remaining Work

### 1. Validation Final Mile

Objective:

- Make upload limits and media acceptance rules come from one runtime source of truth.

Status notes:

- `MediaValidationService` already provides the main request/UI rule set.
- `ValidateAudioFile` still performs backend validation through the extraction path.
- `MetadataExtractionService` still contains hard-coded audio validation and limit checks.

Tasks:

- [ ] Decide whether `MediaValidationService` becomes the single canonical source, or whether a lower-level shared rule object should sit beneath it.
- [ ] Route backend audio validation through that shared source instead of duplicating limits in job/service code.
- [ ] Remove hard-coded size/type checks from metadata extraction once equivalent shared validation exists.
- [ ] Keep displayed frontend limits aligned with the same config-backed source.

Exit criteria:

- Audio, video, and livestream rules are defined once and reused consistently by requests, Livewire, jobs, and metadata extraction.

### 2. Startup Orchestration Deduplication

Objective:

- Finish consolidating processing startup paths across media types.

Status notes:

- `ProcessingInitiator` already covers shared startup for video and livestream processing.
- Audio still uses a separate startup path in `UnifiedMediaProcessor`.

Tasks:

- [ ] Extract the remaining audio startup path onto the same orchestration boundary used by video and livestream flows where practical.
- [ ] Reuse shared setup for processing log creation, metadata bootstrap, and inferred defaults.
- [ ] Preserve the audio-specific processing sequence after the shared startup boundary.

Exit criteria:

- There is one clear startup orchestration layer for all supported media types, with audio-specific behaviour only where the pipelines genuinely diverge.

### 3. Video Extraction and Storage Boundary Cleanup

Objective:

- Reduce complexity in the video extraction path without redoing completed helper work.

Status notes:

- Shared helpers already exist for some storage/disk concerns.
- `VideoExtractionService` remains a large coordination hotspot.

Tasks:

- [ ] Split `VideoExtractionService` only where the seams are now clear: extraction/transcoding coordination, storage resolution, and path promotion/cleanup.
- [ ] Reuse existing helper classes rather than introducing parallel abstractions.
- [ ] Keep FFmpeg behaviour and storage semantics unchanged while reducing responsibility density.

Exit criteria:

- `VideoExtractionService` is materially easier to follow, and storage/disk/path logic lives in focused collaborators instead of one large service.

### 4. Selective Contract Simplification

Objective:

- Remove indirection only where it no longer earns its keep.

Status notes:

- The earlier plan treated interface cleanup as a broad sweep.
- The codebase now needs a narrower pass based on actual extension seams, not blanket removal.

Tasks:

- [ ] Audit media-processing interfaces one by one.
- [ ] Remove only pass-through contracts with no alternate implementation value and no testing benefit.
- [ ] Keep abstractions that separate external services, disk/storage differences, or independently testable workflows.

Exit criteria:

- The dependency graph is easier to follow, with less ceremonial indirection and no loss of meaningful substitution boundaries.

## Explicitly Closed from the Earlier Plan

- Status and cancellation normalization no longer belongs in remaining work unless new regressions appear.
- Broad "start over" decomposition is not needed; the remaining refactor should build on helpers already introduced.
- Contract cleanup should not proceed as a framework-wide simplification exercise.

## Suggested Order

1. Validation final mile
2. Startup orchestration deduplication
3. Video extraction and storage boundary cleanup
4. Selective contract simplification

## Definition of Done

- [ ] Validation rules are centralised and reused across every media entry point.
- [ ] Audio startup uses the shared orchestration boundary.
- [ ] Video extraction/storage responsibilities are split at clear seams without behavioural drift.
- [ ] Any contract removals are justified by a concrete usage audit.
- [ ] Existing media upload tests still pass after each phase.
