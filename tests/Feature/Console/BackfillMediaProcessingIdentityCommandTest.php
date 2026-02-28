<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\SermonService;
use App\Models\MediaProcessingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackfillMediaProcessingIdentityCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_does_not_persist_changes_in_dry_run_mode(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => null,
            'extracted_service' => null,
            'processing_metadata' => [
                'extracted_date' => '2026-01-12',
                'extracted_service' => SermonService::MORNING->value,
            ],
        ]);

        $this->artisan('media-processing:backfill-extracted-identity', [
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('DRY RUN enabled')
            ->assertSuccessful();

        $processingLog->refresh();

        $this->assertNull($processingLog->extracted_date);
        $this->assertNull($processingLog->extracted_service);
    }

    #[Test]
    public function it_backfills_missing_identity_columns_from_valid_metadata(): void
    {
        $fullyMissing = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => null,
            'extracted_service' => null,
            'processing_metadata' => [
                'extracted_date' => '2026-01-19',
                'extracted_service' => SermonService::MORNING->value,
            ],
        ]);

        $partiallyMissing = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-01-26',
            'extracted_service' => null,
            'processing_metadata' => [
                'extracted_date' => '2026-02-02',
                'extracted_service' => SermonService::EVENING->value,
            ],
        ]);

        $invalidMetadata = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => null,
            'extracted_service' => null,
            'processing_metadata' => [
                'extracted_date' => 'not-a-date',
                'extracted_service' => 'invalid-service',
            ],
        ]);

        $this->artisan('media-processing:backfill-extracted-identity')->assertSuccessful();

        $fullyMissing->refresh();
        $partiallyMissing->refresh();
        $invalidMetadata->refresh();

        $this->assertSame('2026-01-19', $fullyMissing->extracted_date?->toDateString());
        $this->assertSame(SermonService::MORNING, $fullyMissing->extracted_service);

        $this->assertSame('2026-01-26', $partiallyMissing->extracted_date?->toDateString());
        $this->assertSame(SermonService::EVENING, $partiallyMissing->extracted_service);

        $this->assertNull($invalidMetadata->extracted_date);
        $this->assertNull($invalidMetadata->extracted_service);
    }

    #[Test]
    public function it_is_idempotent_when_run_multiple_times(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => null,
            'extracted_service' => null,
            'processing_metadata' => [
                'extracted_date' => '2026-02-09',
                'extracted_service' => SermonService::MORNING->value,
            ],
        ]);

        $this->artisan('media-processing:backfill-extracted-identity')->assertSuccessful();
        $this->artisan('media-processing:backfill-extracted-identity')
            ->expectsOutputToContain('No media processing logs require backfill.')
            ->assertSuccessful();

        $processingLog->refresh();

        $this->assertSame('2026-02-09', $processingLog->extracted_date?->toDateString());
        $this->assertSame(SermonService::MORNING, $processingLog->extracted_service);
    }
}
