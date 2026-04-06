# Test Output Performance Report

Source reviewed: [test-output.txt](/Users/garethclarridge/Projects/crockenhill/test-output.txt)

## Summary

The biggest performance opportunities are concentrated in:

1. Broad filesystem-scanning command tests
2. Schema drift and migration tests
3. Route-sweep and URL-hydration Livewire tests
4. Image-generation tests with pixel-by-pixel assertions
5. A few action and notification tests that exercise more of the stack than their assertions require

## Highest-Leverage Opportunities

### 1. WebP conversion command tests scan too much of the repo

Files:

- [tests/Feature/Console/ConvertJpgToWebpCommandTest.php](/Users/garethclarridge/Projects/crockenhill/tests/Feature/Console/ConvertJpgToWebpCommandTest.php#L39)
- [app/Console/Commands/ConvertJpgToWebp.php](/Users/garethclarridge/Projects/crockenhill/app/Console/Commands/ConvertJpgToWebp.php#L93)
- [app/Console/Commands/ConvertJpgToWebp.php](/Users/garethclarridge/Projects/crockenhill/app/Console/Commands/ConvertJpgToWebp.php#L166)

Why it stands out:

- `Tests\Feature\Console\ConvertJpgToWebpCommandTest` contributes about `12.02s` across only 3 tests.
- The command recursively scans large real directories including `public/images`, `resources/views`, `app`, `resources/css`, `resources/js`, and `database`.
- The tests create fixtures inside real repo paths, so each run pays the cost of scanning a large part of the codebase.

Suggested improvements:

- Make scan roots configurable so tests can point the command at a tiny temporary fixture tree.
- Add command options or constructor-injected paths for image roots and reference roots.
- In tests, avoid writing fixtures into the real `resources/views` and `public/images` trees.

Expected impact:

- Very high. This looks like the single biggest time-saving opportunity in the suite.

### 2. Schema migration tests are costly and could be isolated

Files:

- [tests/Feature/Database/CorrectiveSchemaMigrationsTest.php](/Users/garethclarridge/Projects/crockenhill/tests/Feature/Database/CorrectiveSchemaMigrationsTest.php#L88)
- [tests/Feature/Database/SongCatalogSchemaTest.php](/Users/garethclarridge/Projects/crockenhill/tests/Feature/Database/SongCatalogSchemaTest.php#L57)

Why it stands out:

- `Tests\Feature\Database\CorrectiveSchemaMigrationsTest` contributes about `7.29s`.
- `Tests\Feature\Database\SongCatalogSchemaTest` contributes about `5.24s`.
- These tests perform expensive `ALTER TABLE`, `DROP`, `CREATE`, migration `up()` and `down()` calls, plus repeated `information_schema` lookups.

Suggested improvements:

- Move the heaviest schema drift tests into a dedicated `schema` or slower CI group.
- Reduce repetition by using targeted scratch-table setups where possible.
- Cache helper lookups inside a test when the same metadata is checked multiple times.
- Keep a slim smoke test in the default suite and reserve deeper reversibility/drift checks for a narrower run.

Expected impact:

- Very high, especially for CI wall-clock time.

### 3. Admin route authorization coverage is broader than necessary

Files:

- [tests/Feature/Admin/AdminLivewireAuthorizationTest.php](/Users/garethclarridge/Projects/crockenhill/tests/Feature/Admin/AdminLivewireAuthorizationTest.php#L50)
- [tests/Feature/Admin/AdminLivewireAuthorizationTest.php](/Users/garethclarridge/Projects/crockenhill/tests/Feature/Admin/AdminLivewireAuthorizationTest.php#L75)
- [tests/Feature/Admin/AdminLivewireAuthorizationTest.php](/Users/garethclarridge/Projects/crockenhill/tests/Feature/Admin/AdminLivewireAuthorizationTest.php#L109)

Why it stands out:

- The test already verifies every routed admin Livewire component has `auth`, `verified`, and `admin` middleware.
- It then performs guest, non-admin, and unverified-admin HTTP requests against every route scenario.
- The slowest case here is `non admin users cannot access routed admin livewire components` at `0.53s`.

Suggested improvements:

- Keep the exhaustive middleware audit.
- Replace the exhaustive response sweep with a few representative route checks per failure mode.
- Build route scenario records once per class if the exhaustive route requests remain necessary.

Expected impact:

- Medium to high, with low risk to confidence if representative route coverage is chosen carefully.

### 4. URL state tests are doing double duty

Files:

- [tests/Feature/Livewire/Admin/AdminUrlStateTest.php](/Users/garethclarridge/Projects/crockenhill/tests/Feature/Livewire/Admin/AdminUrlStateTest.php#L60)
- [tests/Feature/Livewire/Admin/AdminUrlStateTest.php](/Users/garethclarridge/Projects/crockenhill/tests/Feature/Livewire/Admin/AdminUrlStateTest.php#L105)
- [tests/Feature/Livewire/Admin/AdminUrlStateTest.php](/Users/garethclarridge/Projects/crockenhill/tests/Feature/Livewire/Admin/AdminUrlStateTest.php#L180)
- [tests/Feature/Livewire/AdminUserTest.php](/Users/garethclarridge/Projects/crockenhill/tests/Feature/Livewire/AdminUserTest.php#L171)

Why it stands out:

- The URL-state tests assert both query-string hydration and rendered filtering behavior.
- Dedicated list tests already cover much of the filter and sort behavior.
- Examples in `test-output.txt` include the page and user URL-state tests at around `0.31s` and `0.30s`.

Suggested improvements:

- Narrow these tests to URL hydration only: favor `assertSet(...)` for the URL-backed properties.
- Remove duplicate `assertSee` and `assertDontSee` checks when filtering behavior is already covered elsewhere.
- Use the minimum fixture data needed to prove hydration.

Expected impact:

- Medium, but spread across many tests.

### 5. Thumbnail tests spend time rendering full images and scanning pixels

Files:

- [tests/Unit/ThumbnailGenerationServiceTest.php](/Users/garethclarridge/Projects/crockenhill/tests/Unit/ThumbnailGenerationServiceTest.php#L337)
- [tests/Unit/ThumbnailGenerationServiceTest.php](/Users/garethclarridge/Projects/crockenhill/tests/Unit/ThumbnailGenerationServiceTest.php#L382)
- [tests/Unit/ThumbnailGenerationServiceTest.php](/Users/garethclarridge/Projects/crockenhill/tests/Unit/ThumbnailGenerationServiceTest.php#L498)

Why it stands out:

- The slowest cases are around `0.58s`, `0.44s`, and `0.28s`.
- These tests render full-sized images and then scan large rectangular areas pixel by pixel.
- The dark/green pixel search helpers do nested loops over large coordinate ranges.

Suggested improvements:

- Reduce canvas size for tests if the production logic allows it.
- Replace wide-region scans with a few known sentinel coordinates.
- Lean more on composition metadata assertions where possible.
- Split visual smoke coverage from detailed image composition verification.

Expected impact:

- Medium, with the benefit increasing if more visual tests are added over time.

## Other Worthwhile Opportunities

### 6. Inbound email approval action test hits a broad import path

Files:

- [tests/Feature/Actions/InboundEmail/ApproveInboundEmailImportTest.php](/Users/garethclarridge/Projects/crockenhill/tests/Feature/Actions/InboundEmail/ApproveInboundEmailImportTest.php#L36)

Why it stands out:

- `it imports and returns a church service on success` takes `2.79s`.
- This action-level test exercises a substantial portion of the import pipeline rather than only the action’s control flow.

Suggested improvements:

- Mock `InboundEmailImportService::import()` in the action test and assert orchestration only.
- Keep full-stack import behavior in dedicated service tests.

Expected impact:

- Medium to high for this file.

### 7. Notification behavior is covered in both feature and unit tests

Files:

- [tests/Feature/SermonProcessingJobChainTest.php](/Users/garethclarridge/Projects/crockenhill/tests/Feature/SermonProcessingJobChainTest.php#L372)
- [tests/Feature/SermonProcessingJobChainTest.php](/Users/garethclarridge/Projects/crockenhill/tests/Feature/SermonProcessingJobChainTest.php#L436)
- [tests/Unit/Jobs/SendCompletionNotificationTest.php](/Users/garethclarridge/Projects/crockenhill/tests/Unit/Jobs/SendCompletionNotificationTest.php#L19)

Why it stands out:

- The feature suite includes notification success and admin-email targeting cases.
- The unit suite already covers most of the same behavior in more focused form.
- Two feature tests appear in the slower group at around `0.53s` each.

Suggested improvements:

- Keep one feature-level job-chain integration test.
- Move detailed delivery behavior and branching checks to the unit test only.

Expected impact:

- Medium.

## Aggregate Hotspots From `test-output.txt`

Top contributors by summed test time:

- `Tests\Feature\Console\ConvertJpgToWebpCommandTest` about `12.02s`
- `Tests\Feature\Database\CorrectiveSchemaMigrationsTest` about `7.29s`
- `Tests\Feature\Livewire\AdminUserTest` about `6.07s`
- `Tests\Feature\Database\SongCatalogSchemaTest` about `5.24s`
- `Tests\Feature\Livewire\AdminChurchServiceTest` about `5.15s`
- `Tests\Unit\Jobs\SendCompletionNotificationTest` about `2.38s`
- `Tests\Unit\ThumbnailGenerationServiceTest` about `2.25s`

## Recommended Order Of Attack

1. Make the WebP command testable with tiny fixture directories.
2. Split or isolate the heaviest schema migration tests from the default suite.
3. Trim exhaustive admin route response sweeps to representative checks.
4. Simplify URL hydration tests so they stop retesting list filtering.
5. Reduce image test pixel scanning and full-size render work.
6. Narrow action and notification tests to the level of behavior they actually need to prove.

## Notes

- This report is based on `test-output.txt` plus follow-up inspection of the related test and implementation files.
- No code changes were made as part of this report.
