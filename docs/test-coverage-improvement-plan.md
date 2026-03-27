# Test Coverage Improvement Plan

## Current State (March 2026)

| Category     | Total | Tested | Coverage |
|--------------|-------|--------|----------|
| Models       | 21    | 21     | 100%     |
| Policies     | 2     | 2      | 100%     |
| Jobs         | 27    | 23     | 85%      |
| Services     | 101   | 81     | 80%      |
| Controllers  | 19    | 11     | 58%      |
| Livewire     | 39    | 14     | 36%      |
| Data / DTOs  | 42    | 2      | 5%       |

---

## Phase 1 — Controllers (highest risk, quickest wins)

Public-facing routes with no HTTP integration tests. Failures here break
user-visible features silently and are difficult to catch in CI.

### Missing controller tests (8)

| Controller                            | Priority | Why                                      |
|---------------------------------------|----------|------------------------------------------|
| `Api/SermonApiController`             | Critical | Public JSON API, external consumers      |
| `SermonController`                    | Critical | Core sermon browse/detail routes         |
| `PodcastFeedController`               | High     | RSS feed — malformed feed is invisible   |
| `SitemapController`                   | High     | SEO impact; broken XML not caught        |
| `PageController`                      | High     | Renders most public static content       |
| `MeetingController`                   | Medium   | Community-facing calendar pages          |
| `Api/MediaController`                 | Medium   | API used by upload flows                 |
| `ChildrensCornerController`           | Medium   | Feature-specific route                   |
| `PublicSongListController`            | Low      | Read-only list; low complexity           |
| `Auth/AuthenticatedSessionController` | Low      | Auth covered by Dusk; basic HTTP check   |
| `Admin/ServiceSectionCandidateMediaController` | Low | Admin-only, lower blast radius  |

### Approach
- Feature tests hitting real HTTP routes (no mocking controllers)
- Cover: 200 response, content shape, 404 handling, auth guards
- Test `PodcastFeedController` with XML structure assertions
- Test `SitemapController` with URL presence assertions

---

## Phase 2 — Jobs (4 remaining gaps)

Job coverage is already strong at 85%. Four jobs remain untested.

| Job                        | Priority | Why                                        |
|----------------------------|----------|--------------------------------------------|
| `FetchBibleTextForSermon`  | High     | External API call; failure loses scripture |
| `MoveSermonToPrivateStorage` | High   | File operation; wrong path = data loss     |
| `GenerateThumbnail`        | Medium   | Has test in non-standard location — move and verify |
| `AnalyzeSegments`          | Medium   | Segmentation accuracy affects sermon split |

### Approach
- Unit tests with mocked external services (API Bible client, storage)
- Assert job dispatches downstream jobs or updates expected model state
- `GenerateThumbnail`: locate existing test, confirm it runs in CI, move if needed

---

## Phase 3 — Services (20 missing)

### High-priority (stateful / user-visible impact)

| Service                         | Why                                             |
|---------------------------------|-------------------------------------------------|
| `SermonIdentitySyncService`     | Data consistency — deduplication logic          |
| `ChurchServiceCanonicalStateService` | Complex state transitions, many dependents |
| `PublicPageVisibilityGuard`     | Incorrect visibility = content leak or 404      |
| `ProcessingRunFailureHandler`   | Error recovery path; untested failures compound |
| `LivestreamCreateSermonService` | Core livestream→sermon conversion path          |
| `SongSectionAligner`            | Alignment errors silently degrade OOS data      |

### Medium-priority (cache / read model layer)

| Service                    | Why                                           |
|----------------------------|-----------------------------------------------|
| `PublicMeetingReadModelCache` | Cache invalidation bugs are subtle           |
| `PublicPageReadModelCache`  | Same — stale cache hard to detect             |
| `PageImageCacheService`     | Image serving correctness                     |

### Lower-priority (can be deferred)

- `TranscriptFormatterService`, `StructuralSectionAligner`, `UnmatchedSongReviewApplicator`,
  `SitemapService`, `SongCatalogSyncService`, `ChildrensTalkSpeakerService`
- `ProcessingPhaseResetService` — test via integration when covering related jobs
- Mock/null services (`MockSermonAnalysisService`, `NullSpeakerIdentificationService`) — not needed

---

## Phase 4 — Livewire Admin Components (25 missing)

Coverage at 36%. Admin components have complex state mutations that are
invisible to HTTP tests. Priority is on components with write operations.

### High-priority (mutate data)

| Component group                              | Tests needed                                   |
|----------------------------------------------|------------------------------------------------|
| `Admin/ChurchServices/ManageChurchService`   | Status transitions, section lifecycle          |
| `Admin/ChurchServices/ServiceReviewDashboard`| Review actions, state changes                  |
| `Admin/ChurchServices/ProcessingReview`      | Approval/rejection mutations                   |
| `Admin/Sermons/` (already tested partially)  | Verify EditSermon covers all field updates     |
| `Admin/Pages/CreatePage`, `EditPage`         | Image upload, slug generation                  |
| `Admin/Meetings/CreateMeeting`, `EditMeeting`| Recurring rule handling                        |

### Medium-priority (read-heavy but user-facing)

- `Admin/ChurchServices/ListChurchServices`, `ShowChurchService`, `ListSectionPublications`
- `Admin/Preachers/*`, `Admin/Users/*`
- `MediaUpload/Form`

### Approach
- Use Livewire testing helpers: `Livewire::test(Component::class)`
- Assert on `$component->assertSet()`, `assertDispatched()`, database state
- Group related admin CRUD into one test file per resource (e.g. `PagesAdminTest`)

---

## Phase 5 — DTOs (40 missing, 5% coverage)

### Why DTOs need tests

DTOs carry validation, casting, and transformation logic. A wrong cast
(e.g. `SermonAnalysis` misreading a null field) silently corrupts downstream
data. These are pure PHP — tests are fast and cheap.

### Priority DTOs

| DTO                         | Why                                        |
|-----------------------------|--------------------------------------------|
| `SermonAnalysis`            | Core output of AI pipeline                 |
| `SermonMetadata`            | Populates sermon records                   |
| `LivestreamSegmentData`     | Drives segmentation accuracy               |
| `ProcessingMetadata` + cast | Serialisation round-trip bugs are subtle   |
| `PublicMeetingReadModel`    | Public-facing, must be shape-stable        |
| `PublicPageReadModel`       | Same                                       |
| `SectionPublicationMetadata`| Controls section visibility                |

### Approach
- Unit tests: instantiate from array/JSON, assert properties and types
- Test cast classes: round-trip `get()` / `set()` with valid and invalid input
- Test nullable/optional fields and edge-case values

---

## Recommended Order of Work

1. **Phase 1** — Controllers: highest user-visible risk, 8 tests, fast to write
2. **Phase 2** — Jobs: 4 remaining, straightforward isolation with mocks
3. **Phase 3** — Services (high-priority 6): stateful, data-integrity risk
4. **Phase 5** — Priority DTOs (7): cheap unit tests, high correctness value
5. **Phase 4** — Livewire admin: largest batch, group by resource to manage scope
6. **Phase 3** — Services (remainder): cache layer and lower-risk services

---

## Definition of Done (per item)

- [ ] Happy path covered
- [ ] At least one failure / edge case covered
- [ ] PHPStan passes at 0 errors after adding test
- [ ] Test runs with `--parallel` without flakiness
- [ ] No new Pint violations (`--dirty`)
