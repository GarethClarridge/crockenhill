# Preacher Model and Speaker Identification Plan

## Overview

This plan is split into two major initiatives:

1. Introduce a first-class `Preacher` model and migrate away from relying on `sermons.preacher` as a free-text field.
2. Add speaker embedding-based identification so new uploads can automatically link to the correct `Preacher`.

The goal is to improve data quality, support richer preacher content (images/contact details/bio), and provide a reliable foundation for automated speaker detection.

---

## Current State Summary

- Sermons currently store preacher as a string column (`sermons.preacher`).
- Sermon creation currently contains a legacy AI fallback check, but AI analysis does not reliably return preacher names.
- Effective preacher assignment should be treated as `ID3 preacher -> explicit override -> default` until speaker-ID is implemented.
- There is no dedicated identity table for preachers and no speaker embedding pipeline.
- Existing media processing uses job chains for audio/video/livestream processing.

---

## Part 1: Introduce `Preacher` as a First-Class Model

## Objectives

- Normalize preacher identities.
- Support preacher profile fields (image, contact, bio, active flag).
- Keep backward compatibility during migration.
- Prepare data model for speaker embeddings tied to preacher IDs.

## Proposed Data Model

### New table: `preachers`

Recommended columns:

- `id` (bigint, primary key)
- `name` (string, unique enough for your context)
- `slug` (string, unique)
- `image_path` (nullable string)
- `bio` (nullable text)
- timestamps

Indexes:

- unique index on `slug`
- index on `name`
- optional index on `is_active`

### Sermons table changes

- Add nullable `preacher_id` foreign key to `preachers.id`.
- Keep legacy `preacher` string field temporarily for compatibility.

Optional transitional rule:

- `sermons.preacher` becomes denormalized display cache synced from relation.

## Eloquent Relationships

- `Preacher` hasMany `Sermon`.
- `Sermon` belongsTo `Preacher`.

## Implementation Phases

### Phase P1: Schema and model scaffolding

Tasks:

- Create migration for `preachers`.
- Create migration adding nullable `preacher_id` to `sermons` with FK.
- Add `Preacher` model and factory.
- Add relationships on both models.

Acceptance criteria:

- Migrations run cleanly in local and CI.
- Existing sermon flows still work unchanged.

### Phase P2: Backfill existing sermon data

Tasks:

- Create one preacher record per distinct non-empty `sermons.preacher`.
- Set `sermons.preacher_id` for matching sermons.
- Create an explicit fallback preacher record (for unknown/default cases) if needed.
- Add an idempotent Artisan command for backfill (safe to rerun).

Acceptance criteria:

- All historical sermons have `preacher_id` populated where possible.
- No data loss in legacy `preacher` string field.

### Phase P3: Update write paths

Tasks:

- Update sermon creation flow to resolve/set `preacher_id`.
- Continue writing legacy `preacher` string during transition.
- Add helper method/service: `resolvePreacher(string $name): Preacher`.
- Remove legacy fallback to `aiAnalysis['preacher']` in sermon creation, since preacher identity should not come from transcript AI metadata.

Behavior:

- If preacher name is known, attach existing `preacher_id`.
- If unseen name appears, create preacher record automatically (or queue manual review if preferred).

Acceptance criteria:

- New sermons get both `preacher_id` and `preacher` values consistently.

### Phase P4: Update read paths and admin UI

Tasks:

- Update public preacher listings to query `preachers` table.
- Update sermon filters/search and admin edit forms to use relation-based selection.
- Add `Preacher` admin CRUD (name/slug/image/bio). This should follow the design of the existing admin pages for Sermons, Meetings, Pages. 

Acceptance criteria:

- Public pages and API responses remain correct.
- Admin can manage preacher profiles without editing sermon text fields.

### Phase P5: Contract and compatibility cleanup

Tasks:

- Expose `preacher_id` and serialized preacher object in APIs/resources.
- Keep legacy `preacher` response field for a deprecation period.
- Once consumers are migrated, remove or deprecate write-dependence on `sermons.preacher`.

Acceptance criteria:

- Backward compatible API period complete.
- Clear deprecation timeline documented.

---

## Part 2: Speaker Embedding Identification

## Objectives

- Automatically identify likely preacher for new sermon uploads.
- Use closed-set matching against known preachers.
- Protect data quality with confidence gating and fallback behavior.

## Design Principles

- Preacher identity remains canonical in `preachers`.
- Speaker identification is advisory unless confidence is high.
- No hard coupling to a specific ML provider in domain logic.
- Keep inference asynchronous inside existing queue pipelines.

## Proposed Data Model for Speaker ID

### New table: `speaker_profiles`

Purpose: one voice profile per preacher (can support multiple profile versions).

Columns:

- `id`
- `preacher_id` (FK)
- `provider` (string, e.g. pyannote/ecapa/custom)
- `model_version` (string)
- `centroid_embedding` (json)
- `sample_count` (int)
- `quality_score` (float nullable)
- `accept_threshold` (float nullable)
- `margin_threshold` (float nullable)
- `is_active` (boolean)
- timestamps

### New table: `speaker_samples`

Purpose: store per-sermon embedding vectors used for profile building/auditing.

Columns:

- `id`
- `speaker_profile_id` (FK)
- `sermon_id` (FK nullable for non-sermon samples)
- `media_processing_log_id` (FK nullable)
- `embedding` (json)
- `duration_seconds` (float)
- `quality_score` (float nullable)
- `source` (enum/string: backfill, upload_auto, manual_label)
- `approved` (boolean default false)
- timestamps

### Processing metadata storage

Store identification attempt details in `media_processing_logs.processing_metadata`, for example:

- top candidate preacher ID/name
- top-N similarity scores
- model/provider/version
- thresholds used
- decision (`accepted`, `low_confidence`, `skipped`)

## Service Contracts

Add a provider-agnostic contract, e.g. `SpeakerIdentificationInterface`:

- `extractEmbedding(string $audioPath): SpeakerEmbeddingResult`
- `identify(string $audioPath, Collection $profiles): SpeakerMatchResult`
- `updateProfile(SpeakerProfile $profile, array $approvedEmbeddings): SpeakerProfile`

Provide implementations:

- `NullSpeakerIdentificationService` (feature flag off/testing fallback)
- `ExternalSpeakerIdentificationService` (Python microservice or local binary)

## Pipeline Integration

Insert a new queued job `IdentifySpeaker` after sermon creation:

- Audio pipeline: after `CreateSermonRecord`, before `TranscribeAudio`.
- Direct video pipeline: after `CreateSermonRecord`, before `TranscribeAudio`.
- Livestream pipeline: after `SubmitToProcessing`, before `TranscribeAudio`.

Reason:

- Sermon exists (`sermon_id` available).
- Audio path is known.
- Early enough for downstream metadata and notifications.

## Matching Logic

For each new audio sample:

1. Extract embedding.
2. Compare against active preacher profiles using cosine similarity.
3. Rank top candidates.
4. Apply acceptance rules:
   - `top1 >= accept_threshold`
   - `(top1 - top2) >= margin_threshold`
5. If accepted:
   - set `sermons.preacher_id`
   - sync `sermons.preacher` display string
   - store sample embedding
6. If not accepted:
   - keep existing preacher assignment
   - store attempt metadata and mark for optional review

## Profile Lifecycle

### Bootstrapping

- Start with trusted historical sermons (manually verified preacher assignments).
- Build initial profile centroid per preacher from approved samples only.

### Incremental learning

- On high-confidence matches and/or manual confirmations, add new sample.
- Recompute centroid periodically (batch job) or online.

### Governance

- Allow manual approve/reject of low-confidence predictions.
- Keep audit trail of assignment source: `manual`, `id3`, `speaker_model`, `ai_fallback`.

---

## Cross-Cutting Requirements

## Feature Flags and Config

Add config block:

- `speaker_identification.enabled`
- `speaker_identification.mode` (`shadow`, `enforce`)
- `speaker_identification.provider`
- thresholds (`accept`, `margin`)
- minimum audio duration
- queue name/timeouts

Rollout strategy:

- Shadow mode first (log predictions only, do not modify preacher assignment).
- Enforce mode only after quality targets are met.

## Observability

Track metrics:

- match acceptance rate
- low-confidence rate
- top-1 similarity distribution
- false positive/negative rate from manual review
- per-preacher confusion rates

Log with processing ID and sermon ID for traceability.

## Security and Privacy

- Treat embeddings as sensitive internal data.
- Restrict access in admin/API.
- Avoid exposing embeddings in public endpoints.
- Define retention and deletion policy for samples.

---

## Testing Strategy

## Unit tests

- Preacher resolution and relationship behavior.
- Migration/backfill command idempotency.
- Speaker match acceptance rules (threshold and margin cases).
- Fallback behavior when provider unavailable.

## Feature/integration tests

- End-to-end pipeline with `IdentifySpeaker` job inserted.
- Shadow mode does not mutate preacher assignment.
- Enforce mode updates preacher when confidence is high.
- Low-confidence path records metadata and leaves preacher unchanged.

## Data migration tests

- Existing sermons remain readable during transition.
- Backfill creates expected preacher records and foreign keys.

---

## Rollout Plan

## Stage 1: Preacher model foundation

- Ship schema + backfill + relation-based reads/writes.
- Keep legacy `sermons.preacher` synchronized.
- Validate public/admin behavior.

## Stage 2: Speaker ID in shadow mode

- Deploy embedding extraction and matching jobs.
- Record predictions and confidence without changing data.
- Collect evaluation set and measure accuracy.

## Stage 3: Limited enforce mode

- Enable auto-assignment only above strict threshold.
- Start with a subset of trusted preachers if needed.
- Monitor misclassification metrics and manual corrections.

## Stage 4: Full adoption and cleanup

- Expand enforce coverage.
- Finalize API/admin around `preacher_id`.
- Decide whether to keep or fully deprecate string preacher column.

---

## Risks and Mitigations

- Risk: historical preacher strings are inconsistent.
  - Mitigation: canonicalization map + manual review during backfill.

- Risk: embeddings drift with recording quality differences.
  - Mitigation: quality scoring, minimum duration, retraining from approved samples.

- Risk: false confident matches overwrite correct preacher.
  - Mitigation: strict thresholds, margin checks, shadow-mode validation, audit trail.

- Risk: operational complexity of ML runtime.
  - Mitigation: provider abstraction, health checks, graceful fallback service.

---

## Suggested Deliverables by PR

1. PR A: `Preacher` model, migrations, relationships, backfill command.
2. PR B: Update creation/read/admin flows to use `preacher_id` (compat mode).
3. PR C: Speaker profile/sample schemas + service contract + null implementation.
4. PR D: `IdentifySpeaker` job + pipeline wiring + shadow mode logging.
5. PR E: Evaluation tooling + admin review workflow + enforce mode.

---

## Definition of Done

The initiative is complete when:

- Sermons reference canonical `preacher_id` across creation and retrieval.
- Preacher profiles (image/contact/bio) are manageable in admin and visible where needed.
- New uploads can auto-identify preacher with measurable accuracy and safe fallback behavior.
- Deployment and rollback procedures are documented and tested.
