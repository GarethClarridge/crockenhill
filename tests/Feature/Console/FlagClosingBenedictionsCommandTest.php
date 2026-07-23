<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Queries\ServiceReviewDashboardQuery;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Support\ServiceSectionConfidence;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlagClosingBenedictionsCommandTest extends TestCase
{
    use DatabaseTransactions;

    private ServiceReviewDashboardQuery $query;

    protected function setUp(): void
    {
        parent::setUp();

        $this->query = app(ServiceReviewDashboardQuery::class);
    }

    #[Test]
    public function it_flags_a_short_closing_reading_so_it_stops_needing_review(): void
    {
        [$section] = $this->closingReading(recordingDuration: 4569.0, endTime: 4569.0);

        // Before: a low-confidence closing bible_reading is a review candidate.
        $this->assertTrue($this->query->isReviewCandidate($section));

        $this->artisan('services:flag-closing-benedictions', ['--execute' => true])
            ->assertSuccessful();

        $fresh = $section->fresh();
        $this->assertContains(
            ServiceStructureValidator::FLAG_BENEDICTION_SUSPECT,
            $fresh->metadata?->toArray()['review_flags'] ?? [],
        );
        $this->assertFalse($fresh->needs_manual_review);
        $this->assertFalse($this->query->isReviewCandidate($fresh));
    }

    #[Test]
    public function it_leaves_a_mid_service_short_reading_untouched(): void
    {
        // Ends far from the recording end → not a closing benediction.
        [$section] = $this->closingReading(recordingDuration: 4569.0, endTime: 2000.0);

        $this->artisan('services:flag-closing-benedictions', ['--execute' => true])
            ->assertSuccessful();

        $this->assertNotContains(
            ServiceStructureValidator::FLAG_BENEDICTION_SUSPECT,
            $section->fresh()->metadata?->toArray()['review_flags'] ?? [],
        );
    }

    #[Test]
    public function it_is_a_dry_run_by_default(): void
    {
        [$section] = $this->closingReading(recordingDuration: 4569.0, endTime: 4569.0);

        $this->artisan('services:flag-closing-benedictions')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertNotContains(
            ServiceStructureValidator::FLAG_BENEDICTION_SUSPECT,
            $section->fresh()->metadata?->toArray()['review_flags'] ?? [],
        );
    }

    /**
     * @return array{0: ServiceSection, 1: ChurchService}
     */
    private function closingReading(float $recordingDuration, float $endTime): array
    {
        $service = ChurchService::factory()->create(['needs_review' => true, 'review_reason' => null]);
        $run = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'duration' => $recordingDuration,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::BibleReading,
            'start_time' => $endTime - 36.0,
            'end_time' => $endTime,
            'duration' => 36.0,
            'confidence' => ServiceSectionConfidence::HIGH_THRESHOLD - 0.05,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'metadata' => ['review_flags' => []],
        ]);

        return [$section, $service];
    }
}
