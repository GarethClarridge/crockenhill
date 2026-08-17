<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Contracts\OosEmailItemExtractor;
use App\Data\OosEmailItemExtractionResult;
use App\Enums\InboundEmailStatus;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\InboundEmail;
use App\Models\Sermon;
use App\Queries\AdminAttentionCounts;
use App\Queries\ReviewInboxQuery;
use App\Services\ChurchService\ChurchServiceEvidenceSet;
use App\Services\ChurchService\ChurchServiceSongLinker;
use App\Services\Email\OosArchiveParseCacheBinding;
use App\Services\Email\OosCurationManifest;
use App\Services\Import\HistoricEmailEvidenceReleaseGate;
use App\Services\Import\HistoricImportResourceIdentity;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ImportOosArchiveCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_refuses_missing_or_conflicting_modes_before_reading_or_mutating_the_corpus(): void
    {
        $this->artisan('oos:import-archive', [])
            ->expectsOutputToContain('Choose exactly one mode')
            ->assertExitCode(1);
        $this->artisan('oos:import-archive', ['--dry-run' => true, '--apply-bundle' => '/not-read.json'])
            ->expectsOutputToContain('Choose exactly one mode')
            ->assertExitCode(1);

        $this->assertDatabaseCount('inbound_emails', 0);
        $this->assertDatabaseCount('church_services', 0);
    }

    /** @var list<string> */
    private array $temporaryPaths = [];

    /** @var list<string> */
    private array $temporaryDirectories = [];

    /** @var array<string, string> */
    private array $corpusArguments = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        foreach ($this->temporaryDirectories as $directory) {
            foreach ((array) glob($directory.'/*/*') as $file) {
                unlink((string) $file);
            }

            foreach ((array) glob($directory.'/*') as $child) {
                is_dir((string) $child) ? rmdir((string) $child) : unlink((string) $child);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function dry_run_splits_and_reports_without_database_or_extractor_access(): void
    {
        $this->app->bind(OosEmailItemExtractor::class, fn () => new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                throw new RuntimeException('Dry run must not call the extractor.');
            }
        });
        $corpus = $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);
        $report = $this->temporaryPath('json');

        $this->artisan('oos:import-archive', [...$corpus, '--dry-run' => true, '--report' => $report])
            ->assertExitCode(0);

        $this->assertDatabaseCount('inbound_emails', 0);
        $payload = $this->readReport($report);
        $this->assertSame('reconcile', $payload['mode']);
        $this->assertCount(1, $payload['entries']);
        $this->assertArrayHasKey('aggregate', $payload);
        $this->assertSame('oos-test-batch', $payload['curation_plan']['batch_key']);
        $this->assertSame([], $payload['adjudicated_identity_disagreements']);
    }

    #[Test]
    public function parse_runs_are_idempotent_and_hash_aware(): void
    {
        $extractor = new class implements OosEmailItemExtractor
        {
            public int $calls = 0;

            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                $this->calls++;

                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Amazing Grace']],
                    confidence: 0.99,
                    services: [[
                        'service' => 'morning',
                        'date' => '2026-07-12',
                        'items' => [['type' => 'song', 'title' => 'Amazing Grace']],
                        'confidence' => 0.99,
                    ]],
                );
            }
        };
        $this->app->instance(OosEmailItemExtractor::class, $extractor);
        $corpus = $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);
        $report = $this->temporaryPath('json');

        $arguments = [...$corpus, '--evaluate' => true, '--report' => $report];
        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);
        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);

        $this->assertSame(1, $extractor->calls);
        $this->assertDatabaseCount('inbound_emails', 1);
        $email = InboundEmail::query()->firstOrFail();
        $originalHash = $email->processing_metadata['archive']['input_hash'];

        // An evaluation run produces a report for private inspection; only an --import run
        // releases entries into the operator's inbox.
        $this->assertSame(InboundEmailStatus::ArchiveEval, $email->status);
        $this->assertSame(0, app(AdminAttentionCounts::class)->counts()['pending_emails']);

        // A corrected source is a re-curation, not a file edit: changing the bytes changes the
        // payload digest, so the manifest that approved the old bytes no longer validates.
        $arguments = [...$this->corpus([
            ['key' => '2026-07-12-am', 'date' => '2026-07-12', 'body' => 'Changed song'],
        ]), '--evaluate' => true, '--report' => $report];
        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);

        $this->assertSame(2, $extractor->calls);
        $this->assertStringContainsString('Changed song', $email->fresh()->body_plain ?? '');
        $this->assertNotSame($originalHash, $email->fresh()->processing_metadata['archive']['input_hash']);
    }

    #[Test]
    public function a_stale_parser_version_forces_a_reparse_even_when_the_input_hash_matches(): void
    {
        $extractor = new class implements OosEmailItemExtractor
        {
            public int $calls = 0;

            /** @var list<string> */
            public array $receivedDates = [];

            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                $this->calls++;
                $this->receivedDates[] = $receivedDate;

                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Amazing Grace']],
                    confidence: 0.99,
                    services: [[
                        'service' => 'morning',
                        'date' => '2026-07-12',
                        'items' => [['type' => 'song', 'title' => 'Amazing Grace']],
                        'confidence' => 0.99,
                    ]],
                );
            }
        };
        $this->app->instance(OosEmailItemExtractor::class, $extractor);
        $corpus = $this->corpus([[
            'key' => '2026-07-12-am',
            'date' => '2026-07-12',
            'source_date' => '2026-07-11',
        ]]);
        $arguments = [...$corpus, '--evaluate' => true, '--report' => $this->temporaryPath('json')];

        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);
        $this->assertSame(1, $extractor->calls);

        $email = InboundEmail::query()->firstOrFail();
        $currentVersion = $email->processing_metadata[OosArchiveParseCacheBinding::MetadataKey]['raw_cache_key']['parser_version'];
        $this->assertNotNull($currentVersion);

        // Age the cache the way an unbumped parser rewrite does: the archive text is untouched, so
        // the input hash still matches and only the version can invalidate the stored extraction.
        $this->ageRawParseCache($email, ['parser_version' => 'archive-v1']);
        $email->received_at = '2026-07-10 09:00:00';
        $email->save();

        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);

        $this->assertSame(2, $extractor->calls);
        $this->assertSame(['2026-07-11', '2026-07-11'], $extractor->receivedDates);
        $refreshed = $email->fresh()->processing_metadata;
        $this->assertSame(
            $currentVersion,
            $refreshed[OosArchiveParseCacheBinding::MetadataKey]['raw_cache_key']['parser_version'],
        );
        $this->assertFalse($refreshed[OosArchiveParseCacheBinding::MetadataKey]['extraction_reused']);
        $this->assertNotNull($refreshed['parsing']['disposition']);
    }

    #[Test]
    public function a_stale_parser_surface_commit_warns_without_invalidating_a_raw_cache_entry(): void
    {
        $extractor = $this->bindCountingExtractor();
        $this->app->instance(OosArchiveParseCacheBinding::class, new OosArchiveParseCacheBinding(
            parserSurfaceCommitSha: 'current-parser-surface',
        ));
        $corpus = $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);
        $arguments = [...$corpus, '--evaluate' => true, '--report' => $this->temporaryPath('json')];

        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);

        $email = InboundEmail::query()->firstOrFail();
        $metadata = $email->processing_metadata;
        $metadata[OosArchiveParseCacheBinding::MetadataKey]['parser_surface_commit'] = 'stale-parser-surface';
        $email->processing_metadata = $metadata;
        $email->save();

        Log::spy();

        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);

        $this->assertSame(1, $extractor->calls, 'A parser-surface warning must not force a new extraction.');
        $email->refresh();
        $binding = $this->parseCacheBinding($email);
        $this->assertTrue($binding['extraction_reused']);
        $this->assertSame('current-parser-surface', $binding['parser_surface_commit']);
        Log::shouldHaveReceived('warning')
            ->once()
            ->with('OoS archive raw parse cache was produced by a stale parser surface', \Mockery::on(
                static fn (array $context): bool => $context['stored_parser_surface_commit'] === 'stale-parser-surface'
                    && $context['current_parser_surface_commit'] === 'current-parser-surface',
            ));
    }

    #[Test]
    public function a_changed_received_date_invalidates_a_same_version_cached_parse(): void
    {
        $extractor = new class implements OosEmailItemExtractor
        {
            /** @var list<string> */
            public array $receivedDates = [];

            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                $this->receivedDates[] = $receivedDate;

                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Amazing Grace']],
                    confidence: 0.99,
                    services: [[
                        'service' => 'morning',
                        'date' => '2026-07-12',
                        'items' => [['type' => 'song', 'title' => 'Amazing Grace']],
                        'confidence' => 0.99,
                    ]],
                );
            }
        };
        $this->app->instance(OosEmailItemExtractor::class, $extractor);
        $corpus = $this->corpus([[
            'key' => '2026-07-12-am',
            'date' => '2026-07-12',
            'source_date' => '2026-07-11',
        ]]);
        $arguments = [...$corpus, '--evaluate' => true, '--report' => $this->temporaryPath('json')];

        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);

        $email = InboundEmail::query()->firstOrFail();
        $this->ageRawParseCache($email, ['received_date' => '2026-07-10']);
        $email->received_at = '2026-07-10 09:00:00';
        $email->save();

        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);

        $this->assertSame(['2026-07-11', '2026-07-11'], $extractor->receivedDates);
        $this->assertSame(
            '2026-07-11',
            $email->fresh()->processing_metadata[OosArchiveParseCacheBinding::MetadataKey]['raw_cache_key']['received_date'],
        );
    }

    #[Test]
    public function import_merges_into_an_existing_service_and_creates_gap_slots(): void
    {
        $this->app->instance(OosEmailItemExtractor::class, new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                $date = str_contains($subject, '2026-07-19') ? '2026-07-19' : '2026-07-12';

                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Amazing Grace']],
                    confidence: 1.0,
                    services: [[
                        'service' => 'morning',
                        'date' => $date,
                        'items' => [['type' => 'song', 'title' => 'Amazing Grace']],
                        'confidence' => 1.0,
                    ]],
                );
            }
        });

        // An OpenLP export identifies the service but cannot carry prayers, notices or a sermon:
        // the archive email is the completeness authority and merges into it.
        $existing = ChurchService::factory()->create([
            'date' => '2026-07-12',
            'service' => 'morning',
            'source' => 'openlp',
            'import_metadata' => ['preserve' => true],
        ]);
        $corpus = $this->corpus([
            ['key' => '2026-07-12-am', 'date' => '2026-07-12'],
            ['key' => '2026-07-19-am', 'date' => '2026-07-19'],
        ]);
        $report = $this->temporaryPath('json');

        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--import' => true, '--plan-hash' => $this->planHash(),
            '--report' => $report,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('church_services', 2);
        $this->assertSame('email', $existing->fresh()->source);
        $this->assertSame('Amazing Grace', $existing->items()->firstOrFail()->title);
        $this->assertTrue($existing->fresh()->import_metadata->toArray()['preserve']);
        $this->assertDatabaseHas('church_services', [
            'date' => '2026-07-19',
            'service' => 'morning',
            'source' => 'email',
        ]);

        $payload = $this->readReport($report);
        $this->assertSame(['merged', 'created'], array_column($payload['entries'], 'disposition'));
        $this->assertSame(2, InboundEmail::query()->where('status', InboundEmailStatus::Processed)->count());
    }

    #[Test]
    public function a_single_unidentified_plan_uses_the_approved_manifest_identity(): void
    {
        $this->app->instance(OosEmailItemExtractor::class, new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Amazing Grace']],
                    confidence: 0.99,
                    services: [[
                        'service' => null,
                        'date' => null,
                        'items' => [['type' => 'song', 'title' => 'Amazing Grace']],
                        'confidence' => 0.99,
                    ]],
                );
            }
        });
        $corpus = $this->corpus([[
            'key' => '2026-07-12-pm',
            'date' => '2026-07-12',
            'service' => 'evening',
            'body' => 'Amazing Grace',
        ]]);

        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--import' => true,
            '--plan-hash' => $this->planHash(),
        ])->assertExitCode(0);

        $this->assertDatabaseHas('church_services', [
            'date' => '2026-07-12',
            'service' => 'evening',
        ]);
    }

    /**
     * The predecessor of this command had a third, "blocked" route for an entry whose own text
     * contradicted its date. Under §7.3 the manifest is mutation authority and reconciliation
     * happens up front, so a contradiction is no longer a quiet per-entry report line that a
     * reader has to notice — it stops the run before a single email is written.
     */
    #[Test]
    public function a_strict_identity_disagreement_fails_the_run_before_anything_is_touched(): void
    {
        $this->app->bind(OosEmailItemExtractor::class, fn () => new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                throw new RuntimeException('A failed reconciliation must not reach the extractor.');
            }
        });

        // The manifest resolves this entry to the morning service; the payload itself says pm.
        $corpus = $this->corpus([
            ['key' => '2026-07-12-am', 'date' => '2026-07-12', 'service' => 'morning', 'frontmatter_service' => 'pm'],
        ]);

        $this->artisan('oos:import-archive', [...$corpus, '--evaluate' => true, '--report' => $this->temporaryPath('json')])
            ->expectsOutputToContain('contradicts')
            ->assertExitCode(1);

        $this->assertDatabaseCount('inbound_emails', 0);
        $this->assertDatabaseCount('church_services', 0);
    }

    /**
     * The G8 boundary, now scoped to production rather than to the command.
     *
     * Staging Email evidence is only reachable through `--import` — nothing else
     * persists a `ChurchServiceSourceRevision` — so a prohibition on the command
     * would forbid §13.5 steps 3-4 and with them G5. What is actually forbidden
     * is doing it to production unapproved, and that is what fails here.
     */
    #[Test]
    public function an_unapproved_production_import_is_refused_before_anything_is_touched(): void
    {
        $this->bindPortableExtractor();
        $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);
        $arguments = $this->importArguments(['--report' => $this->temporaryPath('json')]);

        Config::set('church.historic_corpus.production_import_approval', null);
        $this->app['env'] = 'production';

        $this->artisan('oos:import-archive', $arguments)
            ->expectsOutputToContain('no approved G8 import operation is recorded')
            ->assertExitCode(1);

        $this->assertDatabaseCount('church_services', 0);
    }

    /**
     * The mutating call site, under HIR1's anchor guard.
     *
     * A local shell resolving the production database on a drifted release is
     * exactly the misconfiguration review finding 4 named, and before HIR1 the
     * drift is what made the guard stand down. `--import` is the operation that
     * would then have written canonical services into production.
     */
    #[Test]
    public function a_local_shell_on_the_production_database_is_refused_before_anything_is_touched(): void
    {
        $this->bindPortableExtractor();
        $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);
        $arguments = $this->importArguments(['--report' => $this->temporaryPath('json')]);

        Config::set('church.historic_corpus.production_import_approval', null);
        Config::set(
            'church.historic_corpus.production_database_anchor',
            app(HistoricImportResourceIdentity::class)->databaseAnchor(),
        );
        Config::set('app.release_identifier', 'release-that-drifted-'.uniqid());
        $this->app['env'] = 'local';

        $this->artisan('oos:import-archive', $arguments)
            ->expectsOutputToContain('no approved G8 import operation is recorded')
            ->assertExitCode(1);

        $this->assertDatabaseCount('church_services', 0);
        $this->assertDatabaseCount('inbound_emails', 0);
    }

    #[Test]
    public function a_legacy_free_form_production_token_is_not_approval(): void
    {
        $this->bindPortableExtractor();
        $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);
        $arguments = $this->importArguments(['--report' => $this->temporaryPath('json')]);

        Config::set('church.historic_corpus.production_import_approval', 'g8-2026-08-20');
        $this->app['env'] = 'production';

        $this->artisan('oos:import-archive', $arguments)
            ->expectsOutputToContain('approval artifact is missing')
            ->assertExitCode(1);

        $this->assertDatabaseCount('church_services', 0);
    }

    /**
     * §7.5 previously described evaluation mode as read-only, which it is not: it
     * writes `InboundEmail` rows and parse caches. What makes it a staging rather
     * than an importing activity is narrower and is what this pins — no canonical
     * service, and nothing handed to the operator's inbox.
     */
    #[Test]
    public function evaluation_mode_writes_evidence_but_creates_no_service_and_releases_nothing(): void
    {
        $this->bindPortableExtractor();
        $corpus = $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);

        $this->artisan('oos:import-archive', [...$corpus, '--evaluate' => true, '--report' => $this->temporaryPath('json')])
            ->assertExitCode(0);

        $this->assertDatabaseCount('inbound_emails', 1);
        $this->assertDatabaseCount('church_services', 0);

        $email = InboundEmail::query()->firstOrFail();
        $this->assertSame(InboundEmailStatus::ArchiveEval, $email->status);
        $this->assertNotNull($email->processing_metadata['parsing'] ?? null);
        $this->assertSame(0, app(AdminAttentionCounts::class)->counts()['pending_emails']);
        $this->assertSame(0, app(ReviewInboxQuery::class)->build()['counts']['emails']);
    }

    #[Test]
    public function manifest_authoritative_records_the_adjudication_and_lets_the_entry_through(): void
    {
        $this->app->instance(OosEmailItemExtractor::class, new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Amazing Grace']],
                    confidence: 1.0,
                    services: [[
                        'service' => 'morning',
                        'date' => '2026-07-12',
                        'items' => [['type' => 'song', 'title' => 'Amazing Grace']],
                        'confidence' => 1.0,
                    ]],
                );
            }
        });

        $corpus = $this->corpus([[
            'key' => '2026-07-12-am',
            'date' => '2026-07-12',
            'service' => 'morning',
            'frontmatter_service' => 'pm',
            'parse_decision' => 'manifest-authoritative',
        ]]);
        $report = $this->temporaryPath('json');

        $this->artisan('oos:import-archive', [...$corpus, '--evaluate' => true, '--report' => $report])->assertExitCode(0);

        $payload = $this->readReport($report);
        $this->assertSame([[
            'item_key' => '2026-07-12-am',
            'field' => 'service',
            'manifest' => 'morning',
            'source' => 'evening',
        ]], $payload['adjudicated_identity_disagreements']);
        $this->assertSame('eligible', $payload['entries'][0]['disposition']);
    }

    #[Test]
    public function a_partial_order_is_retained_as_incomplete_evidence_without_entering_the_review_inbox(): void
    {
        $this->app->instance(OosEmailItemExtractor::class, new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                $date = str_contains($body, 'Abide') ? '2026-07-26' : '2026-07-12';

                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Amazing Grace']],
                    confidence: 1.0,
                    services: [[
                        'service' => 'morning',
                        'date' => $date,
                        'items' => [['type' => 'song', 'title' => 'Amazing Grace']],
                        'confidence' => 1.0,
                    ]],
                );
            }
        });

        $corpus = $this->corpus([
            ['key' => '2026-07-12-am', 'date' => '2026-07-12'],
            ['key' => '2026-07-26-hymns', 'date' => '2026-07-26', 'scope' => 'partial', 'body' => 'Abide With Me'],
        ]);
        $report = $this->temporaryPath('json');

        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--import' => true, '--plan-hash' => $this->planHash(),
            '--report' => $report,
        ])->assertExitCode(0);

        $payload = $this->readReport($report);
        $this->assertSame(['created', 'evidence_retained'], array_column($payload['entries'], 'disposition'));
        $this->assertNotContains('partial_source_scope', $payload['entries'][1]['gate_reasons']);
        $this->assertSame(['full' => 1, 'partial' => 1], $payload['cohorts']);

        $this->assertDatabaseCount('church_services', 2);
        $this->assertDatabaseHas('church_services', ['date' => '2026-07-12', 'service' => 'morning']);
        $partialService = ChurchService::query()
            ->whereDate('date', '2026-07-26')
            ->where('service', 'morning')
            ->sole();
        $partialSource = $partialService->sourceRecords()->with('assertions')->sole();
        $this->assertFalse($partialSource->payload_complete);
        $this->assertCount(1, $partialSource->assertions);
        $this->assertCount(0, $partialService->items);

        $this->assertSame(InboundEmailStatus::Processed, $this->emailForEntry($payload, 1)->status);
        $this->assertSame(0, app(AdminAttentionCounts::class)->counts()['pending_emails']);
        $this->assertSame(0, app(ReviewInboxQuery::class)->build()['counts']['emails']);
    }

    /**
     * HIR0 red test for review finding 5 (High), owned by package **HIR2**.
     *
     * The parse cache key is source byte hash + parser version + received date.
     * None of those carry the curation plan, so a re-curation that leaves the
     * archive text untouched cannot invalidate the stored parse.
     *
     * `synchroniseEmail()` overwrites the `archive` metadata with the new plan
     * first, so the entry looks correctly re-curated, and the plan hash the
     * import quotes back still matches. `parseResult()` then returns the parse
     * stored under the *old* plan without running
     * `OosArchiveIdentityResolver` — the only place manifest-owned scope,
     * identity and supersession are applied — so the canonical import consumes
     * the superseded decision.
     *
     * Full to partial is the sharpest case: the approved outcome is
     * evidence-only retention with no canonical items, and the stale full scope
     * projects them anyway. The extractor call count is asserted because a run
     * that happened to reparse would pass this for the wrong reason.
     *
     * @see docs/reviews/historic-import-commit-review-2026-08-12.md finding 5
     * @see docs/archived-plans/HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md §8 (HIR2), §4 (HIR-D5)
     */
    #[Test]
    #[Group('hir-red')]
    public function a_re_curation_to_partial_cannot_reuse_a_parse_resolved_as_a_full_order(): void
    {
        $extractor = new class implements OosEmailItemExtractor
        {
            public int $calls = 0;

            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                $this->calls++;

                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Amazing Grace']],
                    confidence: 0.99,
                    services: [[
                        'service' => 'morning',
                        'date' => '2026-07-12',
                        'items' => [['type' => 'song', 'title' => 'Amazing Grace']],
                        'confidence' => 0.99,
                    ]],
                );
            }
        };
        $this->app->instance(OosEmailItemExtractor::class, $extractor);

        // Curated as a complete order, and parsed under that authority.
        $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);
        $this->artisan('oos:import-archive', [
            ...$this->corpusArguments,
            '--evaluate' => true,
            '--report' => $this->temporaryPath('json'),
        ])->assertExitCode(0);

        $this->assertSame(1, $extractor->calls);

        // Re-curated as partial. The archive text is byte-identical, so the
        // whole cache key is unchanged and only the manifest has moved.
        $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12', 'scope' => 'partial']]);
        $report = $this->temporaryPath('json');

        $this->artisan('oos:import-archive', $this->importArguments(['--report' => $report]))
            ->assertExitCode(0);

        $payload = $this->readReport($report);

        $this->assertSame(1, $extractor->calls, 'Unchanged source bytes must not force another model call.');
        $this->assertSame('evidence_retained', $payload['entries'][0]['disposition']);
        $this->assertDatabaseCount('church_service_items', 0);
        $this->assertFalse(
            ChurchService::query()->sole()->sourceRecords()->sole()->payload_complete,
            'A partial order must be retained as incomplete evidence, not projected as a complete one.',
        );
    }

    /**
     * HIR2's other re-curation cases, all sharing one shape: the archive text
     * never changes, so the raw extraction is reused and only the manifest has
     * moved. Each asserts that the entry's recorded authority moved with it and
     * that the extractor was not called a second time.
     *
     * @param  array<string, mixed>  $recuration
     */
    #[Test]
    #[DataProvider('recurationsThatMustReResolve')]
    public function a_re_curation_re_resolves_a_reused_extraction(array $recuration): void
    {
        $extractor = $this->bindCountingExtractor();
        $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);
        $this->artisan('oos:import-archive', [
            ...$this->corpusArguments,
            '--evaluate' => true,
            '--report' => $this->temporaryPath('json'),
        ])->assertExitCode(0);

        $this->assertSame(1, $extractor->calls);
        $before = $this->parseCacheBinding();

        $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12', ...$recuration]]);
        $report = $this->temporaryPath('json');
        $this->artisan('oos:import-archive', [
            ...$this->corpusArguments,
            '--evaluate' => true,
            '--report' => $report,
        ])->assertExitCode(0);

        $after = $this->parseCacheBinding();

        $this->assertSame(1, $extractor->calls, 'Unchanged source bytes must not force another model call.');
        $this->assertTrue($after['extraction_reused']);
        $this->assertSame($before['raw_result_hash'], $after['raw_result_hash'], 'The reused extraction must be carried forward unchanged.');
        $this->assertNotSame(
            $before['entry_authority_hash'],
            $after['entry_authority_hash'],
            'A re-curation that changes what the entry resolves to must change its recorded authority.',
        );
        /** Compared loosely: MySQL's JSON type reorders object keys on the way back out. */
        $this->assertEquals(
            $after,
            $this->readReport($report)['entries'][0]['parse_cache'],
            'The report must carry the binding the entry was actually resolved under.',
        );
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function recurationsThatMustReResolve(): array
    {
        return [
            'full to partial' => [['scope' => 'partial']],
            'a different resolved service' => [['service' => 'evening']],
            'a parse decision overridden' => [['parse_decision' => 'manifest-authoritative']],
            'an asserted item count' => [['expected_item_count' => 3]],
        ];
    }

    /**
     * A re-keyed predecessor.
     *
     * A same-identity pair with no declared lineage is refused when the plan is
     * built, so "supersession added" is not a state this command can be walked
     * through. What a maintainer really does is re-key an entry, and the
     * correction that names it then supersedes a different source key while its
     * own bytes never move — reused extraction, changed authority.
     */
    #[Test]
    public function a_re_keyed_predecessor_re_resolves_the_correction_that_names_it(): void
    {
        $extractor = $this->bindCountingExtractor();
        $corpusEntries = [
            ['key' => '2026-07-12-original', 'date' => '2026-07-12', 'subject' => 'Original order'],
            [
                'key' => '2026-07-12-correction',
                'date' => '2026-07-12',
                'subject' => 'Corrected order',
                'body' => "Morning service\nAmazing Grace\nCorrected",
                'supersedes' => '2026-07-12-original',
            ],
        ];
        $arguments = fn (): array => [
            ...$this->corpusArguments,
            '--evaluate' => true,
            '--report' => $this->temporaryPath('json'),
        ];

        $this->corpus($corpusEntries);
        $this->artisan('oos:import-archive', $arguments())->assertExitCode(0);

        $this->assertSame(2, $extractor->calls);
        $before = $this->parseCacheBinding($this->archiveEmailContaining('Corrected'));

        $corpusEntries[0]['key'] = '2026-07-12-original-rekeyed';
        $corpusEntries[1]['supersedes'] = '2026-07-12-original-rekeyed';
        $this->corpus($corpusEntries);
        $this->artisan('oos:import-archive', $arguments())->assertExitCode(0);

        $after = $this->parseCacheBinding($this->archiveEmailContaining('Corrected'));

        $this->assertSame(3, $extractor->calls, 'Only the re-keyed predecessor is a new entry to extract.');
        $this->assertTrue($after['extraction_reused']);
        $this->assertSame($before['raw_result_hash'], $after['raw_result_hash']);
        $this->assertNotSame(
            $before['entry_authority_hash'],
            $after['entry_authority_hash'],
            'The correction now supersedes a different source key, so its authority moved.',
        );
    }

    /**
     * A new approved plan whose entry is semantically unchanged.
     *
     * The plan hash moves because the batch gained an entry, so the binding
     * records a new plan. What the entry itself resolves to has not changed, so
     * neither its authority hash nor its extraction may.
     */
    #[Test]
    public function a_new_plan_over_an_unchanged_entry_rebinds_without_re_resolving_it(): void
    {
        $extractor = $this->bindCountingExtractor();
        $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);
        $this->artisan('oos:import-archive', [
            ...$this->corpusArguments,
            '--evaluate' => true,
            '--report' => $this->temporaryPath('json'),
        ])->assertExitCode(0);

        $before = $this->parseCacheBinding();

        $this->corpus([
            ['key' => '2026-07-12-am', 'date' => '2026-07-12'],
            ['key' => '2026-07-19-am', 'date' => '2026-07-19'],
        ]);
        $this->artisan('oos:import-archive', [
            ...$this->corpusArguments,
            '--evaluate' => true,
            '--report' => $this->temporaryPath('json'),
        ])->assertExitCode(0);

        $after = $this->parseCacheBinding();

        $this->assertSame(2, $extractor->calls, 'Only the new entry may be extracted.');
        $this->assertNotSame($before['curation_plan_hash'], $after['curation_plan_hash']);
        $this->assertSame($before['entry_authority_hash'], $after['entry_authority_hash']);
        $this->assertSame($before['raw_result_hash'], $after['raw_result_hash']);
        $this->assertSame($before['resolved_result_hash'], $after['resolved_result_hash']);
    }

    /**
     * Metadata from before HIR2 cached a *resolved* result. There is no way to
     * recover the model output it was derived from, so the entry is reparsed
     * once rather than guessed at, and the old block is left where it is.
     */
    #[Test]
    public function a_pre_hir2_resolved_cache_is_retained_but_never_reused(): void
    {
        $extractor = $this->bindCountingExtractor();
        $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);
        $arguments = [
            ...$this->corpusArguments,
            '--evaluate' => true,
            '--report' => $this->temporaryPath('json'),
        ];
        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);

        // Roll the email back to the shape the old contract left behind: a
        // resolved parse keyed on source bytes, parser version and date.
        $email = InboundEmail::query()->firstOrFail();
        $metadata = $email->processing_metadata;
        $legacyCache = $metadata[OosArchiveParseCacheBinding::MetadataKey]['raw_cache_key'];
        unset($metadata[OosArchiveParseCacheBinding::MetadataKey]);
        $metadata['parsing'] = [...$metadata['parsing'], ...$legacyCache];
        $email->processing_metadata = $metadata;
        $email->save();

        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);

        $this->assertSame(2, $extractor->calls, 'A resolved-only cache cannot stand in for raw model output.');
        $refreshed = $this->parseCacheBinding();
        $this->assertSame(OosArchiveParseCacheBinding::Version, $refreshed['version']);
        $this->assertFalse($refreshed['extraction_reused']);

        // A third run reuses the binding the second one wrote.
        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);
        $this->assertSame(2, $extractor->calls);
        $this->assertTrue($this->parseCacheBinding()['extraction_reused']);
    }

    /**
     * `--fresh-parse` buys another model call and nothing else: the curation
     * binding is applied and recorded exactly as it is on a reusing run.
     */
    #[Test]
    public function fresh_parse_replaces_the_extraction_and_still_records_the_current_curation(): void
    {
        $extractor = $this->bindCountingExtractor();
        $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);
        $arguments = [
            ...$this->corpusArguments,
            '--evaluate' => true,
            '--report' => $this->temporaryPath('json'),
        ];
        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);

        $before = $this->parseCacheBinding();

        $this->artisan('oos:import-archive', [...$arguments, '--fresh-parse' => true])->assertExitCode(0);

        $after = $this->parseCacheBinding();

        $this->assertSame(2, $extractor->calls);
        $this->assertFalse($after['extraction_reused']);
        $this->assertSame($before['raw_cache_key_hash'], $after['raw_cache_key_hash']);
        $this->assertSame($before['entry_authority_hash'], $after['entry_authority_hash']);
        $this->assertSame($before['resolved_result_hash'], $after['resolved_result_hash']);
    }

    /**
     * The manifest corroborates one plan; the model proposed two. Curated scope
     * belongs to the corroborated plan alone, so a re-curation moves that plan
     * and leaves the extra one exactly as the parser left it — an entry's
     * authority does not reach a plan its manifest never asserted.
     */
    #[Test]
    public function an_extra_uncorroborated_plan_keeps_its_own_unknown_scope_across_a_re_resolve(): void
    {
        $extractor = $this->bindCountingExtractor([
            [
                'service' => 'morning',
                'date' => '2026-07-12',
                'items' => [['type' => 'song', 'title' => 'Amazing Grace']],
                'confidence' => 0.99,
            ],
            [
                'service' => 'morning',
                'date' => '2026-07-19',
                'items' => [['type' => 'song', 'title' => 'Invented Extra Hymn']],
                'confidence' => 0.99,
            ],
        ]);
        $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);
        $arguments = [
            ...$this->corpusArguments,
            '--evaluate' => true,
            '--report' => $this->temporaryPath('json'),
        ];
        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);

        $before = $this->planContentScopesByDate();
        $this->assertSame('full', $before['2026-07-12']);

        $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12', 'scope' => 'partial']]);
        $this->artisan('oos:import-archive', [
            ...$this->corpusArguments,
            '--evaluate' => true,
            '--report' => $this->temporaryPath('json'),
        ])->assertExitCode(0);

        $this->assertSame(1, $extractor->calls);

        $after = $this->planContentScopesByDate();

        $this->assertSame('partial', $after['2026-07-12'], 'The corroborated plan takes the curated scope.');
        $this->assertSame(
            $before['2026-07-19'],
            $after['2026-07-19'],
            'An uncorroborated plan has no curated scope to take.',
        );
    }

    #[Test]
    public function an_import_refuses_a_plan_hash_that_does_not_match_the_current_curation(): void
    {
        $corpus = $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);

        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--import' => true,
            '--plan-hash' => str_repeat('0', 64),
            '--report' => $this->temporaryPath('json'),
        ])->assertExitCode(1);

        $this->assertDatabaseCount('church_services', 0);
    }

    #[Test]
    public function it_refuses_to_run_without_a_curation_manifest(): void
    {
        $this->artisan('oos:import-archive', ['--dry-run' => true])
            ->expectsOutputToContain('An approved curation manifest is required')
            ->assertExitCode(1);
    }

    #[Test]
    public function re_running_the_import_merges_idempotently(): void
    {
        $this->app->instance(OosEmailItemExtractor::class, new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                $items = [
                    ['type' => 'song', 'title' => 'Amazing Grace'],
                    ['type' => 'sermon', 'title' => 'The Good Shepherd'],
                ];

                return new OosEmailItemExtractionResult(
                    items: $items,
                    confidence: 1.0,
                    services: [[
                        'service' => 'morning',
                        'date' => '2026-07-12',
                        'items' => $items,
                        'confidence' => 1.0,
                    ]],
                );
            }
        });
        $corpus = $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);
        $arguments = [...$corpus, '--import' => true, '--plan-hash' => $this->planHash(), '--report' => $this->temporaryPath('json')];

        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);

        $service = ChurchService::query()->where('date', '2026-07-12')->firstOrFail();
        $firstPass = $service->items()->orderBy('position')->pluck('title')->all();
        $this->assertCount(2, $firstPass);

        $report = $this->temporaryPath('json');
        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--import' => true, '--plan-hash' => $this->planHash(),
            '--fresh-parse' => true,
            '--report' => $report,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('church_services', 1);
        $this->assertSame($firstPass, $service->items()->orderBy('position')->pluck('title')->all());
        $this->assertSame('merged', $this->readReport($report)['entries'][0]['disposition']);
    }

    #[Test]
    public function a_reparse_that_holds_every_plan_returns_a_processed_entry_to_the_inbox(): void
    {
        // Exactly the shape of an invalidated cache: the archive text is untouched, but the
        // re-parse now lands its date off the document the manifest approved. The manifest-
        // corroboration gate is the one identity failure REV-D2 explicitly keeps held — "do not
        // weaken it" — so nothing is applied to the service the earlier run built, and the entry
        // has to become visible again rather than stay Processed.
        $extractor = new class implements OosEmailItemExtractor
        {
            public string $date = '2026-07-12';

            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Amazing Grace']],
                    confidence: 0.99,
                    services: [[
                        'service' => 'morning',
                        'date' => $this->date,
                        'items' => [['type' => 'song', 'title' => 'Amazing Grace']],
                        'confidence' => 0.99,
                    ]],
                );
            }
        };
        $this->app->instance(OosEmailItemExtractor::class, $extractor);
        $corpus = $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);

        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--import' => true, '--plan-hash' => $this->planHash(),
            '--report' => $this->temporaryPath('json'),
        ])->assertExitCode(0);

        $this->assertSame(InboundEmailStatus::Processed, InboundEmail::query()->firstOrFail()->status);
        $service = ChurchService::query()->where('date', '2026-07-12')->firstOrFail();

        // A date the manifest never approved for this document — the identity gate, not merely
        // low confidence.
        $extractor->date = '2026-07-19';
        $report = $this->temporaryPath('json');

        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--import' => true, '--plan-hash' => $this->planHash(),
            '--fresh-parse' => true,
            '--report' => $report,
            // IC2: held/pending residue is reported state, not a command failure —
            // see hasUnsettledResults(). Only failed/import_failed exit non-zero.
        ])->assertExitCode(0);

        $entryReport = $this->readReport($report)['entries'][0];
        $this->assertSame('held_for_review', $entryReport['disposition']);
        $this->assertSame(1, $entryReport['attempt_count']);
        $this->assertSame([], $entryReport['corroborated_plan_keys']);
        $this->assertNull($entryReport['imported_plan_keys']);
        $this->assertTrue($entryReport['held']);
        $this->assertSame(['no_corroborated_plan'], $entryReport['gate_reasons']);
        $this->assertFalse($entryReport['adjudicated']);
        $this->assertSame(InboundEmailStatus::Pending, InboundEmail::query()->firstOrFail()->status);
        $this->assertSame(1, app(ReviewInboxQuery::class)->build()['counts']['emails']);
        $this->assertSame('Amazing Grace', $service->items()->firstOrFail()->title);
    }

    #[Test]
    public function a_reparse_below_the_auto_import_bar_still_merges_as_unfinalised_evidence(): void
    {
        // IC1/REV-D2: unlike the identity-failure case above, a re-parse that keeps the same
        // corroborated identity but drops below the auto-import bar is not held — it merges as
        // unreviewed, unfinalised evidence, exactly as a live corrective email would.
        $extractor = new class implements OosEmailItemExtractor
        {
            public float $confidence = 1.0;

            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Amazing Grace']],
                    confidence: $this->confidence,
                    services: [[
                        'service' => 'morning',
                        'date' => '2026-07-12',
                        'items' => [['type' => 'song', 'title' => 'Amazing Grace']],
                        'confidence' => $this->confidence,
                    ]],
                );
            }
        };
        $this->app->instance(OosEmailItemExtractor::class, $extractor);
        $corpus = $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);

        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--import' => true, '--plan-hash' => $this->planHash(),
            '--report' => $this->temporaryPath('json'),
        ])->assertExitCode(0);

        $service = ChurchService::query()->where('date', '2026-07-12')->firstOrFail();
        $this->assertFalse((bool) $service->needs_review);

        $extractor->confidence = 0.4;
        $report = $this->temporaryPath('json');

        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--import' => true, '--plan-hash' => $this->planHash(),
            '--fresh-parse' => true,
            '--report' => $report,
        ])->assertExitCode(0);

        $entryReport = $this->readReport($report)['entries'][0];
        $this->assertSame('merged', $entryReport['disposition']);
        $this->assertSame('review_required', $entryReport['plans'][0]['disposition']);
        $this->assertSame(['low_confidence'], $entryReport['plans'][0]['hold_reasons']);
        $this->assertSame(InboundEmailStatus::Processed, InboundEmail::query()->firstOrFail()->status);
        $service->refresh();
        $this->assertTrue((bool) $service->needs_review);
        $this->assertSame([false], array_column($service->import_metadata->toArray()['email_evidence'], 'finalised'));
    }

    /**
     * The manifest is authority over the source's *date*, and that gate is real: a plan the parser
     * places on some other Sunday is not this document's order and must not be written. A second
     * service on the curated date is a different matter entirely — see the multi-service test.
     */
    #[Test]
    public function import_skips_a_plan_dated_outside_the_curated_service(): void
    {
        $this->app->instance(OosEmailItemExtractor::class, new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [
                        ['type' => 'song', 'title' => 'Amazing Grace'],
                        ['type' => 'sermon', 'title' => 'Next Week'],
                    ],
                    confidence: 0.99,
                    services: [
                        ['service' => 'morning', 'date' => '2026-07-12', 'items' => [
                            ['type' => 'song', 'title' => 'Amazing Grace'],
                        ], 'confidence' => 0.99],
                        // A date the manifest never approved for this document.
                        ['service' => 'evening', 'date' => '2026-07-19', 'items' => [
                            ['type' => 'sermon', 'title' => 'Next Week'],
                        ], 'confidence' => 0.99],
                    ],
                );
            }
        });
        $corpus = $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);
        $report = $this->temporaryPath('json');

        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--import' => true, '--plan-hash' => $this->planHash(),
            '--report' => $report,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('church_services', 1);
        $this->assertDatabaseHas('church_services', ['date' => '2026-07-12', 'service' => 'morning']);
        $this->assertDatabaseMissing('church_services', ['date' => '2026-07-19']);
    }

    #[Test]
    public function an_import_failure_surfaces_as_a_failed_disposition(): void
    {
        $this->app->instance(OosEmailItemExtractor::class, new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Amazing Grace']],
                    confidence: 1.0,
                    services: [[
                        'service' => 'morning',
                        'date' => '2026-07-12',
                        'items' => [['type' => 'song', 'title' => 'Amazing Grace']],
                        'confidence' => 1.0,
                    ]],
                );
            }
        });
        $this->mock(ChurchServiceSongLinker::class, function (MockInterface $mock): void {
            $mock->shouldReceive('linkForService')->andThrow(new RuntimeException('song sync exploded'));
        });
        $corpus = $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);
        $report = $this->temporaryPath('json');

        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--import' => true, '--plan-hash' => $this->planHash(),
            '--report' => $report,
        ])->assertExitCode(1);

        $this->assertDatabaseCount('church_services', 0);
        $payload = $this->readReport($report);
        $this->assertSame('import_failed', $payload['entries'][0]['disposition']);
        $this->assertStringContainsString('song sync exploded', (string) $payload['entries'][0]['error']);
        // A failed import leaves the email in the inbox, exactly as a live email would be.
        $this->assertSame(InboundEmailStatus::Pending, InboundEmail::query()->firstOrFail()->status);
    }

    #[Test]
    public function a_corroborated_plan_below_the_auto_import_threshold_imports_as_unfinalised_evidence(): void
    {
        // IC1/REV-D2: both services are in the ground truth, so the archive corroborates both
        // plans by identity. The evening plan's confidence never reaches the auto-import bar, so
        // it is not finalised — but its identity is trustworthy, so it now imports as unreviewed
        // source evidence rather than being held outright.
        $this->app->instance(OosEmailItemExtractor::class, new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [
                        ['type' => 'song', 'title' => 'Amazing Grace'],
                        ['type' => 'song', 'title' => 'Abide With Me'],
                    ],
                    confidence: 0.99,
                    services: [
                        ['service' => 'morning', 'date' => '2026-07-12', 'items' => [
                            ['type' => 'song', 'title' => 'Amazing Grace'],
                        ], 'confidence' => 0.99],
                        ['service' => 'evening', 'date' => '2026-07-12', 'items' => [
                            ['type' => 'song', 'title' => 'Abide With Me'],
                        ], 'confidence' => 0.60],
                    ],
                );
            }
        });
        $corpus = $this->corpus([[
            'key' => '2026-07-12-am',
            'date' => '2026-07-12',
            'body' => "Morning service\nAmazing Grace\n\nEvening service\nAbide With Me",
        ]]);
        $report = $this->temporaryPath('json');

        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--import' => true, '--plan-hash' => $this->planHash(),
            '--report' => $report,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('church_services', 2);
        $this->assertDatabaseHas('church_services', ['date' => '2026-07-12', 'service' => 'morning']);
        $evening = ChurchService::query()->where('date', '2026-07-12')->where('service', 'evening')->firstOrFail();
        $this->assertTrue((bool) $evening->needs_review);
        $eveningMetadata = $evening->import_metadata->toArray();
        $this->assertSame('review_required', $eveningMetadata['plan']['disposition']);
        $this->assertSame([false], array_column($eveningMetadata['email_evidence'], 'finalised'));
        $this->assertSame('created', $this->readReport($report)['entries'][0]['disposition']);

        // REV-D2 tier three: it exists in the graph, and it is not releasable.
        $sermon = Sermon::factory()->create(['date' => '2026-07-12', 'service' => SermonService::Evening]);
        $this->assertSame(
            ['2026-07-12 evening'],
            app(HistoricEmailEvidenceReleaseGate::class)->ineligibleServiceLabels([$sermon->id]),
        );
    }

    #[Test]
    public function a_second_round_over_an_evidence_tier_plan_is_an_exact_no_op(): void
    {
        // IC1 red test 5: re-running the import over the same corroborated-but-unfinalised plan
        // must not create a duplicate service or duplicate items.
        $this->app->instance(OosEmailItemExtractor::class, new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Abide With Me']],
                    confidence: 0.60,
                    services: [
                        ['service' => 'morning', 'date' => '2026-07-12', 'items' => [
                            ['type' => 'song', 'title' => 'Abide With Me'],
                        ], 'confidence' => 0.60],
                    ],
                );
            }
        });
        $corpus = $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);
        $arguments = [...$corpus, '--import' => true, '--plan-hash' => $this->planHash(), '--report' => $this->temporaryPath('json')];

        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);

        $service = ChurchService::query()->where('date', '2026-07-12')->firstOrFail();
        $this->assertTrue((bool) $service->needs_review);
        $firstPassItems = $service->items()->orderBy('position')->pluck('title')->all();

        $report = $this->temporaryPath('json');
        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--import' => true, '--plan-hash' => $this->planHash(),
            '--fresh-parse' => true,
            '--report' => $report,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('church_services', 1);
        $this->assertSame($firstPassItems, $service->items()->orderBy('position')->pluck('title')->all());
        $this->assertSame('merged', $this->readReport($report)['entries'][0]['disposition']);
    }

    /**
     * One email routinely carries both that Sunday's orders — at least nine in the real corpus do,
     * and the live pipeline imports both (see OosMultiServiceImportTest). The archive must not be
     * stingier than the live path with the very same emails, so the manifest's single
     * `resolved_service` names the document without capping what it may contain.
     */
    #[Test]
    public function both_orders_in_one_email_are_imported_just_as_the_live_pipeline_does(): void
    {
        $this->app->instance(OosEmailItemExtractor::class, new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [
                        ['type' => 'song', 'title' => 'Amazing Grace'],
                        ['type' => 'song', 'title' => 'Abide With Me'],
                    ],
                    confidence: 0.99,
                    services: [
                        ['service' => 'morning', 'date' => '2026-07-12', 'items' => [
                            ['type' => 'song', 'title' => 'Amazing Grace'],
                        ], 'confidence' => 0.99],
                        ['service' => 'evening', 'date' => '2026-07-12', 'items' => [
                            ['type' => 'song', 'title' => 'Abide With Me'],
                        ], 'confidence' => 0.99],
                    ],
                );
            }
        });
        $corpus = $this->corpus([[
            'key' => '2026-07-12-am',
            'date' => '2026-07-12',
            'body' => "Morning service\nAmazing Grace\n\nEvening service\nAbide With Me",
        ]]);
        $report = $this->temporaryPath('json');

        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--import' => true, '--plan-hash' => $this->planHash(),
            '--report' => $report,
        ])->assertExitCode(0);

        $payload = $this->readReport($report);
        $entry = $payload['entries'][0];

        $this->assertSame(['morning', 'evening'], $entry['services']['detected']);
        $this->assertCount(2, $entry['plans']);
        $this->assertSame('multi_service', $payload['pipeline_mode']);
        $this->assertTrue($entry['plans'][0]['gate_eligible']);
        $this->assertTrue($entry['plans'][1]['gate_eligible']);

        // Both orders become services, exactly as ProcessInboundOosEmail would have done in 2019.
        $this->assertDatabaseCount('church_services', 2);
        $this->assertDatabaseHas('church_services', ['date' => '2026-07-12', 'service' => 'morning']);
        $this->assertDatabaseHas('church_services', ['date' => '2026-07-12', 'service' => 'evening']);
        $this->assertSame('created', $entry['disposition']);

        // The manifest names this entry by its morning service only, and the report says so —
        // curation feedback about an under-describing entry, not a dropped order.
        $this->assertContains('service_beyond_manifest', $entry['parse_flags']);
        $this->assertSame(1, $payload['aggregate']['parse_flag_counts']['service_beyond_manifest']);
    }

    /**
     * The opposite case, and the one that still deserves a human: the manifest says this is the
     * morning service and the parse found no morning order at all.
     */
    #[Test]
    public function an_entry_whose_curated_service_the_parse_never_finds_is_held_for_review(): void
    {
        $this->app->instance(OosEmailItemExtractor::class, new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Abide With Me']],
                    confidence: 0.99,
                    services: [[
                        'service' => 'evening',
                        'date' => '2026-07-12',
                        'items' => [['type' => 'song', 'title' => 'Abide With Me']],
                        'confidence' => 0.99,
                    ]],
                );
            }
        });
        $corpus = $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12', 'service' => 'morning']]);
        $report = $this->temporaryPath('json');

        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--import' => true, '--plan-hash' => $this->planHash(),
            '--report' => $report,
            // IC2: held/pending residue is reported state, not a command failure. The exit code
            // used to be the only signal a source was held, so the summary has to carry it now —
            // otherwise a round that held everything looks exactly like a clean one.
        ])
            ->expectsOutputToContain('held_for_review')
            ->assertExitCode(0);

        $payload = $this->readReport($report);
        $this->assertSame('held_for_review', $payload['entries'][0]['disposition']);
        $this->assertContains('curated_service_not_parsed', $payload['entries'][0]['gate_reasons']);
        $this->assertDatabaseCount('church_services', 0);
        $this->assertSame(InboundEmailStatus::Pending, $this->emailForEntry($payload, 0)->status);
    }

    #[Test]
    public function a_changed_source_after_import_is_reported_without_touching_the_service(): void
    {
        $this->app->instance(OosEmailItemExtractor::class, new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                $item = ['type' => 'song', 'title' => trim($body)];

                return new OosEmailItemExtractionResult(
                    items: [$item],
                    confidence: 1.0,
                    services: [[
                        'service' => 'morning',
                        'date' => '2026-07-12',
                        'items' => [$item],
                        'confidence' => 1.0,
                    ]],
                );
            }
        });
        $corpus = $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);
        $report = $this->temporaryPath('json');
        $arguments = [...$corpus, '--import' => true, '--plan-hash' => $this->planHash(), '--report' => $report];
        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);
        $service = ChurchService::query()->firstOrFail();
        $originalItems = $service->items()->pluck('title')->all();

        // Re-curating the same service with corrected bytes: same item key, new payload digest.
        $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12', 'body' => 'Corrected content']]);
        // IC2: held/pending residue is reported state, not a command failure.
        $this->artisan('oos:import-archive', $this->importArguments(['--report' => $report]))
            ->assertExitCode(0);

        $this->assertSame($originalItems, $service->items()->pluck('title')->all());
        $payload = $this->readReport($report);
        $this->assertContains('source_updated_after_import', $payload['entries'][0]['flags']);
        $this->assertSame('held_for_review', $payload['entries'][0]['disposition']);

        // Corrected archive text after an import is precisely what a human must re-check, so the
        // already-processed email is pushed back into the inbox rather than silently re-imported.
        $this->assertSame(InboundEmailStatus::Pending, InboundEmail::query()->firstOrFail()->status);
    }

    #[Test]
    public function portable_assertions_round_trip_without_ids_or_extractor_calls_and_apply_idempotently(): void
    {
        $this->bindPortableExtractor();
        $corpus = $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);
        $bundle = storage_path('scratch/tests/oos-portable-'.uniqid().'.json');
        $this->temporaryPaths[] = $bundle;

        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--export-bundle' => $bundle,
            '--report' => $this->temporaryPath('json'),
        ])->assertExitCode(0);

        $payload = $this->readReport($bundle);
        $this->assertSame('crockenhill-oos-assertions', $payload['format']);
        $this->assertStringNotContainsString('"id":', (string) file_get_contents($bundle));
        $this->assertStringNotContainsString('inbound_email_id', (string) file_get_contents($bundle));

        InboundEmail::query()->delete();
        InboundEmail::factory()->create(['message_id' => '<different-primary-key@example.test>']);
        $this->app->bind(OosEmailItemExtractor::class, fn () => new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                throw new RuntimeException('Portable bundle modes must not call the extractor.');
            }
        });

        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--import-bundle' => $bundle,
        ])->assertExitCode(0);
        $this->assertDatabaseCount('church_services', 0);
        $stagedEmail = InboundEmail::query()->where('message_id', 'like', '<oos-%')->firstOrFail();
        $this->assertNotSame(1, $stagedEmail->id);
        $this->assertNull($stagedEmail->body_plain);

        $arguments = [...$corpus, '--apply-bundle' => $bundle];
        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);
        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);

        $this->assertDatabaseCount('church_services', 1);
        $this->assertDatabaseCount('church_service_items', 1);
        $this->assertDatabaseHas('church_services', ['date' => '2026-07-12', 'service' => 'morning']);
    }

    /**
     * Found by the 2026-08-11 staging run, which lost `2026-03-15-am-second-hand`.
     *
     * The confidence gate and the supersession contract were independent: the
     * predecessor parsed at 0.85 and was held for review, its correction parsed at
     * 0.92 and imported, and the ingest then refused the correction because the
     * record it supersedes did not exist. A correction chain is now admitted or
     * held as a unit, so neither half can be imported without the other.
     *
     * IC1/REV-D2 changes what "admitted" means for the predecessor half: a corroborated
     * low-confidence plan is no longer held outright, it imports as unreviewed evidence — so a
     * correction chain whose only defect was the predecessor's confidence now flows through both
     * halves rather than being stuck. {@see self::a_correction_stays_held_when_its_predecessor_fails_the_identity_gate()}
     * covers the case that still holds.
     */
    #[Test]
    public function a_correction_imports_when_its_predecessor_only_missed_the_auto_import_bar(): void
    {
        $this->app->instance(OosEmailItemExtractor::class, new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                $corrected = str_contains($subject, 'Corrected');
                $confidence = $corrected ? 0.92 : 0.85;
                $item = [
                    'type' => 'song',
                    'title' => $corrected ? 'Corrected order' : 'Original order',
                    'source_line_ids' => [2],
                    'continuation' => false,
                ];

                return new OosEmailItemExtractionResult(
                    items: [$item],
                    confidence: $confidence,
                    services: [[
                        'service' => 'morning', 'date' => '2026-07-12', 'service_evidence_line_ids' => [1],
                        'items' => [$item], 'confidence' => $confidence,
                    ]],
                    serviceCount: 1,
                    provenanceComplete: true,
                );
            }
        });
        $corpus = $this->corpus([
            ['key' => '2026-07-12-original', 'date' => '2026-07-12', 'subject' => 'Original order'],
            ['key' => '2026-07-12-correction', 'date' => '2026-07-12', 'subject' => 'Corrected order', 'supersedes' => '2026-07-12-original'],
        ]);
        $report = $this->temporaryPath('json');

        $this->artisan('oos:import-archive', [
            ...$corpus, '--import' => true, '--plan-hash' => $this->planHash(), '--report' => $report,
        ])->assertExitCode(0);

        $entries = collect($this->readReport($report)['entries'])->keyBy('item_key');

        $this->assertSame('created', $entries['2026-07-12-original']['disposition']);
        $this->assertSame('merged', $entries['2026-07-12-correction']['disposition']);
        $this->assertSame([], $entries['2026-07-12-correction']['gate_reasons']);

        $service = ChurchService::query()->where('date', '2026-07-12')->firstOrFail();
        $this->assertCount(1, $service->items);

        // Both revisions keep their own tier: the predecessor was never finalised, the correction
        // cleared the auto-import bar outright. Recording one flag per service could only have
        // kept whichever wrote last.
        $evidence = $service->import_metadata->toArray()['email_evidence'];
        $this->assertSame([false, true], array_column($evidence, 'finalised'));

        // Release still goes ahead: the unfinalised evidence is the superseded predecessor, and
        // supersession is exactly how a correction chain resolves it.
        $sermon = Sermon::factory()->create(['date' => '2026-07-12', 'service' => SermonService::Morning]);
        $this->assertSame([], app(HistoricEmailEvidenceReleaseGate::class)->ineligibleServiceLabels([$sermon->id]));
    }

    /**
     * The identity gate REV-D2 keeps held is different from the confidence bar the test above now
     * clears: a plan the manifest never corroborates is not admitted as evidence at any
     * confidence, so the chain it heads stays held as a unit exactly as before.
     */
    #[Test]
    public function a_correction_stays_held_when_its_predecessor_fails_the_identity_gate(): void
    {
        $this->app->instance(OosEmailItemExtractor::class, new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                $corrected = str_contains($subject, 'Corrected');
                // The uncorrected document's own extraction disagrees with the manifest about
                // which date it belongs to — an identity failure, not a confidence one.
                $date = $corrected ? '2026-07-12' : '2026-07-19';
                $item = [
                    'type' => 'song',
                    'title' => $corrected ? 'Corrected order' : 'Original order',
                    'source_line_ids' => [2],
                    'continuation' => false,
                ];

                return new OosEmailItemExtractionResult(
                    items: [$item],
                    confidence: 0.99,
                    services: [[
                        'service' => 'morning', 'date' => $date, 'service_evidence_line_ids' => [1],
                        'items' => [$item], 'confidence' => 0.99,
                    ]],
                    serviceCount: 1,
                    provenanceComplete: true,
                );
            }
        });
        $corpus = $this->corpus([
            ['key' => '2026-07-12-original', 'date' => '2026-07-12', 'subject' => 'Original order'],
            ['key' => '2026-07-12-correction', 'date' => '2026-07-12', 'subject' => 'Corrected order', 'supersedes' => '2026-07-12-original'],
        ]);
        $report = $this->temporaryPath('json');

        // IC2: held/pending residue is reported state, not a command failure.
        $this->artisan('oos:import-archive', [
            ...$corpus, '--import' => true, '--plan-hash' => $this->planHash(), '--report' => $report,
        ])->assertExitCode(0);

        $entries = collect($this->readReport($report)['entries'])->keyBy('item_key');

        $this->assertSame('held_for_review', $entries['2026-07-12-original']['disposition']);
        $this->assertSame('held_for_review', $entries['2026-07-12-correction']['disposition']);
        $this->assertContains(
            'superseded_predecessor_not_imported',
            $entries['2026-07-12-correction']['gate_reasons'],
        );
        // The point of holding it: nothing was written that the operator has not seen.
        $this->assertDatabaseCount('church_service_source_records', 0);
    }

    #[Test]
    public function manifest_authorised_email_correction_replaces_its_predecessor_in_direct_and_portable_imports(): void
    {
        $this->app->instance(OosEmailItemExtractor::class, new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                $title = str_contains($subject, 'Corrected') ? 'Corrected order' : 'Original order';
                $item = ['type' => 'song', 'title' => $title, 'source_line_ids' => [2], 'continuation' => false];

                return new OosEmailItemExtractionResult(
                    items: [$item],
                    confidence: 1.0,
                    services: [[
                        'service' => 'morning', 'date' => '2026-07-12', 'service_evidence_line_ids' => [1],
                        'items' => [$item], 'confidence' => 1.0,
                    ]],
                    serviceCount: 1,
                    provenanceComplete: true,
                );
            }
        });
        $corpus = $this->corpus([
            ['key' => '2026-07-12-original', 'date' => '2026-07-12', 'subject' => 'Original order'],
            ['key' => '2026-07-12-correction', 'date' => '2026-07-12', 'subject' => 'Corrected order', 'supersedes' => '2026-07-12-original'],
        ]);

        $this->artisan('oos:import-archive', [
            ...$corpus, '--import' => true, '--plan-hash' => $this->planHash(),
        ])->assertExitCode(0);

        $this->assertCorrectionLineage();

        $bundle = storage_path('scratch/tests/oos-supersession-'.uniqid().'.json');
        $this->temporaryPaths[] = $bundle;
        $this->artisan('oos:import-archive', [
            ...$corpus, '--export-bundle' => $bundle, '--report' => $this->temporaryPath('json'),
        ])->assertExitCode(0);

        ChurchService::query()->delete();
        InboundEmail::query()->delete();
        InboundEmail::factory()->create(['message_id' => '<different-primary-key@example.test>']);

        $this->artisan('oos:import-archive', [...$corpus, '--import-bundle' => $bundle])->assertExitCode(0);
        $arguments = [...$corpus, '--apply-bundle' => $bundle];
        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);

        $this->assertCorrectionLineage();
    }

    #[Test]
    public function bundled_apply_uses_the_verified_payload_instead_of_mutable_staged_parse_data(): void
    {
        $this->bindPortableExtractor();
        $corpus = $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);
        $bundle = storage_path('scratch/tests/oos-immutable-'.uniqid().'.json');
        $this->temporaryPaths[] = $bundle;

        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--export-bundle' => $bundle,
            '--report' => $this->temporaryPath('json'),
        ])->assertExitCode(0);
        InboundEmail::query()->delete();

        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--import-bundle' => $bundle,
        ])->assertExitCode(0);

        $stagedEmail = InboundEmail::query()->where('message_id', 'like', '<oos-%')->firstOrFail();
        $metadata = $stagedEmail->processing_metadata;
        $metadata['parsing']['items'][0]['title'] = 'Tampered staged title';
        $metadata['parsing']['service_plans'][0]['items'][0]['title'] = 'Tampered staged title';
        $stagedEmail->processing_metadata = $metadata;
        $stagedEmail->save();

        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--apply-bundle' => $bundle,
        ])->assertExitCode(0);

        $this->assertSame('Amazing Grace', ChurchService::query()->firstOrFail()->items()->sole()->title);
    }

    #[Test]
    public function bundle_preflight_rejects_entry_hash_fingerprint_and_markdown_mismatches_before_mutation(): void
    {
        $this->bindPortableExtractor();
        $corpus = $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);
        $bundle = storage_path('scratch/tests/oos-tamper-'.uniqid().'.json');
        $this->temporaryPaths[] = $bundle;
        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--export-bundle' => $bundle,
            '--report' => $this->temporaryPath('json'),
        ])->assertExitCode(0);
        InboundEmail::query()->delete();

        $payload = $this->readReport($bundle);
        $payload['entries'][0]['fingerprints']['model_id'] = 'different-model';
        $payload['entries'][0]['payload_hash'] = CanonicalJson::hash(
            array_diff_key($payload['entries'][0], ['payload_hash' => true]),
        );
        $payload['bundle_hash'] = CanonicalJson::hash(array_diff_key($payload, ['bundle_hash' => true]));
        file_put_contents($bundle, json_encode($payload, JSON_THROW_ON_ERROR));

        $this->artisan('oos:import-archive', [...$corpus, '--import-bundle' => $bundle])
            ->assertExitCode(1);
        $this->assertDatabaseCount('inbound_emails', 0);
        $this->assertDatabaseCount('church_services', 0);

        $payload['entries'][0]['fingerprints']['model_id'] = (string) config('service-tracking.email_parsing.model');
        $payload['entries'][0]['payload_hash'] = CanonicalJson::hash(
            array_diff_key($payload['entries'][0], ['payload_hash' => true]),
        );
        $payload['bundle_hash'] = CanonicalJson::hash(array_diff_key($payload, ['bundle_hash' => true]));
        file_put_contents($bundle, json_encode($payload, JSON_THROW_ON_ERROR));
        // Production-shaped staging is deliberately independent of the raw corpus: the
        // normalized source document and curation identity travel in the verified bundle.
        $this->artisan('oos:import-archive', ['--import-bundle' => $bundle])
            ->assertExitCode(0);
        $this->assertDatabaseCount('inbound_emails', 1);
    }

    #[Test]
    public function production_revalidation_holds_a_structurally_invalid_shipped_entry_without_a_canonical_write(): void
    {
        $this->bindPortableExtractor();
        $corpus = $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12']]);
        $bundle = storage_path('scratch/tests/oos-invalid-'.uniqid().'.json');
        $this->temporaryPaths[] = $bundle;
        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--export-bundle' => $bundle,
            '--report' => $this->temporaryPath('json'),
        ])->assertExitCode(0);
        InboundEmail::query()->delete();

        $payload = $this->readReport($bundle);
        $payload['entries'][0]['parse']['service_plans'][0]['source_provenance']['items'][0]['source_line_ids'] = [999];
        $payload['entries'][0]['payload_hash'] = CanonicalJson::hash(
            array_diff_key($payload['entries'][0], ['payload_hash' => true]),
        );
        $payload['bundle_hash'] = CanonicalJson::hash(array_diff_key($payload, ['bundle_hash' => true]));
        file_put_contents($bundle, json_encode($payload, JSON_THROW_ON_ERROR));

        $this->artisan('oos:import-archive', [...$corpus, '--import-bundle' => $bundle])
            ->assertExitCode(0);

        $this->assertDatabaseCount('church_services', 0);
        $this->assertSame(InboundEmailStatus::Pending, InboundEmail::query()->firstOrFail()->status);
        $this->artisan('oos:import-archive', [...$corpus, '--apply-bundle' => $bundle])
            ->assertExitCode(1);
        $this->assertDatabaseCount('church_services', 0);
    }

    #[Test]
    public function a_multi_entry_bundle_apply_rolls_back_when_any_entry_fails(): void
    {
        $this->app->instance(OosEmailItemExtractor::class, new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                $date = str_contains($subject, '2026-07-19') ? '2026-07-19' : '2026-07-12';
                $item = [
                    'type' => 'song',
                    'title' => 'Amazing Grace',
                    'source_line_ids' => [2],
                    'continuation' => false,
                ];

                return new OosEmailItemExtractionResult(
                    items: [$item],
                    confidence: 1.0,
                    services: [[
                        'service' => 'morning',
                        'date' => $date,
                        'service_evidence_line_ids' => [1],
                        'items' => [$item],
                        'confidence' => 1.0,
                    ]],
                    serviceCount: 1,
                    provenanceComplete: true,
                );
            }
        });
        $corpus = $this->corpus([
            ['key' => '2026-07-12-am', 'date' => '2026-07-12'],
            ['key' => '2026-07-19-am', 'date' => '2026-07-19'],
        ]);
        $bundle = storage_path('scratch/tests/oos-rollback-'.uniqid().'.json');
        $this->temporaryPaths[] = $bundle;
        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--export-bundle' => $bundle,
            '--report' => $this->temporaryPath('json'),
        ])->assertExitCode(0);
        InboundEmail::query()->delete();
        $this->artisan('oos:import-archive', [...$corpus, '--import-bundle' => $bundle])
            ->assertExitCode(0);

        $this->mock(ChurchServiceSongLinker::class, function (MockInterface $mock): void {
            $mock->shouldReceive('linkForService')
                ->once()
                ->andThrow(new RuntimeException('bundle entry failed'));
        });

        $this->artisan('oos:import-archive', [...$corpus, '--apply-bundle' => $bundle])
            ->assertExitCode(1);

        $this->assertDatabaseCount('church_services', 0);
        $this->assertDatabaseCount('church_service_items', 0);
    }

    private function bindPortableExtractor(): void
    {
        $this->app->instance(OosEmailItemExtractor::class, new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                $item = [
                    'type' => 'song',
                    'title' => 'Amazing Grace',
                    'source_line_ids' => [2],
                    'continuation' => false,
                ];

                return new OosEmailItemExtractionResult(
                    items: [$item],
                    confidence: 1.0,
                    services: [[
                        'service' => 'morning',
                        'date' => '2026-07-12',
                        'service_evidence_line_ids' => [1],
                        'items' => [$item],
                        'confidence' => 1.0,
                    ]],
                    serviceCount: 1,
                    provenanceComplete: true,
                );
            }
        });
    }

    /**
     * Writes a two-root corpus and an approved manifest over it, and returns the arguments every
     * invocation needs. Each entry is one payload file in the verbatim root; the formatted root
     * exists and stays empty, which is a shape the real corpus has too (143 verbatim-only files).
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, string>
     */
    /**
     * F2. `--import` stages into a rehearsal database, and §13.5 step 3 now requires
     * that database to be clean. A curated identity already holding items no source
     * explains would raise `unnormalized_legacy_items` instead of projecting, so the
     * run is refused before it writes anything rather than producing a census that
     * describes the previous import.
     */
    #[Test]
    public function import_refuses_when_a_curated_identity_already_holds_unevidenced_items(): void
    {
        $this->stubMorningExtractor();
        $corpus = $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12', 'service' => 'morning']]);
        $service = ChurchService::factory()->create(['date' => '2026-07-12', 'service' => 'morning']);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'metadata' => ['source' => 'legacy-openlp-import'],
        ]);

        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--import' => true, '--plan-hash' => $this->planHash(),
        ])
            ->expectsOutputToContain('1 of 1 curated identities already hold items with no normalized evidence')
            ->assertFailed();

        $this->assertDatabaseCount('church_service_source_records', 0);
    }

    #[Test]
    public function import_proceeds_over_unevidenced_items_when_explicitly_accepted(): void
    {
        $this->stubMorningExtractor();
        $corpus = $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12', 'service' => 'morning']]);
        $service = ChurchService::factory()->create(['date' => '2026-07-12', 'service' => 'morning']);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'metadata' => ['source' => 'legacy-openlp-import'],
        ]);

        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--import' => true, '--plan-hash' => $this->planHash(),
            '--accept-unevidenced-items' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('church_service_source_records', 1);
    }

    /**
     * The guard is scoped to the corpus being staged. A legacy service on an unrelated
     * date is §12.4's population, and blocking on it would make `--import` unusable
     * against any database that has ever held one.
     */
    #[Test]
    public function import_ignores_unevidenced_items_outside_the_curated_corpus(): void
    {
        $this->stubMorningExtractor();
        $corpus = $this->corpus([['key' => '2026-07-12-am', 'date' => '2026-07-12', 'service' => 'morning']]);
        $unrelated = ChurchService::factory()->create(['date' => '2019-01-06', 'service' => 'evening']);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $unrelated->id,
            'position' => 1,
            'metadata' => ['source' => 'legacy-openlp-import'],
        ]);

        $this->artisan('oos:import-archive', [
            ...$corpus,
            '--import' => true, '--plan-hash' => $this->planHash(),
        ])->assertExitCode(0);

        $this->assertDatabaseCount('church_service_source_records', 1);
    }

    /** A deterministic single-service parse, so the F2 tests turn only on the guard. */
    private function stubMorningExtractor(): void
    {
        $this->app->instance(OosEmailItemExtractor::class, new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Amazing Grace']],
                    confidence: 0.99,
                    services: [[
                        'service' => 'morning',
                        'date' => '2026-07-12',
                        'items' => [['type' => 'song', 'title' => 'Amazing Grace']],
                        'confidence' => 0.99,
                    ]],
                );
            }
        });
    }

    private function assertCorrectionLineage(): void
    {
        $service = ChurchService::query()->sole();
        $records = $service->sourceRecords()->orderBy('id')->get();

        $this->assertCount(2, $records);
        $original = $records->firstWhere('source_key', '<oos-2026-07-12-original-'.substr(sha1('2026-07-12-original'), 0, 8).'@crockenhill.local>|morning:2026-07-12');
        $correction = $records->firstWhere('source_key', '<oos-2026-07-12-correction-'.substr(sha1('2026-07-12-correction'), 0, 8).'@crockenhill.local>|morning:2026-07-12');

        $this->assertNotNull($original);
        $this->assertNotNull($correction);
        $this->assertSame($original->id, $correction->supersedes_id);
        $this->assertTrue(app(ChurchServiceEvidenceSet::class)->records($records)->sole()->is($correction));
    }

    private function corpus(array $entries): array
    {
        $root = $this->temporaryDirectory();
        $verbatim = $root.'/verbatim';
        $formatted = $root.'/formatted';
        mkdir($verbatim, 0755, true);
        mkdir($formatted, 0755, true);

        $manifestEntries = [];

        foreach ($entries as $entry) {
            $key = $entry['key'];
            $file = "{$key}.md";
            file_put_contents($verbatim.'/'.$file, $this->payload($entry));

            $manifestEntries[] = array_filter([
                'item_key' => $key,
                'source_kind' => 'email',
                'verbatim_relative_path' => $file,
                'verbatim_sha256' => hash_file('sha256', $verbatim.'/'.$file),
                'verbatim_byte_size' => filesize($verbatim.'/'.$file),
                'disposition' => 'include',
                'payload' => 'verbatim',
                'resolved_date' => $entry['date'],
                'resolved_service' => $entry['service'] ?? 'morning',
                'date_decision' => 'explicit',
                'content_scope' => $entry['scope'] ?? 'full',
                'partial_scope_reason' => ($entry['scope'] ?? 'full') === 'partial' ? 'hymn list only' : null,
                'parse_decision' => $entry['parse_decision'] ?? 'strict',
                'expected_item_count' => $entry['expected_item_count'] ?? null,
                'supersedes' => $entry['supersedes'] ?? null,
                'decided_by' => isset($entry['expected_item_count']) ? 'maintainer' : null,
                'decided_at' => isset($entry['expected_item_count']) ? '2026-08-06T10:00:00+00:00' : null,
                'decision_rule_version' => 'oos-curation-test-v1',
            ], static fn (mixed $value): bool => $value !== null);
        }

        $manifest = $root.'/manifest.json';
        file_put_contents($manifest, json_encode([
            'format' => 'crockenhill-oos-curation',
            'version' => 1,
            'batch_key' => 'oos-test-batch',
            'entries' => $manifestEntries,
        ], JSON_THROW_ON_ERROR));

        return $this->corpusArguments = [
            '--manifest' => $manifest,
            '--verbatim' => $verbatim,
            '--formatted' => $formatted,
        ];
    }

    /** @param array<string, mixed> $entry */
    private function payload(array $entry): string
    {
        $frontmatter = ['title: "Order for '.$entry['date'].'"', 'date: '.$entry['date']];
        $frontmatter[] = 'source_subject: "'.($entry['subject'] ?? 'Details for '.$entry['date']).'"';

        if (isset($entry['source_date'])) {
            $frontmatter[] = 'source_date: '.$entry['source_date'];
        }

        if (isset($entry['frontmatter_service'])) {
            $frontmatter[] = 'service: '.$entry['frontmatter_service'];
        }

        $body = $entry['body'] ?? "Morning service\nAmazing Grace";

        return "---\n".implode("\n", $frontmatter)."\n---\n\n{$body}\n";
    }

    /**
     * §7.4 binds an import to the exact plan the operator reviewed, so every import run has to
     * quote the plan hash back. A test that skipped it would be exercising a path no operator can.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function importArguments(array $extra = []): array
    {
        return [...$this->corpusArguments, '--import' => true, '--plan-hash' => $this->planHash(), ...$extra];
    }

    private function planHash(): string
    {
        return app(OosCurationManifest::class)->plan(
            $this->corpusArguments['--verbatim'],
            $this->corpusArguments['--formatted'],
            $this->corpusArguments['--manifest'],
        )->planHash;
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir().'/oos_corpus_'.str_replace('.', '', uniqid('', true));
        mkdir($path, 0755, true);
        $this->temporaryDirectories[] = $path;

        return $path;
    }

    private function temporaryPath(string $extension): string
    {
        $path = sys_get_temp_dir().'/oos_archive_'.str_replace('.', '', uniqid('', true)).".{$extension}";
        $this->temporaryPaths[] = $path;

        return $path;
    }

    /** @param array<string, mixed> $payload */
    private function emailForEntry(array $payload, int $entryIndex): InboundEmail
    {
        return InboundEmail::query()
            ->where('message_id', $payload['entries'][$entryIndex]['message_id'])
            ->firstOrFail();
    }

    /**
     * An extractor that counts its calls, so a test can tell a reused
     * extraction from a re-run one rather than inferring it from the output.
     *
     * @param  list<array<string, mixed>>|null  $services
     */
    private function bindCountingExtractor(?array $services = null): object
    {
        $services ??= [[
            'service' => 'morning',
            'date' => '2026-07-12',
            'items' => [['type' => 'song', 'title' => 'Amazing Grace']],
            'confidence' => 0.99,
        ]];

        $extractor = new class($services) implements OosEmailItemExtractor
        {
            public int $calls = 0;

            /** @param list<array<string, mixed>> $services */
            public function __construct(private readonly array $services) {}

            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                $this->calls++;

                return new OosEmailItemExtractionResult(
                    items: $this->services[0]['items'],
                    confidence: 0.99,
                    services: $this->services,
                );
            }
        };

        $this->app->instance(OosEmailItemExtractor::class, $extractor);

        return $extractor;
    }

    /** @return array<string, string> */
    private function planContentScopesByDate(): array
    {
        $scopes = [];

        foreach (InboundEmail::query()->firstOrFail()->processing_metadata['parsing']['service_plans'] as $plan) {
            $scopes[$plan['date']] = $plan['content_scope'];
        }

        return $scopes;
    }

    /**
     * The archive email whose body carries this fragment. Identity is derived
     * from the item key by a rule this test has no business restating, so a
     * fixture that needs one particular entry finds it by its own text.
     */
    private function archiveEmailContaining(string $fragment): InboundEmail
    {
        return InboundEmail::query()->where('body_plain', 'like', '%'.$fragment.'%')->sole();
    }

    /** @return array<string, mixed> */
    private function parseCacheBinding(?InboundEmail $email = null): array
    {
        $email ??= InboundEmail::query()->firstOrFail();

        return app(OosArchiveParseCacheBinding::class)
            ->evidence($email->processing_metadata[OosArchiveParseCacheBinding::MetadataKey]);
    }

    /**
     * Age the raw-extraction cache the way a parser rewrite or a re-dated
     * source does, without touching the archive text.
     *
     * The key hash is what decides reuse, so a fixture that edited only the
     * readable key would leave the cache eligible and prove nothing.
     *
     * @param  array<string, string>  $overrides
     */
    private function ageRawParseCache(InboundEmail $email, array $overrides): void
    {
        $metadata = $email->processing_metadata;
        $binding = $metadata[OosArchiveParseCacheBinding::MetadataKey];
        $binding['raw_cache_key'] = [...$binding['raw_cache_key'], ...$overrides];
        $binding['raw_cache_key_hash'] = CanonicalJson::hash($binding['raw_cache_key']);
        $metadata[OosArchiveParseCacheBinding::MetadataKey] = $binding;
        $email->processing_metadata = $metadata;
        $email->save();
    }

    /** @return array<string, mixed> */
    private function readReport(string $path): array
    {
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload);

        return $payload;
    }
}
