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
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
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
            public function extract(string $subject, string $body): OosEmailItemExtractionResult
            {
                throw new \RuntimeException('Dry run must not call the extractor.');
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

            public function extract(string $subject, string $body): OosEmailItemExtractionResult
            {
                $this->calls++;

                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Amazing Grace']],
                    confidence: 0.99,
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
            public function extract(string $subject, string $body): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => 'Amazing Grace']],
                    confidence: 1.0,
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
    public function a_changed_source_after_import_is_reported_without_touching_the_service(): void
    {
        $this->app->instance(OosEmailItemExtractor::class, new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body): OosEmailItemExtractionResult
            {
                return new OosEmailItemExtractionResult(
                    items: [['type' => 'song', 'title' => trim($body)]],
                    confidence: 1.0,
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
