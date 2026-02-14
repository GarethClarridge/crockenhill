# Preacher Model and Speaker Identification Plan (v2)

## Overview

This plan has two linked initiatives:

1. **Part 1**: Introduce a first-class `Preacher` model and cut over from free-text preacher names.
2. **Part 2**: Add speaker embedding identification for automated preacher assignment on new uploads.

This version reflects agreed decisions:

- Use a **one-shot cutover deployment** (not a long multi-release transition).
- Remove legacy preacher inference branches that are not used.
- Change the fallback preacher from `"Mark Drury"` to **"Visiting speaker"**.
- Add persistent alias resolution to prevent duplicate preacher identities.

---

## Current State (Code Reality)

- Sermons currently store preacher as free text (`sermons.preacher`).
- Current assignment in `SermonCreationService` is:
  `id3Preacher -> options.preacher -> aiAnalysis['preacher'] -> 'Mark Drury'`.
- `aiAnalysis['preacher']` is dead code (AI schema does not return preacher).
- `options.preacher` is effectively unused by real upload paths.
- There is no canonical preacher entity and no speaker embedding system.

---

## Non-Goals (v1)

- No diarization (single-speaker assumption for sermon segment).
- No multilingual speaker modelling.
- No hard dependency on a hosted third-party speaker API.

---

## Part 1: Canonical Preacher Model

## Objectives

- Canonicalize preacher identity.
- Support richer preacher metadata (image, bio, active flag).
- Eliminate fragile slug-from-string behavior in routes/views.
- Prepare clean input for speaker profile training.

## Data Model

### Table: `preachers`

- `id` bigint PK
- `name` string(255)
- `slug` string(255) unique
- `image_path` string nullable
- `bio` text nullable
- `is_active` boolean default true
- timestamps

Indexes:

- unique(`slug`)
- index(`name`)
- index(`is_active`)

### Table: `preacher_aliases` (new)

Purpose: persistent alias mapping, not just one-off backfill cleanup.

- `id` bigint PK
- `preacher_id` bigint FK -> `preachers.id` (cascade on delete)
- `alias` string(255) unique (normalized/lowercased)
- timestamps

Indexes:

- unique(`alias`)
- index(`preacher_id`)

### Sermon Columns

Add to `sermons`:

- `preacher_id` bigint FK -> `preachers.id` (`SET NULL` on delete)
- `preacher_source` enum/string (`id3`, `speaker_model`, `manual`, `default`)
- `preacher_confidence` float nullable
- `needs_preacher_review` boolean default false

Keep legacy `sermons.preacher` during cutover as rollback safety and compatibility cache.

## Relation Naming (explicit fix)

Because `sermons.preacher` already exists as an attribute, do not name the relation `preacher()`.

Use:

- `Sermon::preacherProfile()` (belongsTo `Preacher`)
- `Preacher::sermons()` (hasMany `Sermon`)

This avoids attribute/relation collisions and ambiguity in Blade/API code.

## API Contract (explicit, stable)

After cutover, API returns:

- `preacher` (legacy string, kept for compatibility)
- `preacher_id` (canonical id)
- `preacher_details` object:
  - `id`
  - `name`
  - `slug`
  - `image_url`
- `preacher_source`
- `preacher_confidence`
- `needs_preacher_review`

Do not overload `preacher` with an object; keep key naming unambiguous.

## One-Shot Cutover Plan (Part 1)

### Step 1: Schema Deploy

- Deploy migrations for `preachers`, `preacher_aliases`, sermon columns.
- Keep old `sermons.preacher` column in place.

### Step 2: Data Backfill Command

Create idempotent command: `php artisan preachers:cutover --dry-run`.

Behavior:

1. Read distinct legacy preacher strings from sermons.
2. Normalize names (trim, whitespace collapse, case normalization).
3. Apply alias/canonicalization map (optional input file).
4. Create canonical `Preacher` records and `PreacherAlias` rows.
5. Ensure fallback `Preacher` record exists: `"Visiting speaker"`.
6. Link each sermon to `preacher_id`.
7. Set `preacher_source`:
   - `manual` for backfilled non-default names
   - `default` for empty/unknown names mapped to Visiting speaker
8. Set `needs_preacher_review=true` for defaulted sermons where source is unknown.

Requirements:

- Idempotent
- Supports `--dry-run`
- Emits summary counts and anomalies

### Step 3: Application Cutover

Update write path (`SermonCreationService`):

- Remove dead `aiAnalysis['preacher']` fallback.
- Remove unused explicit override branch (`$options->preacher`) in this project.
- New assignment order:
  1. ID3 preacher (if present and resolved)
  2. Default `"Visiting speaker"`

Set source fields:

- ID3 path: `preacher_source='id3'`, `needs_preacher_review=false`
- Default path: `preacher_source='default'`, `needs_preacher_review=true`

Update read path:

- Views/controllers/resources filter/display via `preacherProfile()` relation (with safe fallback to legacy string).
- Preacher pages route-model bind by `Preacher` slug.

### Step 4: Post-Deploy Validation

- Verify preacher listings and preacher pages.
- Verify admin sermon edit/create flows.
- Verify API response shape and filters.
- Track count of `needs_preacher_review=true` sermons.

### Step 5: Cleanup (Optional Follow-up Release)

Once stable:

- Make `sermons.preacher_id` non-nullable if desired.
- Optionally drop legacy `sermons.preacher`.

---

## Part 2: Speaker Embedding Identification

## Objectives

- Auto-identify preacher for non-ID3 uploads.
- Reduce manual preacher corrections.
- Keep false positives low with strict confidence gates.

## Provider Approach

Primary provider: **Resemblyzer via Python CLI script**.

- Script path: `scripts/extract_embedding.py`
- Called from Laravel job via `Process::run()`
- Outputs JSON embedding vector + metadata

### Deployment Requirement (critical)

Python support must exist in **production app/worker image**, not just local Sail.

Current queue workers run in production app container under Supervisor, so Docker production image must include:

- `python3`
- `pip`
- `resemblyzer` and dependencies

Feature flag fallback:

- If disabled/unavailable, use `NullSpeakerIdentificationService` and continue normal sermon processing.

## Speaker Data Model

### Table: `speaker_profiles`

- `id`
- `preacher_id` FK
- `provider`
- `model_version`
- `centroid_embedding` json
- `sample_count`
- `quality_score` nullable
- `accept_threshold` nullable
- `margin_threshold` nullable
- `is_active` boolean
- timestamps

### Table: `speaker_samples`

- `id`
- `speaker_profile_id` FK
- `sermon_id` nullable FK
- `media_processing_log_id` nullable FK
- `embedding` json
- `duration_seconds`
- `quality_score` nullable
- `source` (`backfill`, `upload_auto`, `manual_upload`)
- `approved` boolean default false
- timestamps

## Service Contract

`SpeakerIdentificationInterface`:

- `extractEmbedding(string $audioPath): SpeakerEmbeddingResult`
- `identify(string $audioPath, Collection $profiles): SpeakerMatchResult`
- `updateProfile(SpeakerProfile $profile, array $approvedEmbeddings): SpeakerProfile`

Implementations:

- `NullSpeakerIdentificationService`
- `ResemblyzerSpeakerIdentificationService`

## Pipeline Integration

Add queued job `IdentifySpeaker`:

- Audio: after `CreateSermonRecord`, before `TranscribeAudio`
- Direct video: after `CreateSermonRecord`, before `TranscribeAudio`
- Livestream: after `SubmitToProcessing`, before `TranscribeAudio`

Queue isolation:

- Use dedicated queue `speaker-identification` (do not default to general queue).
- Configure worker concurrency/memory/timeouts specifically for this queue.

## Identification Rules

For each sermon:

1. Determine if sermon already has `preacher_source='id3'`.
2. If yes:
   - do not reassign preacher
   - optionally still store sample for profile growth
   - log decision `skipped_id3_present`
3. If no:
   - extract embedding from configured initial duration window
   - compare cosine similarity vs active profiles
   - apply gates:
     - `top1 >= accept_threshold`
     - `(top1 - top2) >= margin_threshold`
4. If accepted:
   - assign matched `preacher_id`
   - sync legacy `preacher` string cache
   - set `preacher_source='speaker_model'`
   - set `preacher_confidence=top1`
   - set `needs_preacher_review=false`
5. If not accepted:
   - assign `"Visiting speaker"` preacher id
   - set `preacher_source='default'`
   - set `preacher_confidence=top1` (if available)
   - set `needs_preacher_review=true`

## Handling Unknown Guests

- Unknown voices should fail thresholds and resolve to Visiting speaker + review flag.
- Admin correction creates/uses canonical Preacher.
- Alias map and approved samples improve future matches.

## Profile Lifecycle

### Bootstrap

Command: `speaker-profiles:bootstrap --dry-run`

- Build profiles from trusted historical sermons only.
- Minimum sample threshold per preacher.
- Record each sample as approved seed data.

### Ongoing Learning

- Store new samples as pending.
- Admin approves/rejects sample usage.
- Scheduled `speaker-profiles:recompute` recalculates centroids from approved samples.

## Config

Add `speaker_identification` block in `config/media-processing.php`:

- `enabled`
- `mode` (`shadow`, `enforce`)
- `provider`
- `queue`
- thresholds (`accept`, `margin`)
- min duration + extraction duration
- CLI paths (`python`, script)

## Rollout for Part 2

### Stage A: Infrastructure

- Tables + contracts + null service + provider service + production image support.

### Stage B: Shadow Mode

- Run IdentifySpeaker in shadow mode.
- Never mutate sermon preacher.
- Collect metrics for threshold tuning.

### Stage C: Enforce Mode

- Enable auto-assignment gates.
- Monitor false positives and manual correction rate.

---

## Cross-Cutting Operational Requirements

## Observability

Track:

- accepted vs low-confidence rate
- score distributions
- manual correction rate after auto-assignment
- per-preacher confusion patterns

Store attempt details under `media_processing_logs.processing_metadata.speaker_identification`.

## Security/Privacy

- Treat embeddings as internal sensitive data.
- Admin-only management and review actions.
- No embedding exposure in public APIs.

## Publish/Review Rule

- Sermons with `needs_preacher_review=true` remain publishable by default, but must appear in an admin review queue.
- Add admin filter/view for review backlog.

---

## Testing Plan

## Part 1

- Migrations and FK behavior.
- Backfill idempotency + dry-run.
- Alias resolution behavior.
- Route binding for preacher pages.
- API shape (`preacher`, `preacher_id`, `preacher_details`, source/confidence/review flags).
- Sermon create/edit flows with relation-backed preacher.

## Part 2

- IdentifySpeaker decision matrix (id3 skip, accept, low-confidence, no profiles).
- Shadow vs enforce mode behavior.
- Provider failure fallback behavior.
- Profile bootstrap and centroid recompute commands.
- Queue execution and timeout behavior.

---

## Risks and Mitigations

- String inconsistency and duplicate identities:
  - use `preacher_aliases`, not just one-off backfill mapping.

- Route breakage for preacher pages:
  - explicit route-model binding on `Preacher` slug and redirect mapping if needed.

- Wrong auto assignments:
  - strict thresholds, margin gate, shadow mode before enforce, review queue.

- Operational overhead from Python:
  - provider abstraction + feature flag + null fallback; ensure production image parity.

- Queue contention:
  - dedicated speaker-identification queue and worker settings.

---

## Suggested PR Breakdown

1. Schema PR: `preachers`, `preacher_aliases`, sermon columns, models/relations (with `preacherProfile()` naming).
2. Backfill PR: `preachers:cutover` command + dry-run + alias support.
3. Cutover PR: write/read path updates, route binding, API contract update, remove dead preacher branches.
4. Admin PR: preacher CRUD + preacher review queue/filter.
5. Speaker infra PR: profile/sample tables + contracts + null service.
6. Provider PR: resemblyzer CLI service + production Docker changes + config flags.
7. Pipeline PR: `IdentifySpeaker` job insertion + queue configuration + shadow mode.
8. Enforcement PR: threshold tuning + enforce mode + recompute/bootstrap commands.

---

## Definition of Done

- Preacher identity is canonical (`preacher_id`) across app, admin, and API.
- Legacy preacher string remains synced during safety window.
- Default fallback is `"Visiting speaker"` with explicit review flag.
- Speaker-ID runs safely (shadow then enforce) and reduces manual corrections.
- Production deployment path (including worker image dependencies) is documented and tested.

