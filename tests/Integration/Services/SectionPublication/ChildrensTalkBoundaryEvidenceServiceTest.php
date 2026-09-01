<?php

declare(strict_types=1);

namespace Tests\Integration\Services\SectionPublication;

use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionPublication\ChildrensTalkBoundaryEvidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChildrensTalkBoundaryEvidenceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.transcript_disk' => 'local',
        ]);
    }

    #[Test]
    public function it_records_the_inclusive_tail_and_following_sections_without_recutting(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'section_order' => 1,
            'start_time' => 600.0,
            'end_time' => 900.0,
            'metadata' => ['confidence_level' => 'high'],
        ]);
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Prayer->value,
            'section_order' => 2,
            'title' => 'Following prayer',
            'start_time' => 900.0,
            'end_time' => 1040.0,
        ]);

        $transcriptPath = 'service-transcripts/test-'.$processingLog->processing_id.'.normalized.json';
        Storage::disk('local')->put($transcriptPath, json_encode([
            'cues' => [
                ['start' => 650.0, 'end' => 760.0, 'text' => 'The children hear the story.'],
                ['start' => 810.0, 'end' => 890.0, 'text' => 'Let us pray together now.'],
            ],
            'duration' => 1100.0,
            'source' => 'mock',
        ], JSON_THROW_ON_ERROR));
        $processingLog->putServiceTranscriptPath($transcriptPath);

        $evidence = app(ChildrensTalkBoundaryEvidenceService::class)->assess($section);

        $this->assertSame('inclusive', $evidence['candidate']['kind']);
        $this->assertSame(600.0, $evidence['candidate']['start_time']);
        $this->assertSame(900.0, $evidence['candidate']['end_time']);
        $this->assertSame('retain_inclusive_candidate', $evidence['action']);
        $this->assertTrue($evidence['mandatory_approval']);
        $this->assertSame('available', $evidence['tail']['status']);
        $this->assertStringContainsString('Let us pray together now.', $evidence['tail']['text']);
        $this->assertSame('prayer', $evidence['following_sections'][0]['section_type']);
        $this->assertSame('Following prayer', $evidence['following_sections'][0]['title']);

        $section->refresh();
        $this->assertSame(600.0, (float) $section->start_time);
        $this->assertSame(900.0, (float) $section->end_time);
    }

    #[Test]
    public function it_records_missing_transcript_evidence_without_inventing_a_boundary(): void
    {
        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'start_time' => 100.0,
            'end_time' => 240.0,
        ]);

        $evidence = app(ChildrensTalkBoundaryEvidenceService::class)->assess($section);

        $this->assertSame('not_recorded', $evidence['inputs']['service_transcript']['status']);
        $this->assertSame('unavailable', $evidence['tail']['status']);
        $this->assertSame([], $evidence['following_sections']);
        $this->assertSame(100.0, $evidence['candidate']['start_time']);
        $this->assertSame(240.0, $evidence['candidate']['end_time']);
    }
}
