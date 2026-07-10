<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Sermon\SermonExtractionPlanResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonExtractionPlanResolverTest extends TestCase
{
    use RefreshDatabase;

    private SermonExtractionPlanResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(SermonExtractionPlanResolver::class);
    }

    #[Test]
    public function it_prefers_high_confidence_sermon_section_when_bible_section_is_missing(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('single_span', $plan['mode']);
        $this->assertSame('service_sections', $plan['source']);
        $this->assertSame(500.0, $plan['segments'][0]['start_time']);
        $this->assertSame(1200.0, $plan['segments'][0]['end_time']);
    }

    #[Test]
    public function it_accepts_a_sermon_section_whose_only_review_flag_is_a_cross_type_inversion(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        // A cross-type OoS inversion questions item alignment, not the sermon's
        // boundaries — it must not push extraction onto the coarser baseline path.
        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => true,
            'metadata' => [
                'confidence_level' => 'high',
                'review_flags' => ['structure_oos_cross_type_inversion'],
            ],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('service_sections', $plan['source']);
        $this->assertSame(500.0, $plan['segments'][0]['start_time']);
        $this->assertSame(1200.0, $plan['segments'][0]['end_time']);
    }

    #[Test]
    public function it_accepts_a_sermon_section_flagged_only_for_a_missing_preached_reading(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        // A missing preached reading questions what surrounds the sermon, not
        // its boundaries — extraction must not demote to the RMS baseline.
        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => true,
            'metadata' => [
                'confidence_level' => 'high',
                'review_flags' => ['structure_missing_preached_reading'],
            ],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('service_sections', $plan['source']);
        $this->assertSame(500.0, $plan['segments'][0]['start_time']);
        $this->assertSame(1200.0, $plan['segments'][0]['end_time']);
    }

    #[Test]
    public function it_declines_a_sermon_section_carrying_a_boundary_quality_review_flag(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => true,
            'metadata' => [
                'confidence_level' => 'high',
                'review_flags' => ['structure_oos_cross_type_inversion', 'structure_low_confidence'],
            ],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('processing_log', $plan['source']);
        $this->assertSame(100.0, $plan['segments'][0]['start_time']);
        $this->assertSame(200.0, $plan['segments'][0]['end_time']);
    }

    #[Test]
    public function it_declines_a_review_flagged_sermon_section_without_recorded_flags(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        // Review was requested by something other than structure flags (e.g. an
        // operator) — stay conservative and fall back to the baseline.
        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => true,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('processing_log', $plan['source']);
    }

    #[Test]
    public function it_merges_adjacent_bible_and_sermon_sections_into_single_span(): void
    {
        config(['media-processing.section_extraction.enhanced_sermon.adjacent_gap_seconds' => 60]);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => 1,
            'start_time' => 300.0,
            'end_time' => 600.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 2,
            'start_time' => 630.0,
            'end_time' => 2100.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('single_span', $plan['mode']);
        $this->assertSame(300.0, $plan['segments'][0]['start_time']);
        $this->assertSame(2100.0, $plan['segments'][0]['end_time']);
        $this->assertSame('adjacent_bible_plus_sermon', $plan['metadata']['strategy']);
    }

    #[Test]
    public function it_uses_concat_mode_for_non_adjacent_bible_and_sermon_when_enabled(): void
    {
        config([
            'media-processing.section_extraction.enhanced_sermon.adjacent_gap_seconds' => 60,
            'media-processing.section_extraction.enhanced_sermon.allow_non_adjacent_concat' => true,
        ]);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => 1,
            'start_time' => 300.0,
            'end_time' => 600.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 2,
            'start_time' => 1500.0,
            'end_time' => 2400.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('concat_spans', $plan['mode']);
        $this->assertCount(2, $plan['segments']);
        $this->assertSame(300.0, $plan['segments'][0]['start_time']);
        $this->assertSame(600.0, $plan['segments'][0]['end_time']);
        $this->assertSame(1500.0, $plan['segments'][1]['start_time']);
        $this->assertSame(2400.0, $plan['segments'][1]['end_time']);
    }

    #[Test]
    public function it_falls_back_to_baseline_when_legacy_section_preference_is_disabled(): void
    {
        config(['media-processing.section_classification.prefer_high_confidence_sermon_section' => false]);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 250.0,
            'sermon_end_time' => 1900.0,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('baseline', $plan['mode']);
        $this->assertSame('processing_log', $plan['source']);
        $this->assertSame(250.0, $plan['segments'][0]['start_time']);
        $this->assertSame(1900.0, $plan['segments'][0]['end_time']);
    }

    #[Test]
    public function it_ignores_bible_section_when_it_overlaps_sermon_start(): void
    {
        config([
            'media-processing.section_extraction.enhanced_sermon.adjacent_gap_seconds' => 60,
            'media-processing.section_extraction.enhanced_sermon.allow_non_adjacent_concat' => true,
        ]);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => 1,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 2,
            'start_time' => 1000.0,
            'end_time' => 2400.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('single_span', $plan['mode']);
        $this->assertSame('service_sections', $plan['source']);
        $this->assertSame(1000.0, $plan['segments'][0]['start_time']);
        $this->assertSame(2400.0, $plan['segments'][0]['end_time']);
        $this->assertSame('sermon_only_invalid_bible_timing', $plan['metadata']['strategy']);
    }

    #[Test]
    public function it_prefers_the_bibles_linked_reading_over_a_closer_unlinked_one(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        // Closer but not marked as the day's scripture in the order of service.
        $this->reading($log, order: 1, start: 1800.0, end: 1900.0);

        $linkedReading = $this->reading($log, order: 2, start: 1500.0, end: 1650.0, itemId: $this->biblesOosItem()->id);

        $this->sermon($log, order: 3, start: 2000.0, end: 3500.0);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('non_adjacent_bible_plus_sermon_concat', $plan['metadata']['strategy']);
        $this->assertSame($linkedReading->id, $plan['metadata']['bible_section_id']);
        $this->assertSame(1500.0, $plan['segments'][0]['start_time']);
    }

    #[Test]
    public function it_prefers_the_closest_reading_rather_than_the_earliest(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        // Earliest in service order, but a long way before the sermon.
        $this->reading($log, order: 1, start: 1500.0, end: 1650.0);

        $closerReading = $this->reading($log, order: 2, start: 1750.0, end: 1900.0);

        $this->sermon($log, order: 3, start: 2000.0, end: 3500.0);

        $plan = $this->resolver->resolve($log);

        $this->assertSame($closerReading->id, $plan['metadata']['bible_section_id']);
        $this->assertSame(1750.0, $plan['segments'][0]['start_time']);
    }

    #[Test]
    public function it_demotes_a_short_preamble_reading_in_favour_of_a_substantive_one(): void
    {
        config(['media-processing.section_extraction.enhanced_sermon.min_reading_duration_seconds' => 90]);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        $substantiveReading = $this->reading($log, order: 1, start: 1400.0, end: 1700.0);

        // A 30-second "let us turn to..." preamble immediately before the sermon.
        $this->reading($log, order: 2, start: 1950.0, end: 1980.0);

        $this->sermon($log, order: 3, start: 2000.0, end: 3500.0);

        $plan = $this->resolver->resolve($log);

        $this->assertSame($substantiveReading->id, $plan['metadata']['bible_section_id']);
    }

    #[Test]
    public function it_prefers_the_reading_matching_the_sermon_reference_over_a_longer_earlier_one(): void
    {
        config(['media-processing.section_extraction.enhanced_sermon.min_reading_duration_seconds' => 90]);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        // The 2023-05-07 corpus run: Psalm 72 (168 s) early in the service beat
        // the actual preached text — an adjacent 72-second Philippians reading
        // demoted by the substantive-duration tier — so the published audio
        // opened with the wrong passage.
        $this->reading($log, order: 1, start: 548.0, end: 716.0, reference: 'Psalm 72');

        $philippians = $this->reading($log, order: 2, start: 1106.0, end: 1178.0, reference: 'Philippians 2:5-11');

        $this->sermon($log, order: 3, start: 1372.0, end: 2914.0, reference: 'Philippians 2:5-11');

        $plan = $this->resolver->resolve($log);

        $this->assertSame($philippians->id, $plan['metadata']['bible_section_id']);
        $this->assertSame(1106.0, $plan['segments'][0]['start_time']);
    }

    #[Test]
    public function it_matches_a_sermon_reference_that_is_a_subrange_of_the_reading(): void
    {
        config(['media-processing.section_extraction.enhanced_sermon.min_reading_duration_seconds' => 90]);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        // A sermon usually expounds part of the passage read; overlap, not
        // equality, is the match criterion.
        $this->reading($log, order: 1, start: 1400.0, end: 1700.0, reference: 'Psalm 113');

        $preachedText = $this->reading($log, order: 2, start: 1900.0, end: 1970.0, reference: '1 Timothy 3:14-4:16');

        $this->sermon($log, order: 3, start: 2000.0, end: 3500.0, reference: '1 Timothy 4:7-10');

        $plan = $this->resolver->resolve($log);

        $this->assertSame($preachedText->id, $plan['metadata']['bible_section_id']);
    }

    #[Test]
    public function it_does_not_pair_a_reading_beyond_the_max_pairing_gap(): void
    {
        config(['media-processing.section_extraction.enhanced_sermon.max_pairing_gap_seconds' => 900]);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        // An opening-notices verse ~20 minutes before the sermon.
        $this->reading($log, order: 1, start: 500.0, end: 800.0);

        $this->sermon($log, order: 2, start: 2000.0, end: 3500.0);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('single_span', $plan['mode']);
        $this->assertSame('sermon_only_reading_gap_exceeded', $plan['metadata']['strategy']);
        $this->assertSame(2000.0, $plan['segments'][0]['start_time']);
        $this->assertSame(3500.0, $plan['segments'][0]['end_time']);
    }

    #[Test]
    public function it_routes_an_over_long_sermon_section_to_the_baseline_for_manual_review(): void
    {
        config(['media-processing.section_extraction.enhanced_sermon.max_sermon_duration_seconds' => 2700]);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 0.0,
            'sermon_end_time' => 3916.0,
        ]);

        // A 65-minute "sermon" section — RMS under-segmentation collapsing the whole service.
        $this->sermon($log, order: 1, start: 0.0, end: 3916.0);

        $plan = $this->resolver->resolve($log);

        $this->assertSame('baseline', $plan['mode']);
        $this->assertSame('processing_log', $plan['source']);
        $this->assertSame('sermon_section_exceeds_maximum_duration', $plan['metadata']['reason']);
    }

    private function reading(
        MediaProcessingLog $log,
        int $order,
        float $start,
        float $end,
        ?int $itemId = null,
        ?string $reference = null,
    ): ServiceSection {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => $itemId,
            'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => $order,
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $end - $start,
            'needs_manual_review' => false,
        ] + ($reference === null ? [] : [
            'metadata' => ['confidence_level' => 'high', 'reading_reference' => $reference],
        ]));
    }

    private function sermon(
        MediaProcessingLog $log,
        int $order,
        float $start,
        float $end,
        ?string $reference = null,
    ): ServiceSection {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => $order,
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $end - $start,
            'needs_manual_review' => false,
        ] + ($reference === null ? [] : [
            'metadata' => ['confidence_level' => 'high', 'sermon_reference' => $reference],
        ]));
    }

    private function biblesOosItem(): ChurchServiceItem
    {
        $service = ChurchService::factory()->create();

        return ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'type' => 'bibles',
            'title' => 'Colossians 1:15-23',
        ]);
    }
}
