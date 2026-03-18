# Database and Model Integrity Review

Date: 2026-03-18

## Scope and method

- Reviewed the current MySQL schema through Laravel Boost, including `SHOW CREATE TABLE`, foreign keys, unique keys, and secondary indexes.
- Reviewed the migrations that created or hardened the core content, processing, church service, calendar, and speaker identity tables.
- Reviewed the main models and query paths for sermons, media processing, church services, service sections, songs, meetings, calendar events, preachers, and speaker identity.
- Ran targeted duplicate/orphan/invariant queries against the current development database.
- Caveat: the local database is sparse, so the strongest findings are schema and query-pattern based rather than volume-tested against production-like data.

## Current posture

The schema is in better shape than the older architecture notes imply. The newer church-service and media-processing tables already have meaningful foreign keys and some good uniqueness guarantees, including:

- `church_services (date, service)` unique
- `service_sections (media_processing_log_id, section_order)` unique
- `service_sections.published_sermon_id` unique
- `livestream_segments (media_processing_log_id, segment_index)` unique
- `calendar_events.meeting_slug -> meetings.slug` foreign key
- `sermons.slug`, `preachers.name`, `preacher_aliases.alias`, `songs.canonical_key`, and `scripture_passages (bible_id, normalized_reference)` unique

The weakest area is no longer basic referential integrity. It is domain-state integrity: several current business rules still live in JSON, unconstrained strings, or service-layer repair code instead of in the schema.

## What the dev database currently does not show

The local database did not show live violations for the checks I ran. I did not find:

- duplicate sermon slugs
- orphaned `media_processing_logs` linked to missing sermons or church services
- duplicate `service_sections` per `(media_processing_log_id, section_order)`
- duplicate published sermon links from `service_sections`
- duplicate active `church_service_items` positions in current rows
- out-of-range `service_sections.confidence`

That is reassuring, but the dataset is too small to treat as proof that the current schema covers the real production edge cases.

## Highest-value integrity findings

### 1. `church_service_items.position` is only application-enforced for active rows

The schema has an index on `(church_service_id, position)`, but not a uniqueness guarantee. The sync layer actively reorders and re-sequences items to keep positions unique and contiguous, which is a sign that the real invariant lives in code, not in the database.

Why this matters:

- concurrent imports or edits can still create duplicate active positions
- soft deletes make a naive unique index insufficient in MySQL
- downstream logic assumes a single canonical ordered item list per service

This is the cleanest example of a misleading schema: the index suggests ordering matters, but it does not actually protect the ordering invariant.

Recommended follow-up:

- add an explicit active-row discriminator that MySQL can index safely, such as a generated `is_active_row` column derived from `deleted_at IS NULL`
- add a unique constraint on the active ordering shape
- backfill and repair any production duplicates before turning the constraint on

### 2. `church_service_items.type` no longer represents the business concept the app actually uses

The stored `type` values are import/source style categories such as `songs`, `bibles`, and `custom`. The current alignment and publication logic increasingly cares about semantic section types instead, and it often falls back to `metadata.section_type` or title heuristics to recover that meaning.

Why this matters:

- the database cannot enforce rules such as "song-like rows must resolve to a song identity"
- query logic is forced to mix `type`, JSON metadata, and title heuristics
- the table shape no longer matches the current domain model described by the church-service tooling

This is a schema drift problem, not just a naming problem.

Recommended follow-up:

- add a normalized semantic type column for `church_service_items`
- backfill it from current metadata and alignment results
- migrate readers from `type` and JSON heuristics to the new column
- only after that, decide whether the current `type` should become an ingestion/source field or be retired

### 3. Production reporting still depends on JSON for song-alignment state

`PublicSongUsageService` still filters on `service_sections.metadata->oos_alignment->song_match_type`. This is already called out in the JSON metadata backlog, and the current code confirms it is not just incidental metadata anymore.

Why this matters:

- there is no schema-level guarantee over allowed values
- there is no foreign-key or check-backed relationship for reporting
- there is no dedicated index path if usage reporting grows
- JSON shape changes can silently change reporting semantics

Recommended follow-up:

- promote `song_match_type` into a first-class `service_sections` column
- evaluate whether the matched song identity and title evidence should also move into columns
- add an index that supports public song-usage queries once the value is column-backed

### 4. Speaker identity tables do not enforce the uniqueness assumptions used by the code

The current code uses:

- `SpeakerProfile::firstOrCreate(['preacher_id', 'provider', 'model_version'])`
- `SpeakerSample::updateOrCreate(['speaker_profile_id', 'sermon_id', 'source'])`

The schema does not currently protect either of those shapes.

Why this matters:

- duplicate speaker profiles can exist for the same preacher/provider/model pair
- sample upserts can race and create duplicates
- quality and threshold calculations can become non-deterministic if duplicates appear

Recommended follow-up:

- add a unique constraint to `speaker_profiles` on `(preacher_id, provider, model_version)` if only one active profile per model is intended
- add a unique constraint to `speaker_samples` on `(speaker_profile_id, sermon_id, source)` if the current `updateOrCreate` contract is correct
- add a supporting index on `speaker_samples (speaker_profile_id, approved)` to match the approval query path

### 5. `media_processing_logs.sermon_id` cascades deletes from sermons even though the log behaves like durable runtime history

`media_processing_logs.sermon_id` currently uses `ON DELETE CASCADE`. That makes the processing log behave like an expendable child record of the sermon, but the application and architecture notes treat it more like durable operational history, reconciliation context, and a debugging trail.

Why this matters:

- deleting a sermon currently destroys its linked processing history
- that weakens auditability and post-mortem debugging
- it is inconsistent with other associations that were deliberately hardened with `SET NULL`

Recommended follow-up:

- switch the foreign key to `ON DELETE SET NULL` unless there is a deliberate product decision that sermon deletion should erase processing history too

### 6. `sermons` still has split authority between legacy text fields and normalized relationships

`sermons` now has both:

- `preacher` text and `preacher_id`
- `reference` text and `scripture_passage_id`

The newer normalized relationships exist, but several read paths still search, sort, or display from the text columns.

Why this matters:

- canonical relationships and public/admin reads can drift
- the schema cannot tell which field is authoritative
- future constraints are harder to add while both representations are live

Recommended follow-up:

- define whether the text columns are denormalized caches or still the source of truth
- if they remain as caches, add a consistent synchronization rule and treat the relation as authoritative
- if they remain primary, do not present the FK as if it is the sole canonical identity

## Additional schema and query concerns

### Closed-set state is still inconsistently enforced

Some high-value state columns already use native enums, including:

- `sermons.service`
- `sermons.source_type`
- `livestream_segments.classification`
- `media_processing_logs.processing_type`
- `media_processing_logs.status`
- `church_service_items.source`
- `meetings.type`

But several equally closed or nearly-closed business vocabularies are still plain strings:

- `sermons.content_type`
- `sermons.preacher_source`
- `church_services.service`
- `church_services.source`
- `service_sections.section_type`
- `service_sections.status`
- `service_sections.publication_status`
- `media_processing_logs.current_step`
- `media_processing_logs.extracted_service`
- `media_processing_logs.threshold_method`
- `calendar_events.status`
- `meetings.frequency`
- `speaker_samples.source`

Not all of these need MySQL `ENUM`, but they do need a clear schema-level story once their vocabularies are stable enough. Today they read like constrained state in PHP and free text in SQL.

### Publication and timing invariants are only partially protected

`service_sections` has a good confidence-range check and a unique link for `published_sermon_id`, but it still lacks cross-column checks for its publication lifecycle. For example:

- `publication_status` does not guarantee the presence or absence of `published_sermon_id`
- `published_at` is nullable regardless of publication state
- extracted media paths are nullable regardless of extraction/publication state
- `unpublished_expires_at` is not tied to a specific unpublished status

Similarly, `service_sections` and `livestream_segments` do not currently protect basic timing invariants such as:

- `start_time < end_time`
- `duration >= 0`
- `duration` matching the stored range closely enough to be trustworthy

Those missing checks are exactly the kind of thing that turns nullable flexibility into misleading data later.

### Query-supporting index gaps still exist

The biggest current index gaps are:

- `livestream_segments`
  - current reads often scope by `media_processing_log_id`, filter by `classification`, then order by `segment_order` and `start_time`
  - current indexes do not support that path well
  - likely fix: add `(media_processing_log_id, classification, segment_order)` or `(media_processing_log_id, classification, start_time)` depending the dominant read path
- `media_processing_logs`
  - the manual review queue filters on `processing_type`, `status`, and `current_step`
  - current indexes stop at `(processing_type, status)`
  - likely fix: add `(processing_type, status, current_step, updated_at)` if the queue becomes non-trivial
- `speaker_samples`
  - approved-sample reads are keyed by `speaker_profile_id` and `approved`
  - add `(speaker_profile_id, approved)`
- `meetings`
  - the app reads by `meeting_date` and filters by `is_recurring`
  - current indexes do not cover those fields
- `pages`
  - admin listing filters by `navigation` and sorts by `updated_at`
  - only `area` is indexed today

None of these are urgent on the current dev dataset, but they are the most obvious next gaps once the content-processing tables carry more volume.

### `livestream_segments.segment_index` is still a narrow type

`segment_index` is `TINYINT UNSIGNED`, which caps at 255 segments per processing log. That may be fine today, but it is a brittle ceiling for a segmentation pipeline that can evolve without the schema being revisited.

If there is any realistic path to more than 255 segments in a long or finely segmented recording, this should become a wider integer before it turns into a hard production edge.

### Redundant and legacy schema artifacts remain

The schema still contains:

- `livestream_processing_logs`, a legacy table with no current runtime role and zero local rows
- `preachers_name_index`, which is redundant with `preachers_name_unique`
- `sermon_processing_steps_processing_id_step_index`, which is redundant with the unique key on the same columns

These are not correctness bugs by themselves, but they create noise in schema reviews and increase the risk of future drift or mistaken usage.

## Migration and backfill risk

Recent hardening migrations have improved the schema, but several of them use direct destructive cleanup patterns. That is workable on sparse data and during controlled rollout, but it increases risk for future normalization work.

Examples worth remembering:

- `2025_09_01_204201_add_video_upload_source_type_to_sermons.php`
  - drops `source_type` and recreates it to add a new enum value
  - this is a data-loss pattern if existing values matter
- `2025_10_03_071419_standardize_sermon_file_path_fields.php`
  - schema-shape changes were used to standardize stored paths, which is correct in intent but still needs careful production preflight when repeated elsewhere
- `2025_10_02_143000_fix_livestream_segments_foreign_keys.php`
  - deletes orphan rows before adding foreign keys
- `2026_03_13_095602_fortify_processing_log_integrity.php`
  - deletes orphan `sermon_processing_steps` before adding the foreign key
- `2026_03_17_083035_add_unique_constraint_to_livestream_segments_table.php`
  - mutates duplicate `segment_index` values before adding uniqueness
- `2026_03_14_082820_add_unique_constraint_to_pages_table.php`
- `2026_03_15_064347_add_unique_constraint_to_preachers_name.php`
  - both resolve duplicates by rewriting data to fit the new constraint

The pattern is understandable, but future schema debt work should assume production needs a safer rollout:

1. audit and snapshot candidate violations first
2. backfill in explicit, idempotent steps
3. log the rows that were repaired or skipped
4. only add the new constraint after the violation query is empty

## Best next schema-level debt items after the JSON normalization backlog

After the current JSON normalization work lands, the next schema-level debt items should be tackled in this order:

1. Normalize `church_service_items` semantic typing.
   - This unlocks real constraints for song rows, reduces JSON/type heuristics, and aligns the table with the current church-service domain model.

2. Promote song-alignment reporting state out of `service_sections.metadata`.
   - At minimum: `song_match_type`.
   - Possibly also the matched song identity and the evidence fields that reporting depends on.

3. Add the speaker identity unique constraints and approval-supporting index.
   - This is a compact, high-confidence win because the code already behaves as though these constraints exist.

4. Enforce active item ordering for `church_service_items`.
   - This removes one of the most important service-layer-only invariants in the current schema.

5. Formalize review and publication state.
   - Decide which status and reason fields should become columns instead of JSON or free text.
   - Add cross-column checks for publication invariants once the shape is stable.

6. Resolve split authority in `sermons`.
   - Decide the canonical source of truth for preacher and scripture identity.
   - Treat the non-canonical representation as a cache or remove the ambiguity.

7. Change `media_processing_logs.sermon_id` from `CASCADE` to `SET NULL`.
   - Preserve operational history unless product policy explicitly says otherwise.

8. Add the missing composite indexes and remove redundant ones.
   - Focus first on `livestream_segments`, `media_processing_logs`, `speaker_samples`, and then the admin-listing tables.

9. Finish the closed-set state cleanup.
   - Convert the remaining stable string vocabularies to checks, enums, or reference tables once the business language is settled.

## Summary

The schema is no longer missing the basics. The main remaining debt is that the database still does not fully express the business rules the application now depends on. The strongest post-backlog moves are the ones that:

- replace service-layer repair logic with hard constraints
- replace JSON-backed reporting state with columns
- align table shapes with the current domain model, especially for church services and speaker identity

That sequence will buy more integrity than a broad, unfocused "add more indexes and enums everywhere" pass.
