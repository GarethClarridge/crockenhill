# Eloquent Model Boundary Audit (2026-03-17)

## Scope

- Reviewed every file under `app/Models`.
- Focused on service-container usage, storage access, hidden I/O in accessors, workflow mutations, and non-persistence responsibilities.
- Did not flag plain relationships, casts, simple scopes, or pure formatting helpers unless they also crossed an application boundary.

## Recommendation vocabulary

- `Presenter`: an existing `app/Presenters` or `app/View/Presenters` style class that shapes outbound page, API, SEO, or media fields from already-fetched models.
- `Application service`: an existing `app/Services` style class that coordinates I/O, transactions, workflows, or multi-model read/write behavior.
- `Action`: a narrow command-style use case in `app/Actions`, similar to `ConfirmLivestreamSermonSegment`.
- `Policy`: a Laravel authorization rule in `app/Policies`.
- `Read model`: a query-oriented collaborator built with the project's current repository or service patterns. This does not assume a new `app/ReadModels` directory; in this codebase it could be a repository, a service returning a DTO or array, or a view presenter focused on assembled read state.

## Severity note

- Severity balances runtime blast radius and write-side correctness. High marks either hot-path hidden I/O or state-changing behavior that could produce subtle bugs if refactored carelessly.

## Findings

### High

#### 1. `Page` image accessors expose storage-backed presentation on hot public paths

- Location: `app/Models/Page.php:237-340`
- Violation: the public image accessors are thin wrappers, but they all funnel through `resolveHeadingImageUrl()`, which reaches Media Library and `Storage::disk('public')`.
- Practical risk: page cards, landing pages, and sitemap rows can trigger repeated media lookups and filesystem checks on very hot read paths. The implementation is centralized well, but the boundary is still leaking through public model accessors.
- Best home: `Presenter`

#### 2. `Sermon::getTranscriptAttribute()` performs hidden storage I/O and logging on read

- Location: `app/Models/Sermon.php:413-443`
- Violation: reading `$sermon->transcript` reaches `TranscriptStorageService`, checks configured disks, loads file contents, and emits warning logs.
- Practical risk: a property read can trigger remote filesystem calls, large payload loads, and log side effects from views, API transformers, queued jobs, or serializers. It is especially easy to create accidental N+1 storage reads if transcript access leaks into list pages.
- Best home: `Application service`

#### 3. `Sermon` resolves storage and exposure services from accessors

- Location: `app/Models/Sermon.php:163-198`, `app/Models/Sermon.php:621-625`, `app/Models/Sermon.php:723-726`
- Violation: `getAudioUrlAttribute()`, `getThumbnailUrlAttribute()`, `getPublicUrlAttribute()`, `getVideoUrlAttribute()`, and `getCanonicalUrlAttribute()` call `resolve()` or `app()` to reach `SermonStorageService` and `SermonExposurePolicy`.
- Practical risk: sermon list/detail presenters, API serializers, and feed builders can touch routing or storage services just by reading model attributes. That couples a core entity to container resolution and infrastructure configuration.
- Best home: `Presenter`

#### 4. `MediaProcessingLog` owns processing workflow transitions

- Location: `app/Models/MediaProcessingLog.php:329-416`
- Violation: `markAsProcessing()`, `markAsCompleted()`, `markAsFailed()`, `markAsCancelled()`, `markForManualReview()`, and `confirmSermonSegment()` mutate workflow state directly on the model.
- Practical risk: write-side state changes used by jobs and admin flows are scattered across the entity instead of one use-case owner. That raises the chance of subtle retry, cancel, and manual-review bugs during refactors because the transition API is globally callable.
- Best home: `Application service`

### Medium

#### 5. `Meeting` accessors hide cross-model queries and media lookups

- Location: `app/Models/Meeting.php:128-163`, `app/Models/Meeting.php:324-356`, `app/Models/Meeting.php:420-435`
- Violation: content accessors proxy through `page`, event accessors query `calendarEvents()`, and photo accessors read Media Library state and URLs.
- Practical risk: `$meeting->heading`, `$meeting->next_event`, `$meeting->photos`, and `$meeting->heading_image_url` can trigger hidden reads, with the image case cascading into `Page`'s storage-backed helper. This is a read-side assembly concern rather than plain persistence behavior.
- Best home: `Read model`

#### 6. `Meeting::createEvent()` hides an external calendar write behind the model

- Location: `app/Models/Meeting.php:362-364`
- Violation: the model resolves `GoogleCalendarSyncService` and creates a remote Google Calendar event.
- Practical risk: the method name looks like a local record operation, but it actually performs an external API mutation with its own authorization, retry, and failure semantics. That makes transaction boundaries and user intent much less obvious.
- Best home: `Action`

#### 7. `ServiceSection` mixes publication policy with transition commands

- Location: `app/Models/ServiceSection.php:132-190`
- Violation: `isPublishableType()` reads publishing config, `canTransitionTo()` defines approval rules, and `transitionTo()` mutates publication state while logging invalid attempts.
- Practical risk: environment-driven publishing rules and approval workflow are embedded in the persistence model. The transition method only changes in-memory state, so persistence, auditing, and downstream side effects are easy to forget or implement inconsistently.
- Best home: `Action`

#### 8. `MediaProcessingLog::sourceVideoExists()` performs storage access from the model

- Location: `app/Models/MediaProcessingLog.php:456-467`
- Violation: the model checks configured storage and local filesystem fallback directly.
- Practical risk: workflow callers have to reach the filesystem through the entity, which makes storage behavior harder to swap, test, or centralize alongside the rest of the processing workflow.
- Best home: `Application service`

#### 9. `MediaProcessingLog::scopeVisibleTo()` embeds authorization in the model layer

- Location: `app/Models/MediaProcessingLog.php:298-305`
- Violation: visibility rules are implemented as a user-aware model scope.
- Practical risk: access control can drift away from Laravel's policy layer, and callers can bypass it by forgetting to use the scope. That is especially risky for admin-only processing logs because the model becomes the only place that "knows" the rule.
- Best home: `Policy`

#### 10. `Preacher` owns cached list-building for specific UI use cases

- Location: `app/Models/Preacher.php:101-127`
- Violation: static model methods assemble and cache the admin dropdown list and the public preacher index list.
- Practical risk: cache keys, list shapes, and query tuning are now tied to the entity itself. That encourages static or global reads from the model and makes cache invalidation harder to centralize as the UI grows.
- Best home: `Read model`

#### 11. `LivestreamSegment` exposes reporting helpers that are really read-side queries

- Location: `app/Models/LivestreamSegment.php:248-272`
- Violation: `getLongestSpeechSegment()` and `getSegmentsSummary()` provide specialized query/report answers directly off the model class.
- Practical risk: summary and reporting logic becomes harder to compose, optimize, or cache because it is hidden as ad hoc static helpers on the entity. It also nudges callers toward using the model as a reporting API instead of a persistence record.
- Best home: `Read model`

#### 12. `Sermon::scopeWhereVisibleInSitemap()` mixes query composition with runtime policy resolution

- Location: `app/Models/Sermon.php:279-287`
- Violation: the scope resolves `SermonExposurePolicy` from the container to decide which records belong in the public sitemap.
- Practical risk: query behavior is driven by runtime service resolution rather than explicit callers, which makes the sitemap dataset harder to test, cache, and reuse in batch jobs. The model is also deciding an application-level publication concern rather than just exposing query primitives.
- Best home: `Application service`

### Low

#### 13. `SermonProcessingStep` exposes step-level status write helpers

- Location: `app/Models/SermonProcessingStep.php:59-115`
- Violation: step-local `markAs*()` methods persist their own row state from the model.
- Practical risk: this is lower risk than the parent `MediaProcessingLog` transitions because it is step-level bookkeeping, not the whole processing workflow. The main downside is that step orchestration rules can still spread beyond one coordinator if callers start using these helpers directly.
- Best home: `Application service`

#### 14. Sitemap rendering still lives on `Page`, `Meeting`, and `Preacher`

- Location: `app/Models/Page.php:348-375`, `app/Models/Meeting.php:373-384`, `app/Models/Preacher.php:135-149`
- Violation: these write models emit `Spatie\Sitemap\Tags\Url` output directly. `Sermon` is already mostly extracted via `SermonSitemapPresenter`, so I am not treating it as the same severity.
- Practical risk: outbound SEO formatting changes still require edits to core entities, and `Page::toSitemapTag()` compounds the issue by calling storage-backed image helpers while building the sitemap row.
- Best home: `Presenter`

## Additional low-level gap

- `LivestreamSegment::$is_sermon_segment` is written by services and tests but has no dedicated query primitive in app code. This is more of an ergonomics gap than a boundary violation. If it starts spreading, add a focused scope or query helper rather than another extraction layer.

## Suggested extraction order

- Centralize processing workflow commands first by pulling `MediaProcessingLog`, `ServiceSection`, and any step-level writes behind dedicated use-case classes. That reduces subtle write-side state bugs before touching the read path.
- Move `Sermon::transcript` and the storage-backed `Page` / `Meeting` accessors next. Those are the highest hidden-I/O risks on public reads once the workflow surface is stable.
- Finish with cached lists, reporting helpers, and sitemap rendering so the entities settle back toward persistence-only concerns.

## Models not flagged

- `CalendarEvent`
- `ChurchService`
- `ChurchServiceItem`
- `InboundEmail`
- `PreacherAlias`
- `ScripturePassage`
- `Song`
- `SongAuthor`
- `SongBook`
- `SpeakerProfile`
- `SpeakerSample`
- `User`

