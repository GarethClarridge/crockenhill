# Preacher Model and Speaker Identification Plan

## Overview

This plan covers two initiatives:

1. **Part 1**: Introduce a first-class `Preacher` model and migrate away from relying on `sermons.preacher` as a free-text field.
2. **Part 2**: Add speaker embedding-based identification so new uploads can automatically link to the correct `Preacher`.

The goal is to improve data quality, support richer preacher content (image/bio), and reduce the manual correction burden for the ~15% of sermons where the preacher is not the default.

---

## Current State Summary

- Sermons store preacher as a plain `varchar` column (`sermons.preacher`) with a non-unique index.
- The sermon creation cascade in `SermonCreationService::createSermon()` (line 51) is: `ID3 preacher → $options->preacher → aiAnalysis['preacher'] → 'Mark Drury'`.
- The `aiAnalysis['preacher']` fallback is **dead code** — the AI prompt in `SermonAnalysisService::buildAnalysisPrompt()` only requests `{title, series, reference, points, summary}` and the `SermonAnalysis` data class has no `preacher` property. This branch always evaluates to `null`.
- The `$options->preacher` branch is also effectively dead — no upload path populates it (`fromAudioUpload`, `fromVideoUpload`, and `fromLivestream` all set it to `null`).
- There is no dedicated identity table for preachers.
- ~85% of sermons are by Mark Drury. The remaining ~15% are a handful of regular guest speakers who currently require manual correction.
- Existing media processing uses job chains built by `ProcessingPipelineBuilder`.

---

## Part 1: Introduce `Preacher` as a First-Class Model

### Objectives

- Normalize preacher identities into a dedicated table.
- Support preacher profile fields (image, bio, active flag).
- Keep backward compatibility during migration via a synced legacy string field.
- Prepare the data model for speaker embeddings tied to preacher IDs.

### Proposed Data Model

#### New table: `preachers`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | Primary key, auto-increment |
| `name` | string(255) | Not unique (two people with the same name is plausible) |
| `slug` | string(255) | Unique, generated via `Str::slug()` with collision suffix |
| `image_path` | string, nullable | Path to profile image |
| `bio` | text, nullable | Short biography |
| `is_active` | boolean | Default `true`. Allows hiding departed preachers from dropdowns without deleting |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

Indexes:

- Unique index on `slug`
- Index on `name`
- Index on `is_active`

Slug generation: use `Str::slug($name)` with numeric suffix on collision (e.g. `david-smith`, `david-smith-2`), matching the pattern in `SermonCreationService::generateUniqueSlug()`.

#### Sermons table changes

- Add nullable `preacher_id` foreign key referencing `preachers.id`, with `SET NULL` on delete.
- Keep legacy `preacher` string field during transition. It becomes a denormalized display cache synced from the relation.

### Eloquent Relationships

- `Preacher` hasMany `Sermon`.
- `Sermon` belongsTo `Preacher`.

### Implementation Phases

#### Phase P1: Schema and model scaffolding

Tasks:

- Create migration for `preachers` table.
- Create migration adding nullable `preacher_id` to `sermons` with foreign key.
- Create `Preacher` model with fillable fields, casts (`is_active` → boolean), and `sermons()` relationship.
- Create `PreacherFactory` with sensible defaults and states (`inactive()`, etc.).
- Add `preacher()` belongsTo relationship on `Sermon` model.
- Update `SermonFactory` to optionally accept and create a `Preacher` via a `forPreacher(Preacher $preacher)` state.
- Update `SermonSeeder` to create `Preacher` records and associate them.

Acceptance criteria:

- Migrations run cleanly.
- Existing sermon flows still work unchanged (no code depends on `preacher_id` yet).
- `Preacher::factory()->create()` produces valid records.

#### Phase P2: Backfill existing sermon data

Tasks:

- Create an idempotent Artisan command: `php artisan preachers:backfill`.
- The command should:
  1. Query all distinct non-empty `sermons.preacher` values.
  2. Apply canonicalization: trim whitespace, normalize casing inconsistencies (e.g. `"mark drury"` → `"Mark Drury"`).
  3. Support a `--dry-run` flag that reports what preachers would be created and how many sermons would be linked, without writing anything.
  4. Create one `Preacher` record per canonical name with a generated slug.
  5. Set `sermons.preacher_id` for all matching sermons (case-insensitive match).
  6. Report a summary: preachers created, sermons linked, any unmatched sermons.
- Optionally support a `--canonicalization-map` JSON file for manual overrides (e.g. `{"Rev Mark Drury": "Mark Drury", "M Drury": "Mark Drury"}`).

Acceptance criteria:

- All historical sermons have `preacher_id` populated where the legacy string is non-empty.
- No data loss in legacy `preacher` string field.
- Running the command twice produces the same result (idempotent).
- `--dry-run` makes no database changes.

#### Phase P3: Update write paths

Tasks:

- Add a `PreacherResolver` service with a `resolve(string $name): Preacher` method:
  - Case-insensitive lookup against existing `Preacher` records.
  - If no match, create a new `Preacher` automatically and log a notice.
- Update `SermonCreationService::createSermon()`:
  - Resolve the preacher name to a `Preacher` model using `PreacherResolver`.
  - Set both `preacher_id` (relation) and `preacher` (legacy string, synced from `$preacher->name`).
  - Remove the dead `$options->aiAnalysis['preacher']` fallback branch.
  - Remove the unused `$options->preacher` branch (never populated by any upload path).
  - New priority at creation time: `ID3 preacher name → default name ('Mark Drury')`.
- Continue writing legacy `preacher` string during transition.

Acceptance criteria:

- New sermons get both `preacher_id` and `preacher` values consistently.
- Creating a sermon with an unknown preacher name auto-creates a `Preacher` record.
- Dead code (`aiAnalysis['preacher']` fallback, unused `$options->preacher`) is removed.

#### Phase P4: Update read paths and admin UI

This phase must update every location that currently reads from `sermons.preacher` as a string. The full inventory of touch points:

**Public views:**

| File | Line(s) | Current usage | Change needed |
|---|---|---|---|
| `resources/views/sermons/sermon.blade.php` | 31 | JSON-LD `author.name` | Use `$sermon->preacherModel->name` (or accessor) |
| `resources/views/sermons/sermon.blade.php` | 121 | Preacher display link | Link via `$sermon->preacherModel->slug` |
| `resources/views/components/sermon-card.blade.php` | 30 | Card preacher link | Link via relation |

**Model accessors:**

| Accessor | Line | Current usage | Change needed |
|---|---|---|---|
| `Sermon::getMetaDescriptionAttribute()` | 563 | Uses `$this->preacher` in SEO text | Read from relation, fall back to string |
| `Sermon::getPodcastSummaryAttribute()` | 675 | Uses `$this->preacher` in RSS | Read from relation, fall back to string |
| `Sermon::getPreacherUrlAttribute()` | 191 | Builds URL from slugified string | Use `$this->preacherModel->slug` |

**Controllers:**

| File | Method | Change needed |
|---|---|---|
| `SermonController` | `getPreachers()` | Query `Preacher` model with `withCount('sermons')` instead of `groupBy` on string |
| `SermonController` | `getPreacher(string $preacher)` | Route-model bind on `Preacher` slug instead of fragile `Str::title(str_replace(...))` decode |
| `SermonApiController` | `index()` | Support `?preacher_id=` filter; keep `?preacher=` for backward compat |
| `SermonAdminController` | `update()` | Resolve `preacher_id` from input |

**API resources:**

| File | Change needed |
|---|---|
| `SermonResource` | Add `preacher_id` and nested `preacher` object; keep legacy `preacher` string field |

**Livewire admin components:**

| Component | Change needed |
|---|---|
| `ListSermons` | Replace `$preacherFilter` string dropdown with `Preacher` model select; remove 24h `sermon_preachers` cache (query `preachers` table directly — it's cheap) |
| `EditSermon` | Replace free-text `preacher` input with a `Preacher` dropdown/searchable select |

**Legacy admin:**

| File | Change needed |
|---|---|
| `resources/views/sermons/edit.blade.php` | Replace text input with select (or redirect to Livewire admin) |

**Query scopes:**

| Scope | Change needed |
|---|---|
| `Sermon::scopeByPreacher()` | Accept `Preacher` model or ID instead of string |

Tasks:

- Update all locations listed above.
- Add Livewire 3 admin CRUD for Preacher management: `ListPreachers`, `CreatePreacher`/`EditPreacher` (or combined form). Follow the existing pattern from the Sermons/Meetings/Pages admin components.
- Add preacher image upload support in the edit form.
- Invalidate/remove the `sermon_preachers` cache key in `ListSermons` (or remove caching entirely since `preachers` table queries are cheap).

Acceptance criteria:

- Public pages and API responses remain correct and render the same content.
- Admin can manage preacher profiles (name, slug, image, bio, active flag) without editing sermon text fields.
- Sermon edit forms use a dropdown/select populated from `preachers` table.
- All existing feature tests still pass.

#### Phase P5: Cleanup

Tasks:

- Add `preacher` object (id, name, slug, image_url) to `SermonResource` API response.
- Keep legacy `preacher` string field in API response for backward compatibility.
- Add a deprecation notice to API documentation for the string `preacher` field.
- Once all consumers use `preacher_id`, consider making `sermons.preacher_id` non-nullable (with a migration to backfill any stragglers) and dropping the `sermons.preacher` string column.

Acceptance criteria:

- API backward compatible.
- Clear deprecation timeline.

---

## Part 2: Speaker Embedding Identification

### Objectives

- Automatically identify the likely preacher for new sermon uploads.
- Use closed-set matching against known preachers via voice embeddings.
- Reduce the manual correction burden for the ~15% of non-default sermons.
- Protect data quality with confidence gating and fallback behavior.

### Design Principles

- Preacher identity remains canonical in `preachers` table.
- Speaker identification is advisory — it only overrides the default, never the ID3 tag.
- Priority chain: `ID3 tag > speaker_model > default ('Mark Drury')`.
- No hard coupling to a specific ML provider in domain logic.
- Keep inference asynchronous inside existing queue pipelines.

### Provider: Resemblyzer via Python CLI

**Recommended provider: [Resemblyzer](https://github.com/resemble-ai/Resemblyzer)** — a lightweight Python library for speaker embedding extraction.

Why Resemblyzer:

- **Free, open-source** (Apache 2.0). No API costs, no external dependency.
- **Lightweight**: produces 256-dimensional embedding vectors. Runs on CPU at ~1000x real-time (a 2-minute audio clip processes in ~0.1s).
- **Simple**: single-purpose library, no large framework dependency. `pip install resemblyzer` pulls ~200MB including the pre-trained model.
- **English-optimised**: performs best on English audio, which suits this project.
- **Proven**: widely used for speaker verification and identification tasks.

Why not a hosted API:

- Azure Speaker Recognition and AWS Voice ID have both been **retired/discontinued**.
- Pyannote.ai offers a hosted API (€19/month) but is overprovisioned for this project's volume (~15 minutes/month of actual processing) and adds an external dependency on a small company.
- The service contract abstracts the provider, so a hosted API can be substituted later if needed.

**Implementation: Python CLI script invoked from Laravel**

A standalone script (not a running service) at `scripts/extract_embedding.py`, invoked from Laravel via `Process::run()` within the `IdentifySpeaker` queued job:

- Takes an audio file path as input.
- Outputs a JSON embedding vector (256 floats) to stdout.
- Has no server process, no ports, no lifecycle management.
- Installed inside the Sail Docker container (`python3` + `pip install resemblyzer` added to Dockerfile).

```bash
# Extract embedding from first 120 seconds
python3 scripts/extract_embedding.py /path/to/audio.mp3 --duration 120

# Output (stdout):
# {"embedding": [0.0123, -0.0456, ...], "duration_used": 120.0, "sample_rate": 16000}
```

**Deployment**: add Python 3 and resemblyzer to the Sail Dockerfile. The script has no running process — it's invoked per-job and exits. The app works without Python installed when speaker identification is disabled (`NullSpeakerIdentificationService`).

### Proposed Data Model for Speaker ID

#### New table: `speaker_profiles`

Purpose: one voice profile per preacher (can support multiple profile versions).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | Primary key |
| `preacher_id` | bigint FK | References `preachers.id` |
| `provider` | string | e.g. `resemblyzer`. Allows future provider migration without rebuilding profiles |
| `model_version` | string | Version of the embedding model used |
| `centroid_embedding` | json | Average embedding vector for this speaker |
| `sample_count` | int | Number of approved samples contributing to the centroid |
| `quality_score` | float, nullable | Overall profile quality metric |
| `accept_threshold` | float, nullable | Per-profile override for acceptance threshold |
| `margin_threshold` | float, nullable | Per-profile override for margin threshold |
| `is_active` | boolean | Whether to include in matching |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

Indexes: `preacher_id`, `is_active`.

#### New table: `speaker_samples`

Purpose: store per-sermon embedding vectors used for profile building and auditing.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | Primary key |
| `speaker_profile_id` | bigint FK | References `speaker_profiles.id` |
| `sermon_id` | bigint FK, nullable | References `sermons.id`. Nullable for manually uploaded voice samples |
| `media_processing_log_id` | bigint FK, nullable | References `media_processing_logs.id` |
| `embedding` | json | The raw embedding vector for this sample |
| `duration_seconds` | float | Duration of audio used for this sample |
| `quality_score` | float, nullable | Sample quality metric |
| `source` | string | One of: `backfill`, `upload_auto`, `manual_upload`. `manual_upload` covers voice clips uploaded directly for profile building, not tied to a sermon |
| `approved` | boolean | Default `false`. Only approved samples contribute to centroid |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

#### Processing metadata storage

Store identification attempt details in `media_processing_logs.processing_metadata`, e.g.:

```json
{
    "speaker_identification": {
        "top_candidate": {"preacher_id": 1, "name": "Mark Drury", "similarity": 0.87},
        "runner_up": {"preacher_id": 3, "name": "John Smith", "similarity": 0.42},
        "provider": "resemblyzer",
        "model_version": "0.1.24",
        "thresholds": {"accept": 0.75, "margin": 0.15},
        "decision": "accepted",
        "audio_duration_used": 120.0
    }
}
```

### Service Contracts

Add a provider-agnostic contract `SpeakerIdentificationInterface`:

- `extractEmbedding(string $audioPath): SpeakerEmbeddingResult`
- `identify(string $audioPath, Collection $profiles): SpeakerMatchResult`
- `updateProfile(SpeakerProfile $profile, array $approvedEmbeddings): SpeakerProfile`

Implementations:

- `NullSpeakerIdentificationService` — feature flag off / testing fallback. Returns empty results.
- `ResemblyzerSpeakerIdentificationService` — invokes `scripts/extract_embedding.py` via `Process::run()`. Parses JSON output. Computes cosine similarity in PHP against stored profile centroids.

### Pipeline Integration

Insert a new queued job `IdentifySpeaker` after `CreateSermonRecord`, before `TranscribeAudio`:

- **Audio pipeline**: `ValidateAudioFile` → `CreateSermonRecord` → **`IdentifySpeaker`** → `TranscribeAudio` → ...
- **Direct video pipeline**: `ValidateVideoFile` → `ExtractAudioFromVideo` → `CreateSermonRecord` → **`IdentifySpeaker`** → `TranscribeAudio` → ...
- **Livestream pipeline**: ... → `SubmitToProcessing` → **`IdentifySpeaker`** → `TranscribeAudio` → ...

This insertion point is correct because:

- The sermon record exists (`sermon_id` available for linking).
- The audio file path is known.
- It runs before transcription, so preacher identity is available for downstream metadata.

#### Interaction with ID3 priority

The `IdentifySpeaker` job must check whether ID3 already set a preacher:

1. If `sermon.preacher_id` was set by `CreateSermonRecord` via ID3 tag → **skip identification** but still extract and store the embedding sample (useful for profile building). Log decision as `skipped_id3_present`.
2. If `sermon.preacher_id` is the default → **run identification** and apply if confidence is sufficient.

### Matching Logic

For each new audio sample:

1. Extract embedding from the first N seconds of audio (configurable, default 120s — enough to capture the speaker's voice without processing the entire file).
2. Compare against all active `speaker_profiles` using cosine similarity.
3. Rank candidates by similarity score.
4. Apply acceptance rules:
   - `top1_similarity >= accept_threshold` (global default, overridable per-profile)
   - `(top1_similarity - top2_similarity) >= margin_threshold` (ensures clear winner)
5. **If accepted**:
   - Set `sermon.preacher_id` to the matched preacher.
   - Sync `sermon.preacher` legacy string from `preacher->name`.
   - Store sample embedding (pending approval for profile building).
   - Log decision as `accepted` in processing metadata.
6. **If not accepted**:
   - Keep existing default preacher assignment.
   - Store attempt metadata for optional manual review.
   - Log decision as `low_confidence` in processing metadata.

#### Handling unknown speakers (guest speakers)

The matching logic naturally handles guest speakers not in the database:

- An unknown voice will have low similarity to all known profiles.
- Both `accept_threshold` and `margin_threshold` checks will fail.
- The sermon keeps the default preacher assignment.
- Admin manually corrects and can create a new `Preacher` record.
- If the guest speaks again, their profile (once built from approved samples) will be in the matching set.

### Profile Lifecycle

#### Bootstrapping

- Use existing sermons with trusted preacher assignments (verified by admin).
- An Artisan command `speaker-profiles:bootstrap` should:
  1. For each preacher with sufficient sermons (configurable minimum, e.g. 5), extract embeddings from their sermons.
  2. Compute centroid embedding from approved samples.
  3. Create `speaker_profile` and `speaker_sample` records.
  4. Support `--dry-run` to preview what would be created.
  5. Support `--preacher=slug` to bootstrap a single preacher.

#### Incremental learning

- On high-confidence matches: store sample embedding as pending approval.
- On manual confirmation (admin approves): mark sample as approved, flag profile for centroid recomputation.
- A scheduled command `speaker-profiles:recompute` recalculates centroids from all approved samples.

#### Governance

- Admin can approve/reject pending samples via the preacher admin UI.
- Assignment source is tracked on the sermon (e.g. `preacher_source` column or processing metadata): `manual`, `id3`, `speaker_model`, `default`.
- Admin can view identification history and confidence scores per sermon.

### Config and Feature Flags

Add to `config/media-processing.php`:

```php
'speaker_identification' => [
    'enabled' => env('SPEAKER_IDENTIFICATION_ENABLED', false),
    'mode' => env('SPEAKER_IDENTIFICATION_MODE', 'shadow'), // 'shadow' or 'enforce'
    'provider' => env('SPEAKER_IDENTIFICATION_PROVIDER', 'null'), // 'null' or 'resemblyzer'
    'default_preacher' => env('DEFAULT_PREACHER_NAME', 'Mark Drury'),
    'thresholds' => [
        'accept' => (float) env('SPEAKER_ID_ACCEPT_THRESHOLD', 0.75),
        'margin' => (float) env('SPEAKER_ID_MARGIN_THRESHOLD', 0.15),
    ],
    'audio_duration_seconds' => (int) env('SPEAKER_ID_AUDIO_DURATION', 120),
    'min_audio_duration_seconds' => (int) env('SPEAKER_ID_MIN_DURATION', 30),
    'queue' => env('SPEAKER_ID_QUEUE', 'default'),
    'timeout' => (int) env('SPEAKER_ID_TIMEOUT', 300),
    'cli' => [
        'python_path' => env('SPEAKER_ID_PYTHON_PATH', 'python3'),
        'script_path' => env('SPEAKER_ID_SCRIPT_PATH', base_path('scripts/extract_embedding.py')),
    ],
],
```

### Observability

Track in logs and optionally in a dashboard:

- Match acceptance rate (accepted / total attempts).
- Low-confidence rate.
- Top-1 similarity score distribution.
- Manual correction rate after auto-assignment (false positive indicator).
- Per-preacher identification accuracy (from manual corrections).

Log every identification attempt with `processing_id` and `sermon_id` for traceability.

### Security and Privacy

- Embeddings are internal data — do not expose in public API endpoints.
- Restrict speaker profile management to admin users.
- Define a retention policy for speaker samples (e.g. keep approved samples indefinitely, delete rejected samples after 90 days).

---

## Testing Strategy

### Part 1: Unit tests

- `Preacher` model: creation, slug generation, relationship loading.
- `PreacherResolver::resolve()`: case-insensitive match, auto-creation of unknown names, idempotency.
- `Sermon::preacher()` relationship: eager loading, null handling.
- Backfill command: idempotency (run twice → same result), dry-run mode, canonicalization.

### Part 1: Feature tests

- Sermon creation via pipeline produces correct `preacher_id` and synced `preacher` string.
- Public preacher listing page (`/christ/sermons/preachers`) returns correct data from `preachers` table.
- Individual preacher page (`/christ/sermons/preachers/{slug}`) uses route-model binding.
- API `?preacher=Name` filter still works for backward compatibility.
- API response includes both `preacher` string and `preacher` object.
- Admin preacher CRUD: create, edit, delete (with sermon reassignment or prevention).
- Admin sermon edit: preacher dropdown saves correct `preacher_id`.
- Deleting a preacher sets `preacher_id` to null on associated sermons (SET NULL FK).

### Part 1: Migration tests

- Existing sermons remain readable during transition (both `preacher` string and `preacher_id` accessible).
- Backfill creates expected number of `Preacher` records.
- Backfill correctly links sermons to preachers.
- Backfill with `--dry-run` makes no database changes.

### Part 2: Unit tests

- `SpeakerMatchResult` acceptance rules: threshold and margin cases, edge cases (single profile, tied scores).
- `NullSpeakerIdentificationService` returns empty/skip results.
- `ResemblyzerSpeakerIdentificationService` correctly parses Python CLI output (mock the `Process::run()` call).
- Cosine similarity computation correctness.
- Centroid recomputation from approved samples.
- Fallback behavior when provider is unavailable or times out.

### Part 2: Feature tests

- `IdentifySpeaker` job in full pipeline: accepted match sets `preacher_id`.
- `IdentifySpeaker` job: low-confidence result keeps default preacher.
- `IdentifySpeaker` job: ID3 preacher present → skips identification, still stores sample.
- Shadow mode: predictions logged but sermon not modified.
- Enforce mode: high-confidence prediction updates sermon.
- Profile bootstrap command creates expected profiles and samples.
- Profile recomputation updates centroid correctly.

### Part 2: Data tests

- Speaker profiles are correctly linked to preachers.
- Sample approval workflow updates profile centroid.
- Deleting a preacher cascades to speaker profiles and samples.

---

## Rollout Plan

### Stage 1: Preacher model foundation (Part 1)

- Ship schema + backfill + relation-based reads/writes.
- Keep legacy `sermons.preacher` synchronized.
- Validate public/admin behavior.
- This stage is independently valuable and shippable without Part 2.

### Stage 2: Speaker ID infrastructure (Part 2 setup)

- Deploy `speaker_profiles` and `speaker_samples` tables.
- Deploy service contract + null implementation.
- Bootstrap profiles from trusted historical sermons.
- Validate embedding extraction works reliably.

### Stage 3: Speaker ID in shadow mode

- Enable `IdentifySpeaker` job in pipeline with `mode: shadow`.
- Predictions logged in processing metadata but sermons not modified.
- Collect evaluation data: compare predictions against actual preacher assignments.
- Measure accuracy, tune thresholds.

### Stage 4: Speaker ID in enforce mode

- Enable `mode: enforce` once accuracy targets are met.
- Monitor manual correction rate — should be significantly lower than today's ~15%.
- Approve high-confidence samples for incremental profile improvement.

### Stage 5: Cleanup

- Finalize API/admin around `preacher_id`.
- Decide whether to keep or fully deprecate `sermons.preacher` string column.

---

## Risks and Mitigations

- **Risk**: Historical preacher strings are inconsistent (casing, prefixes, typos).
  - **Mitigation**: Backfill command with `--dry-run` preview, case-insensitive matching, optional canonicalization map for manual overrides.

- **Risk**: Breaking public preacher URLs during migration.
  - **Mitigation**: Current URLs use `Str::title(str_replace('-', ' ', $slug))` which is fragile. The new `Preacher` model with a proper `slug` column replaces this. Set up redirects from old URL patterns if needed.

- **Risk**: Cache staleness after preacher changes.
  - **Mitigation**: Remove or invalidate the 24h `sermon_preachers` cache in `ListSermons` when switching to `preachers` table queries (which are cheap and don't need caching).

- **Risk**: Embeddings drift with recording quality differences.
  - **Mitigation**: Quality scoring, minimum audio duration threshold, recompute centroids from approved samples only.

- **Risk**: False confident matches overwrite correct preacher.
  - **Mitigation**: Speaker ID never overrides ID3 tags. Strict thresholds + margin checks in enforce mode. Shadow mode validation before enabling. Manual correction always available.

- **Risk**: Guest speakers not in the known set.
  - **Mitigation**: Unknown voices score low against all profiles → both threshold checks fail → default preacher applies. Admin corrects manually and new preacher enters the matching set for future sermons.

- **Risk**: Resemblyzer adds Python to the deployment.
  - **Mitigation**: Python + resemblyzer are added to the Sail Dockerfile only. No running service — just a script invoked per-job. `NullSpeakerIdentificationService` means the app works without Python when speaker ID is disabled. The service contract allows swapping to a hosted API in future if needed.

- **Risk**: Resemblyzer is English-optimised and may underperform on other languages.
  - **Mitigation**: All sermons at this church are in English. If multilingual support is needed in future, the provider can be swapped via the service contract.

---

## Suggested Deliverables by PR

### Part 1

1. **PR 1a**: `Preacher` model, migration, factory, seeder updates, relationships on both models.
2. **PR 1b**: Backfill command (`preachers:backfill`) with dry-run support and canonicalization.
3. **PR 1c**: Write path updates — `PreacherResolver` service, `SermonCreationService` changes, dead code removal.
4. **PR 1d**: Read path updates — all views, controllers, API resource, Livewire components, model accessors.
5. **PR 1e**: Preacher admin CRUD (Livewire 3 components: `ListPreachers`, `EditPreacher`).
6. **PR 1f**: Cleanup — non-nullable `preacher_id` migration (if ready), legacy field deprecation.

### Part 2

7. **PR 2a**: `speaker_profiles` and `speaker_samples` migrations + models + factories.
8. **PR 2b**: `SpeakerIdentificationInterface` contract + `NullSpeakerIdentificationService` + data classes (`SpeakerEmbeddingResult`, `SpeakerMatchResult`).
9. **PR 2c**: `ResemblyzerSpeakerIdentificationService` + `scripts/extract_embedding.py` + Dockerfile changes + config/feature flags.
10. **PR 2d**: `IdentifySpeaker` job + pipeline wiring in `ProcessingPipelineBuilder` + shadow mode.
11. **PR 2e**: Profile bootstrap command + centroid recomputation command.
12. **PR 2f**: Admin UI for speaker profile management + sample approval workflow + enforce mode.

---

## Definition of Done

The initiative is complete when:

- Sermons reference a canonical `preacher_id` across creation and retrieval.
- Preacher profiles (image/bio) are manageable in admin and visible on public pages.
- New uploads can auto-identify the preacher via speaker embeddings with measurable accuracy.
- Manual correction rate for non-default preachers is significantly lower than today's ~15% baseline.
- All existing tests pass, new tests cover happy/failure/edge paths.
- Legacy `sermons.preacher` string field remains populated for backward compatibility during transition.
- Deployment and rollback procedures are documented and tested.
