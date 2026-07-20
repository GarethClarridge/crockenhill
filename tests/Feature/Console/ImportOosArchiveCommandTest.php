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
    public function parse_runs_are_idempotent_hash_aware_and_hidden_from_admin_attention(): void
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
        $this->assertSame(InboundEmailStatus::ArchiveEval, $email->status);
        $this->assertSame(0, app(AdminAttentionCounts::class)->counts()['pending_emails']);
        $this->assertSame(0, app(ReviewInboxQuery::class)->build()['counts']['emails']);

        file_put_contents($archive, $this->fullEntry('Sunday 12 July 2026', 'Changed song'));
        $this->artisan('oos:import-archive', $arguments)->assertExitCode(0);

        $this->assertSame(2, $extractor->calls);
        $this->assertStringContainsString('Changed song', $email->fresh()->body_plain ?? '');
        $this->assertNotSame($originalHash, $email->fresh()->processing_metadata['archive']['input_hash']);
    }

    #[Test]
    public function import_respects_ground_truth_gates_skips_openlp_and_creates_only_gap_slots(): void
    {
        $this->app->instance(OosEmailItemExtractor::class, new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                $date = match (true) {
                    str_contains($subject, '19 July') => '2026-07-19',
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
        $existing = ChurchService::factory()->create([
            'date' => '2026-07-12',
            'service' => 'morning',
            'source' => 'openlp',
            'import_metadata' => ['preserve' => true],
        ]);
        $archive = $this->writeArchive(
            $this->fullEntry('Sunday 12 July 2026')
            .$this->fullEntry('Sunday 19 July 2026')
            .$this->unverifiedEntry('Sunday 26 July 2026')
        );
        $report = $this->temporaryPath('json');

        $this->artisan('oos:import-archive', [
            'path' => $archive,
            '--import' => true,
            '--report' => $report,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('church_services', 2);
        $this->assertSame('openlp', $existing->fresh()->source);
        $this->assertSame(['preserve' => true], $existing->fresh()->import_metadata->toArray());
        $this->assertDatabaseHas('church_services', [
            'date' => '2026-07-19',
            'service' => 'morning',
            'source' => 'email',
        ]);
        $this->assertDatabaseMissing('church_services', ['date' => '2026-07-26']);

        $payload = $this->readReport($report);
        $this->assertSame(['skipped_existing', 'created', 'skipped'], array_column($payload['entries'], 'disposition'));
        $this->assertContains('unverified_service_ground_truth', $payload['entries'][2]['gate_reasons']);
        $this->assertSame(2, InboundEmail::query()->where('status', InboundEmailStatus::Processed)->count());
        $this->assertSame(1, InboundEmail::query()->where('status', InboundEmailStatus::ArchiveEval)->count());
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
        $this->assertSame(InboundEmailStatus::ArchiveEval, InboundEmail::query()->firstOrFail()->status);
    }

    #[Test]
    public function a_corroborated_plan_below_the_auto_import_threshold_is_not_created(): void
    {
        // Both services are in the ground truth, but the evening plan's confidence lands in the
        // 0.75-0.89 review band. The archive gate is per-plan >= 0.90: only morning may import.
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
        $this->assertSame('skipped', $payload['entries'][0]['disposition']);
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

    /** @return array<string, mixed> */
    private function readReport(string $path): array
    {
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload);

        return $payload;
    }
}
