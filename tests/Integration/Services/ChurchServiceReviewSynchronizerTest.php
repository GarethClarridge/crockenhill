<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\ChurchServiceReviewSynchronizer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceReviewSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    private ChurchServiceReviewSynchronizer $synchronizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->synchronizer = app(ChurchServiceReviewSynchronizer::class);
    }

    // ── needs_review computation ─────────────────────────────────────────────

    #[Test]
    public function it_sets_needs_review_true_when_review_triggers_are_present(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-10-05',
            'service' => SermonService::Morning->value,
            'needs_review' => false,
        ]);

        $sections = new EloquentCollection;

        $this->synchronizer->sync($churchService, $sections, ['oos_structure_mismatch']);

        $churchService->refresh();
        $this->assertTrue($churchService->needs_review);
    }

    #[Test]
    public function it_sets_needs_review_false_when_no_triggers_and_no_section_review(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-10-06',
            'service' => SermonService::Morning->value,
            'needs_review' => true,
            'import_metadata' => [],
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'needs_manual_review' => false,
        ]);

        $this->synchronizer->sync($churchService, new EloquentCollection([$section]), []);

        $churchService->refresh();
        $this->assertFalse($churchService->needs_review);
    }

    #[Test]
    public function it_sets_needs_review_true_when_a_section_needs_manual_review(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-10-07',
            'service' => SermonService::Morning->value,
            'needs_review' => false,
            'import_metadata' => [],
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'needs_manual_review' => true,
        ]);

        $this->synchronizer->sync($churchService, new EloquentCollection([$section]), []);

        $churchService->refresh();
        $this->assertTrue($churchService->needs_review);
    }

    // ── import_metadata.review_triggers ─────────────────────────────────────

    #[Test]
    public function it_writes_review_triggers_into_import_metadata(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-10-08',
            'service' => SermonService::Morning->value,
            'import_metadata' => [],
        ]);

        $this->synchronizer->sync($churchService, new EloquentCollection, ['unmatched_song_sections', 'manual_review_sections']);

        $churchService->refresh();
        $this->assertSame(
            ['unmatched_song_sections', 'manual_review_sections'],
            $churchService->import_metadata['review_triggers']
        );
    }

    #[Test]
    public function it_removes_review_triggers_from_import_metadata_when_empty(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-10-09',
            'service' => SermonService::Morning->value,
            'import_metadata' => ['review_triggers' => ['stale_trigger']],
        ]);

        $this->synchronizer->sync($churchService, new EloquentCollection, []);

        $churchService->refresh();
        $this->assertArrayNotHasKey('review_triggers', $churchService->import_metadata);
    }

    // ── import confidence threshold ──────────────────────────────────────────

    #[Test]
    public function it_keeps_needs_review_true_when_import_confidence_is_in_review_band(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-10-10',
            'service' => SermonService::Morning->value,
            'source' => 'email',
            'needs_review' => false,
            'import_metadata' => ['confidence_score' => 0.80],
        ]);

        $this->synchronizer->sync($churchService, new EloquentCollection, []);

        $churchService->refresh();
        $this->assertTrue($churchService->needs_review);
    }

    #[Test]
    public function it_does_not_flag_needs_review_for_manual_source_regardless_of_confidence(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-10-11',
            'service' => SermonService::Morning->value,
            'source' => 'manual',
            'needs_review' => false,
            'import_metadata' => ['confidence_score' => 0.80],
        ]);

        $this->synchronizer->sync($churchService, new EloquentCollection, []);

        $churchService->refresh();
        $this->assertFalse($churchService->needs_review);
    }

    // ── canonical conflict ───────────────────────────────────────────────────

    #[Test]
    public function it_keeps_needs_review_true_for_outstanding_canonical_conflict(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-10-12',
            'service' => SermonService::Morning->value,
            'source' => 'manual',
            'needs_review' => false,
            'review_reason' => 'Service items changed after manual review.',
        ]);

        $this->synchronizer->sync($churchService, new EloquentCollection, []);

        $churchService->refresh();
        $this->assertTrue($churchService->needs_review);
        $this->assertSame('Service items changed after manual review.', $churchService->review_reason);
    }

    // ── additive roll-up (openReviewFromSections) ────────────────────────────

    #[Test]
    public function it_opens_review_when_a_section_needs_manual_review(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-10-19',
            'service' => SermonService::Morning->value,
            'needs_review' => false,
            'import_metadata' => [
                'review_triggers' => ['unmatched_song_sections'],
            ],
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => MediaProcessingLog::factory()->livestream()->create()->id,
            'needs_manual_review' => true,
        ]);

        $this->synchronizer->openReviewFromSections($churchService, new EloquentCollection([$section]));

        $churchService->refresh();
        $this->assertTrue($churchService->needs_review);
        $this->assertSame(
            ['unmatched_song_sections'],
            $churchService->import_metadata?->toArray()['review_triggers'] ?? null,
            'The additive roll-up never rewrites review triggers.'
        );
    }

    #[Test]
    public function it_never_closes_review_from_the_additive_roll_up(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-10-26',
            'service' => SermonService::Morning->value,
            'needs_review' => true,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => MediaProcessingLog::factory()->livestream()->create()->id,
            'needs_manual_review' => false,
        ]);

        $this->synchronizer->openReviewFromSections($churchService, new EloquentCollection([$section]));

        $churchService->refresh();
        $this->assertTrue($churchService->needs_review);
    }

    #[Test]
    public function it_leaves_review_closed_when_no_section_needs_manual_review(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2026-11-02',
            'service' => SermonService::Morning->value,
            'needs_review' => false,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => MediaProcessingLog::factory()->livestream()->create()->id,
            'needs_manual_review' => false,
        ]);

        $this->synchronizer->openReviewFromSections($churchService, new EloquentCollection([$section]));

        $churchService->refresh();
        $this->assertFalse($churchService->needs_review);
    }
}
