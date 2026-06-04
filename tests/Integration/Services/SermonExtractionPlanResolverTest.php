<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\ServiceSectionType;
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

        $this->resolver = new SermonExtractionPlanResolver;
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
            'section_type' => ServiceSectionType::SERMON->value,
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
    public function it_merges_adjacent_bible_and_sermon_sections_into_single_span(): void
    {
        config(['media-processing.section_extraction.enhanced_sermon.adjacent_gap_seconds' => 60]);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 200.0,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BIBLE_READING->value,
            'section_order' => 1,
            'start_time' => 300.0,
            'end_time' => 600.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::SERMON->value,
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
            'section_type' => ServiceSectionType::BIBLE_READING->value,
            'section_order' => 1,
            'start_time' => 300.0,
            'end_time' => 600.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::SERMON->value,
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
            'section_type' => ServiceSectionType::SERMON->value,
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
            'section_type' => ServiceSectionType::BIBLE_READING->value,
            'section_order' => 1,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::SERMON->value,
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
}
