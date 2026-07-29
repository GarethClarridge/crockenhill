<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Contracts\OosEmailItemExtractor;
use App\Data\OosEmailItemExtractionResult;
use App\Enums\InboundEmailStatus;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Queries\AdminAttentionCounts;
use App\Queries\ReviewInboxQuery;
use App\Services\ChurchService\ChurchServiceSongLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ImportOosArchiveCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            if (is_file($path)) {
                unlink($path);
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
        $archive = $this->writeArchive($this->fullEntry('Sunday 12 July 2026'));
        $report = $this->temporaryPath('json');

        $this->artisan('oos:import-archive', [
            'path' => $archive,
            '--dry-run' => true,
            '--report' => $report,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('inbound_emails', 0);
        $payload = $this->readReport($report);
        $this->assertSame('dry_run', $payload['mode']);
        $this->assertCount(1, $payload['entries']);
        $this->assertArrayHasKey('aggregate', $payload);
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
        $archive = $this->writeArchive($this->fullEntry('Sunday 12 July 2026'));
        $report = $this->temporaryPath('json');

        $arguments = ['path' => $archive, '--report' => $report];
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

        file_put_contents($archive, $this->fullEntry('Sunday 12 July 2026', 'Changed song'));
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
        $archive = $this->writeArchive($this->fullEntry('Sunday 12 July 2026'));
        $arguments = ['path' => $archive, '--report' => $this->temporaryPath('json')];

        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);
        $this->assertSame(1, $extractor->calls);

        $email = InboundEmail::query()->firstOrFail();
        $currentVersion = $email->processing_metadata['parsing']['parser_version'];
        $this->assertNotNull($currentVersion);

        // Age the cache the way an unbumped parser rewrite does: the archive text is untouched, so
        // the input hash still matches and only the version can invalidate the stored parse.
        $metadata = $email->processing_metadata;
        $metadata['parsing']['parser_version'] = 'archive-v1';
        $email->processing_metadata = $metadata;
        $email->save();

        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);

        $this->assertSame(2, $extractor->calls);
        $refreshed = $email->fresh()->processing_metadata['parsing'];
        $this->assertSame($currentVersion, $refreshed['parser_version']);
        $this->assertNotNull($refreshed['disposition']);
    }

    #[Test]
    public function import_merges_into_an_existing_service_and_creates_gap_slots(): void
    {
        $this->app->instance(OosEmailItemExtractor::class, new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                $date = str_contains($subject, '19 July') ? '2026-07-19' : '2026-07-12';

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
        $archive = $this->writeArchive(
            $this->fullEntry('Sunday 12 July 2026')
            .$this->fullEntry('Sunday 19 July 2026')
        );
        $report = $this->temporaryPath('json');

        $this->artisan('oos:import-archive', [
            'path' => $archive,
            '--import' => true,
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
    public function an_import_blocks_a_contradicted_entry_and_sends_the_rest_to_the_review_inbox(): void
    {
        $this->app->instance(OosEmailItemExtractor::class, new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                $date = match (true) {
                    str_contains($subject, '13 July') => '2026-07-13',
                    str_contains($subject, '26 July') => '2026-07-26',
                    default => '2026-07-12',
                };

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

        // 13 July 2026 is a Monday, so the second heading contradicts itself: the archive text
        // must be corrected before anyone — human or pipeline — can act on it.
        $archive = $this->writeArchive(
            $this->fullEntry('Sunday 12 July 2026')
            .$this->fullEntry('Sunday 13 July 2026')
            .$this->unverifiedEntry('Sunday 26 July 2026')
        );
        $report = $this->temporaryPath('json');

        $this->artisan('oos:import-archive', [
            'path' => $archive,
            '--import' => true,
            '--report' => $report,
        ])->assertExitCode(0);

        $payload = $this->readReport($report);
        $this->assertSame(['created', 'blocked', 'held_for_review'], array_column($payload['entries'], 'disposition'));
        $this->assertContains('weekday_mismatch', $payload['entries'][1]['gate_reasons']);
        $this->assertContains('unverified_service_ground_truth', $payload['entries'][2]['gate_reasons']);

        // Only the corroborated entry became a service; the other two wrote nothing.
        $this->assertDatabaseCount('church_services', 1);
        $this->assertDatabaseHas('church_services', ['date' => '2026-07-12', 'service' => 'morning']);

        $blocked = $this->emailForEntry($payload, 1);
        $reviewable = $this->emailForEntry($payload, 2);
        $this->assertSame(InboundEmailStatus::ArchiveEval, $blocked->status);
        $this->assertSame(InboundEmailStatus::Pending, $reviewable->status);

        // The reviewable entry is now reachable by exactly the route a live email would take.
        $this->assertSame(1, app(AdminAttentionCounts::class)->counts()['pending_emails']);
        $this->assertSame(1, app(ReviewInboxQuery::class)->build()['counts']['emails']);
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
        $archive = $this->writeArchive($this->fullEntry('Sunday 12 July 2026'));
        $arguments = ['path' => $archive, '--import' => true, '--report' => $this->temporaryPath('json')];

        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);

        $service = ChurchService::query()->where('date', '2026-07-12')->firstOrFail();
        $firstPass = $service->items()->orderBy('position')->pluck('title')->all();
        $this->assertCount(2, $firstPass);

        $report = $this->temporaryPath('json');
        $this->artisan('oos:import-archive', [
            'path' => $archive,
            '--import' => true,
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
        // re-parse no longer clears the auto-import bar. Nothing is applied to the service the
        // earlier run built, so the entry has to become visible again rather than stay Processed.
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
        $archive = $this->writeArchive($this->fullEntry('Sunday 12 July 2026'));

        $this->artisan('oos:import-archive', [
            'path' => $archive,
            '--import' => true,
            '--report' => $this->temporaryPath('json'),
        ])->assertExitCode(0);

        $this->assertSame(InboundEmailStatus::Processed, InboundEmail::query()->firstOrFail()->status);
        $service = ChurchService::query()->where('date', '2026-07-12')->firstOrFail();

        $extractor->confidence = 0.4;
        $report = $this->temporaryPath('json');

        $this->artisan('oos:import-archive', [
            'path' => $archive,
            '--import' => true,
            '--fresh-parse' => true,
            '--report' => $report,
        ])->assertExitCode(0);

        $this->assertSame('held_for_review', $this->readReport($report)['entries'][0]['disposition']);
        $this->assertSame(InboundEmailStatus::Pending, InboundEmail::query()->firstOrFail()->status);
        $this->assertSame(1, app(ReviewInboxQuery::class)->build()['counts']['emails']);
        $this->assertSame('Amazing Grace', $service->items()->firstOrFail()->title);
    }

    #[Test]
    public function import_skips_a_plan_the_ground_truth_does_not_corroborate(): void
    {
        // A morning-only archive entry, but the multi-service parser also returns an evening plan.
        // The gate only checks the primary (morning); the ungated evening plan must not be created.
        $this->app->instance(OosEmailItemExtractor::class, new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [
                        ['type' => 'song', 'title' => 'Amazing Grace'],
                        ['type' => 'sermon', 'title' => 'Evening Sermon'],
                    ],
                    confidence: 0.99,
                    services: [
                        ['service' => 'morning', 'date' => '2026-07-12', 'items' => [
                            ['type' => 'song', 'title' => 'Amazing Grace'],
                        ], 'confidence' => 0.99],
                        ['service' => 'evening', 'date' => '2026-07-12', 'items' => [
                            ['type' => 'sermon', 'title' => 'Evening Sermon'],
                        ], 'confidence' => 0.99],
                    ],
                );
            }
        });
        $archive = $this->writeArchive($this->fullEntry('Sunday 12 July 2026'));
        $report = $this->temporaryPath('json');

        $this->artisan('oos:import-archive', [
            'path' => $archive,
            '--import' => true,
            '--report' => $report,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('church_services', 1);
        $this->assertDatabaseHas('church_services', ['date' => '2026-07-12', 'service' => 'morning']);
        $this->assertDatabaseMissing('church_services', ['service' => 'evening']);
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
        $archive = $this->writeArchive($this->fullEntry('Sunday 12 July 2026'));
        $report = $this->temporaryPath('json');

        $this->artisan('oos:import-archive', [
            'path' => $archive,
            '--import' => true,
            '--report' => $report,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('church_services', 0);
        $payload = $this->readReport($report);
        $this->assertSame('import_failed', $payload['entries'][0]['disposition']);
        $this->assertStringContainsString('song sync exploded', (string) $payload['entries'][0]['error']);
        // A failed import leaves the email in the inbox, exactly as a live email would be.
        $this->assertSame(InboundEmailStatus::Pending, InboundEmail::query()->firstOrFail()->status);
    }

    #[Test]
    public function a_corroborated_plan_below_the_auto_import_threshold_is_not_created(): void
    {
        // Both services are in the ground truth, so the archive corroborates both plans. Whether
        // either imports unattended is the live auto-import bar's decision, and the evening plan
        // is below it: it is held for review while morning imports.
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
        $archive = $this->writeArchive($this->twoServiceEntry('Sunday 12 July 2026'));
        $report = $this->temporaryPath('json');

        $this->artisan('oos:import-archive', [
            'path' => $archive,
            '--import' => true,
            '--report' => $report,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('church_services', 1);
        $this->assertDatabaseHas('church_services', ['date' => '2026-07-12', 'service' => 'morning']);
        $this->assertDatabaseMissing('church_services', ['service' => 'evening']);
        $this->assertSame('created', $this->readReport($report)['entries'][0]['disposition']);
    }

    #[Test]
    public function the_report_evaluates_every_service_plan_not_just_the_primary(): void
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
        $archive = $this->writeArchive($this->twoServiceEntry('Sunday 12 July 2026'));
        $report = $this->temporaryPath('json');

        $this->artisan('oos:import-archive', [
            'path' => $archive,
            '--report' => $report,
        ])->assertExitCode(0);

        $payload = $this->readReport($report);
        $entry = $payload['entries'][0];
        $this->assertSame(['morning', 'evening'], $entry['services']['detected']);
        $this->assertCount(2, $entry['plans']);
        // json_encode drops the zero fraction, so the decoded rate is int(1).
        $this->assertEquals(1.0, $payload['aggregate']['service_metrics']['evening']['recall']);
        $this->assertSame('multi_service', $payload['pipeline_mode']);
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
        $archive = $this->writeArchive($this->fullEntry('Sunday 12 July 2026'));
        $report = $this->temporaryPath('json');
        $arguments = ['path' => $archive, '--import' => true, '--report' => $report];
        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);
        $service = ChurchService::query()->firstOrFail();
        $originalItems = $service->items()->pluck('title')->all();

        file_put_contents($archive, $this->fullEntry('Sunday 12 July 2026', 'Corrected content'));
        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);

        $this->assertSame($originalItems, $service->items()->pluck('title')->all());
        $payload = $this->readReport($report);
        $this->assertContains('source_updated_after_import', $payload['entries'][0]['flags']);
        $this->assertSame('held_for_review', $payload['entries'][0]['disposition']);

        // Corrected archive text after an import is precisely what a human must re-check, so the
        // already-processed email is pushed back into the inbox rather than silently re-imported.
        $this->assertSame(InboundEmailStatus::Pending, InboundEmail::query()->firstOrFail()->status);
    }

    private function fullEntry(string $heading, string $item = 'Amazing Grace'): string
    {
        return <<<MARKDOWN
### {$heading}

**Source subject:** Details for {$heading}

#### Sunday Morning

{$item}

---

MARKDOWN;
    }

    private function twoServiceEntry(string $heading): string
    {
        return <<<MARKDOWN
### {$heading}

**Source subject:** Details for {$heading}

#### Sunday Morning

Amazing Grace

#### Sunday Evening

Abide With Me

---

MARKDOWN;
    }

    private function unverifiedEntry(string $heading): string
    {
        return <<<MARKDOWN
### {$heading}

**Source subject:** Morning order for {$heading}

Amazing Grace

---

MARKDOWN;
    }

    private function writeArchive(string $contents): string
    {
        $path = $this->temporaryPath('md');
        file_put_contents($path, $contents);

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

    /** @return array<string, mixed> */
    private function readReport(string $path): array
    {
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload);

        return $payload;
    }
}
