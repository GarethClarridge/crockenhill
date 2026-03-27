# Test Coverage Improvement Plan

## Current State (March 2026 — updated March 2026)

| Category     | Total | Tested | Coverage | Notes                          |
|--------------|-------|--------|----------|--------------------------------|
| Models       | 21    | 21     | 100%     |                                |
| Policies     | 2     | 2      | 100%     |                                |
| Jobs         | 27    | 27     | 100%     | Phase 2 complete ✅            |
| Services     | 106   | 106    | 100%     | Phase 3 complete ✅            |
| Controllers  | 19    | 19     | 100%     | Phase 1 complete ✅            |
| Livewire     | 39    | 39     | 100%     | Phase 4 complete ✅            |
| Data / DTOs  | 42    | 42     | 100%     | Phase 5 complete ✅            |

---

## Phase 1 — Controllers ✅ COMPLETE

All 19 controllers now have HTTP integration tests. Phase completed March 2026.

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

## Phase 3 — Services ✅ COMPLETE

All 20 previously-untested services now have unit tests. Phase completed March 2026.

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

## Phase 4 — Livewire Admin Components ✅ COMPLETE

All 39 admin Livewire components are now covered. Coverage reached 100% as of
March 2026 — the plan's initial 36% figure predates the bulk of the test work.

| Test file                                          | Components covered                                                        |
|----------------------------------------------------|---------------------------------------------------------------------------|
| `AdminChurchServiceTest.php`                       | `ListChurchServices`, `ManageChurchService`, `ShowChurchService`, `UploadChurchService` |
| `AdminServiceReviewDashboardTest.php`              | `ServiceReviewDashboard`                                                  |
| `ProcessingReviewTest.php`                         | `ProcessingReview`, `ProcessingReviewList`                                |
| `Admin/ChurchServices/ProcessingReviewListTest.php`| `ProcessingReviewList` (blob column regression)                           |
| `Admin/ChurchServices/ShowChurchServiceTest.php`   | `ShowChurchService` (failure path / reclassification)                     |
| `AdminSectionPublicationQueueTest.php`             | `ListSectionPublications`                                                 |
| `AdminSongCatalogTest.php`                         | `ListSongs`, `ShowSong`                                                   |
| `AdminInboundEmailReviewTest.php`                  | `ReviewInboundEmails`, `SubmitEmailText`                                  |
| `AdminPageTest.php`                                | `CreatePage`, `EditPage`, `ListPages`                                     |
| `AdminMeetingTest.php`                             | `CreateMeeting`, `EditMeeting`, `ListMeetings`                            |
| `Admin/Preachers/AdminPreacherTest.php`            | `CreatePreacher`, `EditPreacher`, `ListPreachers`                         |
| `AdminUserTest.php`                                | `CreateUser`, `EditUser`, `ListUsers`                                     |
| `AdminSermonTest.php`                              | `EditSermon`, `ListSermons`                                               |
| `AdminCalendarEventTest.php`                       | `EditCalendarEvent`, `ListCalendarEvents`                                 |
| `Admin/MediaUploadFieldTest.php`                   | `MediaUploadField`                                                        |
| `Admin/AdminUrlStateTest.php`                      | URL state persistence across all listing components                       |
| `Admin/ClearFiltersTest.php`                       | Filter clearing across listing components                                 |
| `Admin/SearchSecurityAndGroupingTest.php`          | SQL injection prevention, LIKE escaping                                   |
| `Admin/ChurchServiceAdminAuthTest.php`             | Authorization guards across church service components                     |

---

## Phase 5 — DTOs ✅ COMPLETE

All 42 DTOs now have comprehensive unit tests. Phase completed March 2026.

### Test coverage summary

| Test File | DTOs Covered | Tests | Assertions |
|-----------|--------------|-------|------------|
| `SermonAnalysisDataTest.php` | SermonAnalysis, SermonAnalysisCast | 17 | 48 |
| `SermonMetadataDataTest.php` | SermonMetadata | 6 | 14 |
| `LivestreamSegmentDataTest.php` | LivestreamSegment | 13 | 35 |
| `ProcessingMetadataDataTest.php` | ProcessingMetadata, ProcessingId3Metadata, ProcessingManualReviewMetadata, ProcessingMetadataCast | 18 | 52 |
| `PublicPageReadModelDataTest.php` | PublicPageReadModel | 7 | 18 |
| `PublicMeetingReadModelDataTest.php` | PublicMeetingReadModel | 8 | 22 |
| `SectionPublicationMetadataDataTest.php` | SectionPublicationMetadata | 14 | 39 |
| `ThumbnailDataTest.php` | ThumbnailMetadata, ThumbnailMetadataCast, ThumbnailResult | 16 | 47 |
| `SongClusterDataTest.php` | SongCluster, SongClusterCollection, SongClusterCollectionCast | 17 | 44 |
| `ChurchServiceMetadataDataTest.php` | ChurchServiceManualReviewMetadata, ChurchServiceCanonicalConflictMetadata, ChurchServiceImportMetadata, ChurchServiceImportMetadataCast | 19 | 61 |
| `ServiceSectionMetadataDataTest.php` | ServiceSectionMetadata, ServiceSectionMetadataCast, ChildrensTalkSpeakerMetadata, SectionOosAlignment | 22 | 68 |
| `OosOpenLpDataTest.php` | OosEmailItemExtractionResult, OosEmailParseResult, OpenLpParseResult, OpenLpImportResult | 9 | 19 |
| `ProcessingResultsDataTest.php` | ApiBiblePassageResult, PodcastFeedItemReadModel, SpeakerEmbeddingResult, SpeakerMatchResult, LivestreamProcessingResult | 19 | 55 |
| `ProcessingLogDataTest.php` | ProcessingLogEntry, ProcessingLogCollection | 8 | 20 |
| `StructureMergeDataTest.php` | PendingStructureMergeMetadata, StructureMergeResolution, StructureMergeResult | 10 | 31 |

**Total: 245 tests covering 42 DTOs with 691 assertions**

### Test strategy applied
- **Factory methods**: All static factory methods tested for happy path and error cases
- **Round-trip serialisation**: `fromArray()` → `toArray()` round-trips verify data integrity
- **Cast layer tests**: All Eloquent cast classes tested for JSON serialisation/deserialisation
- **Edge cases**: Nullable fields, empty arrays, type coercion, and filtering of invalid input
- **Non-obvious behaviour**: Tests pinpoint deliberate side effects (e.g., `SectionOosAlignment` removing keys from `raw` during serialisation)

---

## Recommended Order of Work

1. ~~**Phase 1** — Controllers~~ ✅ Complete
2. ~~**Phase 2** — Jobs~~ ✅ Complete
3. ~~**Phase 3** — Services~~ ✅ Complete
4. ~~**Phase 4** — Livewire admin~~ ✅ Complete
5. ~~**Phase 5** — DTOs~~ ✅ Complete

**All phases complete as of March 2026.**

---

## Definition of Done (per item)

- [ ] Happy path covered
- [ ] At least one failure / edge case covered
- [ ] PHPStan passes at 0 errors after adding test
- [ ] Test runs with `--parallel` without flakiness
- [ ] No new Pint violations (`--dirty`)
