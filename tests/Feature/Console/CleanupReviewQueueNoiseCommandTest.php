<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ChurchServiceReviewState;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CleanupReviewQueueNoiseCommandTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_is_a_dry_run_by_default(): void
    {
        $bulkImport = $this->bulkImportService();
        $firstPopulation = $this->firstPopulationConflictService();

        $this->artisan('services:cleanup-review-queue-noise')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain((string) $bulkImport->id)
            ->expectsOutputToContain((string) $firstPopulation->id)
            ->assertSuccessful();

        $this->assertTrue($bulkImport->fresh()->needs_review);
        $this->assertSame(
            'detected',
            $firstPopulation->fresh()->getRawOriginal('canonical_conflict_state'),
        );
    }

    #[Test]
    public function it_only_clears_the_guarded_bulk_archive_review_flags(): void
    {
        $eligible = $this->bulkImportService();
        $withTrigger = $this->bulkImportService([
            'import_metadata' => [
                'confidence_score' => 0.5,
                'review_triggers' => ['ambiguous_sermon_detection'],
            ],
        ]);
        $withPendingMerge = $this->bulkImportService([
            'pending_structure_merge_source' => 'email',
        ]);
        $reopened = $this->bulkImportService([
            'review_state' => ChurchServiceReviewState::Reopened,
            'manual_reviewed_at' => '2026-05-08 08:00:00',
            'manual_review_reopened_at' => '2026-05-08 08:30:00',
            'manual_review_reopened_by_source' => 'openlp',
        ]);
        $wrongDate = $this->bulkImportService([
            'created_at' => '2026-05-09 09:00:00',
            'updated_at' => '2026-05-09 09:00:00',
        ]);
        $wrongConfidence = $this->bulkImportService([
            'import_metadata' => ['confidence_score' => 0.6],
        ]);
        $withFlaggedSection = $this->bulkImportService();
        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $withFlaggedSection->id,
        ]);
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'needs_manual_review' => true,
            'metadata' => ['review_flags' => ['ambiguous_sermon_detection']],
        ]);

        $this->artisan('services:cleanup-review-queue-noise', ['--execute' => true])
            ->expectsOutputToContain("Bulk-import service IDs: {$eligible->id}")
            ->assertSuccessful();

        $this->assertFalse($eligible->fresh()->needs_review);

        foreach ([$withTrigger, $withPendingMerge, $reopened, $wrongDate, $wrongConfidence, $withFlaggedSection] as $preserved) {
            $this->assertTrue($preserved->fresh()->needs_review, "Service {$preserved->id} should remain flagged.");
        }
    }

    #[Test]
    public function it_normalises_only_first_population_conflicts_and_preserves_the_history(): void
    {
        $firstPopulation = $this->firstPopulationConflictService();
        $firstPopulationHistory = $firstPopulation->import_metadata['canonical_conflict_history'];

        $genuineChange = $this->conflictService([
            'type' => 'updated_item',
            'item_id' => 42,
            'fields' => [['field' => 'title', 'before' => 'Old', 'after' => 'New']],
        ]);
        $multipleConflicts = $this->firstPopulationConflictService();
        $multipleMetadata = json_decode(
            (string) $multipleConflicts->getRawOriginal('import_metadata'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $multipleMetadata['canonical_conflict_history'][] = $multipleMetadata['canonical_conflict_history'][0];
        DB::table('church_services')
            ->where('id', $multipleConflicts->id)
            ->update(['import_metadata' => json_encode($multipleMetadata, JSON_THROW_ON_ERROR)]);

        $this->artisan('services:cleanup-review-queue-noise', ['--execute' => true])
            ->assertSuccessful();

        $firstPopulation->refresh();
        $this->assertSame('none', $firstPopulation->getRawOriginal('canonical_conflict_state'));
        $this->assertNull($firstPopulation->getRawOriginal('canonical_conflict_reason'));
        $this->assertNull($firstPopulation->getRawOriginal('canonical_conflict_detected_at'));
        $this->assertArrayNotHasKey('canonical_conflict', $firstPopulation->import_metadata->toArray());
        $this->assertEquals(
            $firstPopulationHistory,
            $firstPopulation->import_metadata['canonical_conflict_history'],
        );

        $this->assertSame('detected', $genuineChange->fresh()->getRawOriginal('canonical_conflict_state'));
        $this->assertSame('detected', $multipleConflicts->fresh()->getRawOriginal('canonical_conflict_state'));
    }

    #[Test]
    public function it_resyncs_only_llm_sections_from_the_local_corpus_run_dates(): void
    {
        config(['app.env' => 'local']);

        $cleanService = ChurchService::factory()->create([
            'needs_review' => true,
            'import_metadata' => [],
        ]);
        $cleanRun = $this->corpusRun($cleanService, '2026-07-09 11:00:00');
        $confirmedSong = ServiceSection::factory()->create([
            'media_processing_log_id' => $cleanRun->id,
            'section_type' => ServiceSectionType::Song,
            'song_match_type' => ServiceSectionSongMatchType::Inferred,
            'needs_manual_review' => true,
            'metadata' => [
                'classification_mode' => 'llm_structure',
                'review_flags' => [],
                'transcript_song_match' => [
                    'song_id' => 123,
                    'confidence' => 0.95,
                ],
            ],
        ]);
        $filler = ServiceSection::factory()->create([
            'media_processing_log_id' => $cleanRun->id,
            'section_type' => ServiceSectionType::Other,
            'needs_manual_review' => true,
            'metadata' => [
                'classification_mode' => 'llm_structure',
                'review_flags' => [
                    'structure_low_confidence',
                    'structure_oos_cross_type_inversion',
                ],
            ],
        ]);

        $actionableService = ChurchService::factory()->create([
            'needs_review' => true,
            'import_metadata' => [],
        ]);
        $actionableRun = $this->corpusRun($actionableService, '2026-07-10 11:00:00');
        $actionableSong = ServiceSection::factory()->create([
            'media_processing_log_id' => $actionableRun->id,
            'section_type' => ServiceSectionType::Song,
            'needs_manual_review' => true,
            'metadata' => [
                'classification_mode' => 'llm_structure',
                'review_flags' => ['structure_low_confidence'],
            ],
        ]);

        $outsideService = ChurchService::factory()->create(['needs_review' => true]);
        $outsideRun = $this->corpusRun($outsideService, '2026-07-11 11:00:00');
        $outsideSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $outsideRun->id,
            'section_type' => ServiceSectionType::Other,
            'needs_manual_review' => true,
            'metadata' => [
                'classification_mode' => 'llm_structure',
                'review_flags' => ['structure_low_confidence'],
            ],
        ]);

        $this->artisan('services:cleanup-review-queue-noise', ['--execute' => true])
            ->assertSuccessful();

        $this->assertSame(ServiceSectionSongMatchType::Confirmed, $confirmedSong->fresh()->song_match_type);
        $this->assertFalse($confirmedSong->fresh()->needs_manual_review);
        $this->assertFalse($filler->fresh()->needs_manual_review);
        $this->assertSame(
            ['structure_low_confidence', 'structure_oos_cross_type_inversion'],
            $filler->fresh()->metadata['review_flags'],
        );
        $this->assertFalse($cleanService->fresh()->needs_review);

        $this->assertTrue($actionableSong->fresh()->needs_manual_review);
        $this->assertTrue($actionableService->fresh()->needs_review);
        $this->assertTrue($outsideSection->fresh()->needs_manual_review);
        $this->assertTrue($outsideService->fresh()->needs_review);
    }

    #[Test]
    public function it_skips_corpus_resync_outside_local_and_testing_environments(): void
    {
        config(['app.env' => 'production']);

        $service = ChurchService::factory()->create(['needs_review' => true]);
        $run = $this->corpusRun($service, '2026-07-09 11:00:00');
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Other,
            'needs_manual_review' => true,
            'metadata' => [
                'classification_mode' => 'llm_structure',
                'review_flags' => ['structure_low_confidence'],
            ],
        ]);

        $this->artisan('services:cleanup-review-queue-noise', ['--execute' => true])
            ->expectsOutputToContain('Corpus re-sync skipped')
            ->assertSuccessful();

        $this->assertTrue($section->fresh()->needs_manual_review);
        $this->assertTrue($service->fresh()->needs_review);
    }

    private function bulkImportService(array $overrides = []): ChurchService
    {
        return ChurchService::factory()->create(array_replace([
            'source' => 'openlp',
            'needs_review' => true,
            'pending_structure_merge_source' => null,
            'import_metadata' => ['confidence_score' => 0.5],
            'created_at' => '2026-05-08 09:00:00',
            'updated_at' => '2026-05-08 09:00:00',
        ], $overrides));
    }

    private function firstPopulationConflictService(): ChurchService
    {
        return $this->conflictService([
            'type' => 'added_item',
            'item' => ['id' => 42, 'title' => 'Amazing grace'],
        ]);
    }

    /** @param array<string, mixed> $change */
    private function conflictService(array $change): ChurchService
    {
        $conflict = [
            'detected_at' => '2026-05-08T09:00:00+00:00',
            'incoming_source' => 'openlp',
            'review_reopened' => false,
            'reviewed_previously' => false,
            'canonical_changed' => true,
            'changes' => [$change],
            'conflicts' => [],
        ];

        $service = ChurchService::factory()->create([
            'canonical_conflict_state' => 'detected',
            'canonical_conflict_detected_at' => '2026-05-08 09:00:00',
            'canonical_conflict_incoming_source' => 'openlp',
            'canonical_conflict_reviewed_previously' => false,
            'canonical_conflict_canonical_changed' => true,
            'canonical_conflict_reason' => 'canonical_changed',
        ]);

        DB::table('church_services')
            ->where('id', $service->id)
            ->update(['import_metadata' => json_encode([
                'confidence_score' => 1.0,
                'canonical_conflict' => $conflict,
                'canonical_conflict_history' => [$conflict],
            ], JSON_THROW_ON_ERROR)]);

        return $service->fresh();
    }

    private function corpusRun(ChurchService $service, string $createdAt): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
