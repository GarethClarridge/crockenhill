<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Support\RecordingMatchAudit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecordingMatchAuditTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_reports_recordings_that_can_attach_or_are_linked_inconsistently(): void
    {
        $matchingService = ChurchService::factory()->create([
            'date' => '2026-08-02',
            'service' => SermonService::Morning,
        ]);
        $wrongService = ChurchService::factory()->create([
            'date' => '2026-08-09',
            'service' => SermonService::Evening,
        ]);

        $firstCollision = $this->livestream([
            'church_service_id' => $matchingService->id,
            'extracted_date' => '2026-08-02',
            'extracted_service' => SermonService::Morning,
        ]);
        $secondCollision = $this->livestream([
            'church_service_id' => $matchingService->id,
            'extracted_date' => '2026-08-02',
            'extracted_service' => SermonService::Morning,
        ]);
        $latent = $this->livestream([
            'extracted_date' => '2026-08-16',
            'extracted_service' => SermonService::Evening,
        ]);
        $mismatched = $this->livestream([
            'church_service_id' => $wrongService->id,
            'extracted_date' => '2026-08-23',
            'extracted_service' => SermonService::Morning,
        ]);
        $superseded = $this->livestream([
            'church_service_id' => $matchingService->id,
            'extracted_date' => '2026-08-02',
            'extracted_service' => SermonService::Morning,
            'superseded_at' => now(),
            'superseded_by_processing_log_id' => $secondCollision->id,
        ]);

        $report = app(RecordingMatchAudit::class)->report();

        $this->assertSame([$latent->id, $mismatched->id], array_column($report['latent_matches'], 'id'));
        $this->assertSame([$mismatched->id], array_column($report['link_mismatches'], 'id'));
        $this->assertSame([$superseded->id], array_column($report['superseded_attachable_runs'], 'id'));
        $this->assertSame(
            [$firstCollision->id, $secondCollision->id],
            array_column($report['identity_collisions'][0]['runs'], 'id'),
        );
        $this->assertTrue(app(RecordingMatchAudit::class)->hasFindings($report));
    }

    #[Test]
    public function it_reports_advisory_duplicate_identity_only_and_suspicious_runs(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-09-06',
            'service' => SermonService::Morning,
        ]);

        $identityOnly = $this->livestream([
            'status' => ProcessingStatus::Failed,
            'extracted_date' => '2026-09-06',
            'extracted_service' => SermonService::Morning,
        ]);
        $duplicate = $this->livestream([
            'church_service_id' => $service->id,
            'extracted_date' => '2026-09-06',
            'extracted_service' => SermonService::Morning,
            'file_hash' => 'duplicate-hash',
        ]);
        $duplicateRetry = $this->livestream([
            'status' => ProcessingStatus::Failed,
            'extracted_date' => '2026-09-06',
            'extracted_service' => SermonService::Morning,
            'file_hash' => 'duplicate-hash',
        ]);
        $short = $this->livestream([
            'church_service_id' => $service->id,
            'extracted_date' => '2026-09-13',
            'extracted_service' => SermonService::Morning,
            'duration' => 30,
            'audio_file_path' => null,
        ]);
        $empty = $this->livestream([
            'status' => ProcessingStatus::Failed,
            'extracted_date' => '2026-09-20',
            'extracted_service' => SermonService::Evening,
            'audio_file_path' => null,
        ]);
        $threshold = $this->livestream([
            'church_service_id' => $service->id,
            'extracted_date' => '2026-09-27',
            'extracted_service' => SermonService::Morning,
            'duration' => RecordingMatchAudit::ShortDurationSeconds,
            'audio_file_path' => 'sermons/audio/threshold.mp3',
        ]);

        MediaProcessingLog::factory()->audio()->completed()->create([
            'extracted_date' => '2026-10-04',
            'extracted_service' => SermonService::Morning,
            'file_hash' => 'duplicate-hash',
        ]);
        ServiceSection::factory()->create([
            'media_processing_log_id' => $duplicate->id,
        ]);

        $report = app(RecordingMatchAudit::class)->report();

        $this->assertContains($identityOnly->id, array_column($report['identity_only_matches'], 'id'));
        $this->assertSame(
            [$duplicate->id, $duplicateRetry->id],
            array_column($report['duplicate_hashes'][0]['runs'], 'id'),
        );
        $this->assertContains('short_duration', $this->signalsFor($report, $short));
        $this->assertContains('no_useful_outputs', $this->signalsFor($report, $empty));
        $this->assertNotContains($threshold->id, array_column($report['suspicious_runs'], 'id'));
    }

    #[Test]
    public function it_is_read_only_and_ignores_incomplete_identity_rows(): void
    {
        $incomplete = $this->livestream([
            'extracted_date' => null,
            'extracted_service' => null,
        ]);
        $updatedAt = $incomplete->updated_at;

        $report = app(RecordingMatchAudit::class)->report();

        $this->assertSame(0, $report['summary']['scanned_runs']);
        $this->assertFalse(app(RecordingMatchAudit::class)->hasFindings($report));
        $this->assertSame($updatedAt?->toISOString(), $incomplete->fresh()?->updated_at?->toISOString());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function livestream(array $attributes): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::Completed,
            'current_step' => 'completed',
            'original_filename' => 'service-recording.mp4',
            'file_hash' => null,
            'file_size' => 250_000_000,
            'duration' => 3_600,
            'sermon_id' => null,
            'audio_file_path' => 'sermons/audio/service.mp3',
            'video_file_path' => null,
            'transcript_file_path' => null,
            ...$attributes,
        ]);
    }

    /**
     * @param  array{
     *     suspicious_runs: list<array{id: int, signals: list<string>}>
     * }  $report
     * @return list<string>
     */
    private function signalsFor(array $report, MediaProcessingLog $run): array
    {
        $finding = collect($report['suspicious_runs'])
            ->firstWhere('id', $run->id);

        $this->assertIsArray($finding);

        return $finding['signals'];
    }
}
